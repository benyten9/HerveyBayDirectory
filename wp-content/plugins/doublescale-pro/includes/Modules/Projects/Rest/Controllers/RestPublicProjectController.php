<?php
/**
 * Public guest access to projects via hash.
 *
 * @package DoubleScale\Pro\Modules\Projects
 */

namespace DoubleScale\Pro\Modules\Projects\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;
use DoubleScale\Pro\Modules\Projects\Rest\ProjectShaper;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPublicProjectController class.
 */
class RestPublicProjectController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'projects/public';

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<hash>[a-f0-9]{32})',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$disabled = $this->require_module( 'projects' );
		if ( $disabled ) {
			return $disabled;
		}
		if ( ! $this->check_rate_limit() ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'doublescale' ), array( 'status' => 429 ) );
		}

		$project = $this->resolve_by_hash( $request );
		if ( is_wp_error( $project ) ) {
			return $project;
		}

		return new WP_REST_Response( ProjectShaper::shape_public( $project ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return ProjectModel|WP_Error
	 */
	private function resolve_by_hash( WP_REST_Request $request ) {
		$hash    = (string) $request->get_param( 'hash' );
		$project = ProjectModel::get_by_hash( $hash );
		if ( ! $project ) {
			return new WP_Error( 'not_found', __( 'Project not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$project->loadMissing( 'status' );

		return $project;
	}

	/**
	 * @return bool
	 */
	private function check_rate_limit(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- IP used only for rate-limit key.
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
		$key   = 'ds_project_pub_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count > 120 ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}
}
