<?php
/**
 * REST API: Product groups.
 *
 * @package DoubleScale\Pro\Modules\ProductCatalog\Rest\Controllers
 */

namespace DoubleScale\Pro\Modules\ProductCatalog\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Pro\Modules\ProductCatalog\Models\ProductGroupModel;
use DoubleScale\Pro\Modules\ProductCatalog\Models\ProductModel;
use Exception;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestProductGroupController class.
 */
class RestProductGroupController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'product-groups';

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
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
					'args'                => array(
						'name' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		unset( $request );

		return Permissions::has_sales_rep_access();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function manage_permissions_check( $request ) {
		unset( $request );

		return Permissions::has_sales_manager_access();
	}

	/**
	 * Groups are a short list, so this returns them all (no pagination).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		unset( $request );

		try {
			$groups = ProductGroupModel::query()->orderBy( 'name', 'asc' )->get();

			$data = array();
			foreach ( $groups as $group ) {
				$data[] = $this->prepare_group_for_response( $group );
			}

			return new WP_REST_Response( $data, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		try {
			$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
			if ( '' === $name ) {
				return new WP_Error(
					'doublescale_product_group_invalid_name',
					__( 'Group name is required.', 'doublescale' ),
					array( 'status' => 400 )
				);
			}

			$group = ProductGroupModel::create( array( 'name' => $name ) );

			return new WP_REST_Response( $this->prepare_group_for_response( $group ), 201 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		try {
			$group = ProductGroupModel::find( (int) $request->get_param( 'id' ) );
			if ( ! $group ) {
				return new WP_Error( 'not_found', __( 'Group not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
			if ( '' === $name ) {
				return new WP_Error(
					'doublescale_product_group_invalid_name',
					__( 'Group name is required.', 'doublescale' ),
					array( 'status' => 400 )
				);
			}

			$group->name = $name;
			$group->save();

			return new WP_REST_Response( $this->prepare_group_for_response( $group ), 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Deleting a group only detaches its products — a group is a label, and
	 * products (and any documents built from them) must survive it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		try {
			$id    = (int) $request->get_param( 'id' );
			$group = ProductGroupModel::find( $id );
			if ( ! $group ) {
				return new WP_Error( 'not_found', __( 'Group not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			ProductModel::query()->where( 'group_id', $id )->update( array( 'group_id' => null ) );

			$group->delete();

			return new WP_REST_Response( array( 'deleted' => true ), 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @param ProductGroupModel $group Group.
	 * @return array<string, mixed>
	 */
	private function prepare_group_for_response( $group ): array {
		return array(
			'id'   => (int) $group->id,
			'name' => (string) $group->name,
		);
	}
}
