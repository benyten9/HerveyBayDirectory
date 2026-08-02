<?php
/**
 * REST User Email Controller
 *
 * Handles per-user email account endpoints. All endpoints operate on the
 * current user's data only (scoped to get_current_user_id()).
 *
 * @since 1.6.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\Rest\Controllers;

use DoubleScale\Pro\Modules\Inbox\Oauth\UserEmailOauth;
use DoubleScale\Pro\Modules\Inbox\Oauth\EmailOauth;
use DoubleScale\Modules\Tracking\ImapClient;
use DoubleScale\Pro\Settings;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * RestUserEmailController class
 */
class RestUserEmailController {

	/**
	 * Register REST Api routes.
	 */
	public function register_routes() {
		register_rest_route(
			'doublescale/v1',
			'/user-email-account',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_account' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_account' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_account' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			'doublescale/v1',
			'/user-email-account/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_connection' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			'doublescale/v1',
			'/user-email-account/oauth/authorize',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'oauth_authorize' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			'doublescale/v1',
			'/user-email-account/oauth/disconnect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'oauth_disconnect' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	// ─── GET ─────────────────────────────────────────────────────────────

	/**
	 * Get current user's email account configuration.
	 *
	 * @return WP_REST_Response
	 */
	public function get_account() {
		$user_id = get_current_user_id();
		$account = get_user_meta( $user_id, 'doublescale_user_email_account', true );
		$account = is_array( $account ) ? $account : array();

		$user = wp_get_current_user();

		// Mask passwords.
		if ( ! empty( $account['imap']['password'] ) ) {
			$account['imap']['password'] = '********';
		}

		// Sanitize OAuth data — only expose connection status, not credentials.
		$oauth           = $account['oauth'] ?? array();
		$sanitized_oauth = array();
		foreach ( array( 'gmail', 'outlook' ) as $provider ) {
			$provider_data                = $oauth[ $provider ] ?? array();
			$sanitized_oauth[ $provider ] = array(
				'connected'    => UserEmailOauth::is_connected( $provider, $user_id ),
				'email'        => $provider_data['email'] ?? '',
				'needs_reauth' => ! empty( $provider_data['needs_reauth'] ),
			);
		}
		$account['oauth'] = $sanitized_oauth;

		// Indicate which providers have centralized OAuth apps configured by admin (read-through from smtp).
		$account['oauth_apps_configured'] = array(
			'gmail'   => ! empty( EmailOauth::get_oauth_app_credentials( 'gmail' )['client_id'] ),
			'outlook' => ! empty( EmailOauth::get_oauth_app_credentials( 'outlook' )['client_id'] ),
		);

		// Convenience fields for simplified frontend.
		$account['connected_provider'] = null;
		$account['connected_email']    = '';
		foreach ( array( 'gmail', 'outlook' ) as $p ) {
			if ( UserEmailOauth::is_connected( $p, $user_id ) ) {
				$account['connected_provider'] = $p;
				$account['connected_email']    = $sanitized_oauth[ $p ]['email'];
				break;
			}
		}

		// Defaults for frontend.
		$account['defaults'] = array(
			'from_email' => $user->user_email,
			'from_name'  => $user->display_name ?: $user->user_login,
			'reply_to'   => $user->user_email,
		);

		$account['oauth_redirect_uri'] = EmailOauth::get_redirect_uri();

		// Detect smtp Gmail/Outlook accounts for auto-configuration.
		if ( method_exists( '\DoubleScale\Core\Settings\Rest\RestSettingsControllerPro', 'detect_smtp_configuration' ) ) {
			$account['smtp_detection'] = \DoubleScale\Core\Settings\Rest\RestSettingsControllerPro::detect_smtp_configuration();
		}

		// SMTP health check: verify smtp is active and can route this user's email.
		$smtp_health     = array();
		$smtp_active     = EmailOauth::smtp_oauth_storage_available();
		$user_from_email = strtolower( $account['from_email'] ?? '' );

		foreach ( array( 'gmail', 'outlook' ) as $p ) {
			$p_oauth = $oauth[ $p ] ?? array();
			if ( empty( $p_oauth['access_token'] ) ) {
				$smtp_health[ $p ] = 'not_connected';
				continue;
			}

			if ( ! $smtp_active ) {
				$smtp_health[ $p ] = 'smtp_inactive';
				continue;
			}

			// Check 1: user-owned account in smtp (created by sync_user_to_smtp).
			$mailer_settings = get_option( EmailOauth::mailer_settings_option_name( $p ), array() );
			if ( ! is_array( $mailer_settings ) ) {
				$mailer_settings = array();
			}
			$found_account = false;
			foreach ( ( $mailer_settings['accounts'] ?? array() ) as $acct ) {
				if ( ( $acct['user_id'] ?? 0 ) === $user_id ) {
					$found_account = true;
					break;
				}
			}

			// Check 2: if no user-owned account, an org-level connection routing
			// the same from_email still works for SMTP sending.
			if ( ! $found_account && ! empty( $user_from_email ) ) {
				$smtp_settings = get_option( EmailOauth::smtp_routing_option_name(), array() );
				if ( ! is_array( $smtp_settings ) ) {
					$smtp_settings = array();
				}
				foreach ( ( $smtp_settings['connections'] ?? array() ) as $conn ) {
					if ( strtolower( $conn['from_email'] ?? '' ) === $user_from_email
						&& ( $conn['mailer'] ?? '' ) === $p ) {
						$found_account = true;
						break;
					}
				}
			}

			$smtp_health[ $p ] = $found_account ? 'ok' : 'missing_smtp_account';
		}
		$account['smtp_health'] = $smtp_health;

		// Ensure defaults for missing keys.
		$account = wp_parse_args(
			$account,
			array(
				'enabled'              => false,
				'imap_provider'        => 'custom',
				'sync_sent'            => true,
				'auto_create_contacts' => false,
				'from_email'           => '',
				'from_name'            => '',
				'reply_to'             => '',
				'imap'                 => array(
					'host'        => '',
					'port'        => 993,
					'encryption'  => 'ssl',
					'username'    => '',
					'password'    => '',
					'sent_folder' => 'Sent',
				),
			)
		);

		return new WP_REST_Response( $account, 200 );
	}

	// ─── POST (Save) ────────────────────────────────────────────────────

	/**
	 * Save/update current user's email account configuration.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_account( $request ) {
		$user_id = get_current_user_id();
		$body    = $request->get_json_params();

		if ( empty( $body ) ) {
			return new WP_Error( 'invalid_data', __( 'No settings provided.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$existing = get_user_meta( $user_id, 'doublescale_user_email_account', true );
		$existing = is_array( $existing ) ? $existing : array();

		// Patch mode: start from existing account, only update provided fields.
		// OAuth-managed fields (from_email, imap_provider, enabled, oauth) are untouched.
		$account = $existing;

		if ( isset( $body['from_name'] ) ) {
			$account['from_name'] = sanitize_text_field( $body['from_name'] );
		}
		if ( isset( $body['sync_sent'] ) ) {
			$account['sync_sent'] = ! empty( $body['sync_sent'] );
		}
		if ( isset( $body['auto_create_contacts'] ) ) {
			$account['auto_create_contacts'] = ! empty( $body['auto_create_contacts'] );
		}

		// Preserve OAuth tokens (managed by OAuth flow, not settings save).
		$account['oauth'] = $existing['oauth'] ?? array();

		update_user_meta( $user_id, 'doublescale_user_email_account', $account );

		// Manage scheduler flag based on enabled state.
		if ( ! empty( $account['enabled'] ) ) {
			UserEmailOauth::ensure_scheduler_running();
		} else {
			$this->maybe_clear_scheduler_flag( $user_id );
		}

		// Collect smtp warnings.
		$warnings = $this->collect_warnings( $account );

		$response = array(
			'success' => true,
			'message' => __( 'Email account settings saved.', 'doublescale' ),
		);

		if ( ! empty( $warnings ) ) {
			$response['warnings'] = $warnings;
		}

		return new WP_REST_Response( $response, 200 );
	}

	// ─── DELETE ──────────────────────────────────────────────────────────

	/**
	 * Remove current user's email account entirely.
	 *
	 * @return WP_REST_Response
	 */
	public function delete_account() {
		$user_id = get_current_user_id();

		// Clean up smtp entries for both providers before deleting meta.
		UserEmailOauth::disconnect( 'gmail', $user_id );
		UserEmailOauth::disconnect( 'outlook', $user_id );

		delete_user_meta( $user_id, 'doublescale_user_email_account' );
		delete_user_meta( $user_id, 'doublescale_user_imap_last_poll' );
		delete_user_meta( $user_id, 'doublescale_user_imap_sent_last_poll' );
		delete_transient( 'doublescale_user_imap_failures_' . $user_id );

		$this->maybe_clear_scheduler_flag( $user_id );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Email account removed.', 'doublescale' ),
			),
			200
		);
	}

	// ─── Test Connection ─────────────────────────────────────────────────

	/**
	 * Test IMAP connection for current user's account.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function test_connection( $request ) {
		$user_id  = get_current_user_id();
		$body     = $request->get_json_params();
		$provider = sanitize_text_field( $body['provider'] ?? 'custom' );

		if ( in_array( $provider, array( 'gmail', 'outlook' ), true ) ) {
			return $this->test_oauth_connection( $provider, $user_id );
		}

		if ( 'smtp_gmail' === $provider ) {
			return $this->test_smtp_provider_connection( 'gmail', $body );
		}

		if ( 'smtp_outlook' === $provider ) {
			return $this->test_smtp_provider_connection( 'outlook', $body );
		}

		return $this->test_custom_imap_connection( $body, $user_id );
	}

	/**
	 * Test a custom IMAP connection.
	 *
	 * @param array $body    Request body.
	 * @param int   $user_id WordPress user ID.
	 * @return WP_REST_Response
	 */
	private function test_custom_imap_connection( $body, $user_id ) {
		$existing = get_user_meta( $user_id, 'doublescale_user_email_account', true );
		$existing = is_array( $existing ) ? $existing : array();

		$host       = sanitize_text_field( $body['host'] ?? '' );
		$port       = absint( $body['port'] ?? 993 );
		$encryption = sanitize_text_field( $body['encryption'] ?? 'ssl' );
		$username   = sanitize_text_field( $body['username'] ?? '' );
		$password   = ( $body['password'] ?? '' ) === '********'
			? ( ( $existing['imap'] ?? array() )['password'] ?? '' )
			: ( $body['password'] ?? '' );

		if ( empty( $host ) || empty( $username ) || empty( $password ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Please provide host, username, and password.', 'doublescale' ),
				),
				200
			);
		}

		try {
			$client = new ImapClient( $host, $port, $username, $password, $encryption, 'login' );
			$client->connect();
			// Count only recent unseen mail (today onward) — a raw UNSEEN count on
			// Gmail can be thousands of old web-read-but-IMAP-unseen messages.
			$unseen_count = $client->count_unseen( gmdate( 'Y-m-d' ) );
			$client->disconnect();

			return new WP_REST_Response(
				array(
					'success'      => true,
					'message'      => sprintf(
						/* translators: %d: number of recent unseen emails */
						__( 'Connected successfully. Found %d new unseen email(s) today.', 'doublescale' ),
						$unseen_count
					),
					'unseen_count' => $unseen_count,
				),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $e->getMessage(),
				),
				200
			);
		}
	}

	/**
	 * Test an OAuth IMAP connection for a user.
	 *
	 * @param string $provider 'gmail' or 'outlook'.
	 * @param int    $user_id  WordPress user ID.
	 * @return WP_REST_Response
	 */
	private function test_oauth_connection( $provider, $user_id ) {
		if ( ! UserEmailOauth::is_connected( $provider, $user_id ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => sprintf(
						/* translators: %s: OAuth provider name */
						__( '%s is not connected. Please authorize first.', 'doublescale' ),
						ucfirst( $provider )
					),
				),
				200
			);
		}

		$config = UserEmailOauth::get_imap_config( $provider, $user_id );
		if ( ! $config ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => sprintf(
						/* translators: %s: OAuth provider name */
						__( 'Failed to get %s IMAP configuration. Token may have expired — try reconnecting.', 'doublescale' ),
						ucfirst( $provider )
					),
				),
				200
			);
		}

		try {
			$client = new ImapClient(
				$config['host'],
				$config['port'],
				$config['username'],
				$config['password'],
				$config['encryption'],
				$config['authentication']
			);
			$client->connect();
			// Count only recent unseen mail (today onward) — see ImapClient::count_unseen().
			$unseen_count = $client->count_unseen( gmdate( 'Y-m-d' ) );
			$client->disconnect();

			return new WP_REST_Response(
				array(
					'success'      => true,
					'message'      => sprintf(
						/* translators: 1: provider name, 2: number of recent unseen emails */
						__( '%1$s connected successfully. Found %2$d new unseen email(s) today.', 'doublescale' ),
						ucfirst( $provider ),
						$unseen_count
					),
					'unseen_count' => $unseen_count,
				),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $e->getMessage(),
				),
				200
			);
		}
	}

	/**
	 * Test a smtp-provided Gmail/Outlook IMAP connection.
	 *
	 * @param string $smtp_provider 'gmail' or 'outlook'.
	 * @param array  $body          Request body.
	 * @return WP_REST_Response
	 */
	private function test_smtp_provider_connection( $smtp_provider, $body ) {
		$account_id = sanitize_text_field( $body['account_id'] ?? '' );
		$method     = 'gmail' === $smtp_provider
			? 'get_smtp_gmail_imap_config'
			: 'get_smtp_outlook_imap_config';

		if ( ! method_exists( '\DoubleScale\Core\Settings\Rest\RestSettingsControllerPro', $method ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'smtp integration not available.', 'doublescale' ),
				),
				200
			);
		}

		$config = \DoubleScale\Core\Settings\Rest\RestSettingsControllerPro::$method( $account_id );
		if ( ! $config ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => sprintf(
						/* translators: %s: provider name */
						__( 'smtp %s connection not available. Check that it is configured with valid credentials.', 'doublescale' ),
						ucfirst( $smtp_provider )
					),
				),
				200
			);
		}

		try {
			$client = new ImapClient(
				$config['host'],
				$config['port'],
				$config['username'],
				$config['password'],
				$config['encryption'],
				$config['authentication']
			);
			$client->connect();
			// Count only recent unseen mail (today onward) — see ImapClient::count_unseen().
			$unseen_count = $client->count_unseen( gmdate( 'Y-m-d' ) );
			$client->disconnect();

			return new WP_REST_Response(
				array(
					'success'      => true,
					'message'      => sprintf(
						/* translators: 1: provider name, 2: number of recent unseen emails */
						__( 'smtp %1$s connected successfully. Found %2$d new unseen email(s) today.', 'doublescale' ),
						ucfirst( $smtp_provider ),
						$unseen_count
					),
					'unseen_count' => $unseen_count,
				),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $e->getMessage(),
				),
				200
			);
		}
	}

	// ─── OAuth ───────────────────────────────────────────────────────────

	/**
	 * Get OAuth authorization URL for current user.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function oauth_authorize( $request ) {
		$user_id  = get_current_user_id();
		$body     = $request->get_json_params();
		$provider = sanitize_text_field( $body['provider'] ?? '' );

		if ( ! in_array( $provider, array( 'gmail', 'outlook' ), true ) ) {
			return new WP_Error( 'invalid_provider', __( 'Invalid OAuth provider.', 'doublescale' ), array( 'status' => 400 ) );
		}

		// Uses centralized admin-configured credentials.
		$authorization_url = UserEmailOauth::get_authorization_url( $provider, $user_id );

		if ( is_wp_error( $authorization_url ) ) {
			return $authorization_url;
		}

		return new WP_REST_Response(
			array(
				'authorization_url' => $authorization_url,
			),
			200
		);
	}

	/**
	 * Disconnect an OAuth provider for current user.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function oauth_disconnect( $request ) {
		$user_id  = get_current_user_id();
		$body     = $request->get_json_params();
		$provider = sanitize_text_field( $body['provider'] ?? '' );

		if ( ! in_array( $provider, array( 'gmail', 'outlook' ), true ) ) {
			return new WP_Error( 'invalid_provider', __( 'Invalid OAuth provider.', 'doublescale' ), array( 'status' => 400 ) );
		}

		UserEmailOauth::disconnect( $provider, $user_id );

		// Auto-disable account on disconnect.
		$account = get_user_meta( $user_id, 'doublescale_user_email_account', true );
		$account = is_array( $account ) ? $account : array();

		$account['enabled']    = false;
		$account['from_email'] = '';

		update_user_meta( $user_id, 'doublescale_user_email_account', $account );
		$this->maybe_clear_scheduler_flag( $user_id );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => sprintf(
					/* translators: %s: OAuth provider name */
					__( '%s disconnected successfully.', 'doublescale' ),
					ucfirst( $provider )
				),
			),
			200
		);
	}

	// ─── Scheduler Flag ─────────────────────────────────────────────────

	/**
	 * Clear the scheduler flag if no other users have enabled email accounts.
	 *
	 * @param int $exclude_user_id User ID to exclude from the check (the one being disabled/deleted).
	 */
	private function maybe_clear_scheduler_flag( $exclude_user_id = 0 ) {
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

		$has_enabled = false;
		foreach ( $users as $uid ) {
			if ( (int) $uid === (int) $exclude_user_id ) {
				continue;
			}
			$acct = get_user_meta( $uid, 'doublescale_user_email_account', true );
			if ( is_array( $acct ) && ! empty( $acct['enabled'] ) ) {
				$has_enabled = true;
				break;
			}
		}

		if ( ! $has_enabled ) {
			delete_option( 'doublescale_has_user_email_accounts' );
			$campaigns_tasks = \DoubleScale\Core\PluginKernel::instance()->campaigns_tasks;
			$campaigns_tasks->unschedule_all( 'doublescale_user_email_accounts' );
		}
	}

	// ─── smtp Validation ────────────────────────────────────────────

	/**
	 * Collect non-blocking warnings for the account.
	 *
	 * @param array $account Account settings.
	 * @return array Warning messages.
	 */
	private function collect_warnings( $account ) {
		$warnings   = array();
		$from_email = $account['from_email'] ?? '';

		if ( empty( $from_email ) ) {
			$from_email = wp_get_current_user()->user_email;
		}

		if ( ! empty( $from_email ) ) {
			$warning = $this->check_smtp_connection( $from_email );
			if ( $warning ) {
				$warnings[] = $warning;
			}
		}

		return $warnings;
	}

	/**
	 * Check if a smtp connection is configured for an email address.
	 *
	 * @param string $email Email address to check.
	 * @return string|null Warning message or null.
	 */
	private function check_smtp_connection( $email ) {
		if ( ! EmailOauth::smtp_settings_class() ) {
			return __( 'Warning: No SMTP backend is loaded. Enable the DoubleScale SMTP module or activate SMTP for reliable email delivery.', 'doublescale' );
		}

		$connection_id = EmailOauth::smtp_get_connection_by_from_email( $email );

		if ( empty( $connection_id ) ) {
			return sprintf(
				/* translators: %s: email address */
				__( 'Warning: No SMTP connection configured for: %s. Add a matching connection in CRM SMTP settings.', 'doublescale' ),
				$email
			);
		}

		return null;
	}

	// ─── Permissions ─────────────────────────────────────────────────────

	/**
	 * Permission check -- all CRM roles can access.
	 *
	 * @return bool
	 */
	public function permissions_check() {
		return current_user_can( 'doublescale_access' );
	}
}
