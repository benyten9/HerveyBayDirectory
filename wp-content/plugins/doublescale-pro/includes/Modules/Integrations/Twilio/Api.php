<?php
/**
 * Class Twilio Api
 *
 * This class is responsible for handling the Twilio Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Twilio;

use DoubleScale\Pro\Modules\Integrations\Abstracts\IntegrationApi;
/**
 * Twilio Api class
 */
class Api extends IntegrationApi {

	/**
	 * Account SID
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $account_sid;

	/**
	 * Api Key
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $auth_token;

	/**
	 * Phone number
	 *
	 * @var string
	 */
	public $phone_number;

	/**
	 * Constructor
	 *
	 * @param string $account_sid
	 * @param string $auth_token
	 * @param string $phone_number
	 *
	 * @since 1.0.0
	 */
	public function __construct( $account_sid, $auth_token, $phone_number ) {
		$this->endpoint     = 'https://api.twilio.com/2010-04-01';
		$this->account_sid  = $account_sid;
		$this->auth_token   = $auth_token;
		$this->phone_number = $phone_number;
	}

	/**
	 * Get accounts
	 *
	 * @return array|WP_Error
	 */
	public function get_accounts() {
		return $this->get( 'Accounts.json' );
	}

	/**
	 * Send Sms
	 *
	 * @param array $data
	 *
	 * @return array|WP_Error
	 */
	public function send_sms( $data ) {
		if ( empty( $this->phone_number ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Twilio phone number not configured. Please add your Twilio phone number in Settings > Integrations > Twilio.', 'doublescale'),
			);
		}

		$data['From'] = $this->phone_number;
		return $this->post( 'Accounts/' . $this->account_sid . '/Messages.json', $data );
	}

	/**
	 * Validate Sms configuration comprehensively
	 *
	 * @since 1.0.0
	 *
	 * @return array Array with 'valid' boolean and 'error' message if invalid
	 */
	public function validate_sms_config() {
		$errors = array();

		// Check Account SID
		if ( empty( $this->account_sid ) ) {
			$errors[] = __( 'Twilio Account SID is missing.', 'doublescale');
		} elseif ( ! $this->validate_account_sid_format( $this->account_sid ) ) {
			$errors[] = __( 'Twilio Account SID format is invalid (should start with "AC").', 'doublescale');
		}

		// Check Auth Token
		if ( empty( $this->auth_token ) ) {
			$errors[] = __( 'Twilio Auth Token is missing.', 'doublescale');
		} elseif ( strlen( $this->auth_token ) < 32 ) {
			$errors[] = __( 'Twilio Auth Token appears to be invalid (too short).', 'doublescale');
		}

		// Check Phone Number
		if ( empty( $this->phone_number ) ) {
			$errors[] = __( 'Twilio phone number is missing.', 'doublescale');
		} elseif ( ! $this->validate_phone_number_format( $this->phone_number ) ) {
			$errors[] = __( 'Phone number format is invalid (should be in E.164 format, e.g., +1234567890).', 'doublescale');
		}

		if ( ! empty( $errors ) ) {
			return array(
				'valid' => false,
				'error' => implode( ' ', $errors ),
			);
		}

		return array(
			'valid' => true,
			'error' => null,
		);
	}

	/**
	 * Validate Account SID format
	 *
	 * @since 1.0.0
	 *
	 * @param string $account_sid Account SID to validate
	 * @return bool True if valid format
	 */
	private function validate_account_sid_format( $account_sid ) {
		// Twilio Account SIDs start with "AC" followed by 32 hex characters
		return preg_match( '/^AC[a-f0-9]{32}$/i', $account_sid ) === 1;
	}

	/**
	 * Validate phone number format
	 *
	 * @since 1.0.0
	 *
	 * @param string $phone_number Phone number to validate
	 * @return bool True if valid E.164 format
	 */
	private function validate_phone_number_format( $phone_number ) {
		// E.164 format: +[country code][number] (e.g., +14155552671)
		return preg_match( '/^\+[1-9]\d{1,14}$/', $phone_number ) === 1;
	}

	/**
	 * Test Twilio Sms connection and configuration
	 *
	 * Performs comprehensive checks:
	 * 1. Validates credentials format
	 * 2. Tests Api connectivity
	 * 3. Verifies phone number capabilities
	 *
	 * @since 1.0.0
	 *
	 * @return array Result with success status, message, and details
	 */
	public function test_sms_connection() {
		$result = array(
			'success' => false,
			'message' => '',
			'checks'  => array(),
		);

		// Step 1: Validate configuration format
		$validation = $this->validate_sms_config();
		$result['checks']['config_format'] = array(
			'label'   => __( 'Configuration Format', 'doublescale'),
			'status'  => $validation['valid'] ? 'success' : 'error',
			'message' => $validation['valid']
				? __( 'All credentials are properly formatted', 'doublescale')
				: $validation['error'],
		);

		if ( ! $validation['valid'] ) {
			$result['message'] = __( 'Configuration validation failed. Please check your credentials.', 'doublescale');
			return $result;
		}

		// Step 2: Test Api connectivity
		try {
			$accounts_response = $this->get_accounts();

			if ( ! isset( $accounts_response['success'] ) || ! $accounts_response['success'] ) {
				$error_message = $accounts_response['data']['message'] ?? __( 'Failed to connect to Twilio Api', 'doublescale');
				$result['checks']['api_connection'] = array(
					'label'   => __( 'Api Connection', 'doublescale'),
					'status'  => 'error',
					'message' => $error_message,
				);
				$result['message'] = __( 'Failed to connect to Twilio. Please verify your Account SID and Auth Token.', 'doublescale');
				return $result;
			}

			$result['checks']['api_connection'] = array(
				'label'   => __( 'Api Connection', 'doublescale'),
				'status'  => 'success',
				'message' => __( 'Successfully connected to Twilio Api', 'doublescale'),
			);
		} catch ( \Exception $e ) {
			$result['checks']['api_connection'] = array(
				'label'   => __( 'Api Connection', 'doublescale'),
				'status'  => 'error',
				'message' => $e->getMessage(),
			);
			$result['message'] = __( 'Api connection test failed.', 'doublescale');
			return $result;
		}

		// Step 3: Verify phone number capabilities
		try {
			$phone_info = $this->get_phone_number_capabilities();

			if ( ! isset( $phone_info['success'] ) || ! $phone_info['success'] ) {
				$result['checks']['phone_number'] = array(
					'label'   => __( 'Phone Number', 'doublescale'),
					'status'  => 'warning',
					'message' => __( 'Could not verify phone number capabilities', 'doublescale'),
				);
			} else {
				$capabilities = $phone_info['data']['capabilities'] ?? array();
				$has_sms      = ! empty( $capabilities['sms'] );
				$has_mms      = ! empty( $capabilities['mms'] );

				$result['checks']['phone_number'] = array(
					'label'   => __( 'Phone Number', 'doublescale'),
					'status'  => 'success',
					'message' => sprintf(
						/* translators: %s: phone number */
						__( 'Phone number %s is active', 'doublescale'),
						$this->phone_number
					),
					'details' => array(
						'sms' => $has_sms,
						'mms' => $has_mms,
					),
				);
			}
		} catch ( \Exception $e ) {
			$result['checks']['phone_number'] = array(
				'label'   => __( 'Phone Number', 'doublescale'),
				'status'  => 'warning',
				'message' => __( 'Could not verify phone number', 'doublescale'),
			);
		}

		$result['checks']['sms_ready'] = array(
			'label'   => __( 'Sms Ready', 'doublescale'),
			'status'  => 'success',
			'message' => __( 'Twilio is configured for Sms messaging.', 'doublescale'),
		);

		// Determine overall success
		$has_errors = false;
		foreach ( $result['checks'] as $check ) {
			if ( $check['status'] === 'error' ) {
				$has_errors = true;
				break;
			}
		}

		$result['success'] = ! $has_errors;
		$result['message'] = $has_errors
			? __( 'Twilio Sms configuration has issues. Please review the checks below.', 'doublescale')
			: __( 'Twilio is properly configured for Sms messaging!', 'doublescale');

		return $result;
	}

	/**
	 * Get phone number capabilities from Twilio
	 *
	 * @since 1.0.0
	 *
	 * @return array Response with phone number capabilities
	 */
	public function get_phone_number_capabilities() {
		// Remove + prefix and any non-digit characters for Api call
		$phone_number_encoded = str_replace( '+', '', $this->phone_number );

		$response = wp_remote_request(
			"{$this->endpoint}/Accounts/{$this->account_sid}/IncomingPhoneNumbers.json?PhoneNumber=" . urlencode( '+' . $phone_number_encoded ),
			array(
				'method'  => 'GET',
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Basic ' . base64_encode( $this->account_sid . ':' . $this->auth_token ),
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			// Extract first phone number from results
			$phone_numbers = $data['incoming_phone_numbers'] ?? array();
			if ( ! empty( $phone_numbers ) ) {
				return array(
					'success' => true,
					'data'    => $phone_numbers[0],
				);
			}

			return array(
				'success' => false,
				'error'   => __( 'Phone number not found in your Twilio account', 'doublescale'),
			);
		}

		return array(
			'success' => false,
			'error'   => $data['message'] ?? __( 'Failed to fetch phone number capabilities', 'doublescale'),
			'code'    => $data['code'] ?? $code,
		);
	}

	/**
	 * Send POST request to the api.
	 *
	 * @param string     $path Path.
	 * @param array|null $body Body.
	 * @return array
	 */
	public function post( $path, $body = array() ) {
		return $this->request( 'POST', $path, $body ? http_build_query( $body ) : null );
	}

	/**
	 * Send request to the api.
	 *
	 * @param string      $method Method.
	 * @param string      $path URL.
	 * @param string|null $body Body.
	 * @return array|WP_Error
	 */
	public function request_remote( $method, $path, $body = null ) {
		return wp_remote_request(
			"{$this->endpoint}/$path",
			array(
				'method'  => $method,
				'body'    => $body,
				'headers' => array(
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Cache-Control' => 'no-cache',
					'Authorization' => 'Basic ' . base64_encode( $this->account_sid . ':' . $this->auth_token ),
				),
				'timeout' => 30,
			)
		);
	}
}
