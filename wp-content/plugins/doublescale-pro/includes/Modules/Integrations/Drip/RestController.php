<?php

/**
 * Class Drip Rest Controller
 *
 * This class is responsible for handling the Drip REST Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Drip;

use DoubleScale\Pro\Modules\Integrations\Abstracts\RestIntegrationController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Core\UserRoles\Permissions;

/**
 * Drip Rest Controller
 */
class RestController extends RestIntegrationController {


	/**
	 * Register the routes for the objects of the controller.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		 parent::register_routes();

		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/account",
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_account' ),
					'permission_callback' => array( $this, 'update_account_permissions_check' ),
				),
			)
		);
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
				 'api_token'  => array(
					 'label'       => __( 'Api Token', 'doublescale'),
					 'type'        => 'string',
					 'required'    => true,
					 'arg_options' => array(
						 'sanitize_callback' => 'sanitize_text_field',
					 ),
				 ),
				 'account_id' => array(
					 'label'       => __( 'Account ID', 'doublescale'),
					 'type'        => 'string',
					 'required'    => false,
					 'arg_options' => array(
						 'sanitize_callback' => 'sanitize_text_field',
					 ),
				 ),
			 ),
		 );
	}

	/**
	 * Update account
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_account( $request ) {
		$account_id = $request->get_param( 'account_id' );
		if ( empty( $account_id ) ) {
			return new WP_Error( 'invalid_account_id', __( 'Account ID is required.', 'doublescale') );
		}

		$this->integration->update_setting( 'account_id', $account_id );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Account ID updated.', 'doublescale'),
			),
		);
	}

	/**
	 * Update account permissions check
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function update_account_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}
}
