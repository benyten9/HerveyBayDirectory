<?php
/**
 * REST controller for the support auto-close settings.
 *
 * Surfaces the configuration consumed by {@see AutoCloseRunner}:
 *   - GET  /doublescale/v1/support/auto-close-settings   → current settings + meta (tags)
 *   - POST /doublescale/v1/support/auto-close-settings   → save settings
 *   - POST /doublescale/v1/support/auto-close-settings/run-now → run the pass now (manual test)
 *
 * Modeled on {@see RestCustomFieldsController}: extends the shared
 * {@see \DoubleScale\Core\Abstracts\RestController} (namespace `doublescale/v1`),
 * gates reads with `has_support_access()` and writes/runs with
 * `can_access_support_settings()`, and returns a `module_disabled` 404 when the
 * Support module is off.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\Support
 */

namespace DoubleScale\Pro\Modules\Support\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Contacts\Models\TagModel;
use DoubleScale\Pro\Modules\Support\Services\AutoCloseRunner;
use DoubleScale\Pro\Modules\Support\Services\AutoCloseSettings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestAutoCloseController class.
 */
class RestAutoCloseController extends RestController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'support/auto-close-settings';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'read_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => array( $this, 'settings_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/run-now',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run_now' ),
					'permission_callback' => array( $this, 'settings_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Return the current settings plus the meta the UI needs to render the form
	 * (the list of selectable tags for include/exclude).
	 *
	 * @param WP_REST_Request $request Unused.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_settings( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		return new WP_REST_Response(
			array(
				'data' => array(
					'settings' => AutoCloseSettings::get(),
					'meta'     => array(
						'tags'              => $this->available_tags(),
						'max_inactive_days' => AutoCloseSettings::MAX_INACTIVE_DAYS,
					),
				),
			),
			200
		);
	}

	/**
	 * Persist the posted settings and return the normalized result.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_settings( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		$input = isset( $params['settings'] ) && is_array( $params['settings'] )
			? $params['settings']
			: ( is_array( $params ) ? $params : array() );

		$saved = AutoCloseSettings::save( $input );

		return new WP_REST_Response(
			array(
				'data'    => array( 'settings' => $saved ),
				'message' => __( 'Auto-close settings saved.', 'doublescale' ),
			),
			200
		);
	}

	/**
	 * Run the auto-close pass immediately (manual trigger for operators/testing).
	 *
	 * @param WP_REST_Request $request Unused.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_now( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$closed = ( new AutoCloseRunner() )->run();

		return new WP_REST_Response(
			array(
				'data'    => array( 'closed' => $closed ),
				/* translators: %d: number of tickets that were auto-closed. */
				'message' => sprintf( _n( '%d ticket was closed.', '%d tickets were closed.', $closed, 'doublescale' ), $closed ),
			),
			200
		);
	}

	/**
	 * Selectable tags shaped as `{ id, name }` for the include/exclude pickers.
	 *
	 * @return array<int, array{id:int, name:string}>
	 */
	private function available_tags(): array {
		$tags = TagModel::query()
			->orderBy( 'name', 'asc' )
			->get( array( 'id', 'name' ) );

		$out = array();
		foreach ( $tags as $tag ) {
			$out[] = array(
				'id'   => (int) $tag->id,
				'name' => (string) $tag->name,
			);
		}
		return $out;
	}

	/**
	 * Reads require support access (agents may view the configuration).
	 *
	 * @param WP_REST_Request $request Unused.
	 * @return bool|WP_Error
	 */
	public function read_permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( Permissions::has_support_access() ) {
			return true;
		}
		return new WP_Error( 'not_allowed', __( 'You do not have permission to access support tickets.', 'doublescale' ), array( 'status' => 403 ) );
	}

	/**
	 * Writes / manual runs require support-settings (manager-tier) access.
	 *
	 * @param WP_REST_Request $request Unused.
	 * @return bool|WP_Error
	 */
	public function settings_permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( Permissions::can_access_support_settings() ) {
			return true;
		}
		return new WP_Error( 'not_allowed', __( 'You do not have permission to manage support settings.', 'doublescale' ), array( 'status' => 403 ) );
	}
}
