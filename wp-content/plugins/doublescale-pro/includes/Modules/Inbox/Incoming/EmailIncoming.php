<?php
/**
 * Email Incoming Handler
 * Processes inbound email messages via IMAP polling
 *
 * @since 1.1.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\Incoming;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Tracking\ImapClient;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Core\Constants\MessageSourceTypes;
use DoubleScale\Core\Constants\MessageDirection;
use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Pro\Settings;
use DoubleScale\Pro\Modules\Inbox\Oauth\EmailOauth;

defined( 'ABSPATH' ) || exit;

/**
 * EmailIncoming class
 *
 * Handles incoming email processing for CRM contacts via IMAP polling.
 */
class EmailIncoming {

	/**
	 * Singleton instance
	 *
	 * @var EmailIncoming|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @param \DoubleScale\Pro\Modules\Tasks\Tasks|null $campaigns_tasks Tasks instance for registering IMAP polling callback.
	 * @return EmailIncoming
	 */
	public static function instance( $campaigns_tasks = null ) {
		if ( null === self::$instance ) {
			self::$instance = new self( $campaigns_tasks );
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * @param \DoubleScale\Pro\Modules\Tasks\Tasks|null $campaigns_tasks Tasks instance.
	 */
	private function __construct( $campaigns_tasks = null ) {
		$this->register_hooks( $campaigns_tasks );
	}

	/**
	 * Register WordPress hooks
	 *
	 * @param \DoubleScale\Pro\Modules\Tasks\Tasks|null $campaigns_tasks Tasks instance.
	 */
	private function register_hooks( $campaigns_tasks = null ) {
		// Action Scheduler hook for IMAP polling.
		if ( $campaigns_tasks ) {
			$campaigns_tasks->register_callback( 'doublescale_email_inbound', array( $this, 'poll_imap' ) );
		}
	}

	/**
	 * Process an incoming email message (provider-agnostic core logic)
	 *
	 * @param array $data {
	 *     Normalized email data.
	 *
	 *     @type string $from_email  Sender's email address.
	 *     @type string $from_name   Sender's display name (optional).
	 *     @type string $to_email    Recipient email address.
	 *     @type string $subject     Email subject.
	 *     @type string $body        Email body (HTML preferred, text fallback).
	 *     @type string $message_id  Message-ID header value.
	 *     @type string $in_reply_to In-Reply-To header value (optional).
	 *     @type string $date        Email date (optional).
	 * }
	 * @param array $options {
	 *     Optional overrides for per-user processing.
	 *
	 *     @type int|null $user_id             User ID to attribute activity to (null for shared inbox).
	 *     @type bool     $auto_create_contacts Override the global auto_create_contacts setting.
	 * }
	 * @return array|false Processing result or false on failure.
	 */
	public function process_incoming_email( $data, $options = array() ) {
		// Validate required fields.
		if ( empty( $data['from_email'] ) || empty( $data['message_id'] ) ) {
			doublescale_get_logger()->warning(
				'Incoming email: missing required fields',
				array(
					'code' => 'email_incoming_missing_fields',
					'data' => array_keys( $data ),
				)
			);
			return false;
		}

		$user_id = $options['user_id'] ?? null;

		// Look up the original outbound tracking record for reply emails.
		// Used for: (1) user_id resolution in shared inbox, (2) marking as opened.
		//
		// Primary: match by In-Reply-To against external_id (works after SENT folder sync
		// patches the provider-assigned Message-ID).
		// Fallback: if the reply arrives before the SENT sync runs, external_id still holds
		// the CRM-generated ID (<hash@site-host>) which won't match. In that case, find the
		// most recent unpatched outbound to the same sender (who is the contact replying).
		$original_outbound = null;
		if ( ! empty( $data['in_reply_to'] ) ) {
			$original_outbound = CommunicationTrackingModel::where( 'external_id', $data['in_reply_to'] )
				->where( 'mode', CommunicationTrackingModel::MODE_EMAIL )
				->where( 'direction', MessageDirection::OUTBOUND )
				->first();

			// Fallback: reply arrived before SENT folder sync patched external_id.
			if ( ! $original_outbound && ! empty( $data['from_email'] ) ) {
				$site_host         = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost';
				$original_outbound = CommunicationTrackingModel::where( 'recipient', strtolower( $data['from_email'] ) )
					->where( 'mode', CommunicationTrackingModel::MODE_EMAIL )
					->where( 'direction', MessageDirection::OUTBOUND )
					->where( 'external_id', 'LIKE', '%@' . $site_host . '>' )
					->where( 'sent_at', '>=', gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) )
					->orderBy( 'id', 'desc' )
					->first();
			}
		}

		// Resolve user from reply threading for shared inbox emails.
		if ( ! $user_id && $original_outbound && $original_outbound->author_id ) {
			$user_id = (int) $original_outbound->author_id;
		}

		// A reply is proof the contact opened the original email.
		// Mark as opened if the tracking pixel didn't already catch it
		// (e.g., images blocked, plain-text client).
		if ( $original_outbound && ! $original_outbound->opened ) {
			$original_outbound->update(
				array(
					'opened'    => 1,
					'opened_at' => current_time( 'mysql', true ),
				)
			);

			$reply_contact = $original_outbound->contact;
			if ( $reply_contact ) {
				do_action( 'doublescale_mail_open', $reply_contact );
			}
		}

		// 1. Check auto-create setting.
		$email_inbound_settings = Settings::get( 'email_inbound', array() );
		if ( array_key_exists( 'auto_create_contacts', $options ) ) {
			$auto_create = (bool) $options['auto_create_contacts'];
		} else {
			$auto_create = ! empty( $email_inbound_settings['auto_create_contacts'] );
		}

		// 2. Find or create contact by email.
		$contact = ContactModel::where( 'email', $data['from_email'] )->first();

		if ( ! $contact ) {
			if ( ! $auto_create ) {
				doublescale_get_logger()->debug(
					'Incoming email from unknown sender, auto_create_contacts is disabled — skipping',
					array(
						'code'       => 'email_incoming_unknown_sender_skipped',
						'from_email' => $data['from_email'],
					)
				);
				return false;
			}

			// Skip excluded senders (no-reply addresses, blocked domains).
			$excluded_domains = $email_inbound_settings['excluded_domains'] ?? array();
			if ( $this->is_excluded_sender( $data['from_email'], $excluded_domains ) ) {
				doublescale_get_logger()->debug(
					'Incoming email from excluded sender — skipping contact creation',
					array(
						'code'       => 'email_incoming_excluded_sender',
						'from_email' => $data['from_email'],
					)
				);
				return false;
			}

			// Parse sender name into first/last.
			$name_parts = explode( ' ', trim( $data['from_name'] ?? '' ), 2 );
			$first_name = ! empty( $name_parts[0] ) ? sanitize_text_field( $name_parts[0] ) : null;
			$last_name  = isset( $name_parts[1] ) ? sanitize_text_field( $name_parts[1] ) : null;

			try {
				$contact = ContactModel::create(
					array(
						'email'      => sanitize_email( $data['from_email'] ),
						'first_name' => $first_name,
						'last_name'  => $last_name,
						'status'     => 'subscribed',
					)
				);

				doublescale_get_logger()->info(
					'New contact created from incoming email',
					array(
						'code'       => 'email_incoming_contact_created',
						'contact_id' => $contact->id,
						'email'      => $data['from_email'],
					)
				);
			} catch ( \Exception $e ) {
				doublescale_get_logger()->error(
					'Failed to create contact from incoming email',
					array(
						'code'  => 'email_incoming_contact_create_failed',
						'email' => $data['from_email'],
						'error' => $e->getMessage(),
					)
				);
				return false;
			}
		}

		// 3. Deduplicate via external_id (Message-ID header).
		$existing = CommunicationTrackingModel::where( 'external_id', $data['message_id'] )
			->where( 'mode', CommunicationTrackingModel::MODE_EMAIL )
			->exists();

		if ( $existing ) {
			doublescale_get_logger()->debug(
				'Incoming email already processed (duplicate Message-ID)',
				array(
					'code'       => 'email_incoming_duplicate',
					'message_id' => $data['message_id'],
				)
			);
			return false;
		}

		// 4. Sanitize email body — preserve full HTML structure for rendering in iframe.
		$data['body'] = self::sanitize_email_body( $data['body'] );

		// 5-6. Create Activity and Communication Tracking records.
		$activity = ActivityModel::create(
			array(
				'contact_id'    => $contact->id,
				'activity_type' => 'email_received',
				'data'          => array(
					'subject'     => sanitize_text_field( $data['subject'] ?? '' ),
					'body'        => $data['body'],
					'from_email'  => $data['from_email'],
					'to_email'    => $data['to_email'] ?? '',
					'message_id'  => $data['message_id'],
					'in_reply_to' => $data['in_reply_to'] ?? '',
				),
				'user_id'       => $user_id,
			)
		);

		$tracking = CommunicationTrackingModel::create(
			array(
				'contact_id'  => $contact->id,
				'mode'        => CommunicationTrackingModel::MODE_EMAIL,
				'direction'   => MessageDirection::INBOUND,
				'source_type' => MessageSourceTypes::INDIVIDUAL,
				'source_id'   => $activity->id,
				'recipient'   => $data['to_email'] ?? '',
				'external_id' => $data['message_id'],
				'hash_key'    => \DoubleScale\Pro\Utils::generate_hash_key(),
				'status'      => TrackingStatus::DELIVERED,
				'sent_at'     => ! empty( $data['date'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $data['date'] ) ) : current_time( 'mysql', true ),
			)
		);

		// Persist any file attachments parsed off the inbound message. Best-effort:
		// a storage failure must never abort inbound processing (the activity and
		// tracking rows above are already committed). The CRM-side service lives in
		// Free, so guard with class_exists() — a version-skewed Free must not fatal.
		$this->store_inbound_attachments( $data, (int) $contact->id, (int) $activity->id );

		// 7. Log success.
		doublescale_get_logger()->info(
			'Incoming email received and stored',
			array(
				'code'        => 'email_incoming_success',
				'contact_id'  => $contact->id,
				'activity_id' => $activity->id,
				'tracking_id' => $tracking->id,
				'subject'     => $data['subject'] ?? '(no subject)',
			)
		);

		/**
		 * Fires when an email is received from a contact.
		 *
		 * @since 1.1.0
		 *
		 * @param \DoubleScale\Modules\Contacts\Models\ContactModel                $contact  Contact who sent the email.
		 * @param \DoubleScale\Modules\Activities\Models\ActivityModel               $activity Activity record created.
		 * @param \DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel $tracking Tracking record created.
		 * @param array                                         $data     Normalized email data.
		 */
		do_action( 'doublescale_email_received', $contact, $activity, $tracking, $data );

		return array(
			'contact'  => $contact,
			'activity' => $activity,
			'tracking' => $tracking,
		);
	}

	/**
	 * Process an outgoing email found in the SENT folder (sent outside the CRM).
	 *
	 * Mirrors process_incoming_email() but for outbound direction:
	 * - Contact is matched by recipient (to_email), not sender
	 * - Never auto-creates contacts
	 * - Creates email_sent activity + outbound tracking record
	 *
	 * @since 1.5.0
	 *
	 * @param array $data Normalized email data (same shape as process_incoming_email).
	 * @param array $options {
	 *     Optional overrides for per-user processing.
	 *
	 *     @type int|null $user_id User ID to attribute activity to (skips resolve_wp_user_from_email).
	 * }
	 * @return array|false Processing result or false on failure.
	 */
	public function process_outgoing_email( $data, $options = array() ) {
		if ( empty( $data['to_email'] ) || empty( $data['message_id'] ) ) {
			return false;
		}

		// Deduplicate via external_id (Message-ID header).
		$existing = CommunicationTrackingModel::where( 'external_id', $data['message_id'] )
			->where( 'mode', CommunicationTrackingModel::MODE_EMAIL )
			->exists();

		if ( $existing ) {
			return false;
		}

		// Match contact by recipient email (the person the admin wrote to).
		$contact = ContactModel::where( 'email', $data['to_email'] )->first();
		if ( ! $contact ) {
			return false;
		}

		// CRM-sent emails: update external_id with the real Message-ID assigned
		// by the mail server. Gmail (and some other providers) rewrite the
		// Message-ID header on relay, so the ID stored at send time differs from
		// what the recipient sees. Patching it here enables reply matching
		// (In-Reply-To lookup) and reply-implies-open tracking.
		if ( ! empty( $data['crm_sent'] ) ) {
			$this->patch_crm_email_external_id( $data );
			return false;
		}

		// Use provided user_id (per-user poller) or resolve from email (shared inbox).
		$sender_user_id = $options['user_id'] ?? $this->resolve_wp_user_from_email( $data['from_email'] ?? '' );

		$data['body'] = self::sanitize_email_body( $data['body'] );

		$activity = ActivityModel::create(
			array(
				'contact_id'    => $contact->id,
				'activity_type' => 'email_sent',
				'data'          => array(
					'subject'       => sanitize_text_field( $data['subject'] ?? '' ),
					'body'          => $data['body'],
					'from_email'    => $data['from_email'] ?? '',
					'contact_email' => $data['to_email'],
					'message_id'    => $data['message_id'],
					'in_reply_to'   => $data['in_reply_to'] ?? '',
					'sent_at'       => ! empty( $data['date'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $data['date'] ) ) : current_time( 'mysql', true ),
				),
				'user_id'       => $sender_user_id,
			)
		);

		$tracking = CommunicationTrackingModel::create(
			array(
				'contact_id'  => $contact->id,
				'mode'        => CommunicationTrackingModel::MODE_EMAIL,
				'direction'   => MessageDirection::OUTBOUND,
				'source_type' => MessageSourceTypes::INDIVIDUAL,
				'source_id'   => $activity->id,
				'recipient'   => $data['to_email'],
				'external_id' => $data['message_id'],
				'hash_key'    => \DoubleScale\Pro\Utils::generate_hash_key(),
				'status'      => TrackingStatus::SENT,
				'sent_at'     => ! empty( $data['date'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $data['date'] ) ) : current_time( 'mysql', true ),
			)
		);

		// Persist attachments carried by an admin-sent email synced from the SENT
		// folder, so the contact's Emails tab shows them too. Best-effort, same as
		// the inbound path. Here $contact is the recipient.
		$this->store_inbound_attachments( $data, (int) $contact->id, (int) $activity->id );

		doublescale_get_logger()->info(
			'Outgoing email from sent folder stored',
			array(
				'code'        => 'email_outgoing_sent_sync_success',
				'contact_id'  => $contact->id,
				'activity_id' => $activity->id,
				'tracking_id' => $tracking->id,
				'subject'     => $data['subject'] ?? '(no subject)',
			)
		);

		/**
		 * Fires when an outgoing email is synced from the sent folder.
		 *
		 * @since 1.5.0
		 *
		 * @param \DoubleScale\Modules\Contacts\Models\ContactModel                $contact  Contact the email was sent to.
		 * @param \DoubleScale\Modules\Activities\Models\ActivityModel               $activity Activity record created.
		 * @param \DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel $tracking Tracking record created.
		 * @param array                                         $data     Normalized email data.
		 */
		do_action( 'doublescale_email_sent_external', $contact, $activity, $tracking, $data );

		return array(
			'contact'  => $contact,
			'activity' => $activity,
			'tracking' => $tracking,
		);
	}

	/**
	 * Store any file attachments parsed off an email onto its activity.
	 *
	 * Shared by the inbound and SENT-folder-sync paths. Best-effort: every store
	 * is wrapped so a single bad attachment can't abort message processing. The
	 * CRM-side service lives in Free, so the whole block is gated by class_exists()
	 * — a Free install older than this feature simply skips attachment storage.
	 *
	 * @param array $data        Normalized email data ({attachments: [{filename,mime,content,content_id}]}).
	 * @param int   $contact_id  Owner contact id.
	 * @param int   $activity_id Email activity id the attachments belong to.
	 * @return void
	 */
	private function store_inbound_attachments( $data, $contact_id, $activity_id ) {
		if ( empty( $data['attachments'] ) || ! is_array( $data['attachments'] )
			|| ! class_exists( '\DoubleScale\Modules\Contacts\Services\EmailAttachmentService' ) ) {
			return;
		}

		$attachment_service = new \DoubleScale\Modules\Contacts\Services\EmailAttachmentService();
		$stored             = 0;

		foreach ( $data['attachments'] as $file ) {
			if ( ! is_array( $file ) || empty( $file['content'] ) ) {
				continue;
			}

			try {
				$result = $attachment_service->store_email_attachment( $file, $contact_id, $activity_id );
				if ( ! is_wp_error( $result ) ) {
					++$stored;
				} else {
					doublescale_get_logger()->warning(
						'Inbound email attachment rejected',
						array(
							'source'      => 'email-incoming-attachments',
							'activity_id' => $activity_id,
							'filename'    => isset( $file['filename'] ) ? (string) $file['filename'] : '',
							'reason'      => $result->get_error_code(),
						)
					);
				}
			} catch ( \Throwable $e ) {
				doublescale_get_logger()->error(
					'Failed to store inbound email attachment',
					array(
						'source'      => 'email-incoming-attachments',
						'activity_id' => $activity_id,
						'exception'   => $e->getMessage(),
					)
				);
			}
		}

		if ( $stored > 0 ) {
			doublescale_get_logger()->info(
				'Stored inbound email attachments',
				array(
					'source'      => 'email-incoming-attachments',
					'activity_id' => $activity_id,
					'count'       => $stored,
				)
			);
		}
	}

	/**
	 * Check if an email sender should be excluded from auto-create.
	 *
	 * Blocks common automated/no-reply local parts and user-configured domains.
	 * Pure function over arrays — no DB calls, safe to call in a loop.
	 *
	 * @param string $email_address    Sender email address.
	 * @param array  $excluded_domains User-configured excluded domain list.
	 * @return bool True if sender should be excluded.
	 */
	public function is_excluded_sender( $email_address, $excluded_domains = array() ) {
		// 1. Block common automated/no-reply local parts.
		$local_part          = strtolower( explode( '@', $email_address )[0] ?? '' );
		$blocked_local_parts = array( 'noreply', 'no-reply', 'no_reply', 'mailer-daemon', 'postmaster', 'donotreply', 'do-not-reply' );
		if ( in_array( $local_part, $blocked_local_parts, true ) ) {
			return true;
		}

		// 2. Check user-configured excluded domains.
		if ( ! empty( $excluded_domains ) ) {
			$domain = strtolower( substr( strrchr( $email_address, '@' ), 1 ) );
			foreach ( $excluded_domains as $excluded_domain ) {
				if ( strtolower( trim( $excluded_domain ) ) === $domain ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Sanitize email body for safe storage and iframe rendering.
	 *
	 * Unlike wp_kses_post(), this preserves structural HTML tags (<html>, <head>,
	 * <body>, <style>) that are essential for rendering rich email templates.
	 * The content is rendered inside a sandboxed iframe, so leaked styles are not a concern.
	 *
	 * Strips only dangerous elements: <script>, on* event handlers, javascript: URIs.
	 *
	 * @since 1.6.1
	 *
	 * @param string $html Raw email HTML body.
	 * @return string Sanitized HTML safe for iframe rendering.
	 */
	public static function sanitize_email_body( $html ) {
		if ( empty( $html ) ) {
			return '';
		}

		// Strip <script> tags and their contents.
		$html = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html );

		// Strip on* event attributes (onclick, onerror, onload, etc.).
		$html = preg_replace( '#\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)#is', '', $html );

		// Strip javascript: and vbscript: URIs from href/src/action attributes.
		$html = preg_replace( '#(href|src|action)\s*=\s*(?:"|\')?\s*(?:javascript|vbscript)\s*:#is', '$1="removed:', $html );

		return $html;
	}

	/**
	 * Get the SENT folder name for the given IMAP provider.
	 *
	 * @since 1.5.0
	 *
	 * @param string $provider 'custom', 'gmail', 'outlook', 'smtp_gmail', or 'smtp_outlook'.
	 * @param array  $settings Full email_inbound settings array.
	 * @return string Sent folder name.
	 */
	private function get_sent_folder_name( $provider, $settings ) {
		switch ( $provider ) {
			case 'gmail':
			case 'smtp_gmail':
				return '[Gmail]/Sent Mail';
			case 'outlook':
			case 'smtp_outlook':
				return 'Sent Items';
			default:
				return $settings['imap']['sent_folder'] ?? 'Sent';
		}
	}

	/**
	 * Poll the SENT folder for outgoing emails sent outside the CRM.
	 *
	 * Called from poll_imap() after INBOX processing when sync_sent is enabled.
	 * Switches to the provider's SENT folder, fetches recent emails, and creates
	 * outbound activity/tracking records for emails sent to known contacts.
	 *
	 * @since 1.5.0
	 *
	 * @param ImapClient $client      Connected IMAP client (will be reopened to SENT folder).
	 * @param string      $provider    'custom', 'gmail', or 'outlook'.
	 * @param array       $settings    Full email_inbound settings array.
	 * @param array       $contact_set Flipped array of lowercase contact emails for O(1) lookup.
	 */
	private function poll_sent_folder( $client, $provider, $settings, $contact_set ) {
		$sent_folder = $this->get_sent_folder_name( $provider, $settings );

		if ( ! $client->open_folder( $sent_folder ) ) {
			doublescale_get_logger()->warning(
				'IMAP sent folder sync: could not open folder',
				array(
					'code'     => 'email_sent_sync_folder_open_failed',
					'folder'   => $sent_folder,
					'provider' => $provider,
				)
			);
			return;
		}

		// Separate lookback tracking for SENT folder.
		$last_sent_poll = (int) get_option( 'doublescale_imap_sent_last_poll', 0 );
		$max_lookback   = 7 * DAY_IN_SECONDS;
		$since_time     = $last_sent_poll > 0
			? max( $last_sent_poll - 300, time() - $max_lookback )
			: time() - DAY_IN_SECONDS;

		// Respect the forward-only anchor here too: never scan SENT history that
		// predates the connect moment.
		$sync_since = (int) get_option( 'doublescale_imap_sync_since', 0 );
		if ( $sync_since > 0 ) {
			$since_time = max( $since_time, $sync_since );
		}
		$since_date = gmdate( 'Y-m-d', $since_time );

		$lookback_days = max( 1, (int) ceil( ( time() - $since_time ) / DAY_IN_SECONDS ) );
		$limit         = min( 20 * $lookback_days, 100 );

		$emails = $client->fetch_recent( $since_date, $limit );
		if ( empty( $emails ) ) {
			update_option( 'doublescale_imap_sent_last_poll', time(), false );
			return;
		}

		// Identify the connected account email for sender filtering.
		$account_email = $this->get_connected_account_email( $provider, $settings );

		$processed = 0;

		foreach ( $emails as $email ) {
			$sender = strtolower( $email['from_email'] ?? '' );

			// Only process emails sent BY the connected account.
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

			// Process once per matched contact recipient.
			foreach ( $matched_recipients as $matched_email ) {
				$email_copy              = $email;
				$email_copy['to_email']  = $matched_email;

				try {
					$result = $this->process_outgoing_email( $email_copy );
					if ( $result ) {
						++$processed;
					}
				} catch ( \Exception $e ) {
					doublescale_get_logger()->error(
						'Failed to process outgoing email from sent folder',
						array(
							'code'  => 'email_sent_sync_process_error',
							'uid'   => $email['uid'] ?? '',
							'error' => $e->getMessage(),
						)
					);
				}
			}
		}

		update_option( 'doublescale_imap_sent_last_poll', time(), false );

		if ( $processed > 0 ) {
			doublescale_get_logger()->info(
				'IMAP sent folder sync completed',
				array(
					'code'      => 'email_sent_sync_complete',
					'provider'  => $provider,
					'fetched'   => count( $emails ),
					'processed' => $processed,
				)
			);
		}
	}

	/**
	 * Get the email address of the connected IMAP account.
	 *
	 * For OAuth providers the email is stored during authorization.
	 * For custom IMAP, the username is typically the email address.
	 *
	 * @since 1.5.0
	 *
	 * @param string $provider 'custom', 'gmail', 'outlook', 'smtp_gmail', or 'smtp_outlook'.
	 * @param array  $settings Full email_inbound settings array.
	 * @return string Lowercase email address, or empty string.
	 */
	private function get_connected_account_email( $provider, $settings ) {
		if ( in_array( $provider, array( 'gmail', 'outlook' ), true ) ) {
			$oauth_email = $settings['oauth'][ $provider ]['email'] ?? '';
			return strtolower( $oauth_email );
		}

		if ( 'smtp_gmail' === $provider ) {
			$account_id     = $settings['smtp_gmail_account'] ?? '';
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
			$account_id       = $settings['smtp_outlook_account'] ?? '';
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

		return strtolower( $settings['imap']['username'] ?? '' );
	}

	/**
	 * Resolve a WordPress user ID from an email address.
	 *
	 * Used by sent-folder sync to attribute activities to the WP user who
	 * owns the connected email account, so the activity timeline shows their
	 * display name instead of "Unknown User".
	 *
	 * @since 1.5.0
	 *
	 * @param string $email Email address to look up.
	 * @return int|null WordPress user ID, or null if not found.
	 */
	private function resolve_wp_user_from_email( $email ) {
		if ( empty( $email ) ) {
			return null;
		}

		$user = get_user_by( 'email', $email );

		return $user ? $user->ID : null;
	}

	/**
	 * Patch external_id on a CRM-sent tracking record with the real Message-ID.
	 *
	 * Some mail providers (notably Gmail) rewrite the Message-ID header when
	 * relaying via SMTP. The CRM stores a pre-send ID (e.g. <hash@localhost>),
	 * but the recipient's reply references the provider-assigned ID. This method
	 * is called during SENT folder sync when a CRM-stamped email is found, and
	 * updates the tracking record's external_id so In-Reply-To matching works.
	 *
	 * @since 1.7.0
	 *
	 * @param array $data Normalized email data from IMAP (with 'crm_sent' flag).
	 */
	private function patch_crm_email_external_id( $data ) {
		$real_message_id = $data['message_id'] ?? '';
		$recipient       = strtolower( $data['to_email'] ?? '' );

		if ( empty( $real_message_id ) || empty( $recipient ) ) {
			return;
		}

		// Find the outbound tracking record whose external_id still holds the
		// CRM-generated value (not yet patched). When multiple unpatched records
		// exist for the same recipient, disambiguate by matching the email subject
		// against the activity data stored at send time.
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost';

		$query = CommunicationTrackingModel::where( 'recipient', $recipient )
			->where( 'mode', CommunicationTrackingModel::MODE_EMAIL )
			->where( 'direction', MessageDirection::OUTBOUND )
			->where( 'external_id', 'LIKE', '%@' . $site_host . '>' )
			->orderBy( 'id', 'desc' );

		$candidates = $query->limit( 10 )->get();
		$tracking   = null;
		$subject    = $data['subject'] ?? '';

		if ( $candidates->count() === 1 ) {
			$tracking = $candidates->first();
		} elseif ( $candidates->count() > 1 && ! empty( $subject ) ) {
			// Multiple unpatched records -- match by subject to avoid cross-patching.
			foreach ( $candidates as $candidate ) {
				if ( ! $candidate->source_id ) {
					continue;
				}
				$activity = ActivityModel::find( $candidate->source_id );
				if ( ! $activity ) {
					continue;
				}
				$activity_data = $activity->data;
				if ( ! is_array( $activity_data ) ) {
					$activity_data = json_decode( $activity_data, true ) ?: array();
				}
				if ( ( $activity_data['subject'] ?? '' ) === $subject ) {
					$tracking = $candidate;
					break;
				}
			}
			// If no subject match found, fall back to newest (best effort).
			if ( ! $tracking ) {
				$tracking = $candidates->first();
			}
		} elseif ( $candidates->count() > 1 ) {
			$tracking = $candidates->first();
		}

		if ( ! $tracking ) {
			return;
		}

		// Already patched with this ID (idempotent).
		if ( $tracking->external_id === $real_message_id ) {
			return;
		}

		$old_id = $tracking->external_id;
		$tracking->update( array( 'external_id' => $real_message_id ) );

		// Also store the real message_id in the activity data so the frontend's
		// email-threading Pass 1 (In-Reply-To / Message-ID matching) works.
		if ( $tracking->source_id ) {
			$activity = ActivityModel::find( $tracking->source_id );
			if ( $activity ) {
				$activity_data = $activity->data;
				if ( ! is_array( $activity_data ) ) {
					$activity_data = json_decode( $activity_data, true ) ?: array();
				}
				$activity_data['message_id'] = $real_message_id;
				$activity->update( array( 'data' => $activity_data ) );
			}
		}

		doublescale_get_logger()->info(
			'CRM email external_id patched with provider-assigned Message-ID',
			array(
				'code'            => 'email_external_id_patched',
				'tracking_id'     => $tracking->id,
				'recipient'       => $recipient,
				'old_external_id' => $old_id,
				'new_external_id' => $real_message_id,
			)
		);
	}

	/**
	 * Poll IMAP mailbox for new emails
	 *
	 * Called by Action Scheduler on a recurring basis.
	 * Supports custom IMAP, Gmail OAuth, and Outlook OAuth providers.
	 */
	public function poll_imap() {
		$settings = Settings::get( 'email_inbound', array() );

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		$provider = $settings['imap_provider'] ?? 'custom';

		// Skip OAuth providers that need re-authorization.
		// Stop the scheduler — user must re-authorize, which re-saves settings and restarts polling.
		if ( in_array( $provider, array( 'gmail', 'outlook' ), true ) ) {
			$oauth_settings = $settings['oauth'][ $provider ] ?? array();
			if ( ! empty( $oauth_settings['needs_reauth'] ) ) {
				doublescale_get_logger()->warning(
					'IMAP polling stopped: OAuth needs re-authorization',
					array(
						'code'     => 'email_incoming_oauth_needs_reauth',
						'provider' => $provider,
					)
				);
				$campaigns_tasks = \DoubleScale\Core\PluginKernel::instance()->campaigns_tasks;
				$campaigns_tasks->unschedule_all( 'doublescale_email_inbound' );
				return;
			}
		}

		$auto_create      = ! empty( $settings['auto_create_contacts'] );
		$excluded_domains = $settings['excluded_domains'] ?? array();

		// Pre-load all contact emails for O(1) lookup.
		// When auto_create is OFF: only process emails from known contacts (safe default).
		// When auto_create is ON: also process emails from unknown senders, filtered
		// through is_excluded_sender() to block no-reply addresses and excluded domains.
		$contact_emails = ContactModel::pluck( 'email' )->map( function ( $email ) {
			return strtolower( $email );
		} )->all();
		$contact_set    = array_flip( $contact_emails );

		// Forward-only sync anchor: the inbox never ingests mail that predates the
		// moment the mailbox was connected. On the first run for any install
		// (including existing ones updating into this behaviour, and SMTP-reuse
		// connects that bypass the OAuth callback), default the anchor to "now" so
		// nothing historical is backfilled.
		$sync_since = (int) get_option( 'doublescale_imap_sync_since', 0 );
		if ( $sync_since <= 0 ) {
			$sync_since = time();
			update_option( 'doublescale_imap_sync_since', $sync_since, false );
		}

		$last_poll    = (int) get_option( 'doublescale_imap_last_successful_poll', 0 );
		$max_lookback = 7 * DAY_IN_SECONDS;
		$since_time   = $last_poll > 0
			? max( $last_poll - 300, time() - $max_lookback )
			: time() - DAY_IN_SECONDS;

		// Clamp to the connect anchor: never look back before the mailbox existed.
		$since_time = max( $since_time, $sync_since );
		$since_date = gmdate( 'Y-m-d', $since_time );

		$client = null;
		try {
			$client = $this->create_imap_client( $provider, $settings );

			$client->connect();

			// Fetch unseen emails since the sync anchor. Passing $since_date floors
			// the unread set by date so a months-old unread message is NOT pulled in
			// on the first poll — only unread mail at/after the connect moment.
			$unseen_emails = $client->fetch_unseen( 20, $since_date );

			// Also fetch recently-arrived emails regardless of seen/unseen status.
			// This catches replies that were marked as read by another client
			// (e.g., Gmail web UI) before the poll could process them.
			// Lookback is dynamic: uses last successful poll time (capped at 7 days)
			// so emails aren't lost if the poller was down for more than 24 hours.
			$lookback_days = max( 1, (int) ceil( ( time() - $since_time ) / DAY_IN_SECONDS ) );
			$recent_limit  = min( 20 * $lookback_days, 100 );
			$recent_emails = $client->fetch_recent( $since_date, $recent_limit );

			// Merge both sets, deduplicating by UID (or message_id when UID is unavailable).
			// php-imap2's imap2_uid() can return false for some messages — use
			// message_id as the dedup key in that case to avoid collisions.
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

			// Sort newest first (highest UID) and apply batch limit.
			$emails = array_values( $dedup_map );
			usort(
				$emails,
				function ( $a, $b ) {
					// Treat false UIDs as 0 so they sort last (lowest priority).
					return ( (int) $b['uid'] ) - ( (int) $a['uid'] );
				}
			);
			$emails = array_slice( $emails, 0, 20 );

			$processed = 0;

			foreach ( $emails as $email ) {
				// Precise forward-only guard: the date floors above (SINCE / receivedDateTime ge)
				// are day-granular, so a message from earlier on the anchor day can slip
				// through. Drop anything that arrived strictly before the connect anchor.
				// Do NOT mark it seen — it is the user's pre-existing mail and must stay
				// untouched in their inbox; we simply never ingest it.
				if ( $this->is_before_sync_anchor( $email, $sync_since ) ) {
					continue;
				}

				$sender           = strtolower( $email['from_email'] ?? '' );
				$should_mark_seen = true;
				$is_known_contact = isset( $contact_set[ $sender ] );

				if ( $is_known_contact || ( $auto_create && ! $this->is_excluded_sender( $sender, $excluded_domains ) ) ) {
					try {
						$this->process_incoming_email( $email );
						++$processed;
					} catch ( \Exception $e ) {
						// Exception = retriable failure — don't mark as seen so next poll retries.
						$should_mark_seen = false;
						doublescale_get_logger()->error(
							'Failed to process incoming email',
							array(
								'code'  => 'email_incoming_process_error',
								'uid'   => $email['uid'] ?? '',
								'error' => $e->getMessage(),
							)
						);
					}
					// false returns (dedup, validation, unknown sender) are permanent skips — still mark seen.
				}

				if ( $should_mark_seen && ! empty( $email['uid'] ) ) {
					$client->mark_as_seen( $email['uid'] );
				}
			}

			// Reset consecutive failure counter on successful poll.
			delete_transient( 'doublescale_imap_consecutive_failures' );
			update_option( 'doublescale_imap_last_successful_poll', time(), false );

			if ( ! empty( $emails ) ) {
				doublescale_get_logger()->info(
					'IMAP polling completed',
					array(
						'code'      => 'email_incoming_imap_poll_complete',
						'provider'  => $provider,
						'fetched'   => count( $emails ),
						'processed' => $processed,
					)
				);
			}

			// Sync SENT folder if enabled (reuses the same connection).
			if ( ! empty( $settings['sync_sent'] ) ) {
				try {
					$this->poll_sent_folder( $client, $provider, $settings, $contact_set );
				} catch ( \Exception $e ) {
					doublescale_get_logger()->warning(
						'IMAP sent folder sync failed (INBOX sync succeeded)',
						array(
							'code'  => 'email_sent_sync_error',
							'error' => $e->getMessage(),
						)
					);
				}
			}
		} catch ( \Throwable $e ) {
			$is_config_error = ! $client; // Config errors throw before $client is created.

			if ( $is_config_error ) {
				// Configuration errors never self-heal — stop polling to avoid log spam.
				// The scheduler will be re-created when the user saves valid settings.
				doublescale_get_logger()->error(
					'IMAP polling stopped: configuration error',
					array(
						'code'     => 'email_incoming_config_error',
						'provider' => $provider,
						'error'    => $e->getMessage(),
					)
				);
				$campaigns_tasks = \DoubleScale\Core\PluginKernel::instance()->campaigns_tasks;
				$campaigns_tasks->unschedule_all( 'doublescale_email_inbound' );
			} else {
				// Transient errors (network, IMAP server) may self-heal — keep retrying
				// but throttle logging to avoid spam (1st, 5th, then every 10th failure).
				$fail_count = (int) get_transient( 'doublescale_imap_consecutive_failures' );
				++$fail_count;
				set_transient( 'doublescale_imap_consecutive_failures', $fail_count, HOUR_IN_SECONDS );

				if ( 1 === $fail_count || 5 === $fail_count || 0 === $fail_count % 10 ) {
					doublescale_get_logger()->error(
						'IMAP polling error',
						array(
							'code'            => 'email_incoming_imap_error',
							'provider'        => $provider,
							'error'           => $e->getMessage(),
							'consecutive_failures' => $fail_count,
						)
					);
				}

				// For OAuth providers, mark as needing re-auth on authentication errors.
				if ( in_array( $provider, array( 'gmail', 'outlook' ), true ) ) {
					$this->handle_oauth_auth_failure( $provider, $e );
				}
			}
		} finally {
			if ( $client ) {
				$client->disconnect();
			}
		}
	}

	/**
	 * Create an IMAP client based on the selected provider.
	 *
	 * @param string $provider 'custom', 'gmail', 'outlook', 'smtp_gmail', or 'smtp_outlook'.
	 * @param array  $settings Full email_inbound settings array.
	 * @return ImapClient
	 * @throws \RuntimeException If provider configuration is incomplete.
	 */
	private function create_imap_client( $provider, $settings ) {
		if ( 'custom' === $provider ) {
			$imap = $settings['imap'] ?? array();

			if ( empty( $imap['host'] ) || empty( $imap['username'] ) || empty( $imap['password'] ) ) {
				throw new \RuntimeException(
					__( 'IMAP polling: incomplete custom IMAP configuration.', 'doublescale')
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

		// smtp Gmail — reuse tokens from smtp's Gmail mailer.
		if ( 'smtp_gmail' === $provider ) {
			$account_id = $settings['smtp_gmail_account'] ?? '';
			$config     = \DoubleScale\Core\Settings\Rest\RestSettingsControllerPro::get_smtp_gmail_imap_config( $account_id );

			if ( ! $config ) {
				throw new \RuntimeException(
					__( 'smtp Gmail connection not available. Check that Gmail is configured in smtp with valid credentials.', 'doublescale')
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

		// smtp Outlook — reuse tokens from smtp Pro's Outlook mailer.
		// Outlook *receives* over Microsoft Graph (same token family the Outlook
		// sender uses), not IMAP — see GraphMailClient for the why.
		if ( 'smtp_outlook' === $provider ) {
			$account_id   = $settings['smtp_outlook_account'] ?? '';
			$graph_config = \DoubleScale\Core\Settings\Rest\RestSettingsControllerPro::get_smtp_outlook_graph_config( $account_id );

			if ( ! $graph_config ) {
				throw new \RuntimeException(
					__( 'smtp Outlook connection not available. Check that Outlook is configured in smtp with valid credentials.', 'doublescale')
				);
			}

			return GraphMailClient::from_config( $graph_config );
		}

		// Outlook (shared-inbox OAuth) — receive over Microsoft Graph.
		if ( 'outlook' === $provider ) {
			if ( ! EmailOauth::is_connected( 'outlook' ) ) {
				throw new \RuntimeException(
					__( 'OAuth not connected for Outlook. Please re-authorize in settings.', 'doublescale')
				);
			}

			$graph_config = EmailOauth::get_graph_config();
			if ( ! $graph_config ) {
				throw new \RuntimeException(
					__( 'Failed to get Graph configuration for Outlook. Token may have expired.', 'doublescale')
				);
			}

			return GraphMailClient::from_config( $graph_config );
		}

		// Gmail — use OAuth over IMAP (Google fully supports XOAUTH2; no Graph equivalent).
		if ( ! EmailOauth::is_connected( $provider ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: OAuth provider name */
					__( 'OAuth not connected for %s. Please re-authorize in settings.', 'doublescale'),
					ucfirst( $provider )
				)
			);
		}

		$config = EmailOauth::get_imap_config( $provider );
		if ( ! $config ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: OAuth provider name */
					__( 'Failed to get IMAP configuration for %s. Token may have expired.', 'doublescale'),
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
	 * Handle OAuth authentication failure during polling.
	 *
	 * If the error looks like an authentication/authorization issue,
	 * mark the provider as needing re-auth to prevent retry loops.
	 *
	 * @param string     $provider 'gmail' or 'outlook'.
	 * @param \Exception $e        The exception that occurred.
	 */
	/**
	 * Whether a fetched message arrived strictly before the forward-only sync anchor.
	 *
	 * Parses the message's `date` (RFC 2822 for IMAP, ISO-8601 for Graph — both
	 * strtotime-parseable). A message with no parseable date is treated as
	 * NOT-before-anchor (kept), so a missing/odd date header never silently drops
	 * legitimate new mail. Mirrors Support's MailboxImapPoller::is_before_cutoff().
	 *
	 * @param array $email      Normalized message (12-key shape).
	 * @param int   $sync_since Unix timestamp anchor (connect moment).
	 * @return bool True when the message predates the anchor.
	 */
	private function is_before_sync_anchor( $email, $sync_since ) {
		$raw = isset( $email['date'] ) ? (string) $email['date'] : '';
		if ( '' === $raw ) {
			return false;
		}
		$ts = strtotime( $raw );
		if ( false === $ts ) {
			return false;
		}
		return $ts < $sync_since;
	}

	/**
	 * Handle OAuth authentication failure during polling.
	 *
	 * @param string     $provider 'gmail' or 'outlook'.
	 * @param \Exception $e        The exception that occurred.
	 */
	private function handle_oauth_auth_failure( $provider, $e ) {
		// Only mark needs_reauth if the provider was previously connected.
		if ( ! EmailOauth::is_connected( $provider ) ) {
			return;
		}

		$message    = strtolower( $e->getMessage() );
		$auth_terms = array( 'auth', 'login', 'credential', 'token', 'oauth', 'unauthorized', 'forbidden' );

		foreach ( $auth_terms as $term ) {
			if ( strpos( $message, $term ) !== false ) {
				$email_inbound = Settings::get( 'email_inbound', array() );
				if ( isset( $email_inbound['oauth'][ $provider ] ) ) {
					$email_inbound['oauth'][ $provider ]['needs_reauth'] = true;
					Settings::update( 'email_inbound', $email_inbound );
				}

				doublescale_get_logger()->error(
					'OAuth authentication failure — polling paused until re-authorization',
					array(
						'code'     => 'email_incoming_oauth_auth_failure',
						'provider' => $provider,
						'error'    => $e->getMessage(),
					)
				);
				break;
			}
		}
	}

}
