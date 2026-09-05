<?php
/**
 * REST controller for sales assignable team users.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\UserRoles;
use DoubleScale\Modules\Sales\Capabilities;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestSalesUsersController class.
 */
class RestSalesUsersController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'sales/assignable-users';

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
					'callback'            => array( $this, 'get_assignable_users' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);
	}

	/**
	 * @return bool
	 */
	public function get_items_permissions_check( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return Capabilities::can_view_sales() || Capabilities::can_assign_sales_rep();
	}

	/**
	 * Users who may assign sales reps receive every sales-team user; reps receive only themselves.
	 *
	 * @param WP_REST_Request $request Unused. // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
	 * @return WP_REST_Response
	 */
	public function get_assignable_users( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$disabled = $this->require_module( 'sales' );
		if ( $disabled ) {
			return $disabled;
		}

		if ( ! Capabilities::can_assign_sales_rep() ) {
			$self = wp_get_current_user();
			return new WP_REST_Response( array( $this->shape_user( $self ) ), 200 );
		}

		$users  = UserRoles::get_users_with_module_roles( 'sales' );
		$shaped = array();
		$seen   = array();

		foreach ( $users as $user ) {
			$id = (int) $user['id'];
			if ( $id <= 0 || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$shaped[]    = array(
				'id'           => $id,
				'display_name' => (string) $user['name'],
				'email'        => (string) $user['email'],
			);
		}

		$current = wp_get_current_user();
		if ( $current instanceof \WP_User && $current->ID > 0 && empty( $seen[ (int) $current->ID ] ) ) {
			array_unshift( $shaped, $this->shape_user( $current ) );
		}

		return new WP_REST_Response( $shaped, 200 );
	}

	/**
	 * @param \WP_User $user WordPress user.
	 * @return array{id: int, display_name: string, email: string}
	 */
	private function shape_user( \WP_User $user ): array {
		return array(
			'id'           => (int) $user->ID,
			'display_name' => (string) $user->display_name,
			'email'        => (string) $user->user_email,
		);
	}
}
