<?php
/**
 * REST API: Project status controller.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\Rest\Controllers;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectStatusModel;
use DoubleScale\Pro\Modules\Projects\Services\ProjectManager;
use DoubleScale\Pro\Core\UserRoles\PermissionsCompat;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class RestProjectStatusController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'project-statuses';

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
					'permission_callback' => array( $this, 'read_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/reorder',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'reorder_items' ),
				'permission_callback' => array( $this, 'manage_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'read_permissions_check' ),
				),
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
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		ProjectManager::instance()->ensure_protected_statuses();
		$statuses = ProjectStatusModel::orderBy( 'position' )->get();
		$data     = array();
		foreach ( $statuses as $status ) {
			$data[] = $status->toArray();
		}
		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$status = ProjectStatusModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $status ) {
			return new WP_Error( 'status_not_found', __( 'Status not found.', 'doublescale' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response( $status->toArray(), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		if ( '' === $name ) {
			return new WP_Error( 'missing_name', __( 'Status name is required.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$max_position = (int) ProjectStatusModel::max( 'position' );
		$status       = ProjectStatusModel::create(
			array(
				'name'         => $name,
				'color'        => sanitize_text_field( (string) ( $request->get_param( 'color' ) ?: '#8775EC' ) ),
				'bg_color'     => sanitize_text_field( (string) ( $request->get_param( 'bg_color' ) ?: '#F4F2FE' ) ),
				'position'     => $max_position + 1,
				'is_completed' => rest_sanitize_boolean( $request->get_param( 'is_completed' ) ),
			)
		);

		return new WP_REST_Response( $status->toArray(), 201 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$status = ProjectStatusModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $status ) {
			return new WP_Error( 'status_not_found', __( 'Status not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$fields = array( 'name', 'color', 'bg_color', 'position', 'is_completed' );
		foreach ( $fields as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				$status->$field = $request->get_param( $field );
			}
		}
		if ( null !== $request->get_param( 'name' ) ) {
			$status->name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		}
		if ( null !== $request->get_param( 'is_completed' ) && ! $status->is_protected ) {
			$status->is_completed = rest_sanitize_boolean( $request->get_param( 'is_completed' ) );
		}
		$status->save();

		return new WP_REST_Response( $status->toArray(), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$status         = ProjectStatusModel::find( (int) $request->get_param( 'id' ) );
		$move_projects_to = (int) $request->get_param( 'move_projects_to' );
		if ( ! $status ) {
			return new WP_Error( 'status_not_found', __( 'Status not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		if ( $status->is_protected ) {
			return new WP_Error(
				'protected',
				__( 'The Open and Closed statuses cannot be deleted.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$remaining_of_type = ProjectStatusModel::where( 'is_completed', $status->is_completed ? 1 : 0 )
			->where( 'id', '!=', $status->id )
			->count();
		if ( 0 === (int) $remaining_of_type ) {
			return new WP_Error(
				'protected',
				__( 'The Open and Closed statuses cannot be deleted.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$count = $status->projects()->count();
		if ( $count > 0 ) {
			if ( $move_projects_to <= 0 ) {
				return new WP_Error(
					'status_has_projects',
					__( 'Status has projects. Specify move_projects_to before deletion.', 'doublescale' ),
					array( 'status' => 400, 'projects_count' => $count )
				);
			}
			$target = ProjectStatusModel::find( $move_projects_to );
			if ( ! $target ) {
				return new WP_Error( 'invalid_target_status', __( 'Target status not found.', 'doublescale' ), array( 'status' => 400 ) );
			}
			$status->projects()->update( array( 'status_id' => $target->id ) );
		}

		$status->delete();
		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reorder_items( $request ) {
		$status_ids = $request->get_param( 'status_ids' );
		if ( ! is_array( $status_ids ) || empty( $status_ids ) ) {
			return new WP_Error( 'invalid_reorder', __( 'status_ids is required.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$position = 0;
		foreach ( array_map( 'intval', $status_ids ) as $status_id ) {
			$status = ProjectStatusModel::find( $status_id );
			if ( ! $status ) {
				continue;
			}
			$status->position = $position++;
			$status->save();
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	public function read_permissions_check( $request ) {
		return PermissionsCompat::has_project_access();
	}

	public function manage_permissions_check( $request ) {
		return user_can( get_current_user_id(), 'doublescale_project_manage_statuses' );
	}
}
