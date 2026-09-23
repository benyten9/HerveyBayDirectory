<?php
/**
 * REST: portal account provisioning (settings + backlog sync).
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Portal\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Portal\Services\PortalUserProvisioner;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class RestPortalUsersController extends RestController {

	protected $rest_base = 'portal/users';

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'sync' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'after_id'     => array( 'type' => 'integer', 'default' => 0 ),
					'limit'        => array( 'type' => 'integer', 'default' => 100 ),
					'scoped'       => array( 'type' => 'boolean', 'default' => true ),
					'send_welcome' => array( 'type' => 'boolean', 'default' => true ),
				),
			)
		);
	}

	/**
	 * Creating accounts is an administrative action.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		return current_user_can( 'manage_options' ) || current_user_can( 'create_users' );
	}

	public function get_settings( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return new WP_REST_Response( PortalUserProvisioner::settings(), 200 );
	}

	public function update_settings( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		return new WP_REST_Response( PortalUserProvisioner::update_settings( $body ), 200 );
	}

	public function get_status( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return new WP_REST_Response( PortalUserProvisioner::status(), 200 );
	}

	public function sync( WP_REST_Request $request ) {
		$result = PortalUserProvisioner::sync_chunk(
			(int) $request->get_param( 'after_id' ),
			(int) $request->get_param( 'limit' ),
			(bool) $request->get_param( 'scoped' ),
			(bool) $request->get_param( 'send_welcome' )
		);
		$result['status'] = PortalUserProvisioner::status();
		return new WP_REST_Response( $result, 200 );
	}
}
