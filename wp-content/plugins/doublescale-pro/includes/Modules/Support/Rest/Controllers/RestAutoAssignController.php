<?php
/**
 * GET|POST support/auto-assign — Support → Auto assign (helpdesk settings
 * tier only): the settings, and who can take part (everyone with helpdesk
 * access, with whether they are online now).
 *
 * @package DoubleScale\Pro\Modules\Support
 */

namespace DoubleScale\Pro\Modules\Support\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\Support\Services\TeamPresence;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Core\UserRoles\UserRoles;
use DoubleScale\Pro\Modules\Support\Services\AutoAssignSettings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestAutoAssignController class.
 */
class RestAutoAssignController extends RestController {

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/support/auto-assign',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_settings() {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		return new WP_REST_Response(
			array(
				'data' => array(
					'settings' => AutoAssignSettings::get(),
					'meta'     => array( 'agents' => $this->agents() ),
				),
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_settings( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_params();
		$input  = isset( $params['settings'] ) && is_array( $params['settings'] ) ? $params['settings'] : array();

		return new WP_REST_Response( array( 'data' => array( 'settings' => AutoAssignSettings::save( $input ) ) ), 200 );
	}

	/**
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( Permissions::can_access_support_settings() ) {
			return true;
		}

		return new WP_Error( 'not_allowed', __( 'You do not have permission to manage support settings.', 'doublescale' ), array( 'status' => 403 ) );
	}

	/**
	 * Everyone who can take a ticket (the ticket screen's assignee list).
	 *
	 * @return array<int, array{id: int, name: string, online: bool}>
	 */
	private function agents(): array {
		$users = get_users(
			array(
				'role__in' => array_merge( array( UserRoles::ADMINISTRATOR ), UserRoles::get_assignable_role_slugs() ),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);
		$out   = array();
		foreach ( $users as $user ) {
			if ( Permissions::has_support_access( (int) $user->ID ) ) {
				$out[] = array(
					'id'     => (int) $user->ID,
					'name'   => (string) $user->display_name,
					'online' => TeamPresence::is_online( (int) $user->ID ),
				);
			}
		}

		return $out;
	}
}
