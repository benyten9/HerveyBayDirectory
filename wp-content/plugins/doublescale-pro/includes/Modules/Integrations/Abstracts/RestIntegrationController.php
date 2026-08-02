<?php

/**
 * Class Integration REST Controller
 * This class is responsible for handling the Integration REST Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Abstracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Exception;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Pro\Modules\Integrations\Abstracts\Integration;
use DoubleScale\Core\UserRoles\Permissions;

/**
 * Rest Integration Controller
 */
class RestIntegrationController extends RestController {


	/**
	 * Integration.
	 *
	 * @var Integration
	 */
	protected $integration;

	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $rest_base = 'integrations';

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 *
	 * @param Integration $integration
	 */
	public function __construct( Integration $integration ) {
		$this->integration = $integration;
		$this->rest_base   = "integrations/{$this->integration->slug}";

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the routes for the objects of the controller.
	 *
	 * @since 1.0.0
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

		// Allow child classes to register additional routes
		if ( method_exists( $this, 'register_additional_routes' ) ) {
			$this->register_additional_routes();
		}
	}

	/**
	 * Get attributes schema
	 *
	 * @return array
	 */
	public function get_settings_schema() {
		 return array();
	}

	/**
	 * Get item schema
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_item_schema() {
		 return array(
			 '$schema'    => 'http://json-schema.org/draft-04/schema#',
			 'title'      => 'integration',
			 'type'       => 'object',
			 'properties' => array(
				 'settings' => array(
					 'description' => __( 'Integration Settings', 'doublescale'),
					 'type'        => 'object',
					 'required'    => true,
					 'arg_options' => array(
						 'validate_callback' => array( $this, 'validate_item_settings' ),
					 ),
				 ),
			 ),
		 );
	}

	/**
	 * Validate the create item request
	 *
	 * @since 1.0.0
	 *
	 * @param mixed           $value The value of the parameter.
	 * @param WP_REST_Request $request The request object.
	 * @param string          $param The parameter name.
	 *
	 * @return WP_Error|bool
	 */
	public function validate_item_settings( $value, $request, $param ) {
		try {
			// Skip validation if settings are empty (disconnecting)
			if ( empty( $value ) ) {
				return true;
			}

			$attributes_schema = $this->get_settings_schema();
			$validator         = rest_validate_value_from_schema( $value, $attributes_schema, $param );

			if ( is_wp_error( $validator ) ) {
				return $validator;
			}

			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'rest_invalid_request', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Get Integration
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get( WP_REST_Request $request ) {
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
	 * Update Integration
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function update( WP_REST_Request $request ) {
		try {
			$settings = $request->get_param( 'settings' ) ?? array();

			// Skip validation if settings are empty (disconnecting)
			if ( ! empty( $settings ) ) {
				$validator = $this->integration->validate( $settings );
				if ( is_wp_error( $validator ) ) {
					return $validator;
				}
				if ( ! $validator ) {
					return new WP_Error( 'rest_invalid_request', __( 'Invalid settings.', 'doublescale'), array( 'status' => 400 ) );
				}
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
	 * Get Permissions Check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function get_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Update Permissions Check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function update_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}
}
