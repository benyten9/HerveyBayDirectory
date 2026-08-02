<?php
/**
 * Per-User Email OAuth 2.0 Handler
 *
 * Handles OAuth 2.0 flows for per-user Gmail and Outlook IMAP access.
 * Uses centralized admin-configured OAuth app credentials from
 * Settings::get('email_oauth_apps') instead of per-user credentials.
 * Tokens stored in user meta for IMAP and synced to smtp for SMTP.
 *
 * @since 1.6.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\Oauth;

use DoubleScale\Pro\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * UserEmailOauth class
 */
class UserEmailOauth {

	const STATE_PREFIX = 'doublescale-user-email-oauth-';

	/** @var string Legacy OAuth state prefix (still accepted on callback). */
	const LEGACY_STATE_PREFIX = 'ds-user-email-oauth-';

	/**
	 * Singleton instance
	 *
	 * @var UserEmailOauth|null
	 */
	private static $instance = null;

	/**
	 * Initialize OAuth handling (static entry point).
	 */
	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
	}

	/**
	 * Constructor -- registers admin_init hook for OAuth callback.
	 */
	private function __construct() {
		add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );
		add_action( 'deleted_user', array( __CLASS__, 'handle_user_deleted' ) );
	}

	// ─── Authorization URL ───────────────────────────────────────────────

	/**
	 * Build the authorization URL for a per-user OAuth flow.
	 *
	 * Uses centralized OAuth app credentials from admin settings.
	 *
	 * @param string $provider 'gmail' or 'outlook'.
	 * @param int    $user_id  WordPress user ID.
	 * @return string|\WP_Error Authorization URL or WP_Error if not configured.
	 */
	public static function get_authorization_url( $provider, $user_id ) {
		$app = EmailOauth::get_oauth_app_credentials( $provider );

		if ( empty( $app['client_id'] ) || empty( $app['client_secret'] ) ) {
			return new \WP_Error(
				'oauth_not_configured',
				sprintf(
					/* translators: %s: provider name */
					__( '%s OAuth app is not configured. Please ask your administrator to set up OAuth credentials.', 'doublescale' ),
					ucfirst( $provider )
				)
			);
		}

		$nonce = wp_create_nonce( 'doublescale_user_email_oauth_' . $provider . '_' . $user_id );
		$state = self::STATE_PREFIX . $provider . '-' . $user_id . '-' . $nonce;

		$params = array(
			'response_type' => 'code',
			'client_id'     => $app['client_id'],
			'redirect_uri'  => EmailOauth::get_redirect_uri(),
			'state'         => $state,
		);

		if ( 'gmail' === $provider ) {
			$params['scope']       = EmailOauth::GMAIL_SCOPE;
			$params['access_type'] = 'offline';
			$params['prompt']      = 'consent';
			$auth_url              = EmailOauth::GMAIL_AUTH_URL;
		} else {
			$params['scope'] = EmailOauth::OUTLOOK_SCOPE;
			$auth_url        = EmailOauth::OUTLOOK_AUTH_URL;
		}

		return add_query_arg( $params, $auth_url );
	}

	// ─── OAuth Callback ──────────────────────────────────────────────────

	/**
	 * Handle the OAuth callback on admin_init.
	 *
	 * Detects the user-scoped state prefix, verifies nonce and user identity,
	 * exchanges code for tokens, stores in user meta and syncs to smtp.
	 */
	public function handle_oauth_callback() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';

		$state_prefix_len = 0;
		if ( strpos( $state, self::STATE_PREFIX ) === 0 ) {
			$state_prefix_len = strlen( self::STATE_PREFIX );
		} elseif ( strpos( $state, self::LEGACY_STATE_PREFIX ) === 0 ) {
			$state_prefix_len = strlen( self::LEGACY_STATE_PREFIX );
		}
		if ( empty( $state ) || ! $state_prefix_len ) {
			return;
		}

		$state_body = substr( $state, $state_prefix_len );
		$parts      = explode( '-', $state_body, 3 );

		if ( count( $parts ) !== 3 ) {
			return;
		}

		$provider = sanitize_text_field( $parts[0] );
		$user_id  = absint( $parts[1] );
		$nonce    = sanitize_text_field( $parts[2] );

		if ( ! in_array( $provider, array( 'gmail', 'outlook' ), true ) ) {
			return;
		}

		if ( get_current_user_id() !== $user_id ) {
			self::render_oauth_result( false, __( 'User identity mismatch. Please try again.', 'doublescale' ), $provider );
			return;
		}

		if ( ! wp_verify_nonce( $nonce, 'doublescale_user_email_oauth_' . $provider . '_' . $user_id ) ) {
			self::render_oauth_result( false, __( 'Security verification failed. Please try again.', 'doublescale' ), $provider );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		if ( ! empty( $error ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$error_desc = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : $error;
			self::render_oauth_result( false, $error_desc, $provider );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( empty( $code ) ) {
			self::render_oauth_result( false, __( 'No authorization code received.', 'doublescale' ), $provider );
			return;
		}

		// Use centralized credentials (read-through from smtp).
		$app           = EmailOauth::get_oauth_app_credentials( $provider );
		$client_id     = $app['client_id'] ?? '';
		$client_secret = $app['client_secret'] ?? '';

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			self::render_oauth_result( false, __( 'OAuth app credentials are not configured. Please contact your administrator.', 'doublescale' ), $provider );
			return;
		}

		$tokens = EmailOauth::exchange_code( $provider, $code, $client_id, $client_secret );

		if ( is_wp_error( $tokens ) ) {
			self::render_oauth_result( false, $tokens->get_error_message(), $provider );
			return;
		}

		$user_email = EmailOauth::get_user_email( $provider, $tokens );

		// Store tokens in user meta (needed for IMAP).
		$account = get_user_meta( $user_id, 'doublescale_user_email_account', true );
		$account = is_array( $account ) ? $account : array();

		if ( ! isset( $account['oauth'] ) ) {
			$account['oauth'] = array();
		}

		$account['oauth'][ $provider ] = array(
			'access_token'  => $tokens['access_token'],
			'refresh_token' => $tokens['refresh_token'] ?? ( $account['oauth'][ $provider ]['refresh_token'] ?? '' ),
			'expires_at'    => time() + ( (int) ( $tokens['expires_in'] ?? 3600 ) ),
			'email'         => $user_email,
			'needs_reauth'  => false,
		);

		// If switching providers, disconnect the old one atomically.
		foreach ( array( 'gmail', 'outlook' ) as $other ) {
			if ( $other !== $provider && ! empty( $account['oauth'][ $other ]['access_token'] ) ) {
				if ( EmailOauth::smtp_oauth_storage_available() ) {
					self::remove_user_from_smtp( $other, $user_id );
				}
				$account['oauth'][ $other ] = array();
			}
		}

		// Auto-set account fields from OAuth result.
		$account['from_email']    = $user_email;
		$account['imap_provider'] = $provider;
		$account['enabled']       = true;

		if ( ! isset( $account['sync_sent'] ) ) {
			$account['sync_sent'] = true;
		}

		if ( empty( $account['from_name'] ) ) {
			$user                 = get_userdata( $user_id );
			$account['from_name'] = $user->display_name ?: $user->user_login;
		}

		// Single DB write with all changes.
		update_user_meta( $user_id, 'doublescale_user_email_account', $account );

		// Sync to the active SMTP backend so outbound mail works.
		if ( EmailOauth::smtp_oauth_storage_available() ) {
			self::sync_user_to_smtp( $provider, $tokens, $user_email, $user_id );
		}

		// Ensure IMAP polling scheduler is running.
		self::ensure_scheduler_running();

		self::render_oauth_result( true, __( 'Connected successfully!', 'doublescale' ), $provider );
	}

	// ─── Token Refresh ───────────────────────────────────────────────────

	/**
	 * Refresh an expired access token for a user.
	 *
	 * Uses centralized OAuth app credentials.
	 *
	 * @param string $provider 'gmail' or 'outlook'.
	 * @param int    $user_id  WordPress user ID.
	 * @return string|false New access token, or false on failure.
	 */
	public static function refresh_access_token( $provider, $user_id ) {
		$lock_key = 'doublescale_user_oauth_refresh_' . $provider . '_' . $user_id;

		// Bail immediately if another process is refreshing — the next scheduled
		// run will pick up the refreshed token. Blocking with sleep() inside an
		// AS callback delays the entire queue batch.
		if ( get_transient( $lock_key ) ) {
			$account = get_user_meta( $user_id, 'doublescale_user_email_account', true );
			return ( is_array( $account ) ? ( $account['oauth'][ $provider ]['access_token'] ?? false ) : false );
		}

		set_transient( $lock_key, true, 30 );

		try {
			$account = get_user_meta( $user_id, 'doublescale_user_email_account', true );
			$account = is_array( $account ) ? $account : array();
			$oauth   = $account['oauth'][ $provider ] ?? array();

			$refresh_token = $oauth['refresh_token'] ?? '';
			if ( empty( $refresh_token ) ) {
				self::mark_needs_reauth( $provider, $user_id );
				delete_transient( $lock_key );
				return false;
			}

			// Use centralized credentials (read-through from smtp).
			$app           = EmailOauth::get_oauth_app_credentials( $provider );
			$client_id     = $app['client_id'] ?? '';
			$client_secret = $app['client_secret'] ?? '';

			if ( empty( $client_id ) || empty( $client_secret ) ) {
				self::mark_needs_reauth( $provider, $user_id );
				delete_transient( $lock_key );
				return false;
			}

			$token_url = 'gmail' === $provider ? EmailOauth::GMAIL_TOKEN_URL : EmailOauth::OUTLOOK_TOKEN_URL;

			$body = array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
			);

			$response = wp_remote_post(
				$token_url,
				array(
					'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
					'body'    => http_build_query( $body ),
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				delete_transient( $lock_key );
				return false;
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( empty( $data['access_token'] ) ) {
				self::mark_needs_reauth( $provider, $user_id );
				delete_transient( $lock_key );
				return false;
			}

			$account = get_user_meta( $user_id, 'doublescale_user_email_account', true );
			$account = is_array( $account ) ? $account : array();

			$account['oauth'][ $provider ]['access_token'] = $data['access_token'];
			$account['oauth'][ $provider ]['expires_at']   = time() + ( (int) ( $data['expires_in'] ?? 3600 ) );
			$account['oauth'][ $provider ]['needs_reauth'] = false;

			if ( ! empty( $data['refresh_token'] ) ) {
				$account['oauth'][ $provider ]['refresh_token'] = $data['refresh_token'];
			}

			update_user_meta( $user_id, 'doublescale_user_email_account', $account );

			// Also update the token in the active SMTP backend's storage.
			if ( EmailOauth::smtp_oauth_storage_available() ) {
				self::update_smtp_tokens( $provider, $data, $user_id );

				// Force the SMTP backend to re-read credentials on next send so it
				// does not reuse a stale in-memory token that was already refreshed.
				wp_cache_delete( EmailOauth::mailer_settings_option_name( $provider ), 'options' );
			}

			delete_transient( $lock_key );
			return $data['access_token'];
		} catch ( \Exception $e ) {
			delete_transient( $lock_key );
			throw $e;
		}
	}

	/**
	 * Get a valid access token for a user, refreshing if necessary.
	 *
	 * @param string $provider 'gmail' or 'outlook'.
	 * @param int    $user_id  WordPress user ID.
	 * @return string|false Access token or false if unavailable.
	 */
	public static function get_valid_access_token( $provider, $user_id ) {
		$account = get_user_meta( $user_id, 'doublescale_user_email_account', true );
		$account = is_array( $account ) ? $account : array();
		$oauth   = $account['oauth'][ $provider ] ?? array();

		if ( ! empty( $oauth['needs_reauth'] ) ) {
			return false;
		}

		$access_token = $oauth['access_token'] ?? '';
		$expires_at   = (int) ( $oauth['expires_at'] ?? 0 );

		if ( empty( $access_token ) ) {
			return false;
		}

		if ( $expires_at > 0 && ( $expires_at - time() ) < 300 ) {
			$access_token = self::refresh_access_token( $provider, $user_id );
		}

		return $access_token;
	}

	/**
	 * Mint an Outlook-resource access token for IMAP/SMTP (XOAUTH2).
	 *
	 * Step two of the consumer-account flow: the stored refresh token (obtained
	 * with Graph scopes at authorize time) is exchanged for an access token whose
	 * audience is the Outlook mail resource. Only this token is accepted by the
	 * `outlook.office.com` IMAP/SMTP servers — the general Graph token used for
	 * Microsoft Graph calls authenticates IMAP as `NO AUTHENTICATE failed`.
	 *
	 * The exchange does NOT overwrite the stored Graph access token; it returns a
	 * short-lived resource token for immediate use (the poll connects and
	 * disconnects within one run, so caching it adds no value and risks staleness).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string|false Outlook-resource access token, or false on failure.
	 */
	public static function get_outlook_imap_access_token( $user_id ) {
		$account = get_user_meta( $user_id, 'doublescale_user_email_account', true );
		$account = is_array( $account ) ? $account : array();
		$oauth   = $account['oauth']['outlook'] ?? array();

		if ( ! empty( $oauth['needs_reauth'] ) ) {
			return false;
		}

		$refresh_token = $oauth['refresh_token'] ?? '';
		if ( empty( $refresh_token ) ) {
			return false;
		}

		$app           = EmailOauth::get_oauth_app_credentials( 'outlook' );
		$client_id     = $app['client_id'] ?? '';
		$client_secret = $app['client_secret'] ?? '';
		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return false;
		}

		$response = wp_remote_post(
			EmailOauth::OUTLOOK_TOKEN_URL,
			array(
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => http_build_query(
					array(
						'grant_type'    => 'refresh_token',
						'refresh_token' => $refresh_token,
						'client_id'     => $client_id,
						'client_secret' => $client_secret,
						'scope'         => EmailOauth::OUTLOOK_IMAP_SCOPE,
					)
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			// A failed resource exchange usually means consent never covered the
			// Outlook scopes — flag for reconnect so the UI prompts the user.
			self::mark_needs_reauth( 'outlook', $user_id );
			return false;
		}

		// A rotated refresh token (Microsoft rotates on every grant) must be
		// persisted, or the NEXT exchange uses a now-invalid token.
		if ( ! empty( $data['refresh_token'] ) ) {
			$account                                      = get_user_meta( $user_id, 'doublescale_user_email_account', true );
			$account                                      = is_array( $account ) ? $account : array();
			$account['oauth']['outlook']['refresh_token'] = $data['refresh_token'];
			update_user_meta( $user_id, 'doublescale_user_email_account', $account );
		}

		return $data['access_token'];
	}

	// ─── IMAP Configuration ──────────────────────────────────────────────

	/**
	 * Get IMAP connection config for a user's OAuth provider.
	 *
	 * @param string $provider 'gmail' or 'outlook'.
	 * @param int    $user_id  WordPress user ID.
	 * @return array|false Config array or false on failure.
	 */
	public static function get_imap_config( $provider, $user_id ) {
		// Gmail's single token serves IMAP directly. Outlook needs a second-step
		// exchange for an Outlook-resource token (the Graph token IMAP rejects).
		$access_token = 'outlook' === $provider
			? self::get_outlook_imap_access_token( $user_id )
			: self::get_valid_access_token( $provider, $user_id );
		if ( ! $access_token ) {
			return false;
		}

		$account  = get_user_meta( $user_id, 'doublescale_user_email_account', true );
		$account  = is_array( $account ) ? $account : array();
		$username = $account['oauth'][ $provider ]['email'] ?? '';

		if ( empty( $username ) ) {
			return false;
		}

		if ( 'gmail' === $provider ) {
			return array(
				'host'           => EmailOauth::GMAIL_IMAP_HOST,
				'port'           => EmailOauth::GMAIL_IMAP_PORT,
				'username'       => $username,
				'password'       => $access_token,
				'encryption'     => 'ssl',
				'authentication' => 'oauth',
			);
		}

		return array(
			'host'           => EmailOauth::OUTLOOK_IMAP_HOST,
			'port'           => EmailOauth::OUTLOOK_IMAP_PORT,
			'username'       => $username,
			'password'       => $access_token,
			'encryption'     => 'ssl',
			'authentication' => 'oauth',
		);
	}

	/**
	 * Get the Microsoft Graph config for a user's connected Outlook mailbox.
	 *
	 * Per-user analog of {@see EmailOauth::get_graph_config()}: returns the
	 * credentials {@see \DoubleScale\Pro\Modules\Inbox\Incoming\GraphMailClient}
	 * needs to read this user's Outlook mail over Graph instead of IMAP. App
	 * credentials are shared/global; the refresh token is per-user (user meta).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array{client_id:string, client_secret:string, refresh_token:string, email:string, on_refresh_token_rotated:callable}|false
	 */
	public static function get_graph_config( $user_id ) {
		$app           = EmailOauth::get_oauth_app_credentials( 'outlook' );
		$client_id     = $app['client_id'] ?? '';
		$client_secret = $app['client_secret'] ?? '';

		$account       = get_user_meta( $user_id, 'doublescale_user_email_account', true );
		$account       = is_array( $account ) ? $account : array();
		$oauth         = $account['oauth']['outlook'] ?? array();
		$refresh_token = $oauth['refresh_token'] ?? '';
		$email         = $oauth['email'] ?? '';

		if ( empty( $client_id ) || empty( $client_secret ) || empty( $refresh_token ) ) {
			return false;
		}

		return array(
			'client_id'                => $client_id,
			'client_secret'            => $client_secret,
			'refresh_token'            => $refresh_token,
			'email'                    => $email,
			'on_refresh_token_rotated' => static function ( $new_refresh_token ) use ( $user_id ) {
				$acct = get_user_meta( $user_id, 'doublescale_user_email_account', true );
				$acct = is_array( $acct ) ? $acct : array();
				if ( isset( $acct['oauth']['outlook'] ) ) {
					$acct['oauth']['outlook']['refresh_token'] = $new_refresh_token;
					update_user_meta( $user_id, 'doublescale_user_email_account', $acct );
				}
			},
		);
	}

	// ─── Connection Status ───────────────────────────────────────────────

	/**
	 * Check if an OAuth provider is connected for a user.
	 *
	 * @param string $provider 'gmail' or 'outlook'.
	 * @param int    $user_id  WordPress user ID.
	 * @return bool
	 */
	public static function is_connected( $provider, $user_id ) {
		$account = get_user_meta( $user_id, 'doublescale_user_email_account', true );
		$account = is_array( $account ) ? $account : array();
		$oauth   = $account['oauth'][ $provider ] ?? array();

		return ! empty( $oauth['access_token'] ) && ! empty( $oauth['refresh_token'] );
	}

	/**
	 * Disconnect an OAuth provider for a user.
	 *
	 * Clears OAuth tokens from user meta and removes the user's
	 * smtp account + connection for this provider.
	 *
	 * @param string $provider 'gmail' or 'outlook'.
	 * @param int    $user_id  WordPress user ID.
	 */
	public static function disconnect( $provider, $user_id ) {
		$account = get_user_meta( $user_id, 'doublescale_user_email_account', true );
		$account = is_array( $account ) ? $account : array();

		if ( isset( $account['oauth'][ $provider ] ) ) {
			$account['oauth'][ $provider ] = array();
		}

		update_user_meta( $user_id, 'doublescale_user_email_account', $account );

		// Clean up entries in the active SMTP backend.
		if ( EmailOauth::smtp_oauth_storage_available() ) {
			self::remove_user_from_smtp( $provider, $user_id );
		}
	}

	/**
	 * Clean up email OAuth data when a WordPress user is deleted.
	 *
	 * Uses remove_user_from_smtp() directly instead of disconnect()
	 * because user meta is already gone by the time deleted_user fires.
	 *
	 * @param int $user_id Deleted user's ID.
	 */
	public static function handle_user_deleted( $user_id ) {
		if ( EmailOauth::smtp_oauth_storage_available() ) {
			self::remove_user_from_smtp( 'gmail', $user_id );
			self::remove_user_from_smtp( 'outlook', $user_id );
		}
	}

	// ─── smtp Sync ─────────────────────────────────────────────────

	/**
	 * Create or update a smtp account + connection for a user.
	 *
	 * @param string $provider   'gmail' or 'outlook'.
	 * @param array  $tokens     Token data from OAuth exchange.
	 * @param string $user_email The authenticated user's email address.
	 * @param int    $user_id    WordPress user ID.
	 */
	public static function sync_user_to_smtp( $provider, $tokens, $user_email, $user_id ) {
		// Global lock to prevent read-modify-write races with concurrent syncs.
		// Bail if another sync is in progress — caller can retry on the next run.
		$lock_key = 'doublescale_smtp_sync';
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, true, 30 );

		$mailer_slug     = $provider;
		$option_name     = EmailOauth::mailer_settings_option_name( $mailer_slug );
		$mailer_settings = get_option( $option_name, array() );
		if ( ! is_array( $mailer_settings ) ) {
			$mailer_settings = array();
		}
		$accounts = $mailer_settings['accounts'] ?? array();

		// Ensure the SMTP backend has the same app credentials as CRM.
		// Tokens are bound to the OAuth app that issued them — refresh will fail
		// if smtp uses different app credentials.
		$app           = EmailOauth::get_oauth_app_credentials( $provider );
		$client_id     = $app['client_id'] ?? '';
		$client_secret = $app['client_secret'] ?? '';

		if ( ! empty( $client_id ) && ! empty( $client_secret ) ) {
			if ( ! isset( $mailer_settings['app'] ) ) {
				$mailer_settings['app'] = array();
			}
			$mailer_settings['app']['client_id']     = $client_id;
			$mailer_settings['app']['client_secret'] = $client_secret;
		}

		// Find existing account: first by user_id, then by email (pre-existing smtp account).
		$account_id = '';
		foreach ( $accounts as $id => $acct ) {
			if ( ( $acct['user_id'] ?? 0 ) === $user_id ) {
				$account_id = $id;
				break;
			}
		}

		// If no CRM-owned account found, check for pre-existing account by email.
		if ( empty( $account_id ) ) {
			foreach ( $accounts as $id => $acct ) {
				if ( strtolower( $acct['name'] ?? '' ) === strtolower( $user_email ) ) {
					$existing_owner = $acct['user_id'] ?? 0;
					if ( $existing_owner === $user_id ) {
						$account_id = $id;
					} else {
						// Belongs to org (shared mailbox, no user_id) or another user — don't claim.
						// SMTP routing works via existing connection's from_email match.
						doublescale_get_logger()->info(
							'Skipping smtp sync: email already connected',
							array(
								'user_id'        => $user_id,
								'email'          => $user_email,
								'existing_owner' => $existing_owner,
								'provider'       => $provider,
							)
						);
						delete_transient( $lock_key );
						return;
					}
					break;
				}
			}
		}

		if ( empty( $account_id ) ) {
			$account_id = wp_generate_password( 9, false );
		}

		if ( ! isset( $mailer_settings['accounts'] ) ) {
			$mailer_settings['accounts'] = array();
		}

		$user            = get_userdata( $user_id );
		$display_name    = $user ? ( $user->display_name ?: $user->user_login ) : '';
		$provider_label  = 'gmail' === $provider ? 'Gmail' : 'Outlook';
		$connection_name = $display_name
			? sprintf( '%s — %s', $display_name, $provider_label )
			: sprintf( '%s — %s', $user_email, $provider_label );

		// Gmail tokens must be a flat OAuth2 payload at the top level —
		// `Google\Client::setAccessToken()` expects `access_token` to be a string.
		// Outlook uses raw strings and handles refresh reactively on 401 responses.
		if ( 'gmail' === $provider ) {
			$credentials = EmailOauth::normalize_gmail_oauth_credentials_for_smtp( $tokens );
		} else {
			$credentials = $tokens;
		}

		$mailer_settings['accounts'][ $account_id ] = array(
			'name'        => $user_email,
			'user_id'     => $user_id,
			'credentials' => $credentials,
		);

		$old_mailer_settings = get_option( $option_name, array() );
		if ( $old_mailer_settings !== $mailer_settings ) {
			$updated = update_option( $option_name, $mailer_settings );
			if ( ! $updated ) {
				doublescale_get_logger()->warning(
					'Failed to sync user account to smtp',
					array(
						'user_id'  => $user_id,
						'provider' => $provider,
					)
				);
			}
		}

		// Create or update connection in the active SMTP backend.
		$routing_option = EmailOauth::smtp_routing_option_name();
		$smtp_settings  = get_option( $routing_option, array() );
		if ( ! is_array( $smtp_settings ) ) {
			$smtp_settings = array();
		}
		$connections = $smtp_settings['connections'] ?? array();

		// Find existing connection: first by user_id + mailer, then by from_email + mailer.
		$connection_id = '';
		foreach ( $connections as $conn_id => $conn ) {
			if ( ( $conn['user_id'] ?? 0 ) === $user_id && ( $conn['mailer'] ?? '' ) === $mailer_slug ) {
				$connection_id = $conn_id;
				break;
			}
		}

		if ( empty( $connection_id ) ) {
			foreach ( $connections as $conn_id => $conn ) {
				if ( strtolower( $conn['from_email'] ?? '' ) === strtolower( $user_email )
					&& ( $conn['mailer'] ?? '' ) === $mailer_slug ) {
					$connection_id = $conn_id;
					break;
				}
			}
		}

		if ( empty( $connection_id ) ) {
			$connection_id = wp_generate_password( 9, false );
		}

		$connections[ $connection_id ] = array(
			'name'             => $connection_name,
			'mailer'           => $mailer_slug,
			'account_id'       => $account_id,
			'user_id'          => $user_id,
			'from_email'       => $user_email,
			'force_from_email' => false,
			'from_name'        => $display_name,
			'force_from_name'  => false,
		);

		$smtp_settings['connections'] = $connections;
		$old_smtp_settings            = get_option( $routing_option, array() );
		if ( $old_smtp_settings !== $smtp_settings ) {
			$updated = update_option( $routing_option, $smtp_settings );
			if ( ! $updated ) {
				doublescale_get_logger()->warning(
					'Failed to sync user connection to smtp',
					array(
						'user_id'  => $user_id,
						'provider' => $provider,
						'option'   => $routing_option,
					)
				);
			}
		}

		delete_transient( $lock_key );
	}

	/**
	 * Remove a user's account + connection from the active SMTP backend.
	 *
	 * Only removes entries with matching user_id. Never touches the app credentials.
	 *
	 * @param string $provider 'gmail' or 'outlook'.
	 * @param int    $user_id  WordPress user ID.
	 */
	public static function remove_user_from_smtp( $provider, $user_id ) {
		// Global lock to prevent read-modify-write races with concurrent syncs.
		// Bail if another sync is in progress — caller can retry on the next run.
		$lock_key = 'doublescale_smtp_sync';
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, true, 30 );

		$mailer_slug     = $provider;
		$option_name     = EmailOauth::mailer_settings_option_name( $mailer_slug );
		$mailer_settings = get_option( $option_name, array() );
		if ( ! is_array( $mailer_settings ) ) {
			$mailer_settings = array();
		}
		$accounts = $mailer_settings['accounts'] ?? array();

		$original_mailer_settings = $mailer_settings;

		foreach ( $accounts as $account_id => $account_data ) {
			if ( ( $account_data['user_id'] ?? 0 ) === $user_id ) {
				unset( $accounts[ $account_id ] );
			}
		}
		$mailer_settings['accounts'] = $accounts;

		if ( $original_mailer_settings !== $mailer_settings ) {
			$updated = update_option( $option_name, $mailer_settings );
			if ( ! $updated ) {
				doublescale_get_logger()->warning(
					'Failed to remove user account from smtp',
					array(
						'user_id'  => $user_id,
						'provider' => $provider,
					)
				);
			}
		}

		// Remove connection from the active SMTP backend.
		$routing_option = EmailOauth::smtp_routing_option_name();
		$smtp_settings  = get_option( $routing_option, array() );
		if ( ! is_array( $smtp_settings ) ) {
			$smtp_settings = array();
		}
		$original_smtp_settings = $smtp_settings;
		$connections            = $smtp_settings['connections'] ?? array();

		foreach ( $connections as $conn_id => $conn ) {
			if ( ( $conn['user_id'] ?? 0 ) === $user_id && ( $conn['mailer'] ?? '' ) === $mailer_slug ) {
				unset( $connections[ $conn_id ] );
			}
		}
		$smtp_settings['connections'] = $connections;

		if ( $original_smtp_settings !== $smtp_settings ) {
			$updated = update_option( $routing_option, $smtp_settings );
			if ( ! $updated ) {
				doublescale_get_logger()->warning(
					'Failed to remove user connection from smtp',
					array(
						'user_id'  => $user_id,
						'provider' => $provider,
						'option'   => $routing_option,
					)
				);
			}
		}

		delete_transient( $lock_key );
	}

	/**
	 * Update tokens in an existing smtp account after refresh.
	 *
	 * @param string $provider 'gmail' or 'outlook'.
	 * @param array  $data     Token response data.
	 * @param int    $user_id  WordPress user ID.
	 */
	private static function update_smtp_tokens( $provider, $data, $user_id ) {
		// Skip if a structural sync is in progress — user_meta already has fresh tokens,
		// and wp_cache_delete forces smtp to re-read on next send.
		if ( get_transient( 'doublescale_smtp_sync' ) ) {
			return;
		}

		$mailer_slug     = $provider;
		$option_name     = EmailOauth::mailer_settings_option_name( $mailer_slug );
		$mailer_settings = get_option( $option_name, array() );
		if ( ! is_array( $mailer_settings ) ) {
			$mailer_settings = array();
		}
		$accounts = $mailer_settings['accounts'] ?? array();

		foreach ( $accounts as $account_id => &$account_data ) {
			if ( ( $account_data['user_id'] ?? 0 ) === $user_id ) {
				$old_creds     = $account_data['credentials'] ?? array();
				$refresh_token = $data['refresh_token'] ?? ( $old_creds['refresh_token'] ?? '' );

				if ( 'gmail' === $provider ) {
					// Always store the flat OAuth2 payload — Google\Client requires `access_token`
					// to be a string at the top level.
					$payload = $data;
					if ( empty( $payload['refresh_token'] ) ) {
						$payload['refresh_token'] = $refresh_token;
					}
					if ( empty( $payload['scope'] ) && ! empty( $old_creds['scope'] ) ) {
						$payload['scope'] = $old_creds['scope'];
					}
					$account_data['credentials'] = EmailOauth::normalize_gmail_oauth_credentials_for_smtp( $payload );
				} else {
					$account_data['credentials'] = array_merge( $old_creds, $data );
					if ( empty( $account_data['credentials']['refresh_token'] ) ) {
						$account_data['credentials']['refresh_token'] = $refresh_token;
					}
				}
				break;
			}
		}
		unset( $account_data );

		$mailer_settings['accounts'] = $accounts;
		update_option( $option_name, $mailer_settings );
	}

	// ─── Internal Helpers ────────────────────────────────────────────────

	/**
	 * Ensure the user email polling scheduler is running.
	 */
	public static function ensure_scheduler_running() {
		update_option( 'doublescale_has_user_email_accounts', true, true );
		$campaigns_tasks = \DoubleScale\Core\PluginKernel::instance()->campaigns_tasks;
		if ( $campaigns_tasks->get_next_timestamp( 'doublescale_user_email_accounts' ) === false ) {
			$campaigns_tasks->schedule_recurring( time(), 60, 'doublescale_user_email_accounts' );
		}
	}

	/**
	 * Mark a user's OAuth provider as needing re-authorization.
	 *
	 * @param string $provider 'gmail' or 'outlook'.
	 * @param int    $user_id  WordPress user ID.
	 */
	public static function mark_needs_reauth( $provider, $user_id ) {
		$account = get_user_meta( $user_id, 'doublescale_user_email_account', true );
		$account = is_array( $account ) ? $account : array();

		if ( isset( $account['oauth'][ $provider ] ) ) {
			$account['oauth'][ $provider ]['needs_reauth'] = true;
			update_user_meta( $user_id, 'doublescale_user_email_account', $account );
		}
	}

	/**
	 * Render the OAuth result page (displayed in popup, posts message to parent).
	 *
	 * @param bool   $success  Whether the OAuth flow succeeded.
	 * @param string $message  Human-readable message.
	 * @param string $provider Provider slug.
	 */
	private static function render_oauth_result( $success, $message, $provider ) {
		$status = $success ? 'success' : 'error';
		?>
		<!DOCTYPE html>
		<html lang="en">
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title><?php echo esc_html( $success ? __( 'Authorization Complete', 'doublescale' ) : __( 'Authorization Failed', 'doublescale' ) ); ?></title>
			<style>
				body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; background: #f0f0f1; }
				.message { text-align: center; padding: 40px; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.1); max-width: 400px; }
				.message.success { border-top: 4px solid #00a32a; }
				.message.error { border-top: 4px solid #d63638; }
				p { color: #3c434a; font-size: 14px; line-height: 1.6; }
			</style>
		</head>
		<body>
			<div class="message <?php echo esc_attr( $status ); ?>">
				<p><?php echo esc_html( $message ); ?></p>
				<p><small><?php esc_html_e( 'This window will close automatically.', 'doublescale' ); ?></small></p>
			</div>
			<script>
				if ( window.opener ) {
					try {
						window.opener.postMessage(
							{
								type: 'DOUBLESCALE_OAUTH_RESULT',
								scope: 'personal',
								status: <?php echo wp_json_encode( $status ); ?>,
								provider: <?php echo wp_json_encode( $provider ); ?>,
								message: <?php echo wp_json_encode( $message ); ?>
							},
							window.location.origin
						);
					} catch ( e ) {
						try { window.opener.location.reload(); } catch ( e2 ) {}
					}
				}
				setTimeout( function() { window.close(); }, 2000 );
			</script>
		</body>
		</html>
		<?php
		exit;
	}
}
