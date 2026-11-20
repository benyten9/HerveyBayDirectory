<?php
/**
 * GET /doublescale/v1/admin/catalog — the automation catalog and merge tags.
 *
 * These used to be printed into every DoubleScale admin page (~200 KB, ~140 ms).
 * The admin app now fetches them when a screen needs them (automation
 * screens, merge tag pickers, lead scoring, email conditions) and in the
 * background once the page is idle. Same data and same audience as before:
 * anyone who can open the DoubleScale admin (`doublescale_access`).
 *
 * @package DoubleScale
 */

namespace DoubleScale\Admin\Rest;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Admin\AdminConfig;
use DoubleScale\Core\Abstracts\RestController;
use WP_REST_Response;
use WP_REST_Server;

class RestCatalogController extends RestController {

	protected $rest_base = 'admin/catalog';

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_catalog' ),
					'permission_callback' => array( $this, 'get_catalog_permissions_check' ),
				),
			)
		);
	}

	/**
	 * @return bool
	 */
	public function get_catalog_permissions_check() {
		return current_user_can( 'doublescale_access' );
	}

	/**
	 * @return WP_REST_Response
	 */
	public function get_catalog() {
		$response = new WP_REST_Response( AdminConfig::get_catalog() );
		// Per user and changes with settings/modules: never cache in shared caches.
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}
}
