<?php
/**
 * REST API: Task kanban statuses.
 *
 * @package DoubleScale\Pro\Modules\Tasks\Rest\Controllers
 */

namespace DoubleScale\Pro\Modules\Tasks\Rest\Controllers;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskStatusModel;
use DoubleScale\Pro\Modules\Tasks\Services\TaskStatusManager;
use Exception;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * RestTaskStatusController class.
 */
class RestTaskStatusController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'task-stages';

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
						'status' => array(
							'type'    => 'string',
							'default' => 'open',
							'enum'    => array( 'open', 'closed' ),
						),
						'color' => array(
							'type' => 'string',
						),
						'position' => array(
							'type' => 'integer',
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

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/reorder',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reorder_items' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
					'args'                => array(
						'ids' => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'integer' ),
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		try {
			$stages = TaskStatusManager::instance()->list_stages();

			return new WP_REST_Response(
				$stages->map(
					function ( $stage ) {
						return $this->prepare_stage_for_response( $stage );
					}
				)->values()->all(),
				200
			);
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
				return new WP_Error( 'missing_name', __( 'Stage name is required.', 'doublescale' ), array( 'status' => 400 ) );
			}

			$status   = sanitize_key( (string) ( $request->get_param( 'status' ) ?: 'open' ) );
			$color    = sanitize_text_field( (string) ( $request->get_param( 'color' ) ?: '#6d78d8' ) );
			$position = $request->has_param( 'position' ) ? (int) $request->get_param( 'position' ) : null;

			$stage = TaskStatusManager::instance()->create_stage( $name, $status, $color, $position );
			if ( ! $stage ) {
				return new WP_Error( 'creation_failed', __( 'Failed to create stage.', 'doublescale' ), array( 'status' => 500 ) );
			}

			return new WP_REST_Response( $this->prepare_stage_for_response( $stage ), 201 );
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
			$stage = TaskStatusModel::find( (int) $request->get_param( 'id' ) );
			if ( ! $stage ) {
				return new WP_Error( 'not_found', __( 'Stage not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			if ( $request->has_param( 'name' ) ) {
				$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
				if ( '' !== $name ) {
					$stage->name = $name;
				}
			}

			if ( $request->has_param( 'status' ) && ! $stage->is_protected ) {
				$status = sanitize_key( (string) $request->get_param( 'status' ) );
				if ( in_array( $status, array( 'open', 'closed' ), true ) ) {
					$stage->status = $status;
				}
			}

			if ( $request->has_param( 'color' ) ) {
				$stage->color = sanitize_text_field( (string) $request->get_param( 'color' ) );
			}

			if ( $request->has_param( 'sort_order' ) ) {
				$stage->sort_order = (int) $request->get_param( 'sort_order' );
			}

			$stage->save();

			return new WP_REST_Response( $this->prepare_stage_for_response( $stage ), 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		try {
			$stage = TaskStatusModel::find( (int) $request->get_param( 'id' ) );
			if ( ! $stage ) {
				return new WP_Error( 'not_found', __( 'Stage not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			if ( $stage->is_protected ) {
				return new WP_Error(
					'protected',
					__( 'The Open and Closed statuses cannot be deleted.', 'doublescale' ),
					array( 'status' => 400 )
				);
			}

			// Defense-in-depth: never delete the last open or last closed stage.
			$remaining_of_type = TaskStatusModel::where( 'status', $stage->status )
				->where( 'id', '!=', $stage->id )
				->count();
			if ( 0 === (int) $remaining_of_type ) {
				return new WP_Error(
					'protected',
					__( 'The Open and Closed statuses cannot be deleted.', 'doublescale' ),
					array( 'status' => 400 )
				);
			}

			$first_open = TaskStatusModel::where( 'status', 'open' )
				->where( 'id', '!=', $stage->id )
				->orderBy( 'sort_order', 'asc' )
				->first();

			$fallback_id = $first_open ? (int) $first_open->id : null;

			TaskModel::where( 'status_id', $stage->id )->get()->each(
				function ( $task ) use ( $fallback_id ) {
					$task->status_id = $fallback_id;
					$task->save();
				}
			);

			$stage->delete();

			return new WP_REST_Response( array( 'deleted' => true ), 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reorder_items( $request ) {
		try {
			$ids = array_map( 'intval', (array) $request->get_param( 'ids' ) );

			foreach ( $ids as $index => $status_id ) {
				TaskStatusModel::where( 'id', $status_id )->update( array( 'sort_order' => $index ) );
			}

			return new WP_REST_Response( array( 'reordered' => true ), 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @param TaskStatusModel $stage Status row.
	 * @return array<string, mixed>
	 */
	private function prepare_stage_for_response( $stage ) {
		return array(
			'id'           => (int) $stage->id,
			'name'         => (string) $stage->name,
			'status'       => (string) $stage->status,
			'is_protected' => (bool) $stage->is_protected,
			'color'        => (string) $stage->color,
			'sort_order'   => (int) $stage->sort_order,
			'created_at'   => (string) $stage->created_at,
			'updated_at'   => (string) $stage->updated_at,
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function get_items_permissions_check( $request ) {
		return Permissions::can_access_tasks_api();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function manage_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}
}
