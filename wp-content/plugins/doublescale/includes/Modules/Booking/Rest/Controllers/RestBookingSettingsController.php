<?php

/**
 * REST_Settings_Controller class.
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;
use DoubleScale\Modules\Booking\Helpers\BookingSettings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Booking\PaymentGateway\PaymentValidator;

/**
 * REST_Settings_Controller class.
 *
 * @since 1.0.0
 */
class RestBookingSettingsController extends RestController {





	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = 'booking/settings';

	/**
	 * Register the routes for the controller.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}",
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'permission_callback' => array( $this, 'get_permissions_check' ),
					'args'                => array(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'update_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/sms-provider-status",
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_sms_provider_status' ),
				'permission_callback' => array( $this, 'get_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/payment-provider-status",
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_payment_provider_status' ),
				'permission_callback' => array( $this, 'get_permissions_check' ),
			)
		);
	}

	/**
	 * Report whether the bookings payment provider is configured.
	 *
	 * Free tier returns `configured:false`. Pro tier hooks the
	 * `doublescale_booking_payment_provider_status` filter and returns the
	 * real status from the CRM-wide Stripe integration. The Payments event
	 * tab consumes this to decide whether to render the price editor or a
	 * "configure Stripe in CRM integrations" pointer.
	 *
	 * @param WP_REST_Request $request Unused.
	 * @return WP_REST_Response
	 */
	public function get_payment_provider_status( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$payload = array(
			'configured' => false,
			'provider'   => 'stripe',
			'config_url' => admin_url( 'admin.php?page=doublescale&path=integrations/stripe' ),
		);

		/**
		 * Filter the payment provider status payload.
		 *
		 * Pro tier overrides with the real CRM-wide Stripe integration status.
		 *
		 * @param array $payload Default {configured:false, provider:'stripe', config_url:...}.
		 */
		return rest_ensure_response( (array) apply_filters( 'doublescale_booking_payment_provider_status', $payload ) );
	}

	/**
	 * Report whether the bookings SMS provider is configured.
	 *
	 * Free tier returns `configured:false`. Pro tier hooks the
	 * `doublescale_booking_sms_provider_status` filter and returns the real
	 * status from `Modules\Inbox\Services\MessageProviderRegistry`. The
	 * SMS-notifications event tab consumes this to decide whether to render
	 * the editor or a "configure SMS in CRM integrations" pointer.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Unused.
	 * @return WP_REST_Response
	 */
	public function get_sms_provider_status( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$payload = array(
			'configured' => false,
			'provider'   => 'twilio',
			'config_url' => admin_url( 'admin.php?page=doublescale&path=integrations/twilio' ),
		);

		/**
		 * Filter the SMS provider status payload.
		 *
		 * Pro tier overrides with the real provider state via
		 * `Modules\Inbox\Services\MessageProviderRegistry`.
		 *
		 * @param array $payload Default {configured:false, provider:'twilio', config_url:...}.
		 */
		return rest_ensure_response( (array) apply_filters( 'doublescale_booking_sms_provider_status', $payload ) );
	}

	/**
	 * Retrieves schema, conforming to JSON Schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_schema() {
		$schema = array(
			'$schema'              => 'http://json-schema.org/draft-04/schema#',
			'title'                => 'settings',
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'general'  => array(
					'type'        => 'object',
					'description' => __( 'General settings', 'doublescale' ),
					'properties'  => array(
						'start_from'              => array(
							'type'        => 'string',
							'description' => __( 'Start from', 'doublescale' ),
							'default'     => 'Monday',
						),
						'time_format'             => array(
							'type'        => 'string',
							'description' => __( 'Time format', 'doublescale' ),
							'default'     => '12',
						),
						'auto_cancel_after'       => array(
							'type'        => 'integer',
							'description' => __( 'Auto-cancel unpaid bookings after N minutes.', 'doublescale' ),
							'default'     => 30,
						),
						'auto_complete_after'     => array(
							'type'        => 'integer',
							'description' => __( 'Mark a booking completed N minutes after its end time.', 'doublescale' ),
							'default'     => 60,
						),
						'default_country_code'    => array(
							'type'        => 'string',
							'description' => __( 'Default country Code', 'doublescale' ),
							'default'     => 'us',
						),
						'default_time_slot_step'  => array(
							'type'        => 'integer',
							'description' => __( 'Default time slot step in minutes for service duration options', 'doublescale' ),
							'default'     => 15,
						),
						'enable_summary_email'    => array(
							'type'        => 'boolean',
							'description' => __( 'Enable summary email', 'doublescale' ),
							'default'     => false,
						),
						'summary_email_frequency' => array(
							'type'        => 'string',
							'description' => __( 'Summary email frequency', 'doublescale' ),
							'default'     => 'daily',
						),
						'include_ics'             => array(
							'type'        => 'boolean',
							'description' => __( 'Attach .ics calendar invite to booking confirmation emails', 'doublescale' ),
							'default'     => false,
						),
					),
				),
				'payments' => array(
					'type'        => 'object',
					'description' => __( 'Payments settings', 'doublescale' ),
					'properties'  => array(
						'currency' => array(
							'type'        => 'string',
							'description' => __( 'Currency', 'doublescale' ),
							'default'     => 'USD',
						),
					),
				),
				'theme'    => array(
					'type'        => 'object',
					'description' => __( 'Theme settings', 'doublescale' ),
					'properties'  => array(
						'color_scheme' => array(
							'type'        => 'string',
							'description' => __( 'Color scheme', 'doublescale' ),
							'default'     => 'system',
						),
					),
				),
			),
		);
		return $schema;
	}

	/**
	 * Retrieves settings.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get( $request ) 	{ // phpcs:ignore
		return new WP_REST_Response( BookingSettings::all(), 200 );
	}

	/**
	 * Checks if a given request has access to get settings.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function get_permissions_check( $request ) {
		return $this->can_manage_booking_settings();
	}

	/**
	 * Updates settings.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update( $request ) {
		$settings = $request->get_json_params();

		Settings::update_many( $settings );
		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Checks if a given request has access to update settings.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function update_permissions_check( $request ) {
		return $this->can_manage_booking_settings();
	}

	/**
	 * Global booking settings: CRM Manager / WP admin / Booking Manager.
	 *
	 * @return bool
	 */
	private function can_manage_booking_settings(): bool {
		return Permissions::is_crm_manager()
			|| current_user_can( 'doublescale_booking_manage_all_calendars' );
	}
}
