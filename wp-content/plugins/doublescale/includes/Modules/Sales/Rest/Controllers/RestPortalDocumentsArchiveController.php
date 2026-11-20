<?php
/**
 * GET /doublescale/v1/portal/documents/archive?type=all|invoice|proposal|contract|credit_note
 *
 * The client's documents of that type as one zip of PDFs
 * ({@see PortalDocumentsArchive}).
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Documents\Services\DocumentDownload;
use DoubleScale\Modules\Portal\Services\PortalIdentity;
use DoubleScale\Modules\Sales\Services\PortalDocumentsArchive;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * RestPortalDocumentsArchiveController.
 */
class RestPortalDocumentsArchiveController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'portal/documents/archive';

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
					'callback'            => array( $this, 'get_archive' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
					'args'                => array(
						'type' => array(
							'type'    => 'string',
							'enum'    => PortalDocumentsArchive::TYPES,
							'default' => 'all',
						),
					),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function get_archive( WP_REST_Request $request ) {
		$type  = (string) $request->get_param( 'type' );
		$bytes = PortalDocumentsArchive::build( PortalIdentity::current_contact(), $type );
		if ( $bytes instanceof WP_Error ) {
			return $bytes;
		}
		return DocumentDownload::stream( $bytes, 'documents-' . $type . '-' . current_time( 'Y-m-d' ), 'zip', 'application/zip' );
	}
}
