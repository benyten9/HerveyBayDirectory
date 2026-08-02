<?php
/**
 * REST Settings Controller Pro
 *
 * Handles Pro-specific settings endpoints, particularly bounce webhooks,
 * email inbound settings, and OAuth 2.0 for Gmail/Outlook IMAP.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\Settings\Rest;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\Inbox\Services\BounceHandlerManager;
use DoubleScale\Pro\Modules\Inbox\Incoming\MessagingIncoming;
use DoubleScale\Pro\Modules\Inbox\Oauth\EmailOauth;
use DoubleScale\Modules\Tracking\ImapClient;
use DoubleScale\Core\Settings\Settings;
use WP_REST_Response;
use WP_Error;

/**
 * RestSettingsControllerPro class
 */
class RestSettingsControllerPro {

	/**
	 * Pro add-on ships Inbox (OAuth, bounce handlers, messaging webhooks) and Tracking (IMAP client).
	 * Free-only installs register these routes from CoreModule but must not reference missing classes.
	 */
	private static function has_email_oauth_layer(): bool {
		return class_exists( 'DoubleScale\\Pro\\Modules\\Inbox\\Oauth\\EmailOauth' );
	}

	private static function has_bounce_handler_manager(): bool {
		return class_exists( 'DoubleScale\\Pro\\Modules\\Inbox\\Services\\BounceHandlerManager' );
	}

	private static function has_messaging_incoming(): bool {
		return class_exists( 'DoubleScale\\Pro\\Modules\\Inbox\\Incoming\\MessagingIncoming' );
	}

	private static function has_imap_client(): bool {
		return class_exists( 'DoubleScale\\Modules\\Tracking\\ImapClient' );
	}

	private static function has_notifications_push_layer(): bool {
		return class_exists( 'DoubleScale\\Pro\\Modules\\Notifications\\Services\\PushNotificationService' );
	}

	private static function pro_mailbox_unavailable_error(): WP_Error {
		return new WP_Error(
			'doublescale_pro_required',
			__( 'This feature requires DoubleScale Pro (Inbox).', 'doublescale' ),
			array( 'status' => 501 )
		);
	}

	private static function pro_push_unavailable_error(): WP_Error {
		return new WP_Error(
			'doublescale_pro_required',
			__( 'This feature requires DoubleScale Pro (Notifications).', 'doublescale' ),
			array( 'status' => 501 )
		);
	}

	/**
	 * Full email-inbound payload for managers when OAuth layer is absent (avoids fatals on free-only).
	 *
	 * @param array<string, mixed> $settings Stored email_inbound.
	 * @param array<string, mixed> $defaults Default sending identity.
	 * @return array<string, mixed>
	 */
	private function email_inbound_settings_without_oauth_layer( array $settings, array $defaults ): array {
		if ( ! empty( $settings['imap'] ) && is_array( $settings['imap'] ) && ! empty( $settings['imap']['password'] ) ) {
			$settings['imap']['password'] = '********';
		}
		$settings['from_email']            = $settings['from_email'] ?? '';
		$settings['from_name']             = $settings['from_name'] ?? '';
		$settings['reply_to']              = $settings['reply_to'] ?? '';
		$settings['defaults']              = $defaults;
		$settings['oauth']                 = array(
			'gmail'   => array(
				'connected'    => false,
				'email'        => '',
				'needs_reauth' => false,
			),
			'outlook' => array(
				'connected'    => false,
				'email'        => '',
				'needs_reauth' => false,
			),
		);
		$settings['oauth_apps_configured'] = array(
			'gmail'   => false,
			'outlook' => false,
		);
		$settings['oauth_redirect_uri']    = '';
		$settings['imap_available']        = self::has_imap_client();
		$settings['smtp_detection']        = array(
			'has_smtp'           => false,
			'from_emails'        => array(),
			'gmail_detected'     => false,
			'gmail_accounts'     => array(),
			'gmail_app'          => array(),
			'outlook_detected'   => false,
			'outlook_accounts'   => array(),
			'outlook_app'        => array(),
			'detected_providers' => array(),
		);
		if ( ! isset( $settings['imap_provider'] ) ) {
			$settings['imap_provider'] = 'custom';
		}
		return $settings;
	}

	/**
	 * Register REST Api routes
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		register_rest_route(
			'doublescale/v1',
			'/settings/bounce-webhooks',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_bounce_webhooks' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'provider' => array(
						'description'       => __( 'Optional email provider slug to filter results (e.g., sendgrid, mailgun, postmark). If not provided, returns all providers.', 'doublescale' ),
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $param ) {
							// Allow only lowercase alphanumeric and hyphens.
							return preg_match( '/^[a-z0-9-]+$/', $param );
						},
					),
				),
			)
		);

		// Email inbound settings endpoints.
		register_rest_route(
			'doublescale/v1',
			'/settings/email-inbound',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_email_inbound_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_email_inbound_settings' ),
					'permission_callback' => array( $this, 'manage_shared_email_check' ),
				),
			)
		);

		register_rest_route(
			'doublescale/v1',
			'/settings/email-inbound/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_email_inbound_connection' ),
				'permission_callback' => array( $this, 'manage_shared_email_check' ),
			)
		);

		// OAuth endpoints for Gmail/Outlook IMAP.
		register_rest_route(
			'doublescale/v1',
			'/settings/email-inbound/oauth/authorize',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'oauth_authorize' ),
				'permission_callback' => array( $this, 'manage_shared_email_check' ),
			)
		);

		register_rest_route(
			'doublescale/v1',
			'/settings/email-inbound/oauth/disconnect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'oauth_disconnect' ),
				'permission_callback' => array( $this, 'manage_shared_email_check' ),
			)
		);

		// Centralized OAuth app credentials endpoints.
		register_rest_route(
			'doublescale/v1',
			'/settings/email-oauth-apps',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_email_oauth_apps' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_email_oauth_apps' ),
					'permission_callback' => array( $this, 'manage_shared_email_check' ),
				),
			)
		);

		// Mobile app (Firebase push notification) settings.
		register_rest_route(
			'doublescale/v1',
			'/settings/mobile-app',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_mobile_app_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_mobile_app_settings' ),
					'permission_callback' => function () {
						return current_user_can( 'doublescale_manage_settings' );
					},
					'args'                => array(
						'enabled' => array(
							'required' => true,
							'type'     => 'boolean',
						),
					),
				),
			)
		);

		register_rest_route(
			'doublescale/v1',
			'/settings/mobile-app/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_mobile_app_connection' ),
				'permission_callback' => function () {
					return current_user_can( 'doublescale_manage_settings' );
				},
			)
		);

		register_rest_route(
			'doublescale/v1',
			'/settings/mobile-app/test-push',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_mobile_app_push' ),
				'permission_callback' => function () {
					return current_user_can( 'doublescale_access' );
				},
			)
		);

		register_rest_route(
			'doublescale/v1',
			'/settings/messaging-webhooks',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_messaging_webhooks' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'channel' => array(
						'description'       => __( 'Optional channel to filter results (e.g., sms, whatsapp). If not provided, returns all channels.', 'doublescale' ),
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $param ) {
							return in_array( $param, array( 'sms', 'whatsapp' ), true );
						},
					),
				),
			)
		);
	}

	/**
	 * Get bounce webhook URLs
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_bounce_webhooks( $request ) {
		if ( ! self::has_bounce_handler_manager() ) {
			return self::pro_mailbox_unavailable_error();
		}
		$manager  = BounceHandlerManager::instance();
		$urls     = $manager->get_webhook_urls();
		$provider = $request->get_param( 'provider' );

		// If no provider specified, return all webhooks.
		if ( empty( $provider ) ) {
			return new WP_REST_Response( $urls, 200 );
		}

		// Provider specified - validate and return single webhook.
		if ( ! isset( $urls[ $provider ] ) ) {
			return new WP_Error(
				'invalid_provider',
				sprintf(
					/* translators: 1: provider slug, 2: available providers */
					__( 'Provider "%1$s" not found. Available providers: %2$s', 'doublescale' ),
					$provider,
					implode( ', ', array_keys( $urls ) )
				),
				array( 'status' => 404 )
			);
		}

		// Return single provider webhook.
		return new WP_REST_Response(
			array(
				'provider' => $provider,
				'name'     => $urls[ $provider ]['name'],
				'url'      => $urls[ $provider ]['url'],
			),
			200
		);
	}

	/**
	 * Get messaging webhook URLs (Sms, WhatsApp)
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_messaging_webhooks( $request ) {
		if ( ! self::has_messaging_incoming() ) {
			return self::pro_mailbox_unavailable_error();
		}
		$channel = $request->get_param( 'channel' );

		// Build webhook URLs for all messaging channels
		$webhooks = array(
			'sms'      => array(
				'name'        => __( 'Sms Incoming Messages', 'doublescale' ),
				'url'         => MessagingIncoming::get_webhook_url( 'sms' ),
				'description' => __( 'Configure this URL in your Twilio phone number settings under "A MESSAGE COMES IN"', 'doublescale' ),
			),
			'whatsapp' => array(
				'name'        => __( 'Whatsapp Incoming Messages', 'doublescale' ),
				'url'         => MessagingIncoming::get_webhook_url( 'whatsapp' ),
				'description' => __( 'Configure this URL in your Meta WhatsApp Business webhook settings', 'doublescale' ),
			),
		);

		// If channel specified, return only that channel
		if ( ! empty( $channel ) ) {
			if ( ! isset( $webhooks[ $channel ] ) ) {
				return new WP_Error(
					'invalid_channel',
					sprintf(
						/* translators: 1: channel slug, 2: available channels */
						__( 'Channel "%1$s" not found. Available channels: %2$s', 'doublescale' ),
						$channel,
						implode( ', ', array_keys( $webhooks ) )
					),
					array( 'status' => 404 )
				);
			}

			return new WP_REST_Response(
				array(
					'channel'     => $channel,
					'name'        => $webhooks[ $channel ]['name'],
					'url'         => $webhooks[ $channel ]['url'],
					'description' => $webhooks[ $channel ]['description'],
				),
				200
			);
		}

		// Return all webhooks
		return new WP_REST_Response( $webhooks, 200 );
	}

	/**
	 * Get email inbound settings
	 *
	 * @since 1.0.0
	 *
	 * @return WP_REST_Response
	 */
	public function get_email_inbound_settings() {
		$settings = Settings::get( 'email_inbound', array() );

		$defaults = array(
			'from_email' => get_option( 'admin_email' ),
			'from_name'  => get_bloginfo( 'name' ),
			'reply_to'   => get_option( 'admin_email' ),
		);

		// Sales Reps and Sales Managers only need the sending identity for the email composer.
		if ( ! current_user_can( 'doublescale_manage_settings' ) ) {
			return new WP_REST_Response(
				array(
					'from_email' => $settings['from_email'] ?? '',
					'from_name'  => $settings['from_name'] ?? '',
					'reply_to'   => $settings['reply_to'] ?? '',
					'defaults'   => $defaults,
				),
				200
			);
		}

		if ( ! self::has_email_oauth_layer() ) {
			return new WP_REST_Response(
				$this->email_inbound_settings_without_oauth_layer( $settings, $defaults ),
				200
			);
		}

		// Never expose passwords in responses — mask them.
		if ( ! empty( $settings['imap'] ) && ! empty( $settings['imap']['password'] ) ) {
			$settings['imap']['password'] = '********';
		}

		// Ensure sending identity fields are present.
		$settings['from_email'] = $settings['from_email'] ?? '';
		$settings['from_name']  = $settings['from_name'] ?? '';
		$settings['reply_to']   = $settings['reply_to'] ?? '';

		$settings['defaults'] = $defaults;

		// Sanitize OAuth data — only expose connection status, not credentials.
		$oauth           = $settings['oauth'] ?? array();
		$sanitized_oauth = array();

		foreach ( array( 'gmail', 'outlook' ) as $provider ) {
			$provider_data = $oauth[ $provider ] ?? array();

			$sanitized_oauth[ $provider ] = array(
				'connected'    => EmailOauth::is_connected( $provider ),
				'email'        => $provider_data['email'] ?? '',
				'needs_reauth' => ! empty( $provider_data['needs_reauth'] ),
			);
		}
		$settings['oauth'] = $sanitized_oauth;

		// Indicate which providers have centralized OAuth apps configured (read-through from smtp).
		$settings['oauth_apps_configured'] = array(
			'gmail'   => ! empty( EmailOauth::get_oauth_app_credentials( 'gmail' )['client_id'] ),
			'outlook' => ! empty( EmailOauth::get_oauth_app_credentials( 'outlook' )['client_id'] ),
		);

		// Include metadata for frontend display.
		$settings['imap_available']     = true; // Always true — php-imap2 is bundled (no ext-imap needed).
		$settings['oauth_redirect_uri'] = EmailOauth::get_redirect_uri();

		// Default imap_provider to 'custom' so the UI never receives an
		// undefined value.
		if ( ! isset( $settings['imap_provider'] ) ) {
			$settings['imap_provider'] = 'custom';
		}

		// Detect SMTP configuration for frontend guidance.
		$settings['smtp_detection'] = self::detect_smtp_configuration();

		return new WP_REST_Response( $settings, 200 );
	}

	/**
	 * Save email inbound settings
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_email_inbound_settings( $request ) {
		$body = $request->get_json_params();

		if ( empty( $body ) ) {
			return new WP_Error( 'invalid_data', __( 'No settings provided.', 'doublescale' ), array( 'status' => 400 ) );
		}

		// Get existing settings to preserve masked passwords and OAuth tokens.
		$existing = Settings::get( 'email_inbound', array() );

		$from_email = sanitize_email( $body['from_email'] ?? '' );
		$has_pro    = self::has_email_oauth_layer();

		// Free: persist sending identity only; leave all IMAP / OAuth / polling fields untouched.
		// Pro: full payload — IMAP credentials, OAuth wiring, polling toggle, etc.
		if ( ! $has_pro ) {
			$settings = array_merge(
				$existing,
				array(
					'from_email' => $from_email,
					'from_name'  => sanitize_text_field( $body['from_name'] ?? '' ),
					'reply_to'   => sanitize_email( $body['reply_to'] ?? '' ),
				)
			);

			Settings::update( 'email_inbound', $settings );

			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Sending identity saved.', 'doublescale' ),
				),
				200
			);
		}

		// Auto-resolve the IMAP provider and account from from_email.
		// This way the admin never has to manually pick an IMAP account —
		// saving the sending identity automatically wires up the correct one.
		$resolved = self::resolve_imap_provider_for_email( $from_email );

		$settings = array(
			'enabled'              => ! empty( $body['enabled'] ),
			'auto_create_contacts' => ! empty( $body['auto_create_contacts'] ),
			'sync_sent'            => ! empty( $body['sync_sent'] ),
			'excluded_domains'     => array_values(
				array_unique(
					array_filter(
						array_map(
							function ( $domain ) {
								return strtolower( trim( sanitize_text_field( $domain ) ) );
							},
							(array) ( $body['excluded_domains'] ?? array() )
						)
					)
				)
			),
			'from_email'           => $from_email,
			'from_name'            => sanitize_text_field( $body['from_name'] ?? '' ),
			'reply_to'             => sanitize_email( $body['reply_to'] ?? '' ),
			'imap_provider'        => $resolved['imap_provider'],
			'smtp_gmail_account'   => $resolved['smtp_gmail_account'],
			'smtp_outlook_account' => $resolved['smtp_outlook_account'],
			'imap'                 => array(
				'host'        => sanitize_text_field( $body['imap']['host'] ?? '' ),
				'port'        => absint( $body['imap']['port'] ?? 993 ),
				'encryption'  => sanitize_text_field( $body['imap']['encryption'] ?? 'ssl' ),
				'username'    => sanitize_text_field( $body['imap']['username'] ?? '' ),
				'password'    => ( $body['imap']['password'] ?? '' ) === '********'
					? ( ( $existing['imap'] ?? array() )['password'] ?? '' )
					: ( $body['imap']['password'] ?? '' ),
				'sent_folder' => sanitize_text_field( $body['imap']['sent_folder'] ?? 'Sent' ),
			),
		);

		// Preserve OAuth tokens (managed by OAuth flow, not settings save).
		// Credentials are centralized in email_oauth_apps — not stored here.
		$settings['oauth'] = $existing['oauth'] ?? array();

		Settings::update( 'email_inbound', $settings );

		// Manage Action Scheduler task based on new settings.
		$campaigns_tasks = \DoubleScale\Core\PluginKernel::instance()->campaigns_tasks;
		if ( ! empty( $settings['enabled'] ) ) {
			if ( $campaigns_tasks->get_next_timestamp( 'doublescale_email_inbound' ) === false ) {
				$campaigns_tasks->schedule_recurring( time(), 60, 'doublescale_email_inbound' );
			}
		} else {
			$campaigns_tasks->unschedule_all( 'doublescale_email_inbound' );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Inbox settings saved.', 'doublescale' ),
			),
			200
		);
	}

	/**
	 * Test email inbound IMAP connection
	 *
	 * Supports custom IMAP, Gmail OAuth, and Outlook OAuth providers.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test_email_inbound_connection( $request ) {
		$body     = $request->get_json_params();
		$provider = sanitize_text_field( $body['provider'] ?? 'custom' );

		if ( in_array( $provider, array( 'gmail', 'outlook', 'smtp_gmail', 'smtp_outlook' ), true ) ) {
			if ( ! self::has_email_oauth_layer() || ! self::has_imap_client() ) {
				return self::pro_mailbox_unavailable_error();
			}
		} elseif ( ! self::has_imap_client() ) {
			return self::pro_mailbox_unavailable_error();
		}

		if ( in_array( $provider, array( 'gmail', 'outlook' ), true ) ) {
			return $this->test_oauth_connection( $provider );
		}

		if ( 'smtp_gmail' === $provider ) {
			return $this->test_smtp_gmail_connection( $body );
		}

		if ( 'smtp_outlook' === $provider ) {
			return $this->test_smtp_outlook_connection( $body );
		}

		return $this->test_custom_imap_connection( $body );
	}

	/**
	 * Test a custom IMAP connection with provided credentials.
	 *
	 * @param array $body Request body.
	 * @return WP_REST_Response
	 */
	private function test_custom_imap_connection( $body ) {
		// Get existing settings to resolve masked passwords.
		$existing = Settings::get( 'email_inbound', array() );

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
			// Count only recent unseen mail (today onward) — see ImapClient::count_unseen().
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
	 * Test an OAuth IMAP connection (Gmail/Outlook).
	 *
	 * @param string $provider 'gmail' or 'outlook'.
	 * @return WP_REST_Response
	 */
	private function test_oauth_connection( $provider ) {
		if ( ! EmailOauth::is_connected( $provider ) ) {
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

		// Outlook receives over Microsoft Graph; Gmail still uses IMAP+XOAUTH2.
		// Test the transport the poller actually uses for each provider.
		if ( 'outlook' === $provider ) {
			$graph_config = EmailOauth::get_graph_config();
			$graph_class  = '\DoubleScale\Pro\Modules\Inbox\Incoming\GraphMailClient';

			if ( ! $graph_config || ! class_exists( $graph_class ) ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => __( 'Failed to get Outlook Graph configuration. Token may have expired — try reconnecting.', 'doublescale' ),
					),
					200
				);
			}

			try {
				$client = $graph_class::from_config( $graph_config );
				$client->connect();
				$unseen_count = $client->count_unseen( gmdate( 'Y-m-d' ) );
				$client->disconnect();

				return new WP_REST_Response(
					array(
						'success'      => true,
						'message'      => sprintf(
							/* translators: %d: number of recent unseen emails */
							__( 'Outlook connected successfully via Microsoft Graph. Found %d new unseen email(s) today.', 'doublescale' ),
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

		$config = EmailOauth::get_imap_config( $provider );
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
	 * Test IMAP connection using smtp's Gmail OAuth tokens.
	 *
	 * @since 1.0.0
	 *
	 * @param array $body Request body (may contain account_id).
	 * @return WP_REST_Response
	 */
	private function test_smtp_gmail_connection( $body ) {
		$account_id = sanitize_text_field( $body['account_id'] ?? '' );
		$config     = self::get_smtp_gmail_imap_config( $account_id );

		if ( ! $config ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'smtp Gmail connection not available. Ensure Gmail is configured in smtp with valid OAuth credentials.', 'doublescale' ),
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
			// Count only recent unseen mail (today onward). A raw UNSEEN count on
			// Gmail can be thousands — its INBOX keeps a large backlog of mail
			// read on the web but never IMAP-`\Seen`-flagged — which is both
			// alarming and irrelevant: the poller ingests only mail that arrives
			// after the mailbox is connected. See ImapClient::count_unseen().
			$unseen_count = $client->count_unseen( gmdate( 'Y-m-d' ) );
			$client->disconnect();

			return new WP_REST_Response(
				array(
					'success'      => true,
					'message'      => sprintf(
						/* translators: 1: email address, 2: number of recent unseen emails */
						__( 'Gmail (%1$s) connected via smtp. Found %2$d new unseen email(s) today.', 'doublescale' ),
						$config['username'],
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
	 * Test IMAP connection using smtp Pro's Outlook OAuth tokens.
	 *
	 * @since 1.0.0
	 *
	 * @param array $body Request body (may contain account_id).
	 * @return WP_REST_Response
	 */
	private function test_smtp_outlook_connection( $body ) {
		$account_id = sanitize_text_field( $body['account_id'] ?? '' );

		// Outlook *receives* over Microsoft Graph, not IMAP — test the path the
		// poller actually uses (GraphMailClient), so the result matches reality.
		// Testing IMAP here would fail with "User is authenticated but not
		// connected" for personal mailboxes even though Graph receive works.
		$graph_config = self::get_smtp_outlook_graph_config( $account_id );
		$graph_class  = '\DoubleScale\Pro\Modules\Inbox\Incoming\GraphMailClient';

		if ( ! $graph_config || ! class_exists( $graph_class ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'smtp Outlook connection not available. Ensure Outlook is configured in smtp with valid OAuth credentials.', 'doublescale' ),
				),
				200
			);
		}

		try {
			$client = $graph_class::from_config( $graph_config );
			$client->connect();
			// Count only recent unseen mail (today onward), as the IMAP branch does.
			$unseen_count = $client->count_unseen( gmdate( 'Y-m-d' ) );
			$client->disconnect();

			return new WP_REST_Response(
				array(
					'success'      => true,
					'message'      => sprintf(
						/* translators: 1: email address, 2: number of recent unseen emails */
						__( 'Outlook (%1$s) connected via Microsoft Graph. Found %2$d new unseen email(s) today.', 'doublescale' ),
						$graph_config['email'],
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

	// ─── OAuth Endpoints ─────────────────────────────────────────────────────

	/**
	 * Get OAuth authorization URL for a provider (opens in popup).
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function oauth_authorize( $request ) {
		if ( ! self::has_email_oauth_layer() ) {
			return self::pro_mailbox_unavailable_error();
		}
		$body     = $request->get_json_params();
		$provider = sanitize_text_field( $body['provider'] ?? '' );

		if ( ! in_array( $provider, array( 'gmail', 'outlook' ), true ) ) {
			return new WP_Error( 'invalid_provider', __( 'Invalid OAuth provider.', 'doublescale' ), array( 'status' => 400 ) );
		}

		// Uses centralized admin-configured credentials from email_oauth_apps.
		$authorization_url = EmailOauth::get_authorization_url( $provider );

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
	 * Disconnect an OAuth provider.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function oauth_disconnect( $request ) {
		if ( ! self::has_email_oauth_layer() ) {
			return self::pro_mailbox_unavailable_error();
		}
		$body     = $request->get_json_params();
		$provider = sanitize_text_field( $body['provider'] ?? '' );

		if ( ! in_array( $provider, array( 'gmail', 'outlook' ), true ) ) {
			return new WP_Error( 'invalid_provider', __( 'Invalid OAuth provider.', 'doublescale' ), array( 'status' => 400 ) );
		}

		EmailOauth::disconnect( $provider );

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

	// ─── Centralized OAuth App Endpoints ────────────────────────────────────

	/**
	 * Get centralized email OAuth app credentials.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_REST_Response
	 */
	public function get_email_oauth_apps() {
		if ( ! self::has_email_oauth_layer() ) {
			return self::pro_mailbox_unavailable_error();
		}
		$result = array();
		foreach ( array( 'gmail', 'outlook' ) as $provider ) {
			$creds     = EmailOauth::get_oauth_app_credentials( $provider );
			$has_creds = ! empty( $creds['client_id'] ) && ! empty( $creds['client_secret'] );

			$result[ $provider ] = array(
				'client_id'  => $creds['client_id'] ?? '',
				'has_secret' => $has_creds,
			);
		}

		$result['oauth_redirect_uri'] = EmailOauth::get_redirect_uri();

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Save email OAuth app credentials directly to smtp.
	 *
	 * Requires both client_id and client_secret. Writes to smtp only —
	 * CRM does not store its own copy.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_email_oauth_apps( $request ) {
		if ( ! self::has_email_oauth_layer() ) {
			return self::pro_mailbox_unavailable_error();
		}
		if ( ! EmailOauth::smtp_oauth_storage_available() ) {
			return new WP_Error( 'smtp_required', __( 'SMTP storage backend is unavailable; cannot save email credentials.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$body = $request->get_json_params();
		if ( empty( $body ) ) {
			return new WP_Error( 'invalid_data', __( 'No settings provided.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$warnings           = array();
		$total_disconnected = 0;

		foreach ( array( 'gmail', 'outlook' ) as $provider ) {
			if ( empty( $body[ $provider ] ) || ! is_array( $body[ $provider ] ) ) {
				continue;
			}

			$new_client_id     = sanitize_text_field( $body[ $provider ]['client_id'] ?? '' );
			$new_client_secret = $body[ $provider ]['client_secret'] ?? '';

			// Both fields required.
			if ( empty( $new_client_id ) || empty( $new_client_secret ) ) {
				return new WP_Error(
					'missing_credentials',
					sprintf(
						/* translators: %s: provider name */
						__( 'Both Client ID and Client Secret are required for %s.', 'doublescale' ),
						ucfirst( $provider )
					),
					array( 'status' => 400 )
				);
			}

			// Read existing from smtp for change detection.
			$existing          = EmailOauth::get_oauth_app_credentials( $provider );
			$old_client_id     = $existing['client_id'] ?? '';
			$old_client_secret = $existing['client_secret'] ?? '';

			$credentials_changed = ( ! empty( $old_client_id ) || ! empty( $old_client_secret ) )
				&& ( $new_client_id !== $old_client_id || $new_client_secret !== $old_client_secret );

			// Write directly to the active SMTP storage backend (standalone or bundled).
			$option_name     = EmailOauth::mailer_settings_option_name( $provider );
			$mailer_settings = get_option( $option_name, array() );
			if ( ! is_array( $mailer_settings ) ) {
				$mailer_settings = array();
			}

			if ( ! isset( $mailer_settings['app'] ) ) {
				$mailer_settings['app'] = array();
			}

			$mailer_settings['app']['client_id']     = $new_client_id;
			$mailer_settings['app']['client_secret'] = $new_client_secret;

			update_option( $option_name, $mailer_settings );

			// If credentials changed, disconnect all CRM-managed users for this provider.
			if ( $credentials_changed ) {
				$disconnected        = self::disconnect_all_users_for_provider( $provider );
				$total_disconnected += $disconnected;

				if ( $disconnected > 0 ) {
					$warnings[] = sprintf(
						/* translators: 1: provider name, 2: number of disconnected users */
						__( '%1$s credentials changed. %2$d user(s) have been disconnected and will need to re-connect.', 'doublescale' ),
						ucfirst( $provider ),
						$disconnected
					);
				}
			}
		}

		$response = array(
			'success' => true,
			'message' => __( 'OAuth app credentials saved.', 'doublescale' ),
		);

		if ( ! empty( $warnings ) ) {
			$response['warnings']     = $warnings;
			$response['disconnected'] = $total_disconnected;
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Disconnect all CRM-managed users for a provider when credentials change.
	 *
	 * Removes smtp accounts/connections with user_id and clears user meta tokens.
	 *
	 * @since 1.0.0
	 *
	 * @param string $provider 'gmail' or 'outlook'.
	 * @return int Number of users disconnected.
	 */
	private static function disconnect_all_users_for_provider( $provider ) {
		$mailer_slug        = $provider;
		$disconnected_count = 0;

		// 1. Remove CRM-managed accounts from the active SMTP backend.
		if ( EmailOauth::smtp_oauth_storage_available() ) {
			// Global lock to prevent read-modify-write races with concurrent user syncs.
			$lock_key = 'doublescale_smtp_sync';
			$retries  = 0;
			while ( get_transient( $lock_key ) && $retries < 5 ) {
				usleep( 500000 );
				++$retries;
			}
			set_transient( $lock_key, true, 30 );

			$option_name     = EmailOauth::mailer_settings_option_name( $mailer_slug );
			$mailer_settings = get_option( $option_name, array() );
			if ( ! is_array( $mailer_settings ) ) {
				$mailer_settings = array();
			}
			$accounts = $mailer_settings['accounts'] ?? array();

			foreach ( $accounts as $account_id => $account_data ) {
				if ( ! empty( $account_data['user_id'] ) ) {
					unset( $accounts[ $account_id ] );
				}
			}
			$mailer_settings['accounts'] = $accounts;
			update_option( $option_name, $mailer_settings );

			// 2. Remove CRM-managed connections from the active SMTP backend.
			$routing_option = EmailOauth::smtp_routing_option_name();
			$smtp_settings  = get_option( $routing_option, array() );
			if ( ! is_array( $smtp_settings ) ) {
				$smtp_settings = array();
			}
			$connections = $smtp_settings['connections'] ?? array();

			foreach ( $connections as $conn_id => $conn ) {
				if ( ! empty( $conn['user_id'] ) && ( $conn['mailer'] ?? '' ) === $mailer_slug ) {
					unset( $connections[ $conn_id ] );
				}
			}
			$smtp_settings['connections'] = $connections;
			update_option( $routing_option, $smtp_settings );

			// 2b. Warn about non-user smtp accounts that may have stale tokens.
			// We can't distinguish CRM-synced shared accounts from manually-created
			// smtp accounts, so log a warning instead of auto-deleting.
			$non_user_accounts = array();
			foreach ( $accounts as $account_id => $account_data ) {
				if ( empty( $account_data['user_id'] ) ) {
					$non_user_accounts[] = $account_data['name'] ?? $account_id;
				}
			}
			if ( ! empty( $non_user_accounts ) ) {
				doublescale_get_logger()->warning(
					'OAuth app credentials changed. Non-user smtp accounts may need re-authorization.',
					array(
						'provider' => $provider,
						'accounts' => $non_user_accounts,
					)
				);
			}

			delete_transient( $lock_key );
		}

		// 2c. Clear shared email (Email Inbound) OAuth tokens for this provider.
		// These tokens are also bound to the old OAuth app.
		$email_inbound = Settings::get( 'email_inbound', array() );
		if ( ! empty( $email_inbound['oauth'][ $provider ]['access_token'] ) ) {
			$email_inbound['oauth'][ $provider ] = array();
			Settings::update( 'email_inbound', $email_inbound );
		}

		// 3. Clear OAuth tokens from CRM user meta.
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

		foreach ( $users as $uid ) {
			$account = get_user_meta( $uid, 'doublescale_user_email_account', true );
			if ( ! is_array( $account ) || empty( $account['oauth'][ $provider ]['access_token'] ) ) {
				continue;
			}

			// Clear tokens AND auto-disable to prevent poller errors.
			$account['oauth'][ $provider ] = array();
			$account['enabled']            = false;
			$account['from_email']         = '';

			update_user_meta( $uid, 'doublescale_user_email_account', $account );
			++$disconnected_count;
		}

		// Clear scheduler flag if no enabled users remain.
		if ( $disconnected_count > 0 ) {
			$any_enabled = false;
			foreach ( $users as $uid ) {
				$acct = get_user_meta( $uid, 'doublescale_user_email_account', true );
				if ( is_array( $acct ) && ! empty( $acct['enabled'] ) ) {
					$any_enabled = true;
					break;
				}
			}
			if ( ! $any_enabled ) {
				delete_option( 'doublescale_has_user_email_accounts' );
				$campaigns_tasks = \DoubleScale\Core\PluginKernel::instance()->campaigns_tasks;
				$campaigns_tasks->unschedule_all( 'doublescale_user_email_accounts' );
			}
		}

		return $disconnected_count;
	}

	/**
	 * Resolve the IMAP provider and account ID that match a given from_email.
	 *
	 * Checks smtp Gmail and Outlook accounts in order. If a match is found
	 * the correct provider and account ID are returned so the admin never has to
	 * pick them manually. Falls back to preserving 'custom' for manual IMAP.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from_email The sending identity email address.
	 * @return array {
	 *     @type string $imap_provider            'smtp_gmail', 'smtp_outlook', or 'custom'.
	 *     @type string $smtp_gmail_account  Matched Gmail account ID, or empty string.
	 *     @type string $smtp_outlook_account Matched Outlook account ID, or empty string.
	 * }
	 */
	public static function resolve_imap_provider_for_email( $from_email ) {
		$result = array(
			'imap_provider'        => 'custom',
			'smtp_gmail_account'   => '',
			'smtp_outlook_account' => '',
		);

		if ( empty( $from_email ) ) {
			return $result;
		}

		$normalized = strtolower( $from_email );

		// Steps 1 & 2 read smtp's OAuth account store via EmailOauth, a Pro class.
		// Guard the whole block so free-only installs (Pro disabled) skip straight
		// to the standalone-OAuth fallback below instead of fataling on the missing
		// symbol — see has_email_oauth_layer().
		if ( self::has_email_oauth_layer() ) {
			// 1. Check SMTP Gmail accounts (preferred — handles token refresh).
			$gmail_accounts = get_option( EmailOauth::mailer_settings_option_name( 'gmail' ), array() )['accounts'] ?? array();
			foreach ( $gmail_accounts as $account_id => $account_data ) {
				$creds = $account_data['credentials'] ?? array();
				if ( empty( $creds['access_token'] ) || empty( $creds['refresh_token'] ) ) {
					continue;
				}
				$account_email = strtolower( $account_data['name'] ?? '' );
				if ( $account_email === $normalized ) {
					$result['imap_provider']      = 'smtp_gmail';
					$result['smtp_gmail_account'] = $account_id;
					return $result;
				}
			}

			// 2. Check SMTP Outlook accounts.
			$outlook_accounts = get_option( EmailOauth::mailer_settings_option_name( 'outlook' ), array() )['accounts'] ?? array();
			foreach ( $outlook_accounts as $account_id => $account_data ) {
				$creds = $account_data['credentials'] ?? array();
				if ( empty( $creds['access_token'] ) || empty( $creds['refresh_token'] ) ) {
					continue;
				}
				$account_email = strtolower( $account_data['name'] ?? '' );
				if ( $account_email === $normalized ) {
					$result['imap_provider']        = 'smtp_outlook';
					$result['smtp_outlook_account'] = $account_id;
					return $result;
				}
			}
		}

		// 3. Check standalone OAuth connections (when smtp is not installed).
		$email_inbound = Settings::get( 'email_inbound', array() );
		foreach ( array( 'gmail', 'outlook' ) as $provider ) {
			$oauth_data = $email_inbound['oauth'][ $provider ] ?? array();
			if ( ! empty( $oauth_data['access_token'] ) && empty( $oauth_data['needs_reauth'] ) ) {
				$oauth_email = strtolower( $oauth_data['email'] ?? '' );
				if ( $oauth_email === $normalized ) {
					$result['imap_provider'] = $provider;
					return $result;
				}
			}
		}

		return $result;
	}

	/**
	 * Detect the user's SMTP configuration from smtp (or absence thereof).
	 *
	 * Reads smtp's settings to identify configured from-emails and
	 * whether a Gmail OAuth account is available for IMAP reuse.
	 *
	 * @since 1.0.0
	 *
	 * @return array Detection result with from_emails, gmail info, etc.
	 */
	public static function detect_smtp_configuration() {
		$result = array(
			'has_smtp'           => EmailOauth::smtp_oauth_storage_available(),
			'from_emails'        => array(),
			'gmail_detected'     => false,
			'gmail_accounts'     => array(),
			'gmail_app'          => array(),
			'outlook_detected'   => false,
			'outlook_accounts'   => array(),
			'outlook_app'        => array(),
			'detected_providers' => array(),
		);

		if ( ! $result['has_smtp'] ) {
			return $result;
		}

		// Extract from-emails and connection metadata from SMTP connections.
		$smtp_settings = get_option( EmailOauth::smtp_routing_option_name(), array() );
		$connections   = is_array( $smtp_settings ) && isset( $smtp_settings['connections'] ) && is_array( $smtp_settings['connections'] ) ? $smtp_settings['connections'] : array();

		foreach ( $connections as $connection ) {
			if ( ! empty( $connection['from_email'] ) && is_email( $connection['from_email'] ) ) {
				$result['from_emails'][] = strtolower( $connection['from_email'] );
			}
		}
		$result['from_emails'] = array_unique( $result['from_emails'] );

		// Check for Gmail OAuth accounts in the SMTP backend.
		// SMTP stores client_id/client_secret in global 'app' settings (centralized).
		$gmail_settings = get_option( EmailOauth::mailer_settings_option_name( 'gmail' ), array() );
		$gmail_app      = $gmail_settings['app'] ?? array();
		$gmail_accounts = $gmail_settings['accounts'] ?? array();
		$has_gmail_app  = ! empty( $gmail_app['client_id'] ) && ! empty( $gmail_app['client_secret'] );

		if ( $has_gmail_app && ! empty( $gmail_accounts ) ) {
			foreach ( $gmail_accounts as $account_id => $account_data ) {
				$creds      = $account_data['credentials'] ?? array();
				$has_tokens = ! empty( $creds['access_token'] ) && ! empty( $creds['refresh_token'] );

				if ( $has_tokens ) {
					$result['gmail_detected']   = true;
					$result['gmail_app']        = array(
						'client_id'  => $gmail_app['client_id'],
						'has_secret' => true,
					);
					$result['gmail_accounts'][] = array(
						'id'    => $account_id,
						'email' => $account_data['name'] ?? ( $account_id . '@gmail.com' ),
					);
				}
			}
		}

		// Check for Outlook OAuth accounts in the SMTP backend.
		// SMTP stores client_id/client_secret in global 'app' settings (centralized).
		$outlook_settings = get_option( EmailOauth::mailer_settings_option_name( 'outlook' ), array() );
		$outlook_app      = $outlook_settings['app'] ?? array();
		$outlook_accounts = $outlook_settings['accounts'] ?? array();
		$has_outlook_app  = ! empty( $outlook_app['client_id'] ) && ! empty( $outlook_app['client_secret'] );

		if ( $has_outlook_app && ! empty( $outlook_accounts ) ) {
			foreach ( $outlook_accounts as $account_id => $account_data ) {
				$creds      = $account_data['credentials'] ?? array();
				$has_tokens = ! empty( $creds['access_token'] ) && ! empty( $creds['refresh_token'] );

				if ( $has_tokens ) {
					$result['outlook_detected']   = true;
					$result['outlook_app']        = array(
						'client_id'  => $outlook_app['client_id'],
						'has_secret' => true,
					);
					$result['outlook_accounts'][] = array(
						'id'    => $account_id,
						'email' => $account_data['name'] ?? $account_id,
					);
				}
			}
		}

		// smtp mailer slug → IMAP mapping for providers that have IMAP servers.
		// Gmail and Outlook are handled above via dedicated OAuth integration.
		// Transactional-only providers (SendGrid, Mailgun, Postmark, etc.) have no IMAP.
		$mailer_to_imap_map = array(
			'zoho' => array(
				'host'       => 'imap.zoho.com',
				'port'       => 993,
				'encryption' => 'ssl',
				'provider'   => 'Zoho Mail',
			),
		);

		$seen_emails = array();
		foreach ( $connections as $connection ) {
			$from_email = strtolower( $connection['from_email'] ?? '' );
			$mailer     = $connection['mailer'] ?? '';

			if ( empty( $from_email ) || ! is_email( $from_email ) || isset( $seen_emails[ $from_email ] ) ) {
				continue;
			}

			// Skip Gmail/Outlook — those have dedicated OAuth integration above.
			if ( in_array( $mailer, array( 'gmail', 'outlook' ), true ) ) {
				continue;
			}

			if ( isset( $mailer_to_imap_map[ $mailer ] ) ) {
				$imap_config                    = $mailer_to_imap_map[ $mailer ];
				$result['detected_providers'][] = array(
					'email'      => $from_email,
					'provider'   => $imap_config['provider'],
					'imap_host'  => $imap_config['host'],
					'imap_port'  => $imap_config['port'],
					'encryption' => $imap_config['encryption'],
				);
				$seen_emails[ $from_email ]     = true;
			}
		}

		return $result;
	}

	/**
	 * Get smtp Gmail IMAP config by reading tokens from smtp's storage.
	 *
	 * Refreshes the access token if expired, writing back to smtp's option.
	 *
	 * @since 1.0.0
	 *
	 * @param string $account_id Optional account ID. Uses first account if empty.
	 * @return array|false IMAP config array or false on failure.
	 */
	public static function get_smtp_gmail_imap_config( $account_id = '' ) {
		$gmail_option   = EmailOauth::mailer_settings_option_name( 'gmail' );
		$gmail_settings = get_option( $gmail_option, array() );
		$gmail_app      = $gmail_settings['app'] ?? array();
		$gmail_accounts = $gmail_settings['accounts'] ?? array();

		if ( empty( $gmail_accounts ) ) {
			return false;
		}

		// Use specified account or first available.
		if ( ! empty( $account_id ) && isset( $gmail_accounts[ $account_id ] ) ) {
			$account = $gmail_accounts[ $account_id ];
		} else {
			$account_id = array_key_first( $gmail_accounts );
			$account    = $gmail_accounts[ $account_id ];
		}

		$credentials = $account['credentials'] ?? array();
		if ( empty( $credentials['access_token'] ) || empty( $credentials['refresh_token'] ) ) {
			return false;
		}

		$client_id     = $gmail_app['client_id'] ?? '';
		$client_secret = $gmail_app['client_secret'] ?? '';

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return false;
		}

		$access_token = $credentials['access_token'];
		$email        = $account['name'] ?? ( $account_id . '@gmail.com' );

		// Check if token looks expired by attempting a lightweight refresh.
		// Google access tokens are typically arrays with 'access_token' and 'created' keys,
		// or simple strings depending on how smtp stores them.
		$needs_refresh = false;
		if ( is_array( $access_token ) ) {
			$created    = $access_token['created'] ?? 0;
			$expires_in = $access_token['expires_in'] ?? 3600;
			if ( $created > 0 && ( time() - $created ) >= ( $expires_in - 300 ) ) {
				$needs_refresh = true;
			}
			$token_string = $access_token['access_token'] ?? '';
		} else {
			$token_string  = $access_token;
			$needs_refresh = true;
		}

		if ( $needs_refresh ) {
			$lock_key = 'doublescale_smtp_gmail_refresh_' . $account_id;

			if ( get_transient( $lock_key ) ) {
				// Another process is refreshing — wait briefly then re-read.
				sleep( 2 );
				$gmail_settings = get_option( $gmail_option, array() );
				$refreshed      = $gmail_settings['accounts'][ $account_id ]['credentials']['access_token'] ?? '';
				$token_string   = is_array( $refreshed ) ? ( $refreshed['access_token'] ?? '' ) : $refreshed;
			} else {
				set_transient( $lock_key, true, 30 );

				$refresh_token = $credentials['refresh_token'];
				if ( is_array( $refresh_token ) ) {
					$refresh_token = $refresh_token['refresh_token'] ?? ( $refresh_token[0] ?? '' );
				}

				$response = wp_remote_post(
					'https://oauth2.googleapis.com/token',
					array(
						'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
						'body'    => http_build_query(
							array(
								'grant_type'    => 'refresh_token',
								'refresh_token' => $refresh_token,
								'client_id'     => $client_id,
								'client_secret' => $client_secret,
							)
						),
						'timeout' => 30,
					)
				);

				if ( ! is_wp_error( $response ) ) {
					$data = json_decode( wp_remote_retrieve_body( $response ), true );
					if ( ! empty( $data['access_token'] ) ) {
						$token_string = $data['access_token'];

						// Re-read to avoid overwriting concurrent changes by another writer.
						$gmail_settings = get_option( $gmail_option, array() );
						$gmail_accounts = $gmail_settings['accounts'] ?? array();

						$new_token = array(
							'access_token' => $data['access_token'],
							'expires_in'   => $data['expires_in'] ?? 3600,
							'created'      => time(),
							'scope'        => $data['scope'] ?? '',
							'token_type'   => $data['token_type'] ?? 'Bearer',
						);
						if ( ! empty( $data['refresh_token'] ) ) {
							$new_token['refresh_token']                                    = $data['refresh_token'];
							$gmail_accounts[ $account_id ]['credentials']['refresh_token'] = $data['refresh_token'];
						}
						$gmail_accounts[ $account_id ]['credentials']['access_token'] = $new_token;
						$gmail_settings['accounts']                                   = $gmail_accounts;
						update_option( $gmail_option, $gmail_settings );
					}
				}

				delete_transient( $lock_key );
			}
		}

		if ( empty( $token_string ) ) {
			return false;
		}

		return array(
			'host'           => 'imap.gmail.com',
			'port'           => 993,
			'username'       => $email,
			'password'       => $token_string,
			'encryption'     => 'ssl',
			'authentication' => 'oauth',
		);
	}

	/**
	 * Get smtp Outlook IMAP config by reading tokens from smtp Pro's storage.
	 *
	 * Refreshes the access token if expired, writing back to smtp's option.
	 *
	 * @since 1.0.0
	 *
	 * @param string $account_id Optional account ID. Uses first account if empty.
	 * @return array|false IMAP config array or false on failure.
	 */
	public static function get_smtp_outlook_imap_config( $account_id = '' ) {
		$outlook_option   = EmailOauth::mailer_settings_option_name( 'outlook' );
		$outlook_settings = get_option( $outlook_option, array() );
		$outlook_app      = $outlook_settings['app'] ?? array();
		$outlook_accounts = $outlook_settings['accounts'] ?? array();

		if ( empty( $outlook_accounts ) ) {
			return false;
		}

		// Use specified account or first available.
		if ( ! empty( $account_id ) && isset( $outlook_accounts[ $account_id ] ) ) {
			$account = $outlook_accounts[ $account_id ];
		} else {
			$account_id = array_key_first( $outlook_accounts );
			$account    = $outlook_accounts[ $account_id ];
		}

		$credentials = $account['credentials'] ?? array();
		if ( empty( $credentials['access_token'] ) || empty( $credentials['refresh_token'] ) ) {
			return false;
		}

		$client_id     = $outlook_app['client_id'] ?? '';
		$client_secret = $outlook_app['client_secret'] ?? '';

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return false;
		}

		$access_token = $credentials['access_token'];
		$email        = $account['name'] ?? $account_id;

		// Outlook tokens from smtp are typically stored as flat strings after token exchange,
		// but may also be arrays with expiry info depending on refresh cycles.
		$needs_refresh = false;
		if ( is_array( $access_token ) ) {
			$created    = $access_token['created'] ?? 0;
			$expires_in = $access_token['expires_in'] ?? 3600;
			if ( $created > 0 && ( time() - $created ) >= ( $expires_in - 300 ) ) {
				$needs_refresh = true;
			}
			$token_string = $access_token['access_token'] ?? '';
		} else {
			$token_string = $access_token;
			// Flat string tokens cannot be checked for expiry — always refresh.
			$needs_refresh = true;
		}

		if ( $needs_refresh ) {
			$lock_key = 'doublescale_smtp_outlook_refresh_' . $account_id;

			if ( get_transient( $lock_key ) ) {
				sleep( 2 );
				$outlook_settings = get_option( $outlook_option, array() );
				$refreshed        = $outlook_settings['accounts'][ $account_id ]['credentials']['access_token'] ?? '';
				$token_string     = is_array( $refreshed ) ? ( $refreshed['access_token'] ?? '' ) : $refreshed;
			} else {
				set_transient( $lock_key, true, 30 );

				$refresh_token = $credentials['refresh_token'];
				if ( is_array( $refresh_token ) ) {
					$refresh_token = $refresh_token['refresh_token'] ?? ( $refresh_token[0] ?? '' );
				}

				$response = wp_remote_post(
					'https://login.microsoftonline.com/common/oauth2/v2.0/token',
					array(
						'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
						'body'    => http_build_query(
							array(
								'grant_type'    => 'refresh_token',
								'refresh_token' => $refresh_token,
								'client_id'     => $client_id,
								'client_secret' => $client_secret,
								// FQDN must be outlook.office.com (NOT office365) for consumer accounts;
									// the office365 host is rejected and yields a token IMAP refuses.
									'scope'         => 'https://outlook.office.com/IMAP.AccessAsUser.All https://outlook.office.com/SMTP.Send offline_access',
							)
						),
						'timeout' => 30,
					)
				);

				if ( ! is_wp_error( $response ) ) {
					$data = json_decode( wp_remote_retrieve_body( $response ), true );
					if ( ! empty( $data['access_token'] ) ) {
						$token_string = $data['access_token'];

						// Re-read to avoid overwriting concurrent changes by another writer.
						$outlook_settings = get_option( $outlook_option, array() );
						$outlook_accounts = $outlook_settings['accounts'] ?? array();

						$new_token = array(
							'access_token' => $data['access_token'],
							'expires_in'   => $data['expires_in'] ?? 3600,
							'created'      => time(),
							'scope'        => $data['scope'] ?? '',
							'token_type'   => $data['token_type'] ?? 'Bearer',
						);
						if ( ! empty( $data['refresh_token'] ) ) {
							$new_token['refresh_token']                                      = $data['refresh_token'];
							$outlook_accounts[ $account_id ]['credentials']['refresh_token'] = $data['refresh_token'];
						}
						$outlook_accounts[ $account_id ]['credentials']['access_token'] = $new_token;
						$outlook_settings['accounts']                                   = $outlook_accounts;
						update_option( $outlook_option, $outlook_settings );
					}
				}

				delete_transient( $lock_key );
			}
		}

		if ( empty( $token_string ) ) {
			return false;
		}

		return array(
			'host'           => 'outlook.office365.com',
			'port'           => 993,
			'username'       => $email,
			'password'       => $token_string,
			'encryption'     => 'ssl',
			'authentication' => 'oauth',
		);
	}

	/**
	 * Get Microsoft Graph config for a smtp Outlook account.
	 *
	 * Parallels {@see get_smtp_outlook_imap_config()} but for the Graph receive
	 * path: returns the app client_id/secret and the account refresh token that
	 * {@see \DoubleScale\Pro\Modules\Inbox\Incoming\GraphMailClient} mints a
	 * Graph-audience token from. No token is minted here — the client does that
	 * at connect() and persists any rotation via the callback.
	 *
	 * @since 1.0.0
	 *
	 * @param string $account_id Optional account ID. Uses first account if empty.
	 * @return array{client_id:string, client_secret:string, refresh_token:string, email:string, on_refresh_token_rotated:callable}|false
	 */
	public static function get_smtp_outlook_graph_config( $account_id = '' ) {
		$outlook_option   = EmailOauth::mailer_settings_option_name( 'outlook' );
		$outlook_settings = get_option( $outlook_option, array() );
		$outlook_app      = $outlook_settings['app'] ?? array();
		$outlook_accounts = $outlook_settings['accounts'] ?? array();

		if ( empty( $outlook_accounts ) ) {
			return false;
		}

		if ( ! empty( $account_id ) && isset( $outlook_accounts[ $account_id ] ) ) {
			$account = $outlook_accounts[ $account_id ];
		} else {
			$account_id = array_key_first( $outlook_accounts );
			$account    = $outlook_accounts[ $account_id ];
		}

		$credentials   = $account['credentials'] ?? array();
		$refresh_token = $credentials['refresh_token'] ?? '';
		if ( is_array( $refresh_token ) ) {
			$refresh_token = $refresh_token['refresh_token'] ?? ( $refresh_token[0] ?? '' );
		}

		$client_id     = $outlook_app['client_id'] ?? '';
		$client_secret = $outlook_app['client_secret'] ?? '';

		if ( empty( $refresh_token ) || empty( $client_id ) || empty( $client_secret ) ) {
			return false;
		}

		return array(
			'client_id'                => $client_id,
			'client_secret'            => $client_secret,
			'refresh_token'            => $refresh_token,
			'email'                    => $account['name'] ?? $account_id,
			'on_refresh_token_rotated' => static function ( $new_refresh_token ) use ( $outlook_option, $account_id ) {
				$settings = get_option( $outlook_option, array() );
				if ( isset( $settings['accounts'][ $account_id ] ) ) {
					$settings['accounts'][ $account_id ]['credentials']['refresh_token'] = $new_refresh_token;
					update_option( $outlook_option, $settings );
				}
			},
		);
	}

	// ─── Mobile App (Firebase Push) Endpoints ───────────────────────────────

	/**
	 * Get mobile app / Firebase configuration status.
	 *
	 * Auto-provisions from the bundled service account if not yet configured.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_REST_Response
	 */
	public function get_mobile_app_settings() {
		if ( ! self::has_notifications_push_layer() ) {
			return new WP_REST_Response(
				array(
					'enabled'               => false,
					'configured'            => false,
					'credentials_available' => false,
					'project_id'            => '',
					'configured_at'         => '',
				),
				200
			);
		}
		\DoubleScale\Pro\Modules\Notifications\Services\PushNotificationService::ensure_config();
		$config = get_option( 'doublescale_firebase_config', array() );

		// Check whether the bundled credential files exist on disk.
		$enc_path = DOUBLESCALE_PLUGIN_DIR . 'includes/Firebase/service-account.enc';
		$key_path = DOUBLESCALE_PLUGIN_DIR . 'includes/Firebase/bundle.key';

		return new WP_REST_Response(
			array(
				'enabled'               => (bool) get_option( 'doublescale_push_enabled', false ),
				'configured'            => ! empty( $config['service_account'] ),
				'credentials_available' => file_exists( $enc_path ) && file_exists( $key_path ),
				'project_id'            => $config['project_id'] ?? '',
				'configured_at'         => $config['configured_at'] ?? '',
			),
			200
		);
	}

	/**
	 * Save mobile app push notification settings (enable/disable toggle).
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function save_mobile_app_settings( $request ) {
		if ( ! self::has_notifications_push_layer() ) {
			return self::pro_push_unavailable_error();
		}
		$enabled = (bool) $request->get_param( 'enabled' );
		update_option( 'doublescale_push_enabled', $enabled, false );

		if ( $enabled ) {
			\DoubleScale\Pro\Modules\Notifications\Services\PushNotificationService::ensure_config();
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'enabled' => $enabled,
			),
			200
		);
	}

	/**
	 * Test Firebase connection by generating an access token.
	 *
	 * Auto-provisions from the bundled service account if not yet configured.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_REST_Response
	 */
	public function test_mobile_app_connection() {
		if ( ! self::has_notifications_push_layer() ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Push notifications require DoubleScale Pro.', 'doublescale' ),
				),
				200
			);
		}
		\DoubleScale\Pro\Modules\Notifications\Services\PushNotificationService::ensure_config();
		$config = get_option( 'doublescale_firebase_config', array() );

		if ( empty( $config['service_account'] ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Push notification credentials could not be provisioned. Please contact support.', 'doublescale' ),
				),
				200
			);
		}

		$json = self::decrypt_firebase( $config['service_account'] );
		if ( false === $json ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Failed to decrypt push notification credentials. This may happen after migrating your site. Please deactivate and reactivate the plugin.', 'doublescale' ),
				),
				200
			);
		}

		$sa = json_decode( $json, true );
		if ( ! $sa || empty( $sa['private_key'] ) || empty( $sa['client_email'] ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Service account data is corrupted. Please re-upload.', 'doublescale' ),
				),
				200
			);
		}

		// Try generating a JWT and exchanging it for an access token.
		$jwt = \DoubleScale\Pro\Modules\Notifications\Services\PushNotificationService::generate_jwt( $sa );
		if ( is_wp_error( $jwt ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $jwt->get_error_message(),
				),
				200
			);
		}

		$token_response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => http_build_query(
					array(
						'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
						'assertion'  => $jwt,
					)
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $token_response ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $token_response->get_error_message(),
				),
				200
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $token_response ), true );

		if ( ! empty( $data['access_token'] ) ) {
			return new WP_REST_Response(
				array(
					'success'    => true,
					'message'    => sprintf(
						/* translators: %s: project ID */
						__( 'Connected successfully to Firebase project: %s', 'doublescale' ),
						$config['project_id']
					),
					'project_id' => $config['project_id'],
				),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'success' => false,
				'message' => $data['error_description'] ?? __( 'Failed to obtain access token.', 'doublescale' ),
			),
			200
		);
	}

	/**
	 * Send a real test push notification to the current user's mobile device(s).
	 *
	 * Unlike test_mobile_app_connection() which only validates credentials,
	 * this sends an actual FCM message so the user can confirm delivery.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_REST_Response
	 */
	public function test_mobile_app_push() {
		if ( ! self::has_notifications_push_layer() ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Push notifications require DoubleScale Pro.', 'doublescale' ),
				),
				200
			);
		}
		if ( ! get_option( 'doublescale_push_enabled', false ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Push notifications are disabled site-wide.', 'doublescale' ),
				),
				200
			);
		}

		$user_id = get_current_user_id();
		$tokens  = \DoubleScale\Pro\Modules\Notifications\Services\DeviceTokenService::get_tokens( $user_id );

		if ( empty( $tokens ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'No mobile devices registered. Open the Plugin mobile app and log in to register your device.', 'doublescale' ),
				),
				200
			);
		}

		\DoubleScale\Pro\Modules\Notifications\Services\PushNotificationService::ensure_config();
		$config = get_option( 'doublescale_firebase_config', array() );

		if ( empty( $config['service_account'] ) || empty( $config['project_id'] ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Push notification credentials are not configured.', 'doublescale' ),
				),
				200
			);
		}

		$access_token = \DoubleScale\Pro\Modules\Notifications\Services\PushNotificationService::get_access_token( $config );
		if ( ! $access_token ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Failed to authenticate with Firebase. Please test the connection first.', 'doublescale' ),
				),
				200
			);
		}

		$push_data = (object) array(
			'id'          => 0,
			'title'       => __( 'Test Notification', 'doublescale' ),
			'message'     => __( 'Push notifications are working! This is a test from your Plugin settings.', 'doublescale' ),
			// Point at the in-app notifications list so the test also exercises
			// tap-to-navigate, not just delivery.
			'mobile_link' => '/notifications',
			'subcategory' => 'system_general',
		);

		$endpoint = sprintf( \DoubleScale\Pro\Modules\Notifications\Services\PushNotificationService::FCM_ENDPOINT, $config['project_id'] );
		$sent     = 0;
		$failed   = 0;

		foreach ( $tokens as $entry ) {
			$payload  = \DoubleScale\Pro\Modules\Notifications\Services\PushNotificationService::build_payload(
				$entry['token'],
				$push_data
			);
			$response = wp_remote_post(
				$endpoint,
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $access_token,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode( $payload ),
					'timeout' => 15,
				)
			);
			$code     = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 300 ) {
				++$sent;
			} else {
				++$failed;
			}
		}

		if ( $sent > 0 ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => sprintf(
						/* translators: %d: number of devices */
						_n(
							'Test notification sent to %d device. Check your mobile app!',
							'Test notification sent to %d devices. Check your mobile app!',
							$sent,
							'doublescale'
						),
						$sent
					),
				),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'success' => false,
				'message' => __( 'Failed to deliver the test notification. Your device token may be expired — try logging out and back in on the mobile app.', 'doublescale' ),
			),
			200
		);
	}

	/**
	 * Encrypt a string using AES-256-CBC with a key derived from wp_salt('auth').
	 *
	 * @since 1.0.0
	 *
	 * @param string $plaintext Text to encrypt.
	 * @return string|false Base64-encoded encrypted blob, or false on failure.
	 */
	public static function encrypt_firebase( $plaintext ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return false;
		}

		$key    = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv     = openssl_random_pseudo_bytes( 16 );
		$cipher = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $cipher ) {
			return false;
		}

		return base64_encode( $iv . $cipher );
	}

	/**
	 * Decrypt a string encrypted with encrypt_firebase().
	 *
	 * @since 1.0.0
	 *
	 * @param string $encrypted Base64-encoded encrypted blob.
	 * @return string|false Decrypted text, or false on failure.
	 */
	public static function decrypt_firebase( $encrypted ) {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return false;
		}

		$key  = hash( 'sha256', wp_salt( 'auth' ), true );
		$data = base64_decode( $encrypted );

		if ( strlen( $data ) < 17 ) {
			return false;
		}

		$iv = substr( $data, 0, 16 );

		return openssl_decrypt( substr( $data, 16 ), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
	}

	/**
	 * Check permissions
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function permissions_check() {
		return current_user_can( 'doublescale_access' );
	}

	/**
	 * Permission callback for shared email management (save, test, OAuth).
	 *
	 * Only CRM Managers (+ admins) and Sales Managers can modify shared email settings.
	 * Sales Reps can read (GET) but not write.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function manage_shared_email_check() {
		return current_user_can( 'doublescale_manage_settings' );
	}
}
