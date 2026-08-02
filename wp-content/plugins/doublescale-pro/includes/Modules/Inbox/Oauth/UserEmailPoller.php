<?php
/**
 * Per-User Email IMAP Poller
 *
 * Polls IMAP mailboxes for all users who have connected personal email accounts.
 * Uses a single Action Scheduler task that iterates all connected users with a
 * time budget and fair round-robin ordering.
 *
 * @since 1.6.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\Oauth;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Tracking\ImapClient;
use DoubleScale\Pro\Modules\Inbox\Incoming\EmailIncoming;
use DoubleScale\Pro\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * UserEmailPoller class
 */
class UserEmailPoller {

	const TIME_BUDGET_SECONDS = 45;

	/**
	 * Singleton instance
	 *
	 * @var UserEmailPoller|null
	 */
	private static $instance = null;

	/**
	 * EmailIncoming instance for processing.
	 *
	 * @var EmailIncoming
	 */
	private $email_incoming;

	/**
	 * Get singleton instance.
	 *
	 * @param \DoubleScale\Pro\Modules\Tasks\Tasks|null $campaigns_tasks Tasks instance for registering callback.
	 * @return UserEmailPoller
	 */
	public static function instance( $campaigns_tasks = null ) {
		if ( null === self::$instance ) {
			self::$instance = new self( $campaigns_tasks );
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @param \DoubleScale\Pro\Modules\Tasks\Tasks|null $campaigns_tasks Tasks instance.
	 */
	private function __construct( $campaigns_tasks = null ) {
		$this->email_incoming = EmailIncoming::instance();

		if ( $campaigns_tasks ) {
			$campaigns_tasks->register_callback( 'doublescale_user_email_accounts', array( $this, 'poll_all_user_accounts' ) );
		}
	}

	/**
	 * Poll all user email accounts within a time budget.
	 *
	 * Sorted by last-poll time ascending for fair round-robin. Each user's
	 * errors are isolated so one failure doesn't block others.
	 */
	public function poll_all_user_accounts() {
		$users = get_users(
			array(
				'meta_query' => array(
					array(
						'key'     => 'doublescale_user_email_account',
						'compare' => 'EXISTS',
					),
				),
				'fields'     => 'ID',
			)
		);

		if ( empty( $users ) ) {
			return;
		}

		// Filter to enabled accounts and sort by last-poll time ascending.
		$user_accounts = array();
		foreach ( $users as $uid ) {
			$account = get_user_meta( $uid, 'doublescale_user_email_account', true );
			if ( ! is_array( $account ) || empty( $account['enabled'] ) ) {
				continue;
			}
			$last_poll = (int) get_user_meta( $uid, 'doublescale_user_imap_last_poll', true );
			$user_accounts[] = array(
				'user_id'   => (int) $uid,
				'account'   => $account,
				'last_poll' => $last_poll,
			);
		}

		if ( empty( $user_accounts ) ) {
			return;
		}

		usort( $user_accounts, function ( $a, $b ) {
			return $a['last_poll'] - $b['last_poll'];
		} );

		// Pre-load contact email set once (shared across all user polls).
		$contact_emails = ContactModel::pluck( 'email' )->map( function ( $email ) {
			return strtolower( $email );
		} )->all();
		$contact_set = array_flip( $contact_emails );

		$global_settings  = Settings::get( 'email_inbound', array() );
		$excluded_domains = $global_settings['excluded_domains'] ?? array();

		$start_time = microtime( true );

		foreach ( $user_accounts as $entry ) {
			if ( ( microtime( true ) - $start_time ) > self::TIME_BUDGET_SECONDS ) {
				break;
			}

			$user_id = $entry['user_id'];
			$account = $entry['account'];

			try {
				$this->poll_user_account( $user_id, $account, $contact_set, $excluded_domains );
			} catch ( \Throwable $e ) {
				$this->handle_user_poll_error( $user_id, $account, $e );
			}
		}
	}

	/**
	 * Poll a single user's IMAP account (inbox + optional sent folder).
	 *
	 * @param int    $user_id         WordPress user ID.
	 * @param array  $account         User's email account meta.
	 * @param array  $contact_set     Flipped array of lowercase contact emails.
	 * @param array  $excluded_domains Global excluded domains list.
	 */
	private function poll_user_account( $user_id, $account, $contact_set, $excluded_domains ) {
		$provider = $account['imap_provider'] ?? 'custom';

		// Skip OAuth providers that need re-authorization.
		if ( in_array( $provider, array( 'gmail', 'outlook' ), true ) ) {
			$oauth = $account['oauth'][ $provider ] ?? array();
			if ( ! empty( $oauth['needs_reauth'] ) ) {
				return;
			}
		}

		$auto_create = ! empty( $account['auto_create_contacts'] );
		$options     = array(
			'user_id'              => $user_id,
			'auto_create_contacts' => $auto_create,
		);

		$client = null;
		try {
			$client = $this->create_imap_client_for_user( $provider, $account, $user_id );
			$client->connect();

			$this->poll_user_inbox( $client, $user_id, $account, $contact_set, $excluded_domains, $options );

			if ( ! empty( $account['sync_sent'] ) ) {
				try {
					$this->poll_user_sent_folder( $client, $user_id, $account, $provider, $contact_set, $options );
				} catch ( \Exception $e ) {
					doublescale_get_logger()->warning(
						'User IMAP sent folder sync failed',
						array(
							'code'    => 'user_email_sent_sync_error',
							'user_id' => $user_id,
							'error'   => $e->getMessage(),
						)
					);
				}
			}

			// Reset failure counter on success.
			delete_transient( 'doublescale_user_imap_failures_' . $user_id );
			update_user_meta( $user_id, 'doublescale_user_imap_last_poll', time() );
		} finally {
			if ( $client ) {
				$client->disconnect();
			}
		}
	}

	/**
	 * Poll a user's INBOX for incoming emails.
	 *
	 * @param ImapClient $client          Connected IMAP client.
	 * @param int         $user_id         WordPress user ID.
	 * @param array       $account         User's email account meta.
	 * @param array       $contact_set     Flipped contact emails.
	 * @param array       $excluded_domains Global excluded domains.
	 * @param array       $options         Options for process_incoming_email().
	 */
	private function poll_user_inbox( $client, $user_id, $account, $contact_set, $excluded_domains, $options ) {
		$last_poll    = (int) get_user_meta( $user_id, 'doublescale_user_imap_last_poll', true );
		$max_lookback = 7 * DAY_IN_SECONDS;
		$since_time   = $last_poll > 0
			? max( $last_poll - 300, time() - $max_lookback )
			: time() - DAY_IN_SECONDS;
		$since_date   = gmdate( 'Y-m-d', $since_time );

		$unseen_emails = $client->fetch_unseen();

		$lookback_days = max( 1, (int) ceil( ( time() - $since_time ) / DAY_IN_SECONDS ) );
		$recent_limit  = min( 20 * $lookback_days, 100 );
		$recent_emails = $client->fetch_recent( $since_date, $recent_limit );

		// Merge and dedup by UID or message_id.
		$dedup_map = array();
		foreach ( $unseen_emails as $email ) {
			$key = ( false !== $email['uid'] && $email['uid'] ) ? 'uid:' . $email['uid'] : 'mid:' . $email['message_id'];
			$dedup_map[ $key ] = $email;
		}
		foreach ( $recent_emails as $email ) {
			$key = ( false !== $email['uid'] && $email['uid'] ) ? 'uid:' . $email['uid'] : 'mid:' . $email['message_id'];
			if ( ! isset( $dedup_map[ $key ] ) ) {
				$dedup_map[ $key ] = $email;
			}
		}

		$emails = array_values( $dedup_map );
		usort( $emails, function ( $a, $b ) {
			return ( (int) $b['uid'] ) - ( (int) $a['uid'] );
		} );
		$emails = array_slice( $emails, 0, 20 );

		$auto_create = ! empty( $options['auto_create_contacts'] );
		$processed   = 0;

		foreach ( $emails as $email ) {
			$sender           = strtolower( $email['from_email'] ?? '' );
			$should_mark_seen = true;
			$is_known_contact = isset( $contact_set[ $sender ] );

			if ( $is_known_contact || ( $auto_create && ! $this->is_excluded_sender( $sender, $excluded_domains ) ) ) {
				try {
					$this->email_incoming->process_incoming_email( $email, $options );
					++$processed;
				} catch ( \Exception $e ) {
					$should_mark_seen = false;
					doublescale_get_logger()->error(
						'Failed to process incoming email for user',
						array(
							'code'    => 'user_email_incoming_process_error',
							'user_id' => $user_id,
							'uid'     => $email['uid'] ?? '',
							'error'   => $e->getMessage(),
						)
					);
				}
			}

			if ( $should_mark_seen && ! empty( $email['uid'] ) ) {
				$client->mark_as_seen( $email['uid'] );
			}
		}

		if ( $processed > 0 ) {
			doublescale_get_logger()->info(
				'User IMAP inbox polling completed',
				array(
					'code'      => 'user_email_inbox_poll_complete',
					'user_id'   => $user_id,
					'fetched'   => count( $emails ),
					'processed' => $processed,
				)
			);
		}
	}

	/**
	 * Poll a user's SENT folder for outgoing emails.
	 *
	 * @param ImapClient $client      Connected IMAP client.
	 * @param int         $user_id     WordPress user ID.
	 * @param array       $account     User's email account meta.
	 * @param string      $provider    IMAP provider slug.
	 * @param array       $contact_set Flipped contact emails.
	 * @param array       $options     Options for process_outgoing_email().
	 */
	private function poll_user_sent_folder( $client, $user_id, $account, $provider, $contact_set, $options ) {
		$sent_folder = $this->get_sent_folder_name( $provider, $account );

		if ( ! $client->open_folder( $sent_folder ) ) {
			doublescale_get_logger()->warning(
				'User IMAP sent folder: could not open folder',
				array(
					'code'    => 'user_email_sent_folder_open_failed',
					'user_id' => $user_id,
					'folder'  => $sent_folder,
				)
			);
			return;
		}

		$last_sent_poll = (int) get_user_meta( $user_id, 'doublescale_user_imap_sent_last_poll', true );
		$max_lookback   = 7 * DAY_IN_SECONDS;
		$since_time     = $last_sent_poll > 0
			? max( $last_sent_poll - 300, time() - $max_lookback )
			: time() - DAY_IN_SECONDS;
		$since_date     = gmdate( 'Y-m-d', $since_time );

		$lookback_days = max( 1, (int) ceil( ( time() - $since_time ) / DAY_IN_SECONDS ) );
		$limit         = min( 20 * $lookback_days, 100 );

		$emails = $client->fetch_recent( $since_date, $limit );
		if ( empty( $emails ) ) {
			update_user_meta( $user_id, 'doublescale_user_imap_sent_last_poll', time() );
			return;
		}

		$account_email = $this->get_user_account_email( $provider, $account, $user_id );
		$processed     = 0;

		foreach ( $emails as $email ) {
			$sender = strtolower( $email['from_email'] ?? '' );

			if ( ! empty( $account_email ) && $sender !== $account_email ) {
				continue;
			}

			// Check all recipients (To + CC) against known contacts.
			$recipients = ! empty( $email['all_recipients'] )
				? array_map( 'strtolower', $email['all_recipients'] )
				: array( strtolower( $email['to_email'] ?? '' ) );

			$matched_recipients = array_filter(
				$recipients,
				function ( $r ) use ( $contact_set ) {
					return isset( $contact_set[ $r ] );
				}
			);

			if ( empty( $matched_recipients ) ) {
				continue;
			}

			foreach ( $matched_recipients as $matched_email ) {
				$email_copy              = $email;
				$email_copy['to_email']  = $matched_email;

				try {
					$result = $this->email_incoming->process_outgoing_email( $email_copy, $options );
					if ( $result ) {
						++$processed;
					}
				} catch ( \Exception $e ) {
					doublescale_get_logger()->error(
						'Failed to process outgoing email for user',
						array(
							'code'    => 'user_email_sent_sync_process_error',
							'user_id' => $user_id,
							'uid'     => $email['uid'] ?? '',
							'error'   => $e->getMessage(),
						)
					);
				}
			}
		}

		update_user_meta( $user_id, 'doublescale_user_imap_sent_last_poll', time() );

		if ( $processed > 0 ) {
			doublescale_get_logger()->info(
				'User IMAP sent folder sync completed',
				array(
					'code'      => 'user_email_sent_sync_complete',
					'user_id'   => $user_id,
					'fetched'   => count( $emails ),
					'processed' => $processed,
				)
			);
		}
	}

	/**
	 * Create an IMAP client for a user's account.
	 *
	 * @param string $provider IMAP provider ('custom', 'gmail', 'outlook', 'smtp_gmail', 'smtp_outlook').
	 * @param array  $account  User's email account meta.
	 * @param int    $user_id  WordPress user ID.
	 * @return ImapClient
	 * @throws \RuntimeException If configuration is incomplete.
	 */
	private function create_imap_client_for_user( $provider, $account, $user_id ) {
		if ( 'custom' === $provider ) {
			$imap = $account['imap'] ?? array();

			if ( empty( $imap['host'] ) || empty( $imap['username'] ) || empty( $imap['password'] ) ) {
				throw new \RuntimeException(
					__( 'User IMAP: incomplete custom IMAP configuration.', 'doublescale')
				);
			}

			return new ImapClient(
				$imap['host'],
				(int) ( $imap['port'] ?? 993 ),
				$imap['username'],
				$imap['password'],
				$imap['encryption'] ?? 'ssl',
				'login'
			);
		}

		// smtp Outlook — receives over Microsoft Graph (not IMAP), same as the
		// shared inbox. Build a GraphMailClient from the smtp Outlook account.
		if ( 'smtp_outlook' === $provider ) {
			$account_id   = $account['smtp_outlook_account'] ?? '';
			$graph_class  = '\DoubleScale\Pro\Modules\Inbox\Incoming\GraphMailClient';
			$graph_config = \DoubleScale\Core\Settings\Rest\RestSettingsControllerPro::get_smtp_outlook_graph_config( $account_id );

			if ( $graph_config && class_exists( $graph_class ) ) {
				return $graph_class::from_config( $graph_config );
			}

			throw new \RuntimeException(
				__( 'smtp Outlook Graph config unavailable. Check the smtp connection.', 'doublescale')
			);
		}

		// smtp Gmail — reuse tokens from smtp plugin (Gmail stays on IMAP+XOAUTH2).
		if ( 'smtp_gmail' === $provider ) {
			$account_id = $account['smtp_gmail_account'] ?? '';

			if ( method_exists( '\DoubleScale\Core\Settings\Rest\RestSettingsControllerPro', 'get_smtp_gmail_imap_config' ) ) {
				$config = \DoubleScale\Core\Settings\Rest\RestSettingsControllerPro::get_smtp_gmail_imap_config( $account_id );
				if ( $config ) {
					return new ImapClient(
						$config['host'],
						$config['port'],
						$config['username'],
						$config['password'],
						$config['encryption'],
						$config['authentication']
					);
				}
			}

			throw new \RuntimeException(
				__( 'smtp Gmail IMAP config unavailable. Check the smtp connection.', 'doublescale')
			);
		}

		// Outlook via per-user OAuth — receive over Microsoft Graph.
		if ( 'outlook' === $provider ) {
			$graph_class  = '\DoubleScale\Pro\Modules\Inbox\Incoming\GraphMailClient';
			$graph_config = UserEmailOauth::get_graph_config( $user_id );

			if ( $graph_config && class_exists( $graph_class ) ) {
				return $graph_class::from_config( $graph_config );
			}

			throw new \RuntimeException(
				__( 'User Outlook Graph config unavailable. Token may have expired.', 'doublescale')
			);
		}

		// Gmail via per-user OAuth (IMAP+XOAUTH2).
		$config = UserEmailOauth::get_imap_config( $provider, $user_id );
		if ( ! $config ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: OAuth provider name */
					__( 'User OAuth IMAP config unavailable for %s. Token may have expired.', 'doublescale'),
					ucfirst( $provider )
				)
			);
		}

		return new ImapClient(
			$config['host'],
			$config['port'],
			$config['username'],
			$config['password'],
			$config['encryption'],
			$config['authentication']
		);
	}

	/**
	 * Handle a per-user poll error with isolation and throttled logging.
	 *
	 * @param int        $user_id WordPress user ID.
	 * @param array      $account User's email account meta.
	 * @param \Throwable $e       The error or exception.
	 */
	private function handle_user_poll_error( $user_id, $account, $e ) {
		$fail_count = (int) get_transient( 'doublescale_user_imap_failures_' . $user_id );
		++$fail_count;
		set_transient( 'doublescale_user_imap_failures_' . $user_id, $fail_count, HOUR_IN_SECONDS );

		if ( 1 === $fail_count || 5 === $fail_count || 0 === $fail_count % 10 ) {
			doublescale_get_logger()->error(
				'User IMAP polling error',
				array(
					'code'                 => 'user_email_imap_error',
					'user_id'              => $user_id,
					'error'                => $e->getMessage(),
					'consecutive_failures' => $fail_count,
				)
			);
		}

		// For OAuth providers, check for auth failures.
		$provider = $account['imap_provider'] ?? 'custom';
		if ( in_array( $provider, array( 'gmail', 'outlook' ), true ) ) {
			$message    = strtolower( $e->getMessage() );
			$auth_terms = array( 'auth', 'login', 'credential', 'token', 'oauth', 'unauthorized', 'forbidden' );

			foreach ( $auth_terms as $term ) {
				if ( strpos( $message, $term ) !== false ) {
					UserEmailOauth::mark_needs_reauth( $provider, $user_id );
					break;
				}
			}
		}
	}

	/**
	 * Get the SENT folder name for a provider.
	 *
	 * @param string $provider IMAP provider.
	 * @param array  $account  User's email account meta.
	 * @return string Sent folder name.
	 */
	private function get_sent_folder_name( $provider, $account ) {
		switch ( $provider ) {
			case 'gmail':
			case 'smtp_gmail':
				return '[Gmail]/Sent Mail';
			case 'outlook':
			case 'smtp_outlook':
				return 'Sent Items';
			default:
				return $account['imap']['sent_folder'] ?? 'Sent';
		}
	}

	/**
	 * Get the email address of a user's connected account.
	 *
	 * @param string $provider IMAP provider.
	 * @param array  $account  User's email account meta.
	 * @param int    $user_id  WordPress user ID.
	 * @return string Lowercase email address.
	 */
	private function get_user_account_email( $provider, $account, $user_id ) {
		if ( in_array( $provider, array( 'gmail', 'outlook' ), true ) ) {
			return strtolower( $account['oauth'][ $provider ]['email'] ?? '' );
		}

		if ( 'smtp_gmail' === $provider ) {
			$account_id     = $account['smtp_gmail_account'] ?? '';
			$gmail_settings = get_option( EmailOauth::mailer_settings_option_name( 'gmail' ), array() );
			if ( ! is_array( $gmail_settings ) ) {
				$gmail_settings = array();
			}
			$accounts = $gmail_settings['accounts'] ?? array();

			if ( ! empty( $account_id ) && isset( $accounts[ $account_id ] ) ) {
				return strtolower( $accounts[ $account_id ]['name'] ?? ( $account_id . '@gmail.com' ) );
			}
			if ( ! empty( $accounts ) ) {
				$first = reset( $accounts );
				return strtolower( $first['name'] ?? '' );
			}
			return '';
		}

		if ( 'smtp_outlook' === $provider ) {
			$account_id       = $account['smtp_outlook_account'] ?? '';
			$outlook_settings = get_option( EmailOauth::mailer_settings_option_name( 'outlook' ), array() );
			if ( ! is_array( $outlook_settings ) ) {
				$outlook_settings = array();
			}
			$accounts = $outlook_settings['accounts'] ?? array();

			if ( ! empty( $account_id ) && isset( $accounts[ $account_id ] ) ) {
				return strtolower( $accounts[ $account_id ]['name'] ?? $account_id );
			}
			if ( ! empty( $accounts ) ) {
				$first = reset( $accounts );
				return strtolower( $first['name'] ?? '' );
			}
			return '';
		}

		return strtolower( $account['imap']['username'] ?? '' );
	}

	/**
	 * Check if an email sender should be excluded from auto-create.
	 *
	 * Delegates to EmailIncoming to avoid duplicating the exclusion logic.
	 *
	 * @param string $email_address    Sender email address.
	 * @param array  $excluded_domains Excluded domain list.
	 * @return bool
	 */
	private function is_excluded_sender( $email_address, $excluded_domains = array() ) {
		return $this->email_incoming->is_excluded_sender( $email_address, $excluded_domains );
	}
}
