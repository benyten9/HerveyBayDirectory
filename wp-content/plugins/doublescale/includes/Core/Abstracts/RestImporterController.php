<?php

/**
 * Class Importer REST Controller
 * This class is responsible for handling the Importer REST Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\Abstracts;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Exception;
use DoubleScale\Modules\Contacts\Abstracts\Importer;
use DoubleScale\Core\UserRoles\Permissions;

/**
 * Rest Importer Controller
 */
class RestImporterController extends RestController {


	/**
	 * Importer.
	 *
	 * @var Importer
	 */
	protected $integration;

	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $rest_base = 'importers';

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 *
	 * @param Importer $integration
	 */
	public function __construct( Importer $integration ) {
		$this->integration = $integration;
		$this->rest_base   = "importers/{$this->integration->slug}";

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
			array()
		);
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
