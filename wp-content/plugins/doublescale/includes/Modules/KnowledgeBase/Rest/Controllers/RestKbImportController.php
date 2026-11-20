<?php
/**
 * Import from BetterDocs, for the Articles screen:
 *
 *   GET  kb/import/betterdocs   What there is to copy ({@see BetterDocsImport::scan()}).
 *   POST kb/import/betterdocs   Copy the next batch; the screen calls again
 *                               until `remaining` is 0. `duplicates`: skip
 *                               (default) or copy docs already here.
 *
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\KnowledgeBase\Services\BetterDocsImport;
use DoubleScale\Modules\KnowledgeBase\Services\KbAccess;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestKbImportController class.
 */
class RestKbImportController extends RestController {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/kb/import/betterdocs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'scan' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
					'args'                => array(
						'limit'      => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
							'default' => BetterDocsImport::BATCH,
						),
						// Docs whose article is already here: skip them or copy them too.
						'duplicates' => array(
							'type'    => 'string',
							'enum'    => array( BetterDocsImport::SKIP, BetterDocsImport::COPY ),
							'default' => BetterDocsImport::SKIP,
						),
					),
				),
			)
		);
	}

	/**
	 * Help center managers bring their articles over.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function manage_permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		return KbAccess::can_manage();
	}

	/**
	 * GET kb/import/betterdocs
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function scan( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		$disabled = $this->require_module( 'knowledge_base' );
		if ( $disabled ) {
			return $disabled;
		}

		return new WP_REST_Response( BetterDocsImport::scan(), 200 );
	}

	/**
	 * POST kb/import/betterdocs
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run( $request ) {
		$disabled = $this->require_module( 'knowledge_base' );
		if ( $disabled ) {
			return $disabled;
		}

		$result = BetterDocsImport::run( (int) $request->get_param( 'limit' ), (string) $request->get_param( 'duplicates' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}
}
