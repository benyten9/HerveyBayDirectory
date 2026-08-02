<?php
/**
 * Shared REST routes for an integration’s global settings (GET/POST …/integrations/{slug}).
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Integration\Rest;

use DoubleScale\Modules\Booking\Abstracts\Integration;
use DoubleScale\Modules\Booking\Abstracts\REST_Controller;
use Exception;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * @property Integration $integration
 */
abstract class REST_Integration_Controller extends REST_Controller {

	/**
	 * Integration instance.
	 *
	 * @var Integration
	 */
	protected $integration;

	/**
	 * Route suffix after namespace (e.g. integrations/google).
	 *
	 * @var string
	 */
	public $rest_base = 'integrations';

	/**
	 * @param Integration $integration Integration.
	 */
	public function __construct( Integration $integration ) {
		$this->integration = $integration;
		$this->rest_base   = 'integrations/' . $this->integration->slug;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the routes for the objects of the controller.
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
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'update_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
			)
		);
	}

	/**
	 * Item schema for settings payload.
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'integration',
			'type'       => 'object',
			'properties' => array(
				'settings' => array(
					'description' => __( 'Integration Settings', 'doublescale' ),
					'type'        => 'object',
					'required'    => true,
				),
			),
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get( $request ) {
		try {
			$settings = $this->integration->get_settings();

			return new WP_REST_Response(
				array(
					'settings' => $settings,
				),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'rest_invalid_request', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( $request ) {
		try {
			$settings  = $request->get_param( 'settings' ) ?? array();
			$validator = $this->integration->validate( $settings );
			if ( ! $validator ) {
				return new WP_Error( 'rest_invalid_request', __( 'Invalid settings.', 'doublescale' ), array( 'status' => 400 ) );
			}

			$this->integration->update_settings( $settings );

			return new WP_REST_Response(
				array(
					'settings' => $settings,
				),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'rest_invalid_request', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function get_permissions_check( $request ) {
		return $this->current_user_can_manage_integrations();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function update_permissions_check( $request ) {
		return $this->current_user_can_manage_integrations();
	}

	/**
	 * Whether the current user may change booking calendar integrations.
	 */
	protected function current_user_can_manage_integrations(): bool {
		return current_user_can( 'manage_options' )
			|| current_user_can( 'doublescale_booking_manage_own_calendars' )
			|| current_user_can( 'doublescale_booking_manage_all_calendars' );
	}
}
