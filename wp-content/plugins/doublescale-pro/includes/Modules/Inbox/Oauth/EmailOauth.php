<?php
/**
 * Email OAuth 2.0 Handler
 *
 * Handles OAuth 2.0 flows for Gmail and Outlook email IMAP access.
 * Manages authorization, token exchange, refresh, and storage.
 *
 * @since 1.4.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\Oauth;

use DoubleScale\Pro\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * EmailOauth class
 */
class EmailOauth {

	// ─── Gmail OAuth constants ───────────────────────────────────────────────

	const GMAIL_AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
	const GMAIL_TOKEN_URL = 'https://oauth2.googleapis.com/token';
	const GMAIL_USERINFO  = 'https://www.googleapis.com/oauth2/v3/userinfo';
	const GMAIL_SCOPE     = 'https://mail.google.com/ openid email';
	const GMAIL_IMAP_HOST = 'imap.gmail.com';
	const GMAIL_IMAP_PORT = 993;

	// ─── Outlook OAuth constants ─────────────────────────────────────────────

	const OUTLOOK_AUTH_URL  = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
	const OUTLOOK_TOKEN_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
	const OUTLOOK_USERINFO  = 'https://graph.microsoft.com/v1.0/me';

	/**
	 * Authorize-step scope: UNPREFIXED Graph scopes.
	 *
	 * Personal (consumer / MSA) accounts reject the resource-prefixed
	 * `https://outlook.office.com/...` scopes at the `/authorize` endpoint with
	 * `invalid_scope`. The grant must request the short Graph scope names; the
	 * resulting refresh token is then exchanged for an Outlook-resource access
	 * token (see {@see OUTLOOK_IMAP_SCOPE}) just before IMAP authentication.
	 */
	const OUTLOOK_SCOPE = 'https://graph.microsoft.com/IMAP.AccessAsUser.All https://graph.microsoft.com/SMTP.Send offline_access openid email profile';

	/**
	 * IMAP-step scope: resource-prefixed Outlook scopes.
	 *
	 * Used in a second `refresh_token` exchange to mint an access token whose
	 * audience is the Outlook mail resource, which is the only token the
	 * `outlook.office.com` IMAP/SMTP servers accept over XOAUTH2. The FQDN must be
	 * `outlook.office.com` (NOT `outlook.office365.com`) for consumer accounts.
	 */
	const OUTLOOK_IMAP_SCOPE = 'https://outlook.office.com/IMAP.AccessAsUser.All https://outlook.office.com/SMTP.Send offline_access';

	const OUTLOOK_IMAP_HOST = 'outlook.office365.com';
	const OUTLOOK_IMAP_PORT = 993;

	// ─── State prefix ────────────────────────────────────────────────────────

	const STATE_PREFIX = 'doublescale-email-oauth-';

	/** @var string Legacy OAuth state prefix (still accepted on callback). */
	const LEGACY_STATE_PREFIX = 'ds-email-oauth-';

	/**
	 * Singleton instance
	 *
	 * @var EmailOauth|null
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
	 * Constructor — registers admin_init hook for OAuth callback.
	 */
	private function __construct() {
		add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );
	}

	// ─── Storage Backend Resolution (bundled SMTP module) ───────────────────

	/**
	 * Resolve the option name for a per-mailer settings bag (gmail/outlook/zoho).
	 *
	 * @param string $provider Mailer slug (e.g. "gmail").
	 * @return string
	 */
	public static function mailer_settings_option_name( $provider ) {
		return 'doublescale_smtp_' . $provider . '_settings';
	}

	/**
	 * Resolve the option name for the global SMTP routing payload (connections,
	 * default_connection, fallback_connection, …).
	 *
	 * @return string
	 */
	public static function smtp_routing_option_name() {
		return 'doublescale_smtp_settings';
	}

	/**
	 * Whether the bundled SMTP module is available to store OAuth credentials.
	 *
	 * @return bool
	 */
	public static function smtp_oauth_storage_available() {
		return class_exists( '\\DoubleScale\\Modules\\Smtp\\Module', false )
			|| class_exists( '\\DoubleScale\\Modules\\Smtp\\Settings', false );
	}

	/**
	 * Fully-qualified Settings class used for smart routing and connection lookup.
	 *
	 * @return class-string|null FQCN or null if the SMTP module is not loaded.
	 */
	public static function smtp_settings_class() {
		if ( class_exists( '\\DoubleScale\\Modules\\Smtp\\Settings' ) ) {
			return '\\DoubleScale\\Modules\\Smtp\\Settings';
		}
		return null;
	}

	/**
	 * Resolve an SMTP connection id for a From address (same semantics as
	 * {@see \DoubleScale\Modules\Smtp\Settings::get_connection_by_from_email()}).
	 *
	 * @param string $from_email From address.
	 * @return string|null Connection id or null.
	 */
	public static function smtp_get_connection_by_from_email( $from_email ) {
		$cls = self::smtp_settings_class();
		if ( ! $cls || ! is_callable( array( $cls, 'get_connection_by_from_email' ) ) ) {
			return null;
		}
		return call_user_func( array( $cls, 'get_connection_by_from_email' ), $from_email );
	}

	// ─── Credential Resolution ──────────────────────────────────────────────

	/**
	 * Get OAuth app credentials from the active SMTP storage backend.
	 *
	 * Returns empty array if no SMTP backend is configured.
	 *
	 * @param string $provider 'gmail' or 'outlook'.
	 * @return array { client_id, client_secret } or empty array.
	 */
	public static function get_oauth_app_credentials( $provider ) {
		if ( ! self::smtp_oauth_storage_available() ) {
			return array();
		}

		$mailer_settings = get_option( self::mailer_settings_option_name( $provider ), array() );
		$app             = is_array( $mailer_settings ) && isset( $mailer_settings['app'] ) ? $mailer_settings['app'] : array();

		if ( ! empty( $app['client_id'] ) && ! empty( $app['client_secret'] ) ) {
			return $app;
		}

		return array();
	}

	/**
	 * Normalize Gmail OAuth tokens into the flat shape that SMTP / Google\Client expect.
	 *
	 * Earlier ports of this code stored the Gmail access_token as a *nested* array under
	 * `credentials.access_token.access_token`, which broke `Google\Client::setAccessToken()`
	 * (it expects a flat array with a top-level `access_token` string and a `created` key).
	 *
	 * This helper accepts either the raw token response from Google or a previously stored
	 * nested credentials array and returns a flat OAuth2 payload.
	 *
	 * @param array $tokens Raw token response from Google or stored credentials.
	 * @return array
	 */
	public static function normalize_gmail_oauth_credentials_for_smtp( array $tokens ) {
		$out = $tokens;

		if ( isset( $out['access_token'] ) && is_array( $out['access_token'] ) ) {
			$inner = $out['access_token'];
			unset( $out['access_token'] );
			$out = array_merge( $inner, $out );
		}

		if ( ! empty( $out['access_token'] ) && is_string( $out['access_token'] ) && empty( $out['created'] ) ) {
			$out['created'] = time();
		}

		if ( empty( $out['expires_in'] ) ) {
			$out['expires_in'] = 3600;
		}

		if ( empty( $out['token_type'] ) ) {
			$out['token_type'] = 'Bearer';
		}

		if ( ! isset( $out['scope'] ) ) {
			$out['scope'] = '';
		}

		return $out;
	}

	// ─── Authorization URL ───────────────────────────────────────────────────

	/**
	 * Get the OAuth redirect URI (where the provider sends the user back).
	 *
	 * @return string
	 */
	public static function get_redirect_uri() {
		return admin_url( 'admin.php' );
	}

	/**
	 * Build the authorization URL for the given provider.
	 *
	 * Uses centralized OAuth app credentials from Settings::get('email_oauth_apps').
	 *
	 * @param string $provider 'gmail' or 'outlook'.
	 * @return string|\WP_Error Authorization URL or WP_Error if not configured.
	 */
	public static function get_authorization_url( $provider ) {
		$app = self::get_oauth_app_credentials( $provider );

		if ( empty( $app['client_id'] ) || empty( $app['client_secret'] ) ) {
			return new \WP_Error(
				'oauth_not_configured',
				sprintf(
					/* translators: %s: provider name */
					__( '%s OAuth app credentials are not configured. Please set them up in Email Provider Setup first.', 'doublescale' ),
					ucfirst( $provider )
				)
			);
		}

		// Generate CSRF nonce and embed in state.
		$nonce = wp_create_nonce( 'doublescale_email_oauth_' . $provider );
		$state = self::STATE_PREFIX . $provider . '-' . $nonce;

		$params = array(
			'response_type' => 'code',
			'client_id'     => $app['client_id'],
			'redirect_uri'  => self::get_redirect_uri(),
			'state'         => $state,
		);

		if ( 'gmail' === $provider ) {
			$params['scope']       = self::GMAIL_SCOPE;
			$params['access_type'] = 'offline';
			$params['prompt']      = 'consent'; // Required to always get refresh_token.
			$auth_url              = self::GMAIL_AUTH_URL;
		} else {
			$params['scope'] = self::OUTLOOK_SCOPE;
			$auth_url        = self::OUTLOOK_AUTH_URL;
		}

		return add_query_arg( $params, $auth_url );
	}

	// ─── OAuth Callback ──────────────────────────────────────────────────────

	/**
	 * Handle the OAuth callback on admin_init.
	 *
	 * Detects the state prefix, verifies nonce, exchanges code for tokens,
	 * fetches user email, stores everything, and closes the popup.
	 */
	public function handle_oauth_callback() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified below via state parameter
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';

		$state_prefix_len = 0;
		if ( strpos( $state, self::STATE_PREFIX ) === 0 ) {
			$state_prefix_len = strlen( self::STATE_PREFIX );
		} elseif ( strpos( $state, self::LEGACY_STATE_PREFIX ) === 0 ) {
			$state_prefix_len = strlen( self::LEGACY_STATE_PREFIX );
		}
		if ( empty( $state ) || ! $state_prefix_len ) {
			return; // Not our callback.
		}

		// Extract provider and nonce from state.
		$state_body = substr( $state, $state_prefix_len );
		$parts      = explode( '-', $state_body, 2 );

		if ( count( $parts ) !== 2 ) {
			return;
		}

		$provider = sanitize_text_field( $parts[0] );
		$nonce    = sanitize_text_field( $parts[1] );

		if ( ! in_array( $provider, array( 'gmail', 'outlook' ), true ) ) {
			return;
		}

		// Verify CSRF nonce.
		if ( ! wp_verify_nonce( $nonce, 'doublescale_email_oauth_' . $provider ) ) {
			self::render_oauth_result( false, __( 'Security verification failed. Please try again.', 'doublescale' ), $provider );
			return;
		}

		// Check for error (user denied access or provider error).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		if ( ! empty( $error ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$error_desc = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : $error;
			self::render_oauth_result( false, $error_desc, $provider );
			return;
		}

		// Get authorization code.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( empty( $code ) ) {
			self::render_oauth_result( false, __( 'No authorization code received.', 'doublescale' ), $provider );
			return;
		}

		// Use centralized OAuth app credentials (read-through from smtp).
		$app           = self::get_oauth_app_credentials( $provider );
		$client_id     = $app['client_id'] ?? '';
		$client_secret = $app['client_secret'] ?? '';

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			self::render_oauth_result( false, __( 'OAuth app credentials are not configured. Please contact your administrator.', 'doublescale' ), $provider );
			return;
		}

		// Exchange code for tokens.
		$tokens = self::exchange_code( $provider, $code, $client_id, $client_secret );

		if ( is_wp_error( $tokens ) ) {
			self::render_oauth_result( false, $tokens->get_error_message(), $provider );
			return;
		}

		// Resolve the authenticated address (Gmail: userinfo; Outlook: id_token).
		$user_email = self::get_user_email( $provider, $tokens );

		// Store tokens (no client_id/client_secret — those are centralized in email_oauth_apps).
		$email_inbound = Settings::get( 'email_inbound', array() );
		if ( ! isset( $email_inbound['oauth'] ) ) {
			$email_inbound['oauth'] = array();
		}

		$email_inbound['oauth'][ $provider ] = array(
			'access_token'  => $tokens['access_token'],
			'refresh_token' => $tokens['refresh_token'] ?? ( $email_inbound['oauth'][ $provider ]['refresh_token'] ?? '' ),
			'expires_at'    => time() + ( (int) ( $tokens['expires_in'] ?? 3600 ) ),
			'email'         => $user_email,
			'needs_reauth'  => false,
		);

		$email_inbound['enabled']       = true;
		$email_inbound['imap_provider'] = $provider;

		Settings::update( 'email_inbound', $email_inbound );

		// Forward-only: anchor the inbox sync to the connect moment so a freshly
		// connected mailbox never imports pre-existing history. poll_imap() also
		// defaults this to "now" on its first run (covering SMTP-reuse connects
		// that don't pass through this callback), but stamping it here makes the
		// floor precise for the shared-inbox OAuth path.
		if ( ! get_option( 'doublescale_imap_sync_since', 0 ) ) {
			update_option( 'doublescale_imap_sync_since', time(), false );
		}

		// Mirror the connection into the SMTP storage backend (standalone plugin
		// or bundled module) so outbound mail can flow without a separate setup.
		// Shared connections intentionally omit user_id — they belong to the organization.
		if ( self::smtp_oauth_storage_available() ) {
			self::sync_to_smtp( $provider, $client_id, $client_secret, $tokens, $user_email );
		}

		self::render_oauth_result( true, __( 'Connected successfully!', 'doublescale' ), $provider );
	}

	// ─── Token Exchange ──────────────────────────────────────────────────────

	/**
	 * Exchange an authorization code for access/refresh tokens.
	 *
	 * @param string $provider      'gmail' or 'outlook'.
	 * @param string $code          Authorization code.
	 * @param string $client_id     OAuth client ID.
	 * @param string $client_secret OAuth client secret.
	 * @return array|\WP_Error Token data array or WP_Error on failure.
	 */
	public static function exchange_code( $provider, $code, $client_id, $client_secret ) {
		$token_url = 'gmail' === $provider ? self::GMAIL_TOKEN_URL : self::OUTLOOK_TOKEN_URL;

		$body = array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
			'redirect_uri'  => self::get_redirect_uri(),
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
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['access_token'] ) ) {
			$error_msg = $data['error_description'] ?? $data['error'] ?? __( 'Failed to obtain access token.', 'doublescale' );
			return new \WP_Error( 'token_exchange_failed', $error_msg );
		}

		return $data;
	}

	// ─── Token Refresh ───────────────────────────────────────────────────────

	/**
	 * Refresh an expired access token.
	 *
	 * On permanent failure (no refresh_token, or provider rejects it),
	 * sets the needs_reauth flag so the polling loop does not retry endlessly.
	 *
	 * @param string $provider 'gmail' or 'outlook'.
	 * @return string|false New access token, or false on failure.
	 */
	public static function refresh_access_token( $provider ) {
		$lock_key = 'doublescale_oauth_refresh_' . $provider;

		// Prevent concurrent refresh attempts (e.g. overlapping Action Scheduler runs).
		// Bail immediately instead of blocking — the next scheduled run will pick up
		// the refreshed token. Blocking with sleep() inside an AS callback delays the
		// entire queue batch and starves other plugins.
		if ( get_transient( $lock_key ) ) {
			$email_inbound = Settings::get( 'email_inbound', array() );
			return $email_inbound['oauth'][ $provider ]['access_token'] ?? false;
		}

		// Acquire lock with 30-second TTL.
		set_transient( $lock_key, true, 30 );

		try {
			$email_inbound  = Settings::get( 'email_inbound', array() );
			$oauth_settings = $email_inbound['oauth'][ $provider ] ?? array();
			$refresh_token  = $oauth_settings['refresh_token'] ?? '';

			if ( empty( $refresh_token ) ) {
				self::mark_needs_reauth( $provider );
				doublescale_get_logger()->error(
					'OAuth token refresh failed: no refresh token',
					array(
						'code'     => 'email_oauth_no_refresh_token',
						'provider' => $provider,
					)
				);
				delete_transient( $lock_key );
				return false;
			}

			$token_url = 'gmail' === $provider ? self::GMAIL_TOKEN_URL : self::OUTLOOK_TOKEN_URL;

			// Use centralized OAuth app credentials (read-through from smtp).
			$app           = self::get_oauth_app_credentials( $provider );
			$client_id     = $app['client_id'] ?? '';
			$client_secret = $app['client_secret'] ?? '';

			if ( empty( $client_id ) || empty( $client_secret ) ) {
				self::mark_needs_reauth( $provider );
				doublescale_get_logger()->error(
					'OAuth token refresh failed: centralized credentials not configured',
					array(
						'code'     => 'email_oauth_no_app_credentials',
						'provider' => $provider,
					)
				);
				delete_transient( $lock_key );
				return false;
			}

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
				doublescale_get_logger()->error(
					'OAuth token refresh HTTP error',
					array(
						'code'     => 'email_oauth_refresh_http_error',
						'provider' => $provider,
						'error'    => $response->get_error_message(),
					)
				);
				delete_transient( $lock_key );
				return false;
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( empty( $data['access_token'] ) ) {
				// Permanent failure — mark as needing re-authorization.
				self::mark_needs_reauth( $provider );
				doublescale_get_logger()->error(
					'OAuth token refresh rejected by provider',
					array(
						'code'     => 'email_oauth_refresh_rejected',
						'provider' => $provider,
						'error'    => $data['error'] ?? 'unknown',
					)
				);
				delete_transient( $lock_key );
				return false;
			}

			// Update stored tokens.
			$email_inbound = Settings::get( 'email_inbound', array() );

			$email_inbound['oauth'][ $provider ]['access_token'] = $data['access_token'];
			$email_inbound['oauth'][ $provider ]['expires_at']   = time() + ( (int) ( $data['expires_in'] ?? 3600 ) );
			$email_inbound['oauth'][ $provider ]['needs_reauth'] = false;

			// Some providers issue a new refresh_token on refresh.
			if ( ! empty( $data['refresh_token'] ) ) {
				$email_inbound['oauth'][ $provider ]['refresh_token'] = $data['refresh_token'];
			}

			Settings::update( 'email_inbound', $email_inbound );

			delete_transient( $lock_key );
			return $data['access_token'];
		} catch ( \Exception $e ) {
			delete_transient( $lock_key );
			throw $e;
		}
	}

	/**
	 * Get a valid access token, refreshing if necessary.
	 *
	 * @param string $provider 'gmail' or 'outlook'.
	 * @return string|false Access token or false if unavailable.
	 */
	public static function get_valid_access_token( $provider ) {
		$email_inbound  = Settings::get( 'email_inbound', array() );
		$oauth_settings = $email_inbound['oauth'][ $provider ] ?? array();

		if ( ! empty( $oauth_settings['needs_reauth'] ) ) {
			return false;
		}

		$access_token = $oauth_settings['access_token'] ?? '';
		$expires_at   = (int) ( $oauth_settings['expires_at'] ?? 0 );

		if ( empty( $access_token ) ) {
			return false;
		}

		// Refresh if less than 5 minutes to expiry.
		if ( $expires_at > 0 && ( $expires_at - time() ) < 300 ) {
			$access_token = self::refresh_access_token( $provider );
		}

		return $access_token;
	}

	// ─── IMAP Configuration ──────────────────────────────────────────────────

	/**
	 * Get IMAP connection config for an OAuth provider.
	 *
	 * @param string $provider 'gmail' or 'outlook'.
	 * @return array|false Array with host, port, username, password, encryption, authentication; or false on failure.
	 */
	public static function get_imap_config( $provider ) {
		// Gmail's single token serves IMAP directly. Outlook needs a SECOND-STEP
		// exchange for an Outlook-resource token — the Graph-audience token from
		// get_valid_access_token() is rejected by the IMAP server (this was the
		// shared-inbox audience bug). The primary Outlook receive path is now Graph
		// (see GraphMailClient / get_graph_config); this IMAP branch remains correct
		// for any work/school Exchange-Online mailbox that still uses IMAP transport.
		$access_token = 'outlook' === $provider
			? self::get_outlook_imap_access_token()
			: self::get_valid_access_token( $provider );
		if ( ! $access_token ) {
			return false;
		}

		$email_inbound  = Settings::get( 'email_inbound', array() );
		$oauth_settings = $email_inbound['oauth'][ $provider ] ?? array();
		$username       = $oauth_settings['email'] ?? '';

		if ( empty( $username ) ) {
			return false;
		}

		if ( 'gmail' === $provider ) {
			return array(
				'host'           => self::GMAIL_IMAP_HOST,
				'port'           => self::GMAIL_IMAP_PORT,
				'username'       => $username,
				'password'       => $access_token,
				'encryption'     => 'ssl',
				'authentication' => 'oauth',
			);
		}

		return array(
			'host'           => self::OUTLOOK_IMAP_HOST,
			'port'           => self::OUTLOOK_IMAP_PORT,
			'username'       => $username,
			'password'       => $access_token,
			'encryption'     => 'ssl',
			'authentication' => 'oauth',
		);
	}

	/**
	 * Mint an Outlook-resource access token for the shared inbox via the second-step
	 * exchange (scope = OUTLOOK_IMAP_SCOPE on outlook.office.com).
	 *
	 * The IMAP server only accepts a token whose audience is the Outlook mail
	 * resource; the Graph-audience token from get_valid_access_token() is refused.
	 * This is the shared-inbox analog of {@see UserEmailOauth::get_outlook_imap_access_token()}.
	 *
	 * @return string|false Outlook-resource access token, or false on failure.
	 */
	public static function get_outlook_imap_access_token() {
		$email_inbound = Settings::get( 'email_inbound', array() );
		$oauth         = $email_inbound['oauth']['outlook'] ?? array();

		if ( ! empty( $oauth['needs_reauth'] ) ) {
			return false;
		}

		$refresh_token = $oauth['refresh_token'] ?? '';
		if ( empty( $refresh_token ) ) {
			return false;
		}

		$app           = self::get_oauth_app_credentials( 'outlook' );
		$client_id     = $app['client_id'] ?? '';
		$client_secret = $app['client_secret'] ?? '';
		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return false;
		}

		$response = wp_remote_post(
			self::OUTLOOK_TOKEN_URL,
			array(
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => http_build_query(
					array(
						'grant_type'    => 'refresh_token',
						'refresh_token' => $refresh_token,
						'client_id'     => $client_id,
						'client_secret' => $client_secret,
						'scope'         => self::OUTLOOK_IMAP_SCOPE,
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
			self::mark_needs_reauth( 'outlook' );
			return false;
		}

		// A rotated refresh token (Microsoft rotates on every grant) must be
		// persisted, or the NEXT exchange uses a now-invalid token.
		if ( ! empty( $data['refresh_token'] ) ) {
			$email_inbound = Settings::get( 'email_inbound', array() );
			if ( isset( $email_inbound['oauth']['outlook'] ) ) {
				$email_inbound['oauth']['outlook']['refresh_token'] = $data['refresh_token'];
				Settings::update( 'email_inbound', $email_inbound );
			}
		}

		return $data['access_token'];
	}

	/**
	 * Get the Microsoft Graph config for the connected Outlook mailbox.
	 *
	 * Returns the credentials {@see GraphMailClient} needs to read mail over Graph
	 * instead of IMAP: the Azure app client_id/secret and the mailbox refresh token.
	 * No access token is minted here — the client mints a Graph-audience token from
	 * the refresh token at connect() time (and persists any rotation via the
	 * supplied callback). This is the Outlook *receive* path; Gmail keeps IMAP.
	 *
	 * @return array{client_id:string, client_secret:string, refresh_token:string, email:string, on_refresh_token_rotated:callable}|false
	 */
	public static function get_graph_config() {
		$app           = self::get_oauth_app_credentials( 'outlook' );
		$client_id     = $app['client_id'] ?? '';
		$client_secret = $app['client_secret'] ?? '';

		$email_inbound  = Settings::get( 'email_inbound', array() );
		$oauth_settings = $email_inbound['oauth']['outlook'] ?? array();
		$refresh_token  = $oauth_settings['refresh_token'] ?? '';
		$email          = $oauth_settings['email'] ?? '';

		if ( empty( $client_id ) || empty( $client_secret ) || empty( $refresh_token ) ) {
			return false;
		}

		return array(
			'client_id'                => $client_id,
			'client_secret'            => $client_secret,
			'refresh_token'            => $refresh_token,
			'email'                    => $email,
			// Persist a rotated refresh token back into the shared-inbox settings so the
			// connection survives Microsoft's per-redemption rotation of consumer tokens.
			'on_refresh_token_rotated' => static function ( $new_refresh_token ) {
				$settings = Settings::get( 'email_inbound', array() );
				if ( isset( $settings['oauth']['outlook'] ) ) {
					$settings['oauth']['outlook']['refresh_token'] = $new_refresh_token;
					Settings::update( 'email_inbound', $settings );
				}
			},
		);
	}

	// ─── Connection Status ───────────────────────────────────────────────────

	/**
	 * Check if an OAuth provider is connected (has tokens).
	 *
	 * @param string $provider 'gmail' or 'outlook'.
	 * @return bool
	 */
	public static function is_connected( $provider ) {
		$email_inbound  = Settings::get( 'email_inbound', array() );
		$oauth_settings = $email_inbound['oauth'][ $provider ] ?? array();

		return ! empty( $oauth_settings['access_token'] ) && ! empty( $oauth_settings['refresh_token'] );
	}

	/**
	 * Disconnect an OAuth provider (clear tokens).
	 *
	 * Credentials are centralized in email_oauth_apps and never touched here.
	 *
	 * @param string $provider 'gmail' or 'outlook'.
	 */
	public static function disconnect( $provider ) {
		$email_inbound = Settings::get( 'email_inbound', array() );

		if ( isset( $email_inbound['oauth'][ $provider ] ) ) {
			$email_inbound['oauth'][ $provider ] = array();
		}

		Settings::update( 'email_inbound', $email_inbound );
	}

	// ─── User Info ───────────────────────────────────────────────────────────

	/**
	 * Resolve the authenticated user's email address from a token grant.
	 *
	 * Gmail's token targets the Google userinfo endpoint. Outlook's token targets
	 * the Outlook resource (for IMAP/SMTP XOAUTH2), which cannot call Microsoft
	 * Graph — so the address is read from the `id_token` JWT minted by the `openid
	 * email` scopes, with no extra network round-trip.
	 *
	 * @param string $provider 'gmail' or 'outlook'.
	 * @param array  $tokens   Token grant (access_token, and for Outlook id_token).
	 * @return string Email address, or empty string on failure.
	 */
	public static function get_user_email( $provider, $tokens ) {
		// Back-compat: callers used to pass the bare access token string.
		if ( ! is_array( $tokens ) ) {
			$tokens = array( 'access_token' => (string) $tokens );
		}

		if ( 'gmail' === $provider ) {
			$response = wp_remote_get(
				self::GMAIL_USERINFO,
				array(
					'headers' => array( 'Authorization' => 'Bearer ' . ( $tokens['access_token'] ?? '' ) ),
					'timeout' => 15,
				)
			);
			if ( is_wp_error( $response ) ) {
				return '';
			}
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			return is_array( $data ) ? ( $data['email'] ?? '' ) : '';
		}

		// Outlook: decode the id_token JWT (payload carries `email` / `preferred_username`).
		return self::email_from_id_token( $tokens['id_token'] ?? '' );
	}

	/**
	 * Extract an email address from an OIDC id_token's payload.
	 *
	 * @param string $id_token Compact-serialized JWT (header.payload.signature).
	 * @return string Email address, or empty string when absent/unparseable.
	 */
	private static function email_from_id_token( $id_token ) {
		$parts = explode( '.', (string) $id_token );
		if ( count( $parts ) < 2 ) {
			return '';
		}

		$payload = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding a JWT payload segment, not obfuscation.
		if ( ! is_array( $payload ) ) {
			return '';
		}

		return $payload['email'] ?? $payload['preferred_username'] ?? '';
	}

	// ─── smtp Sync ─────────────────────────────────────────────────────

	/**
	 * Create a smtp account and connection from Plugin OAuth credentials.
	 *
	 * This allows users who configure Gmail/Outlook in Plugin to automatically
	 * get a working SMTP connection in smtp without configuring it separately.
	 *
	 * @param string $provider      'gmail' or 'outlook'.
	 * @param string $client_id     OAuth client ID.
	 * @param string $client_secret OAuth client secret.
	 * @param array  $tokens        Token data from the OAuth exchange.
	 * @param string $user_email    The authenticated user's email address.
	 */
	private static function sync_to_smtp( $provider, $client_id, $client_secret, $tokens, $user_email ) {
		// Global lock to prevent read-modify-write races with concurrent syncs.
		$lock_key = 'doublescale_smtp_sync';
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, true, 30 );

		$mailer_slug     = 'gmail' === $provider ? 'gmail' : 'outlook';
		$option_name     = self::mailer_settings_option_name( $mailer_slug );
		$mailer_settings = get_option( $option_name, array() );
		if ( ! is_array( $mailer_settings ) ) {
			$mailer_settings = array();
		}

		// Ensure global app credentials are set (shared across all accounts).
		if ( ! isset( $mailer_settings['app'] ) ) {
			$mailer_settings['app'] = array();
		}
		if ( empty( $mailer_settings['app']['client_id'] ) || empty( $mailer_settings['app']['client_secret'] ) ) {
			$mailer_settings['app']['client_id']     = $client_id;
			$mailer_settings['app']['client_secret'] = $client_secret;
		}

		// Check if an account with the same email already exists.
		$existing_account_id = '';
		foreach ( ( $mailer_settings['accounts'] ?? array() ) as $id => $account ) {
			if ( ( $account['name'] ?? '' ) === $user_email ) {
				$existing_account_id = $id;
				break;
			}
		}

		$account_id = $existing_account_id ?: wp_generate_password( 9, false );

		// Create or update the account — store only tokens (app credentials are global).
		if ( ! isset( $mailer_settings['accounts'] ) ) {
			$mailer_settings['accounts'] = array();
		}

		// Flatten the token response so `credentials.access_token` is the JWT *string*,
		// never a nested array. Gmail needs this because Google\Client::setAccessToken()
		// requires a top-level string + `created`; Outlook needs it because its sender
		// (Smtp\Providers\outlook\class-account-api.php) reads `credentials.access_token`
		// directly into the `Bearer` header — a nested array there becomes "Bearer Array"
		// and forces a wasted 401+refresh on the first send. The normalizer's logic is
		// provider-agnostic (pure structural flattening), so both providers use it.
		$credentials = self::normalize_gmail_oauth_credentials_for_smtp( $tokens );

		$mailer_settings['accounts'][ $account_id ] = array(
			'name'        => $user_email,
			'credentials' => $credentials,
		);

		$old_mailer_settings = get_option( $option_name, array() );
		if ( $old_mailer_settings !== $mailer_settings ) {
			$updated = update_option( $option_name, $mailer_settings );
			if ( ! $updated ) {
				doublescale_get_logger()->warning(
					'Failed to sync shared email account to smtp',
					array(
						'provider' => $provider,
						'option'   => $option_name,
					)
				);
			}
		}

		// Create an SMTP connection (in the active backend) if none exists for this account.
		// Shared connections intentionally omit user_id — they belong to the organization.
		$smtp_routing_option = self::smtp_routing_option_name();
		$smtp_settings       = get_option( $smtp_routing_option, array() );
		if ( ! is_array( $smtp_settings ) ) {
			$smtp_settings = array();
		}
		$connections = $smtp_settings['connections'] ?? array();

		$has_connection = false;
		foreach ( $connections as $conn ) {
			if ( ( $conn['mailer'] ?? '' ) === $mailer_slug && ( $conn['account_id'] ?? '' ) === $account_id ) {
				$has_connection = true;
				break;
			}
		}

		if ( ! $has_connection && ! empty( $user_email ) ) {
			$connection_id  = wp_generate_password( 9, false );
			$provider_label = 'gmail' === $provider ? 'Gmail' : 'Outlook';

			$connections[ $connection_id ] = array(
				'name'             => sprintf( 'Shared Mailbox — %s', $provider_label ),
				'mailer'           => $mailer_slug,
				'account_id'       => $account_id,
				'from_email'       => $user_email,
				'force_from_email' => false,
				'from_name'        => '',
				'force_from_name'  => false,
			);

			$smtp_settings['connections'] = $connections;

			// Set as default connection if none exists.
			if ( empty( $smtp_settings['default_connection'] ) ) {
				$smtp_settings['default_connection'] = $connection_id;
			}

			$updated = update_option( $smtp_routing_option, $smtp_settings );
			if ( ! $updated ) {
				doublescale_get_logger()->warning(
					'Failed to sync shared email connection to smtp',
					array(
						'provider' => $provider,
						'option'   => $smtp_routing_option,
					)
				);
			}
		}

		delete_transient( $lock_key );
	}

	// ─── Internal Helpers ────────────────────────────────────────────────────

	/**
	 * Mark an OAuth provider as needing re-authorization.
	 *
	 * @param string $provider 'gmail' or 'outlook'.
	 */
	private static function mark_needs_reauth( $provider ) {
		$email_inbound = Settings::get( 'email_inbound', array() );

		if ( isset( $email_inbound['oauth'][ $provider ] ) ) {
			$email_inbound['oauth'][ $provider ]['needs_reauth'] = true;
			Settings::update( 'email_inbound', $email_inbound );
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
								scope: 'shared',
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
