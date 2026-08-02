<?php
/**
 * Class Twilio Rest Controller
 *
 * This class is responsible for handling the Twilio REST Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Twilio;

use DoubleScale\Pro\Modules\Integrations\Abstracts\RestIntegrationController;

/**
 * Twilio Rest Controller
 */
class RestController extends RestIntegrationController {

	/**
	 * Register additional routes for Twilio-specific endpoints
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_additional_routes() {
		// Test Twilio Sms connection endpoint.
		register_rest_route(
			$this->namespace,
			'/integrations/twilio/test-sms',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'test_sms_connection' ),
					'permission_callback' => array( $this, 'update_permissions_check' ),
					'args'                => array(
						'account_sid'  => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'auth_token'   => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'phone_number' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Test Twilio Sms connection with provided credentials
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test_sms_connection( $request ) {
		try {
			$account_sid  = $request->get_param( 'account_sid' );
			$auth_token   = $request->get_param( 'auth_token' );
			$phone_number = $request->get_param( 'phone_number' );

			// Create temporary Api instance with provided credentials.
			$api = new \DoubleScale\Pro\Modules\Integrations\Twilio\Api(
				$account_sid,
				$auth_token,
				$phone_number
			);

			// Run comprehensive test.
			$test_result = $api->test_sms_connection();

			// Log the test attempt.
			doublescale_get_logger()->info(
				'Twilio Sms connection test performed',
				array(
					'success'     => $test_result['success'],
					'account_sid' => substr( $account_sid, 0, 10 ) . '...',
					'checks'      => array_keys( $test_result['checks'] ?? array() ),
				)
			);

			return new \WP_REST_Response( $test_result, 200 );

		} catch ( \Exception $e ) {
			doublescale_get_logger()->error(
				'Twilio Sms connection test failed',
				array(
					'error' => $e->getMessage(),
				)
			);

			return new \WP_Error(
				'test_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get settings schema
	 *
	 * @return array
	 */
	public function get_settings_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'account_sid'  => array(
					'label'       => __( 'Account SID', 'doublescale'),
					'type'        => 'string',
					'required'    => true,
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'auth_token'   => array(
					'label'       => __( 'Auth Token', 'doublescale'),
					'type'        => 'string',
					'required'    => true,
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'phone_number' => array(
					'label'       => __( 'Phone Number', 'doublescale'),
					'type'        => 'string',
					'required'    => true,
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
		);
	}
}
