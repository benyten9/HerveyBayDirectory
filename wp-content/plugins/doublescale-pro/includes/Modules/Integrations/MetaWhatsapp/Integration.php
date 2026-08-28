<?php
/**
 * Meta WhatsApp Integration
 *
 * Handles connection settings and validation for Meta WhatsApp Business Api
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\MetaWhatsapp;

use DoubleScale\Pro\Modules\Integrations\Abstracts\Integration as Integration_Abstract;

defined( 'ABSPATH' ) || exit;

/**
 * Integration class for Meta WhatsApp
 */
class Integration extends Integration_Abstract {

	/**
	 * Integration name
	 *
	 * @var string
	 */
	public $name = 'Meta WhatsApp';

	/**
	 * Integration slug
	 *
	 * @var string
	 */
	public $slug = 'meta-whatsapp';

	/**
	 * Integration description
	 *
	 * @var string
	 */
	public $description = 'Connect WhatsApp Business via Meta Cloud Api for sending messages and receiving webhooks.';

	/**
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Connect WhatsApp Business via Meta Cloud Api for sending messages and receiving webhooks.', 'doublescale' );
	}

	/**
	 * Is this a Pro feature?
	 *
	 * @var bool
	 */
	public $is_pro = true;

	/**
	 * Show on Integrations catalog.
	 *
	 * @var bool
	 */
	public $show_in_catalog = true;

	public $catalog_category = 'messaging';

	/**
	 * Option name for storing settings
	 *
	 * @var string
	 */
	public $option_name = 'meta_whatsapp';

	/**
	 * Api instance
	 *
	 * @var Api|null
	 */
	public $api = null;

	/**
	 * Related classes
	 *
	 * @var array
	 */
	protected static $classes = array(
		'remote_data'     => RemoteData::class,
		'rest_controller' => RestController::class,
	);

	/**
	 * Get settings fields for this integration
	 *
	 * @return array Settings field definitions.
	 */
	public function get_settings_fields() {
		return array(
			'access_token'        => array(
				'label'       => __( 'Access Token', 'doublescale'),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'Permanent access token from Meta Developer Console (System User token).', 'doublescale'),
			),
			'phone_number_id'     => array(
				'label'       => __( 'Phone Number ID', 'doublescale'),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Whatsapp phone number ID from Meta Developer Console.', 'doublescale'),
			),
			'business_account_id' => array(
				'label'       => __( 'Business Account ID', 'doublescale'),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Whatsapp Business Account ID (WABA ID).', 'doublescale'),
			),
			'webhook_verify_token' => array(
				'label'       => __( 'Webhook Verify Token', 'doublescale'),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Random string used to verify webhook from Meta. Enter this same value in Meta Developer Console.', 'doublescale'),
			),
			'app_secret'          => array(
				'label'       => __( 'App Secret', 'doublescale'),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'Meta App Secret for webhook signature verification.', 'doublescale'),
			),
		);
	}

	/**
	 * Connect to Meta Api
	 *
	 * @return Api|false Api instance or false if not configured.
	 */
	public function connect() {
		if ( $this->api instanceof Api ) {
			return $this->api;
		}

		$access_token        = $this->get_setting( 'access_token' );
		$phone_number_id     = $this->get_setting( 'phone_number_id' );
		$business_account_id = $this->get_setting( 'business_account_id' );

		if ( empty( $access_token ) || empty( $phone_number_id ) || empty( $business_account_id ) ) {
			return false;
		}

		$this->api = new Api( $access_token, $phone_number_id, $business_account_id );
		return $this->api;
	}

	/**
	 * Validate integration settings
	 *
	 * @param array $settings Settings to validate.
	 *
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function validate( $settings ) {
		$required = array(
			'access_token',
			'phone_number_id',
			'business_account_id',
			'webhook_verify_token',
			'app_secret',
		);

		foreach ( $required as $field ) {
			if ( empty( $settings[ $field ] ) ) {
				return new \WP_Error(
					'invalid_settings',
					sprintf(
						/* translators: %s: field name */
						__( '%s is required.', 'doublescale'),
						ucwords( str_replace( '_', ' ', $field ) )
					)
				);
			}
		}

		// Test Api connection
		$api    = new Api(
			$settings['access_token'],
			$settings['phone_number_id'],
			$settings['business_account_id']
		);
		$result = $api->get_phone_numbers();

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'api_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to connect to Meta Api: %s', 'doublescale'),
					$result['error']
				)
			);
		}

		// Verify the phone_number_id belongs to this business account
		$phone_found = false;
		if ( isset( $result['data']['data'] ) && is_array( $result['data']['data'] ) ) {
			foreach ( $result['data']['data'] as $phone ) {
				if ( $phone['id'] === $settings['phone_number_id'] ) {
					$phone_found = true;
					break;
				}
			}
		}

		if ( ! $phone_found ) {
			return new \WP_Error(
				'invalid_phone',
				__( 'The Phone Number ID does not belong to the specified Business Account.', 'doublescale')
			);
		}

		return true;
	}

	/**
	 * Check if integration is connected
	 *
	 * Validates both that credentials exist AND that they work with the Meta Api.
	 * This prevents showing "connected" status for expired or invalid tokens.
	 *
	 * @return bool True if connected and configured with valid credentials.
	 */
	public function is_connected() {
		$access_token        = $this->get_setting( 'access_token' );
		$phone_number_id     = $this->get_setting( 'phone_number_id' );
		$business_account_id = $this->get_setting( 'business_account_id' );

		// First check: credentials must exist
		if ( empty( $access_token ) || empty( $phone_number_id ) || empty( $business_account_id ) ) {
			return false;
		}

		// Second check: verify token is valid by testing Api connection
		// Use transient caching to avoid hitting Meta Api on every check
		$cache_key = 'qc_meta_whatsapp_connection_status_' . md5( $access_token . $phone_number_id );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return (bool) $cached;
		}

		// Test actual Api connectivity
		$api    = new Api( $access_token, $phone_number_id, $business_account_id );
		$result = $api->get_phone_numbers();

		// Cache the result for 5 minutes to reduce Api calls
		$is_valid = isset( $result['success'] ) && $result['success'];
		set_transient( $cache_key, $is_valid ? 1 : 0, 5 * MINUTE_IN_SECONDS );

		return $is_valid;
	}

	/**
	 * Update settings and clear connection status cache
	 *
	 * @param array $settings Integration settings.
	 * @return void
	 */
	public function update_settings( $settings ) {
		// Clear the connection status cache when settings change
		$old_settings = $this->get_settings();
		parent::update_settings( $settings );

		// Clear cache if credentials changed
		$old_token = $old_settings['access_token'] ?? '';
		$old_phone = $old_settings['phone_number_id'] ?? '';
		$new_token = $settings['access_token'] ?? '';
		$new_phone = $settings['phone_number_id'] ?? '';

		if ( $old_token !== $new_token || $old_phone !== $new_phone ) {
			// Clear both old and new cache keys
			$old_cache_key = 'qc_meta_whatsapp_connection_status_' . md5( $old_token . $old_phone );
			$new_cache_key = 'qc_meta_whatsapp_connection_status_' . md5( $new_token . $new_phone );
			delete_transient( $old_cache_key );
			delete_transient( $new_cache_key );
		}
	}

	/**
	 * Get webhook URL for this integration
	 *
	 * @return string Webhook URL to configure in Meta Developer Console.
	 */
	public function get_webhook_url() {
		return admin_url( 'admin-ajax.php?action=doublescale_whatsapp_webhook' );
	}

	/**
	 * Catalog icon.
	 *
	 * @return string
	 */
	public function get_icon_url(): string {
		return $this->plugin_asset_url( 'assets/images/meta-whatsapp/meta-whatsapp.svg' );
	}

	/**
	 * Get the phone number associated with this integration
	 *
	 * @return string|null Phone number or null if not available.
	 */
	public function get_phone_number() {
		$api = $this->connect();
		if ( ! $api ) {
			return null;
		}

		$result = $api->get_phone_numbers();
		if ( ! $result['success'] || empty( $result['data']['data'] ) ) {
			return null;
		}

		$phone_number_id = $this->get_setting( 'phone_number_id' );
		foreach ( $result['data']['data'] as $phone ) {
			if ( $phone['id'] === $phone_number_id ) {
				return $phone['display_phone_number'] ?? null;
			}
		}

		return null;
	}
}

