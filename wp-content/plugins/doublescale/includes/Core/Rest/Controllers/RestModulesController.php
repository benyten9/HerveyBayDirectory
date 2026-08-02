<?php
/**
 * REST Api: Modules Controller
 *
 * Exposes module metadata and allows toggling modules on/off.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\ModuleManager;
use DoubleScale\Core\UserRoles\UserRoles;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class RestModulesController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'modules';

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/role-impact',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_module_role_impact' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => array(
						'slug' => array(
							'required' => true,
							'type'     => 'string',
							// `deals` (pipeline) is a child of Sales and no longer
							// owns roles, so it has no role impact of its own.
							'enum'     => array( 'support', 'sales' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_modules' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_modules' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public function admin_permissions_check( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage modules.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Users assigned to roles that depend on a module (for disable warnings).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_module_role_impact( $request ) {
		$slug  = (string) $request->get_param( 'slug' );
		$roles = UserRoles::get_role_slugs_for_module( $slug );

		if ( empty( $roles ) ) {
			return new WP_Error(
				'invalid_module',
				__( 'This module does not have team roles tied to it.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$all_roles   = UserRoles::get_roles();
		$role_labels = array();
		foreach ( $roles as $role_slug ) {
			if ( isset( $all_roles[ $role_slug ] ) ) {
				$role_labels[] = $all_roles[ $role_slug ];
			}
		}

		$users = UserRoles::get_users_with_module_roles( $slug );

		return new WP_REST_Response(
			array(
				'slug'        => $slug,
				'user_count'  => count( $users ),
				'users'       => $users,
				'role_labels' => $role_labels,
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function get_modules( $request ) {
		return new WP_REST_Response( $this->build_modules_payload(), 200 );
	}

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_modules( $request ) {
		$incoming = $request->get_param( 'modules' );

		if ( ! is_array( $incoming ) ) {
			return new WP_Error(
				'invalid_param',
				__( 'The "modules" parameter must be an object of slug => boolean.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$all = ModuleManager::all();

		// Snapshot before write so lifecycle sync can compare.
		$stored = get_option( 'doublescale_enabled_modules', array() );

		// Merge incoming with stored values.
		$proposed = is_array( $stored ) ? $stored : array();
		foreach ( $incoming as $slug => $enabled ) {
			$slug   = (string) $slug;
			$module = $all[ $slug ] ?? null;

			if ( $module ) {
				if ( ! $module->is_toggleable() ) {
					continue;
				}
			} elseif ( ! function_exists( 'doublescale_is_phantom_module_toggle_slug' )
				|| ! doublescale_is_phantom_module_toggle_slug( $slug ) ) {
				continue;
			}

			$proposed[ $slug ] = (bool) $enabled;
		}

		// Lifecycle activate/deactivate runs inside update_option hooks and is
		// isolated with try/catch in ModuleManager so failures there do not turn
		// this request into a REST error after the option is already written.
		update_option( 'doublescale_enabled_modules', $proposed );

		// Ensure request-level enabled cache reflects the just-written option even
		// if a lifecycle hook left the cache warm from mid-activation reads.
		ModuleManager::flushCache();

		return new WP_REST_Response(
			array(
				'success' => true,
				'modules' => $this->build_modules_payload(),
			),
			200
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function build_modules_payload(): array {
		return doublescale_build_modules_list_payload( ModuleManager::all() );
	}
}
