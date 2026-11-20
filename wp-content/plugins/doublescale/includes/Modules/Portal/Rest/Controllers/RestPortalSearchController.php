<?php
/**
 * One search across the client portal.
 *
 *   GET /doublescale/v1/portal/search?q=garden
 *
 * Every signed-in client may call it; what it finds follows each area's own
 * rules ({@see PortalSearch}).
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Portal\Services\PortalIdentity;
use DoubleScale\Modules\Portal\Services\PortalSearch;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPortalSearchController.
 */
class RestPortalSearchController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'portal/search';

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
					'args'                => array(
						'q' => array(
							'type'      => 'string',
							'required'  => true,
							'maxLength' => 100,
						),
					),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$query = trim( sanitize_text_field( (string) $request->get_param( 'q' ) ) );
		if ( mb_strlen( $query ) < PortalSearch::MIN_LENGTH ) {
			return new WP_Error(
				'rest_invalid_param',
				/* translators: %d: letters needed */
				sprintf( __( 'Type at least %d letters to search.', 'doublescale' ), PortalSearch::MIN_LENGTH ),
				array( 'status' => 400 )
			);
		}

		return new WP_REST_Response(
			array(
				'query'  => $query,
				'groups' => PortalSearch::search( PortalIdentity::current_contact(), $query ),
			),
			200
		);
	}
}
