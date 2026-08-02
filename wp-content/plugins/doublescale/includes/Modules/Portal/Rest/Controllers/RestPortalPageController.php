<?php
/**
 * Admin Client Portal page management.
 *
 *   GET  /doublescale/v1/portal/page   → page status (id, view/edit URLs, shortcode)
 *   POST /doublescale/v1/portal/page   → adopt-or-create the portal page
 *
 * Powers the "Client Portal" settings card: shows where the portal page lives
 * and lets an admin (re)create it if it was never provisioned or was trashed.
 * Admin-only — distinct from the customer-facing `/portal/*` endpoints, which
 * gate on {@see \DoubleScale\Modules\Portal\Services\PortalIdentity}.
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Portal\Services\PortalPageProvisioner;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPortalPageController.
 */
class RestPortalPageController extends RestController {

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'portal';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/page',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_page' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_page' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * Managing the portal page is a site-settings action.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		return current_user_can( 'manage_options' );
	}

	/**
	 * Return the current portal page status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_page( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		return new WP_REST_Response( PortalPageProvisioner::get_status(), 200 );
	}

	/**
	 * Adopt-or-create the portal page (the settings "Create page" action).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_page( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		$page_id = PortalPageProvisioner::provision();

		if ( $page_id <= 0 ) {
			return new WP_Error(
				'portal_page_create_failed',
				__( 'The portal page could not be created.', 'doublescale' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response( PortalPageProvisioner::get_status(), 200 );
	}
}
