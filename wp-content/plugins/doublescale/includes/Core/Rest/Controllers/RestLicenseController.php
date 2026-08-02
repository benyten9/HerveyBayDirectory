<?php

/**
 * REST Api: License Controller
 *
 * Provides license, plan, and plugin status information.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 * @subpackage Api
 */

namespace DoubleScale\Core\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Website\License;

/**
 * RestLicenseController class.
 *
 * @since 1.0.0
 */
class RestLicenseController extends RestController {

	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = 'license';

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
			'/' . $this->rest_base . '/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'get_status_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Permission check for the status endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error
	 */
	public function get_status_permissions_check( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You are not currently logged in.', 'doublescale' ),
				array( 'status' => 401 )
			);
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		// Mobile app users authenticate with application passwords; allow any
		// authenticated CRM user to read site license/plan status for gating.
		if ( user_can( get_current_user_id(), 'read' ) ) {
			return true;
		}

		return new WP_Error(
			'doublescale_rest_license_forbidden',
			__( 'Sorry, you are not allowed to access this endpoint.', 'doublescale' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Get license, plan, and plugin status.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_status( WP_REST_Request $request ) {
		$is_active      = defined( 'DOUBLESCALE_VERSION' );
		$plugin_version = $is_active ? DOUBLESCALE_VERSION : null;

		$license_instance = License::instance();
		$license_info     = $license_instance->get_license_info( false );

		$license = null;
		$plan    = null;

		if ( $license_info ) {
			$license = array(
				'status'       => $license_info['status'] ?? null,
				'status_label' => $license_info['status_label'] ?? null,
				'expires'      => $license_info['expires'] ?? null,
				'last_check'   => $license_info['last_check'] ?? null,
			);

			$plan = array(
				'key'   => $license_info['plan'] ?? null,
				'label' => $license_info['plan_label'] ?? null,
			);
		}

		$response = array(
			'is_active' => $is_active,
			'version'   => $plugin_version,
			'license'   => $license,
			'plan'      => $plan,
		);

		if ( ! empty( $license_info['upgrades'] ) ) {
			$upgrades = array();
			foreach ( $license_info['upgrades'] as $upgrade_key => $upgrade_data ) {
				$upgrades[] = array(
					'plan_key'   => $upgrade_key,
					'plan_label' => $upgrade_data['plan_label'] ?? null,
					'url'        => $upgrade_data['url'] ?? null,
				);
			}
			$response['upgrades'] = $upgrades;
		}

		return new WP_REST_Response( $response, 200 );
	}
}
