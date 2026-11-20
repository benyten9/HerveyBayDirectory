<?php
/**
 * The client closed the welcome of their first visit.
 *
 *   POST /doublescale/v1/portal/first-visit
 *
 * ({@see PortalWelcome}; the welcome itself comes with the bootstrap.)
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Portal\Services\PortalIdentity;
use DoubleScale\Modules\Portal\Services\PortalWelcome;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPortalFirstVisitController.
 */
class RestPortalFirstVisitController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'portal/first-visit';

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'mark_seen' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function mark_seen( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		PortalWelcome::mark_seen( get_current_user_id() );
		return new WP_REST_Response( array( 'seen' => true ), 200 );
	}
}
