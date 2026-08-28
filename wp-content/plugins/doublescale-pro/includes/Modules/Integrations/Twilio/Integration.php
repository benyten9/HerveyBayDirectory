<?php
/**
 * Class Twilio
 *
 * This class is responsible for handling the Twilio integration
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Twilio;

use DoubleScale\Pro\Modules\Integrations\Abstracts\Integration as Integration_Abstract;
use DoubleScale\Core\Managers\IntegrationsManager;

/**
 * Twilio class
 */
class Integration extends Integration_Abstract {

	/**
	 * Integration Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Twilio';

	/**
	 * Integration Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'twilio';

	/**
	 * Integration Description
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $description = 'Twilio lets apps send and receive messages, make and receive phone calls, and verify users, essentially adding communication features to digital tools.';

	/**
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Twilio lets apps send and receive messages, make and receive phone calls, and verify users, essentially adding communication features to digital tools.', 'doublescale' );
	}

	/**
	 * Is Pro feature
	 *
	 * @var bool
	 *
	 * @since 1.0.0
	 */
	public $is_pro = false;

	/**
	 * Show on Integrations catalog.
	 *
	 * @var bool
	 */
	public $show_in_catalog = true;

	public $catalog_category = 'messaging';

	/**
	 * Option name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $option_name = 'twilio';

	/**
	 * Class names
	 *
	 * @var array
	 */
	protected static $classes = array(
		'remote_data'     => RemoteData::class,
		'rest_controller' => RestController::class,
	);

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct();
	}

	/**
	 * Connect the integration
	 *
	 * @since 1.0.0
	 *
	 * @return bool|Api
	 */
	public function connect() {
		if ( $this->api instanceof Api ) {
			return $this->api;
		}

		$account_sid  = $this->get_setting( 'account_sid' );
		$auth_token   = $this->get_setting( 'auth_token' );
		$phone_number = $this->get_setting( 'phone_number' );

		if ( empty( $account_sid ) || empty( $auth_token ) || empty( $phone_number ) ) {
			return false;
		}

		$this->api = new Api( $account_sid, $auth_token, $phone_number );

		return $this->api;
	}

	/**
	 * Validate.
	 *
	 * @param array $settings Settings.
	 *
	 * @return bool|\WP_Error
	 */
	public function validate( $settings ) {
		$account_sid  = $settings['account_sid'] ?? '';
		$auth_token   = $settings['auth_token'] ?? '';
		$phone_number = $settings['phone_number'] ?? '';

		if ( empty( $account_sid ) ) {
			return new \WP_Error( 'invalid_settings', __( 'Account SID is required.', 'doublescale') );
		}

		if ( empty( $auth_token ) ) {
			return new \WP_Error( 'invalid_settings', __( 'Auth Token is required.', 'doublescale') );
		}

		if ( empty( $phone_number ) ) {
			return new \WP_Error( 'invalid_settings', __( 'Phone Number is required.', 'doublescale') );
		}

		// Test Twilio Api connection
		$api    = new Api( $account_sid, $auth_token, $phone_number );
		$result = $api->get_accounts();

		// Log the full response for debugging
		doublescale_get_logger()->debug(
			'Twilio Api validation attempt',
			array(
				'code'         => 'twilio_validation',
				'account_sid'  => substr( $account_sid, 0, 8 ) . '...', // Log only first 8 chars for security
				'result'       => $result,
			)
		);

		if ( $result['success'] ) {
			return true;
		} else {
			// Extract error message from Twilio response
			// Twilio returns errors in different formats, check all possibilities
			$error_message = __( 'Failed to connect to Twilio. Please check your credentials.', 'doublescale');
			$error_details = '';

			// Check various error formats from Twilio
			if ( isset( $result['data']['message'] ) && ! empty( $result['data']['message'] ) ) {
				$error_message = $result['data']['message'];
			}

			// Twilio sometimes returns error details in 'detail' field
			if ( isset( $result['data']['detail'] ) && ! empty( $result['data']['detail'] ) ) {
				$error_details = $result['data']['detail'];
			}

			// Check for more_info URL from Twilio
			if ( isset( $result['data']['more_info'] ) ) {
				$error_details .= ' More info: ' . $result['data']['more_info'];
			}

			// Check for WordPress HTTP errors
			if ( isset( $result['data']['wp_error']['message'] ) ) {
				$error_message = $result['data']['wp_error']['message'];
			}

			// Handle 401 specifically - always authentication issue
			if ( isset( $result['code'] ) && $result['code'] === 401 ) {
				$error_message = __( 'Authentication failed: Invalid Account SID or Auth Token. Please verify your credentials in Twilio Console.', 'doublescale');
			}

			// Combine message and details
			if ( ! empty( $error_details ) ) {
				$error_message .= ' ' . $error_details;
			}

			// Log the full error for debugging
			doublescale_get_logger()->error(
				'Twilio validation failed',
				array(
					'code'          => 'twilio_validation_failed',
					'error_message' => $error_message,
					'response_code' => $result['code'] ?? null,
					'full_response' => $result, // Log full response for debugging
				)
			);

			return new \WP_Error( 'twilio_connection_failed', $error_message );
		}
	}

	/**
	 * Catalog icon.
	 *
	 * @return string
	 */
	public function get_icon_url(): string {
		return $this->plugin_asset_url( 'assets/images/twilio/twilio.png' );
	}
}

// Registration moved to Plugin main class
