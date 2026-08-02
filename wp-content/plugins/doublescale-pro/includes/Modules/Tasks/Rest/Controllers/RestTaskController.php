<?php
/**
 * REST Api: Task Controller
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 * @subpackage Api
 */

namespace DoubleScale\Pro\Modules\Tasks\Rest\Controllers;

use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Pro\Modules\Projects\Capabilities;
use WP_Error;
use Exception;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;
use DoubleScale\Pro\Modules\Tasks\Models\SubtaskModel;
use DoubleScale\Pro\Modules\Tasks\Models\SubtaskGroupModel;
use DoubleScale\Core\Models\UserModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Core\Constants\TaskEntityType;
use DoubleScale\Core\Constants\TaskStatus;
use DoubleScale\Core\Constants\TaskType;
use DoubleScale\Core\Constants\TaskPriority;
use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;
use DoubleScale\Modules\Activities\Models\ActivityCommentModel;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Activities\Services\ActivityManager;
use DoubleScale\Pro\Modules\Tasks\Services\TaskStatusManager;
use DoubleScale\Pro\Modules\Tasks\Services\TaskCommentNotifier;
use DoubleScale\Pro\Modules\Tasks\Services\TaskCloneService;
use DoubleScale\Pro\Modules\Tasks\Models\TaskLabelModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskStatusModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskRecurrenceModel;
use DoubleScale\Pro\Modules\Tasks\Recurrences\TaskRecurrenceScheduler;
use DoubleScale\Core\Models\AttachmentModel;
use DoubleScale\Core\Services\AttachmentService;

/**
 * RestTaskController is REST api controller class for tasks
 *
 * @since 1.0.0
 */
class RestTaskController extends RestController {

	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = 'tasks';

	/**
	 * Polymorphic attachable_type for task file attachments.
	 */
	private const TASK_ATTACHABLE_TYPE = 'task';

	/**
	 * Maximum upload size per task attachment (10 MB).
	 */
	private const TASK_ATTACHMENT_MAX_BYTES = 10485760;

	/**
	 * Maximum active attachments per task.
	 */
	private const TASK_ATTACHMENT_MAX_COUNT = 20;

	/**
	 * Register the routes for the controller.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// List and Create tasks
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
			)
		);

		// Get, Update, Delete single task
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( false ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				),
			)
		);

		// Clone a task
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/clone',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'clone_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'id' => array(
							'description' => __( 'Task ID to clone.', 'doublescale' ),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
			)
		);

		// Mark task as completed
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/complete',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'mark_completed' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'id' => array(
							'description' => __( 'Task ID.', 'doublescale'),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
			)
		);

		// Mark task as pending
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/pending',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'mark_pending' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'id' => array(
							'description' => __( 'Task ID.', 'doublescale'),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
			)
		);

		// Bulk delete tasks
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bulk-delete',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'bulk_delete' ),
					'permission_callback' => array( $this, 'bulk_action_permissions_check' ),
					'args'                => array(
						'ids' => array(
							'description' => __( 'Array of task IDs to delete', 'doublescale'),
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'required'    => true,
						),
					),
				),
			)
		);

		// Bulk mark tasks as completed
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bulk-complete',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'bulk_complete' ),
					'permission_callback' => array( $this, 'bulk_action_permissions_check' ),
					'args'                => array(
						'ids' => array(
							'description' => __( 'Array of task IDs to mark as completed', 'doublescale'),
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'required'    => true,
						),
					),
				),
			)
		);

		// Bulk mark tasks as pending
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bulk-pending',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'bulk_pending' ),
					'permission_callback' => array( $this, 'bulk_action_permissions_check' ),
					'args'                => array(
						'ids' => array(
							'description' => __( 'Array of task IDs to mark as pending', 'doublescale'),
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'required'    => true,
						),
					),
				),
			)
		);

		// List and create subtasks for a task
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/subtasks',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_subtasks' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_subtask' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'title' => array(
							'description' => __( 'Subtask title.', 'doublescale'),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);

		// Update or delete a single subtask
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/subtasks/(?P<subtask_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_subtask' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_subtask' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		// Reorder subtasks
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/subtasks/reorder',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reorder_subtasks' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'ids' => array(
							'description' => __( 'Ordered array of subtask IDs.', 'doublescale'),
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'required'    => true,
						),
					),
				),
			)
		);

		// List and create subtask groups
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/subtask-groups',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_subtask_groups' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_subtask_group' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'title' => array(
							'description' => __( 'Group title.', 'doublescale'),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);

		// Update or delete a subtask group
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/subtask-groups/(?P<group_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_subtask_group' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_subtask_group' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		// Reorder subtask groups
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/subtask-groups/reorder',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reorder_subtask_groups' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'ids' => array(
							'description' => __( 'Ordered array of group IDs.', 'doublescale'),
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'required'    => true,
						),
					),
				),
			)
		);

		// Clone a subtask
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/subtasks/(?P<subtask_id>\d+)/clone',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'clone_subtask' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		// Convert a subtask into a standalone task
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/subtasks/(?P<subtask_id>\d+)/convert-to-task',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'convert_subtask_to_task' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		// Convert a standalone task into a subtask on another task
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/convert-to-subtask',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'convert_task_to_subtask' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'parent_task_id' => array(
							'description' => __( 'Parent task ID.', 'doublescale'),
							'type'        => 'integer',
							'required'    => true,
						),
						'group_id'       => array(
							'description' => __( 'Target subtask group ID (null for ungrouped).', 'doublescale'),
							'type'        => array( 'integer', 'null' ),
						),
					),
				),
			)
		);

		// Move a subtask to another group
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/subtasks/(?P<subtask_id>\d+)/move',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'move_subtask' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'group_id' => array(
							'description' => __( 'Target group ID (null for ungrouped).', 'doublescale'),
							'type'        => array( 'integer', 'null' ),
						),
					),
				),
			)
		);

		// Task comments (note activities scoped to the task).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/comments',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_comments' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => $this->get_task_feed_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_comment' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'body' => array(
							'description' => __( 'Comment body (HTML).', 'doublescale'),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/comments/(?P<comment_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_comment' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'body' => array(
							'description' => __( 'Updated comment body (HTML).', 'doublescale'),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_comment' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/comments/(?P<comment_id>\d+)/replies',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_comment_reply' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'body' => array(
							'description' => __( 'Reply body (HTML).', 'doublescale'),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/comments/(?P<comment_id>\d+)/replies/(?P<reply_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_comment_reply' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'body' => array(
							'description' => __( 'Updated reply body (HTML).', 'doublescale'),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_comment_reply' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		// Task file attachments.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/attachments',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_attachments' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_attachment' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/attachments/(?P<attachment_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_attachment' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		// Global workspace task labels (CRUD).
		register_rest_route(
			$this->namespace,
			'/task-labels',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_labels' ),
					'permission_callback' => array( $this, 'get_labels_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_label' ),
					'permission_callback' => array( $this, 'manage_labels_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/task-labels/(?P<label_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_label' ),
					'permission_callback' => array( $this, 'manage_labels_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_label' ),
					'permission_callback' => array( $this, 'manage_labels_permissions_check' ),
				),
			)
		);

		// Per-task label assignment.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/labels',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_task_labels' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_label_for_task' ),
					'permission_callback' => array( $this, 'manage_labels_permissions_check' ),
				),
				// PUT only — EDITABLE also matches POST and would shadow create_label_for_task.
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'set_task_labels' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		// Per-task recurrence rule (repeat schedule).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/recurrence',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_recurrence' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'set_recurrence' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_recurrence' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		// Task activity log (all activities associated with the task).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/activity',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_activity' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => $this->get_task_feed_collection_params(),
				),
			)
		);
	}

	/**
	 * Shared query params for paginated task comment/activity feeds.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_task_feed_collection_params() {
		return array(
			'order'    => array(
				'description' => __( 'Sort order: newest (default) or oldest.', 'doublescale'),
				'type'        => 'string',
				'enum'        => array( 'newest', 'oldest' ),
				'default'     => 'newest',
			),
			'page'     => array(
				'description' => __( 'Page number.', 'doublescale'),
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			),
			'per_page' => array(
				'description' => __( 'Items per page.', 'doublescale'),
				'type'        => 'integer',
				'default'     => 10,
				'minimum'     => 1,
				'maximum'     => 50,
			),
			'type'     => array(
				'description' => __( 'Activity type filter.', 'doublescale'),
				'type'        => 'string',
				'enum'        => array( 'all', 'task', 'note', 'call_logged', 'email_sent', 'meeting_scheduled', 'files' ),
				'default'     => 'all',
			),
		);
	}

	/**
	 * Parse sort/page params for task comment and activity feeds.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{sort_order: string, page: int, per_page: int, type: string}
	 */
	private function parse_task_feed_request( $request ) {
		$order = strtolower( (string) ( $request->get_param( 'order' ) ?: 'newest' ) );
		$page  = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$size  = (int) ( $request->get_param( 'per_page' ) ?: 10 );
		$size  = max( 1, min( 50, $size ) );
		$type  = strtolower( (string) ( $request->get_param( 'type' ) ?: 'all' ) );
		$allowed_types = array( 'all', 'task', 'note', 'call_logged', 'email_sent', 'meeting_scheduled', 'files' );
		if ( ! in_array( $type, $allowed_types, true ) ) {
			$type = 'all';
		}

		return array(
			'sort_order' => 'oldest' === $order ? 'asc' : 'desc',
			'page'       => $page,
			'per_page'   => $size,
			'type'       => $type,
		);
	}

	/**
	 * Wrap a paginated task feed for REST responses.
	 *
	 * @param array<int, array<string, mixed>> $items     Shaped rows.
	 * @param \Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator Paginator.
	 * @return array{items: array<int, array<string, mixed>>, pagination: array<string, int|bool>}
	 */
	private function prepare_task_feed_paginated_response( $items, $paginator ) {
		return array(
			'items'      => $items,
			'pagination' => array(
				'page'        => (int) $paginator->currentPage(),
				'per_page'    => (int) $paginator->perPage(),
				'total'       => (int) $paginator->total(),
				'total_pages' => (int) $paginator->lastPage(),
				'has_more'    => $paginator->hasMorePages(),
			),
		);
	}

	/**
	 * Schema for the task
	 *
	 * @since 1.0.0
	 *
	 * @return array $schema The task schema
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'task',
			'type'       => 'object',
			'properties' => array(
				'id'           => array(
					'description' => __( 'Unique identifier for the task.', 'doublescale'),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'title'        => array(
					'description'  => __( 'Task title.', 'doublescale'),
					'type'         => 'string',
					'required'     => true,
					'args_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'description'  => array(
					'description'  => __( 'Task description/notes.', 'doublescale'),
					'type'         => 'string',
					'args_options' => array(
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
				'contact_id'   => array(
					'description' => __( 'Contact ID (for frontend compatibility).', 'doublescale'),
					'type'        => 'integer',
				),
				'deal_id'      => array(
					'description' => __( 'Deal ID (for frontend compatibility, Pro feature).', 'doublescale'),
					'type'        => 'integer',
				),
				'deals'        => array(
					'description' => __( 'Deal ID as string (from InfiniteScrollSelect, for frontend compatibility).', 'doublescale'),
					'type'        => array( 'string', 'integer' ),
				),
				'entity_type'  => array(
					'description' => __( 'Entity type: 1=Contact, 2=Deal.', 'doublescale'),
					'type'        => 'integer',
					'enum'        => array( TaskEntityType::CONTACT, TaskEntityType::DEAL, TaskEntityType::PROJECT ),
				),
				'entity_id'    => array(
					'description' => __( 'Entity ID (contact or deal).', 'doublescale'),
					'type'        => 'integer',
				),
				'assigned_to'  => array(
					'description' => __( 'WordPress user ID assigned to this task.', 'doublescale'),
					'type'        => array( 'integer', 'string' ),
				),
				'task_type'    => array(
					'description' => __( 'Task type.', 'doublescale'),
					'type'        => 'string',
					'required'    => true,
					'enum'        => array_keys( TaskType::get_all() ),
				),
				'status'         => array(
					'description' => __( 'Task database status (pending/completed).', 'doublescale'),
					'type'        => 'string',
					'required'    => true,
					'enum'        => array_keys( TaskStatus::get_db_statuses() ),
				),
				'display_status' => array(
					'description' => __( 'Calculated display status based on due date (pending/completed/overdue/upcoming/due_today).', 'doublescale'),
					'type'        => 'string',
					'readonly'    => true,
					'enum'        => array_keys( TaskStatus::get_all() ),
				),
				'priority'     => array(
					'description' => __( 'Task priority.', 'doublescale'),
					'type'        => 'string',
					'required'    => true,
					'enum'        => array_keys( TaskPriority::get_all() ),
				),
				'due_date'     => array(
					'description' => __( 'Due date (Y-m-d format).', 'doublescale'),
					'type'        => 'string',
					'format'      => 'date',
					'required'    => true,
				),
				'due_time'     => array(
					'description' => __( 'Due time (H:i:s format).', 'doublescale'),
					'type'        => 'string',
					'format'      => 'time',
				),
				'completed_at' => array(
					'description' => __( 'Completion timestamp.', 'doublescale'),
					'type'        => 'string',
					'format'      => 'date-time',
					'readonly'    => true,
				),
				'created_at'   => array(
					'description' => __( 'Creation timestamp.', 'doublescale'),
					'type'        => 'string',
					'format'      => 'date-time',
					'readonly'    => true,
				),
				'updated_at'   => array(
					'description' => __( 'Last update timestamp.', 'doublescale'),
					'type'        => 'string',
					'format'      => 'date-time',
					'readonly'    => true,
				),
				'custom_fields' => array(
					'description' => __( 'Task custom field values.', 'doublescale'),
					'type'        => 'array',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'    => array(
								'type' => 'integer',
							),
							'value' => array(
								'type' => 'string',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * REST query parameters for paginated task listing.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_collection_params() {
		return array(
			'per_page'    => array(
				'description' => __( 'Number of items to fetch.', 'doublescale'),
				'type'        => 'integer',
				'default'     => 10,
				'minimum'     => 1,
				'maximum'     => 200,
			),
			'page'        => array(
				'description' => __( 'Page number.', 'doublescale'),
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			),
			'status'      => array(
				'description' => __( 'Filter by task status.', 'doublescale'),
				'type'        => 'string',
				'enum'        => array_keys( TaskStatus::get_all() ),
			),
			'assigned_to' => array(
				'description' => __( 'Filter by assigned user ID.', 'doublescale'),
				'type'        => 'integer',
			),
			'entity_type' => array(
				'description' => __( 'Filter by entity type (1=Contact, 2=Deal).', 'doublescale'),
				'type'        => 'integer',
				'enum'        => array( TaskEntityType::CONTACT, TaskEntityType::DEAL, TaskEntityType::PROJECT ),
			),
			'entity_id'   => array(
				'description' => __( 'Filter by entity ID (contact or deal).', 'doublescale'),
				'type'        => 'integer',
			),
			'priority'    => array(
				'description' => __( 'Filter by priority.', 'doublescale'),
				'type'        => 'string',
				'enum'        => array_keys( TaskPriority::get_all() ),
			),
			'task_type'   => array(
				'description' => __( 'Filter by task type.', 'doublescale'),
				'type'        => 'string',
				'enum'        => array_keys( TaskType::get_all() ),
			),
			'keywords'    => array(
				'description' => __( 'Search tasks by title or description.', 'doublescale'),
				'type'        => 'string',
			),
			'label'       => array(
				'description' => __( 'Filter by label ID.', 'doublescale'),
				'type'        => 'integer',
			),
			'status_id'    => array(
				'description' => __( 'Filter by kanban stage ID.', 'doublescale'),
				'type'        => 'integer',
			),
		);
	}

	/**
	 * Get all tasks
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		try {
			TaskStatusManager::instance()->ensure_default_stages();
			TaskStatusManager::instance()->backfill_unstaged_tasks();

			$per_page    = $request->get_param( 'per_page' ) ?: 10;
			$page        = $request->get_param( 'page' ) ?: 1;
			$status      = $request->get_param( 'status' );
			$assigned_to = $request->get_param( 'assigned_to' );
			$entity_type = $request->get_param( 'entity_type' );
			$entity_id   = $request->get_param( 'entity_id' );
			$priority    = $request->get_param( 'priority' );
			$task_type   = $request->get_param( 'task_type' );
			$keywords    = $request->get_param( 'keywords' );
			$label_id    = $request->get_param( 'label' );
			$status_id    = $request->get_param( 'status_id' );

			// Start query with assigned user relationship
			// Note: We don't eager load 'contact' here because entity_id is polymorphic
			// (can be contact OR deal). The contact_id/deal_id accessors handle this.
			$query = TaskModel::with( array( 'assignedUser' ) );

			// Sales reps see tasks assigned to them or with a subtask assigned to them.
			if ( Permissions::is_sales_rep() ) {
				$query->visibleToSalesRep( get_current_user_id() );
			}

			// Apply status filter (handles both DB statuses and calculated display statuses)
			if ( $status ) {
				// Stage type filters: open/closed — tasks whose stage has that type.
				if ( 'open' === $status || 'closed' === $status ) {
					$query->whereHas(
						'kanbanStatus',
						static function ( $q ) use ( $status ) {
							$q->where( 'status', $status );
						}
					);
				} elseif ( TaskStatus::is_valid( $status ) ) {
					// DB statuses: pending, completed - filter directly
					$query->where( 'status', $status );
				} elseif ( TaskStatus::OVERDUE === $status ) {
					// Calculated: overdue = pending + due_date < today
					$query->overdue();
				} elseif ( TaskStatus::UPCOMING === $status ) {
					// Calculated: upcoming = pending + due_date > today
					$query->upcoming();
				} elseif ( TaskStatus::DUE_TODAY === $status ) {
					// Calculated: due_today = pending + due_date = today
					$query->dueToday();
				}
			}

			// Only apply assigned_to filter if not a sales rep (they're already filtered)
			if ( $assigned_to && ! Permissions::is_sales_rep() ) {
				$query->where( 'assigned_to', $assigned_to );
			}

			if ( $entity_type ) {
				$query->where( 'entity_type', $entity_type );
			}

			if ( $entity_id ) {
				$query->where( 'entity_id', $entity_id );
			}

			if ( $priority ) {
				$query->where( 'priority', $priority );
			}

			if ( $task_type ) {
				$query->where( 'task_type', $task_type );
			}

			// Search by keywords (title or description)
			if ( $keywords ) {
				$keywords = sanitize_text_field( $keywords );
				$query->where(
					function ( $q ) use ( $keywords ) {
						$q->where( 'title', 'LIKE', '%' . $keywords . '%' )
							->orWhere( 'description', 'LIKE', '%' . $keywords . '%' );
					}
				);
			}

			if ( $label_id ) {
				$query->whereHas(
					'labels',
					function ( $q ) use ( $label_id ) {
						$q->where( 'label_id', (int) $label_id );
					}
				);
			}

			if ( $status_id ) {
				$query->where( 'status_id', (int) $status_id );
			}

			// Order by due date ascending (earliest first), then priority
			$query->orderBy( 'due_date', 'asc' )->byPriority();

			// Get total count before pagination
			$total_count = $query->count();

			// Paginate
			$tasks = $query->paginate( $per_page, array( '*' ), 'page', $page );

			// Load contact/deal data and prepare for response
			$tasks_data = array();
			foreach ( $tasks->items() as $task ) {
				$this->load_task_relationships( $task );
				$tasks_data[] = $this->prepare_task_for_response( $task );
			}

			return new WP_REST_Response(
				array(
					'data'       => $tasks_data,
					'pagination' => array(
						'total'       => $total_count,
						'per_page'    => $per_page,
						'current_page' => $page,
						'total_pages' => ceil( $total_count / $per_page ),
					),
				),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Get a single task
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		try {
			$task_id = $request->get_param( 'id' );
			$task    = TaskModel::with( array( 'assignedUser' ) )->find( $task_id );

			if ( ! $task ) {
				return new WP_Error( 'not_found', __( 'Task not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			// Sales reps can view tasks they own or are assigned on via a subtask.
			$access_check = $this->check_sales_rep_view_access( $task );
			if ( is_wp_error( $access_check ) ) {
				return $access_check;
			}

			// Load contact/deal based on entity_type
			$this->load_task_relationships( $task );

			return new WP_REST_Response( $this->prepare_task_for_response( $task, true ), 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Create a task
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		try {
			// Prepare task data (handles polymorphic mapping)
			$task_data = $this->prepare_task( $request );
			if ( is_wp_error( $task_data ) ) {
				return $task_data;
			}

			// Apply assignee restrictions (self-only for sales reps / project members).
			$task_data = $this->apply_assignee_restrictions( $task_data );
			if ( is_wp_error( $task_data ) ) {
				return $task_data;
			}

			// Create task
			if ( empty( $task_data['status'] ) ) {
				$task_data['status'] = TaskStatus::PENDING;
			}

			$status_id = isset( $task_data['status_id'] ) ? $task_data['status_id'] : null;
			unset( $task_data['status_id'] );

			$task = TaskModel::create( $task_data );

			if ( $status_id ) {
				TaskStatusManager::instance()->apply_status_to_task( $task, $status_id );
				$task->save();
			} else {
				$default_stage = TaskStatusModel::where( 'status', 'open' )
					->orderBy( 'sort_order', 'asc' )
					->orderBy( 'id', 'asc' )
					->first();

				if ( $default_stage ) {
					TaskStatusManager::instance()->apply_status_to_task( $task, (int) $default_stage->id );
					$task->save();
				}
			}

			if ( $request->has_param( 'label_ids' ) ) {
				$sync_result = $this->sync_task_labels( $task, $this->normalize_label_ids( $request->get_param( 'label_ids' ) ) );
				if ( is_wp_error( $sync_result ) ) {
					return $sync_result;
				}
			}

			if ( $request->has_param( 'custom_fields' ) ) {
				$sync_custom_fields = $task->sync_custom_fields( $request->get_param( 'custom_fields' ) );
				if ( is_wp_error( $sync_custom_fields ) ) {
					return $sync_custom_fields;
				}
			}

			// Load relationships for response
			$task->load( array( 'assignedUser' ) );
			$this->load_task_relationships( $task );

			return new WP_REST_Response( $this->prepare_task_for_response( $task, true ), 201 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Update a task
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		try {
			$task_id = $request->get_param( 'id' );
			$task    = TaskModel::find( $task_id );

			if ( ! $task ) {
				return new WP_Error( 'not_found', __( 'Task not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			// Sales reps can only update tasks they own (not subtask-only access).
			$access_check = $this->check_sales_rep_owner_access( $task );
			if ( is_wp_error( $access_check ) ) {
				return $access_check;
			}

			// Prepare task data (handles polymorphic mapping)
			$task_data = $this->prepare_task( $request );
			if ( is_wp_error( $task_data ) ) {
				return $task_data;
			}

			// Apply assignee restrictions (self-only for sales reps / project members).
			$task_data = $this->apply_assignee_restrictions( $task_data );
			if ( is_wp_error( $task_data ) ) {
				return $task_data;
			}

			// Apply kanban stage side effects before persisting.
			if ( array_key_exists( 'status_id', $task_data ) ) {
				$status_id = $task_data['status_id'];
				unset( $task_data['status_id'] );
				TaskStatusManager::instance()->apply_status_to_task( $task, $status_id );
				$task_data['status_id']    = $task->status_id;
				$task_data['status']      = $task->status;
				$task_data['completed_at'] = $task->completed_at;
			}

			// Update task
			$task->update( $task_data );

			if ( $request->has_param( 'label_ids' ) ) {
				$sync_result = $this->sync_task_labels( $task, $this->normalize_label_ids( $request->get_param( 'label_ids' ) ) );
				if ( is_wp_error( $sync_result ) ) {
					return $sync_result;
				}
			}

			if ( $request->has_param( 'custom_fields' ) ) {
				$sync_custom_fields = $task->sync_custom_fields( $request->get_param( 'custom_fields' ) );
				if ( is_wp_error( $sync_custom_fields ) ) {
					return $sync_custom_fields;
				}
			}

			// Load relationships for response
			$task->load( array( 'assignedUser' ) );
			$this->load_task_relationships( $task );

			return new WP_REST_Response( $this->prepare_task_for_response( $task, true ), 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Delete a task
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		try {
			$task_id = $request->get_param( 'id' );
			$task    = TaskModel::find( $task_id );

			if ( ! $task ) {
				return new WP_Error( 'not_found', __( 'Task not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			// Sales reps can only delete tasks they own (not subtask-only access).
			$access_check = $this->check_sales_rep_owner_access( $task );
			if ( is_wp_error( $access_check ) ) {
				return $access_check;
			}

			$task_copy = $task->toArray();
			$task->delete();

			return new WP_REST_Response( $task_copy, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Clone a task into a new pending row (labels, custom fields, subtasks).
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function clone_item( $request ) {
		try {
			$task_id = (int) $request->get_param( 'id' );
			$task    = TaskModel::find( $task_id );

			if ( ! $task ) {
				return new WP_Error( 'not_found', __( 'Task not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			$access_check = $this->check_sales_rep_view_access( $task );
			if ( is_wp_error( $access_check ) ) {
				return $access_check;
			}

			$owner_check = $this->check_sales_rep_owner_access( $task );
			if ( is_wp_error( $owner_check ) ) {
				return $owner_check;
			}

			$clone = TaskCloneService::instance()->clone_task( $task );
			if ( ! $clone ) {
				return new WP_Error( 'clone_failed', __( 'Failed to clone task.', 'doublescale' ), array( 'status' => 500 ) );
			}

			$clone->load( array( 'assignedUser' ) );
			$this->load_task_relationships( $clone );

			return new WP_REST_Response( $this->prepare_task_for_response( $clone, true ), 201 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Mark task as completed
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function mark_completed( $request ) {
		try {
			$task_id = $request->get_param( 'id' );
			$task    = TaskModel::find( $task_id );

			if ( ! $task ) {
				return new WP_Error( 'not_found', __( 'Task not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			// Sales reps can only complete tasks they own (not subtask-only access).
			$access_check = $this->check_sales_rep_owner_access( $task );
			if ( is_wp_error( $access_check ) ) {
				return $access_check;
			}

			$task->markCompleted();
			$task->load( array( 'assignedUser' ) );
			$this->load_task_relationships( $task );

			return new WP_REST_Response( $this->prepare_task_for_response( $task, true ), 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Mark task as pending
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function mark_pending( $request ) {
		try {
			$task_id = $request->get_param( 'id' );
			$task    = TaskModel::find( $task_id );

			if ( ! $task ) {
				return new WP_Error( 'not_found', __( 'Task not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			// Sales reps can only update tasks they own (not subtask-only access).
			$access_check = $this->check_sales_rep_owner_access( $task );
			if ( is_wp_error( $access_check ) ) {
				return $access_check;
			}

			$task->markPending();
			$task->load( array( 'assignedUser' ) );
			$this->load_task_relationships( $task );

			return new WP_REST_Response( $this->prepare_task_for_response( $task, true ), 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Resolve the parent task for a subtask request and enforce access.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request       Request.
	 * @param bool            $require_owner When true, subtask assignees are denied.
	 *
	 * @return TaskModel|WP_Error Parent task or error.
	 */
	private function get_task_for_subtask( $request, $require_owner = false ) {
		$task = TaskModel::find( $request->get_param( 'id' ) );

		if ( ! $task ) {
			return new WP_Error( 'not_found', __( 'Task not found.', 'doublescale'), array( 'status' => 404 ) );
		}

		$access_check = $require_owner
			? $this->check_sales_rep_owner_access( $task )
			: $this->check_sales_rep_view_access( $task );
		if ( is_wp_error( $access_check ) ) {
			return $access_check;
		}

		return $task;
	}

	/**
	 * Shape a subtask model for API responses.
	 *
	 * @since 1.0.0
	 *
	 * @param SubtaskModel $subtask Subtask.
	 *
	 * @return array
	 */
	private function prepare_subtask_for_response( $subtask ) {
		$data = array(
			'id'           => (int) $subtask->id,
			'task_id'      => (int) $subtask->task_id,
			'group_id'     => $subtask->group_id ? (int) $subtask->group_id : null,
			'title'        => (string) $subtask->title,
			'notes'        => $subtask->notes ? (string) $subtask->notes : null,
			'is_completed' => (bool) $subtask->is_completed,
			'position'     => (int) $subtask->position,
			'assigned_to'  => $subtask->assigned_to ? (int) $subtask->assigned_to : null,
			'due_date'     => $subtask->due_date ? (string) $subtask->due_date : null,
			'reminder_at'  => $subtask->reminder_at ? (string) $subtask->reminder_at : null,
		);

		if ( $subtask->relationLoaded( 'assignedUser' ) && $subtask->assignedUser ) {
			$user         = $subtask->assignedUser->toArray();
			$data['user'] = array(
				'id'           => (int) ( $user['ID'] ?? $user['id'] ?? 0 ),
				'display_name' => $user['display_name'] ?? '',
				'email'        => $user['user_email'] ?? '',
			);
		}

		return $data;
	}

	/**
	 * Shape a subtask group (with nested subtasks) for API responses.
	 *
	 * @since 1.0.0
	 *
	 * @param SubtaskGroupModel $group Group.
	 *
	 * @return array
	 */
	private function prepare_group_for_response( $group ) {
		$subtasks = array();
		if ( $group->relationLoaded( 'subtasks' ) ) {
			$subtasks = $group->subtasks
				->map(
					function ( $subtask ) {
						return $this->prepare_subtask_for_response( $subtask );
					}
				)
				->values()
				->all();
		}

		return array(
			'id'       => (int) $group->id,
			'task_id'  => (int) $group->task_id,
			'title'    => (string) $group->title,
			'position' => (int) $group->position,
			'subtasks' => $subtasks,
		);
	}

	/**
	 * List subtasks for a task.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_subtasks( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$subtasks = SubtaskModel::where( 'task_id', $task->id )
				->with( 'assignedUser' )
				->orderBy( 'position' )
				->get()
				->map(
					function ( $subtask ) {
						return $this->prepare_subtask_for_response( $subtask );
					}
				)
				->values();

			return new WP_REST_Response( $subtasks, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Create a subtask for a task.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_subtask( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request, true );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
			if ( '' === trim( $title ) ) {
				return new WP_Error( 'invalid_title', __( 'Subtask title is required.', 'doublescale'), array( 'status' => 400 ) );
			}

			// New subtask goes to the end of its group (or ungrouped list).
			$group_id        = $this->parse_optional_group_id( $request );
			$group_validation = $this->validate_group_for_task( $task, $group_id );
			if ( is_wp_error( $group_validation ) ) {
				return $group_validation;
			}

			$assignee_validation = $this->validate_subtask_assignee( $request );
			if ( is_wp_error( $assignee_validation ) ) {
				return $assignee_validation;
			}

			$next_position = $this->next_subtask_position( $task->id, $group_id );

			$subtask_data = array(
				'task_id'      => $task->id,
				'group_id'     => $group_id,
				'title'        => $title,
				'is_completed' => (bool) $request->get_param( 'is_completed' ),
				'position'     => $next_position,
			);

			if ( $request->has_param( 'assigned_to' ) && $request->get_param( 'assigned_to' ) ) {
				$subtask_data['assigned_to'] = absint( $request->get_param( 'assigned_to' ) );
			}

			if ( $request->has_param( 'due_date' ) && $request->get_param( 'due_date' ) ) {
				$subtask_data['due_date'] = sanitize_text_field( (string) $request->get_param( 'due_date' ) );
			}

			if ( $request->has_param( 'reminder_at' ) && $request->get_param( 'reminder_at' ) ) {
				$subtask_data['reminder_at'] = sanitize_text_field( (string) $request->get_param( 'reminder_at' ) );
			}

			if ( $request->has_param( 'notes' ) ) {
				$notes = $request->get_param( 'notes' );
				$subtask_data['notes'] = $notes ? sanitize_textarea_field( (string) $notes ) : null;
			}

			$subtask = SubtaskModel::create( $subtask_data );
			$subtask->load( 'assignedUser' );

			return new WP_REST_Response( $this->prepare_subtask_for_response( $subtask ), 201 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Update a subtask (title and/or completion state).
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_subtask( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$subtask = SubtaskModel::where( 'task_id', $task->id )
				->where( 'id', $request->get_param( 'subtask_id' ) )
				->first();

			if ( ! $subtask ) {
				return new WP_Error( 'not_found', __( 'Subtask not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			$subtask_access = $this->check_sales_rep_subtask_mutation_access( $task, $subtask, $request );
			if ( is_wp_error( $subtask_access ) ) {
				return $subtask_access;
			}

			if ( $request->has_param( 'title' ) ) {
				$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
				if ( '' === trim( $title ) ) {
					return new WP_Error( 'invalid_title', __( 'Subtask title is required.', 'doublescale'), array( 'status' => 400 ) );
				}
				$subtask->title = $title;
			}

			if ( $request->has_param( 'is_completed' ) ) {
				$subtask->is_completed = (bool) $request->get_param( 'is_completed' );
			}

			if ( $request->has_param( 'group_id' ) ) {
				$group_id         = $this->parse_optional_group_id( $request, 'group_id' );
				$group_validation = $this->validate_group_for_task( $task, $group_id );
				if ( is_wp_error( $group_validation ) ) {
					return $group_validation;
				}
				$subtask->group_id = $group_id;
			}

			if ( $request->has_param( 'assigned_to' ) ) {
				$assignee_validation = $this->validate_subtask_assignee( $request, 'assigned_to' );
				if ( is_wp_error( $assignee_validation ) ) {
					return $assignee_validation;
				}
				$assigned_to = $request->get_param( 'assigned_to' );
				$subtask->assigned_to = $assigned_to ? absint( $assigned_to ) : null;
			}

			if ( $request->has_param( 'due_date' ) ) {
				$due_date = $request->get_param( 'due_date' );
				$subtask->due_date = $due_date ? sanitize_text_field( (string) $due_date ) : null;
			}

			if ( $request->has_param( 'reminder_at' ) ) {
				$reminder_at = $request->get_param( 'reminder_at' );
				$subtask->reminder_at = $reminder_at ? sanitize_text_field( (string) $reminder_at ) : null;
			}

			if ( $request->has_param( 'notes' ) ) {
				$notes = $request->get_param( 'notes' );
				$subtask->notes = $notes ? sanitize_textarea_field( (string) $notes ) : null;
			}

			$subtask->save();
			$subtask->load( 'assignedUser' );

			return new WP_REST_Response( $this->prepare_subtask_for_response( $subtask ), 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Delete a subtask.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_subtask( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request, true );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$subtask = SubtaskModel::where( 'task_id', $task->id )
				->where( 'id', $request->get_param( 'subtask_id' ) )
				->first();

			if ( ! $subtask ) {
				return new WP_Error( 'not_found', __( 'Subtask not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			$subtask_copy = $this->prepare_subtask_for_response( $subtask );
			$subtask->delete();

			return new WP_REST_Response( $subtask_copy, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Reorder subtasks according to the provided ordered ID list.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function reorder_subtasks( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request, true );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$ids = array_map( 'intval', (array) $request->get_param( 'ids' ) );

			$position = 0;
			foreach ( $ids as $subtask_id ) {
				SubtaskModel::where( 'task_id', $task->id )
					->where( 'id', $subtask_id )
					->update( array( 'position' => $position ) );
				++$position;
			}

			$subtasks = SubtaskModel::where( 'task_id', $task->id )
				->with( 'assignedUser' )
				->orderBy( 'position' )
				->get()
				->map(
					function ( $subtask ) {
						return $this->prepare_subtask_for_response( $subtask );
					}
				)
				->values();

			return new WP_REST_Response( $subtasks, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * List subtask groups (with nested subtasks) for a task.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_subtask_groups( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$groups = SubtaskGroupModel::where( 'task_id', $task->id )
				->with( array( 'subtasks.assignedUser' ) )
				->orderBy( 'position' )
				->get()
				->map(
					function ( $group ) {
						return $this->prepare_group_for_response( $group );
					}
				)
				->values();

			return new WP_REST_Response( $groups, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Create a subtask group on a task.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_subtask_group( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request, true );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
			if ( '' === trim( $title ) ) {
				return new WP_Error( 'invalid_title', __( 'Group title is required.', 'doublescale'), array( 'status' => 400 ) );
			}

			$next_position = (int) SubtaskGroupModel::where( 'task_id', $task->id )->max( 'position' ) + 1;

			$group = SubtaskGroupModel::create(
				array(
					'task_id'  => $task->id,
					'title'    => $title,
					'position' => $next_position,
				)
			);

			$group->load( array( 'subtasks.assignedUser' ) );

			return new WP_REST_Response( $this->prepare_group_for_response( $group ), 201 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Update a subtask group title.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_subtask_group( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request, true );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$group = SubtaskGroupModel::where( 'task_id', $task->id )
				->where( 'id', $request->get_param( 'group_id' ) )
				->first();

			if ( ! $group ) {
				return new WP_Error( 'not_found', __( 'Subtask group not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			if ( $request->has_param( 'title' ) ) {
				$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
				if ( '' === trim( $title ) ) {
					return new WP_Error( 'invalid_title', __( 'Group title is required.', 'doublescale'), array( 'status' => 400 ) );
				}
				$group->title = $title;
			}

			$group->save();
			$group->load( array( 'subtasks.assignedUser' ) );

			return new WP_REST_Response( $this->prepare_group_for_response( $group ), 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Delete a subtask group and its subtasks.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_subtask_group( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request, true );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$group = SubtaskGroupModel::where( 'task_id', $task->id )
				->where( 'id', $request->get_param( 'group_id' ) )
				->first();

			if ( ! $group ) {
				return new WP_Error( 'not_found', __( 'Subtask group not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			$group_copy = $this->prepare_group_for_response( $group );
			$group->delete();

			return new WP_REST_Response( $group_copy, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Reorder subtask groups according to the provided ordered ID list.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function reorder_subtask_groups( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request, true );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$ids      = array_map( 'intval', (array) $request->get_param( 'ids' ) );
			$position = 0;
			foreach ( $ids as $group_id ) {
				SubtaskGroupModel::where( 'task_id', $task->id )
					->where( 'id', $group_id )
					->update( array( 'position' => $position ) );
				++$position;
			}

			$groups = SubtaskGroupModel::where( 'task_id', $task->id )
				->with( array( 'subtasks.assignedUser' ) )
				->orderBy( 'position' )
				->get()
				->map(
					function ( $group ) {
						return $this->prepare_group_for_response( $group );
					}
				)
				->values();

			return new WP_REST_Response( $groups, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Clone a subtask (duplicate title with " (copy)", same group, after original).
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function clone_subtask( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request, true );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$subtask = $this->find_subtask_for_task( $task, (int) $request->get_param( 'subtask_id' ) );
			if ( is_wp_error( $subtask ) ) {
				return $subtask;
			}

			$new_position = (int) $subtask->position + 1;

			SubtaskModel::where( 'task_id', $task->id )
				->where( 'position', '>=', $new_position )
				->increment( 'position' );

			$clone = SubtaskModel::create(
				array(
					'task_id'      => $task->id,
					'group_id'     => $subtask->group_id,
					'title'        => $subtask->title . ' (copy)',
					'notes'        => $subtask->notes,
					'is_completed' => false,
					'position'     => $new_position,
					'assigned_to'  => $subtask->assigned_to,
					'due_date'     => $subtask->due_date,
					'reminder_at'  => $subtask->reminder_at,
				)
			);

			$clone->load( 'assignedUser' );

			return new WP_REST_Response( $this->prepare_subtask_for_response( $clone ), 201 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Convert a subtask into a standalone CRM task, then delete the subtask.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function convert_subtask_to_task( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request, true );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$subtask = $this->find_subtask_for_task( $task, (int) $request->get_param( 'subtask_id' ) );
			if ( is_wp_error( $subtask ) ) {
				return $subtask;
			}

			$task_data = array(
				'title'       => $subtask->title,
				'entity_type' => (int) $task->entity_type,
				'entity_id'   => (int) $task->entity_id,
				'assigned_to' => $subtask->assigned_to ? (int) $subtask->assigned_to : (int) $task->assigned_to,
				'task_type'   => TaskType::TODO,
				'status'      => TaskStatus::PENDING,
				'priority'    => TaskPriority::MEDIUM,
				'due_date'    => $subtask->due_date ? (string) $subtask->due_date : (string) $task->due_date,
			);

			$task_data = $this->apply_assignee_restrictions( $task_data );
			if ( is_wp_error( $task_data ) ) {
				return $task_data;
			}

			$new_task = TaskModel::create( $task_data );
			$subtask->delete();

			return new WP_REST_Response(
				array(
					'task_id' => (int) $new_task->id,
				),
				201
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Convert a standalone CRM task into a subtask on another task, then delete it.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function convert_task_to_subtask( $request ) {
		try {
			$task_id = (int) $request->get_param( 'id' );
			$task    = TaskModel::find( $task_id );

			if ( ! $task ) {
				return new WP_Error( 'not_found', __( 'Task not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			$access_check = $this->check_sales_rep_owner_access( $task );
			if ( is_wp_error( $access_check ) ) {
				return $access_check;
			}

			$parent_task_id = absint( $request->get_param( 'parent_task_id' ) );
			if ( $parent_task_id === $task_id ) {
				return new WP_Error(
					'invalid_parent',
					__( 'A task cannot be converted into a subtask of itself.', 'doublescale'),
					array( 'status' => 400 )
				);
			}

			$parent_task = TaskModel::find( $parent_task_id );
			if ( ! $parent_task ) {
				return new WP_Error( 'not_found', __( 'Parent task not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			$parent_access = $this->check_sales_rep_owner_access( $parent_task );
			if ( is_wp_error( $parent_access ) ) {
				return $parent_access;
			}

			if ( (int) $task->entity_type !== (int) $parent_task->entity_type
				|| (int) $task->entity_id !== (int) $parent_task->entity_id ) {
				return new WP_Error(
					'entity_mismatch',
					__( 'Cannot convert task to subtask: both tasks must belong to the same contact or deal.', 'doublescale'),
					array( 'status' => 400 )
				);
			}

			$has_subtasks = SubtaskModel::where( 'task_id', $task_id )->exists();
			$has_groups   = SubtaskGroupModel::where( 'task_id', $task_id )->exists();
			if ( $has_subtasks || $has_groups ) {
				return new WP_Error(
					'has_subtasks',
					__( 'Cannot convert a task that already has subtasks. Remove its checklist first.', 'doublescale'),
					array( 'status' => 400 )
				);
			}

			$group_id         = $this->parse_optional_group_id( $request );
			$group_validation = $this->validate_group_for_task( $parent_task, $group_id );
			if ( is_wp_error( $group_validation ) ) {
				return $group_validation;
			}

			$is_completed = TaskStatus::COMPLETED === (string) $task->status;

			$subtask_data = array(
				'task_id'      => $parent_task_id,
				'group_id'     => $group_id,
				'title'        => (string) $task->title,
				'is_completed' => $is_completed,
				'position'     => $this->next_subtask_position( $parent_task_id, $group_id ),
				'assigned_to'  => (int) $task->assigned_to,
			);

			if ( $task->due_date ) {
				$subtask_data['due_date'] = (string) $task->due_date;
			}

			if ( $task->reminder_at && ! $is_completed ) {
				$subtask_data['reminder_at'] = (string) $task->reminder_at;
			}

			$subtask = SubtaskModel::create( $subtask_data );
			$task->delete();
			$subtask->load( 'assignedUser' );

			return new WP_REST_Response(
				array(
					'subtask_id'     => (int) $subtask->id,
					'parent_task_id' => $parent_task_id,
					'subtask'        => $this->prepare_subtask_for_response( $subtask ),
				),
				201
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Move a subtask to another group (or ungrouped) and append to the end.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function move_subtask( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request, true );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$subtask = $this->find_subtask_for_task( $task, (int) $request->get_param( 'subtask_id' ) );
			if ( is_wp_error( $subtask ) ) {
				return $subtask;
			}

			$group_id         = $this->parse_optional_group_id( $request );
			$group_validation = $this->validate_group_for_task( $task, $group_id );
			if ( is_wp_error( $group_validation ) ) {
				return $group_validation;
			}

			$subtask->group_id = $group_id;
			$subtask->position = $this->next_subtask_position( $task->id, $group_id );
			$subtask->save();
			$subtask->load( 'assignedUser' );

			return new WP_REST_Response( $this->prepare_subtask_for_response( $subtask ), 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Find a subtask belonging to a task.
	 *
	 * @since 1.0.0
	 *
	 * @param TaskModel $task       Parent task.
	 * @param int       $subtask_id Subtask ID.
	 *
	 * @return SubtaskModel|WP_Error
	 */
	private function find_subtask_for_task( $task, $subtask_id ) {
		$subtask = SubtaskModel::where( 'task_id', $task->id )
			->where( 'id', $subtask_id )
			->first();

		if ( ! $subtask ) {
			return new WP_Error( 'not_found', __( 'Subtask not found.', 'doublescale'), array( 'status' => 404 ) );
		}

		return $subtask;
	}

	/**
	 * Parse an optional group_id from the request (null = ungrouped).
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request    Request.
	 * @param string          $param_name Parameter name.
	 *
	 * @return int|null
	 */
	private function parse_optional_group_id( $request, $param_name = 'group_id' ) {
		if ( ! $request->has_param( $param_name ) ) {
			return null;
		}

		$value = $request->get_param( $param_name );
		if ( null === $value || '' === $value ) {
			return null;
		}

		return absint( $value );
	}

	/**
	 * Validate that a group_id belongs to the given task (null is always valid).
	 *
	 * @since 1.0.0
	 *
	 * @param TaskModel $task     Parent task.
	 * @param int|null  $group_id Group ID.
	 *
	 * @return true|WP_Error
	 */
	private function validate_group_for_task( $task, $group_id ) {
		if ( null === $group_id ) {
			return true;
		}

		$exists = SubtaskGroupModel::where( 'task_id', $task->id )
			->where( 'id', $group_id )
			->exists();

		if ( ! $exists ) {
			return new WP_Error( 'invalid_group', __( 'Invalid subtask group for this task.', 'doublescale'), array( 'status' => 400 ) );
		}

		return true;
	}

	/**
	 * Validate an optional subtask assignee user ID.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request    Request.
	 * @param string          $param_name Parameter name.
	 *
	 * @return true|WP_Error
	 */
	private function validate_subtask_assignee( $request, $param_name = 'assigned_to' ) {
		if ( ! $request->has_param( $param_name ) ) {
			return true;
		}

		$user_id = $request->get_param( $param_name );
		if ( null === $user_id || '' === $user_id ) {
			return true;
		}

		if ( Permissions::is_sales_rep() && (int) absint( $user_id ) !== get_current_user_id() ) {
			return new WP_Error(
				'forbidden',
				__( 'Sales reps can only assign subtasks to themselves.', 'doublescale'),
				array( 'status' => 403 )
			);
		}

		$user = UserModel::find( absint( $user_id ) );
		if ( ! $user ) {
			return new WP_Error( 'invalid_user', __( 'Invalid assignee user ID.', 'doublescale'), array( 'status' => 400 ) );
		}

		return true;
	}

	/**
	 * Next position for a subtask within a group scope (or ungrouped).
	 *
	 * @since 1.0.0
	 *
	 * @param int      $task_id  Task ID.
	 * @param int|null $group_id Group ID.
	 *
	 * @return int
	 */
	private function next_subtask_position( $task_id, $group_id ) {
		$query = SubtaskModel::where( 'task_id', $task_id );
		if ( null === $group_id ) {
			$query->whereNull( 'group_id' );
		} else {
			$query->where( 'group_id', $group_id );
		}

		return (int) $query->max( 'position' ) + 1;
	}

	/**
	 * List active file attachments for a task.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_attachments( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			return new WP_REST_Response(
				array(
					'items' => $this->get_task_attachments_shaped( (int) $task->id ),
				),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Upload a file attachment to a task.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_attachment( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request, true );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$files = $request->get_file_params();
			$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;
			if ( ! $file ) {
				return new WP_Error( 'no_file', __( 'No file was uploaded.', 'doublescale' ), array( 'status' => 400 ) );
			}

			$existing_count = AttachmentModel::forType( self::TASK_ATTACHABLE_TYPE )
				->where( 'attachable_id', (int) $task->id )
				->active()
				->count();

			$too_many = $this->guard_task_attachment_count( (int) $existing_count );
			if ( $too_many ) {
				return $too_many;
			}

			$service    = $this->attachment_service();
			$attachment = $service->store_upload(
				$file,
				self::TASK_ATTACHABLE_TYPE,
				(int) $task->id,
				array( 'user_id' => get_current_user_id() ),
				array(
					'status'         => 'active',
					'max_size_bytes' => self::TASK_ATTACHMENT_MAX_BYTES,
					'meta'           => array( 'task_id' => (int) $task->id ),
				)
			);

			if ( is_wp_error( $attachment ) ) {
				return $attachment;
			}

			do_action( 'doublescale_task_file_attached', $task, $attachment );

			return new WP_REST_Response(
				$service->shape_for_api( $attachment ),
				201
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Delete a task file attachment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_attachment( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request, true );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$attachment = $this->find_task_attachment( $task, (int) $request->get_param( 'attachment_id' ) );
			if ( is_wp_error( $attachment ) ) {
				return $attachment;
			}

			do_action( 'doublescale_task_file_removed', $task, $attachment );

			$attachment->delete();

			return new WP_REST_Response(
				array(
					'deleted' => true,
					'id'      => (int) $request->get_param( 'attachment_id' ),
				),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * List note-type comments for a task.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_comments( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$feed = $this->parse_task_feed_request( $request );

			$result = ActivityManager::instance()->get_activities(
				array(
					'entity_type'   => ActivityAssociationModel::ENTITY_TYPE_TASK,
					'entity_id'     => $task->id,
					'activity_type' => 'note',
					'sort_by'       => 'created_at',
					'sort_order'    => $feed['sort_order'],
				),
				$feed['per_page'],
				$feed['page']
			);

			if ( null === $result ) {
				return new WP_Error( 'access_denied', __( 'Access denied.', 'doublescale'), array( 'status' => 403 ) );
			}

			$comments = array();
			foreach ( $result->items() as $activity ) {
				$comments[] = $this->prepare_comment_for_response( $activity, $task->id );
			}

			return new WP_REST_Response(
				$this->prepare_task_feed_paginated_response( $comments, $result ),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Create a comment on a task.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_comment( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$body = wp_kses_post( (string) $request->get_param( 'body' ) );
			if ( '' === trim( wp_strip_all_tags( $body ) ) ) {
				return new WP_Error( 'missing_body', __( 'Comment body is required.', 'doublescale'), array( 'status' => 400 ) );
			}

			$activity = ActivityManager::instance()->add_note(
				array(
					'entity_type' => ActivityAssociationModel::ENTITY_TYPE_TASK,
					'entity_id'   => $task->id,
					'content'     => $body,
				)
			);

			if ( ! $activity ) {
				return new WP_Error( 'creation_failed', __( 'Failed to create comment.', 'doublescale'), array( 'status' => 500 ) );
			}

			$activity->load( 'user' );
			TaskCommentNotifier::notify( $task, $body, true );

			return new WP_REST_Response( $this->prepare_comment_for_response( $activity, $task->id ), 201 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Update a task comment (author only).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_comment( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$activity = $this->find_task_comment( $task, (int) $request->get_param( 'comment_id' ) );
			if ( is_wp_error( $activity ) ) {
				return $activity;
			}

			$body = wp_kses_post( (string) $request->get_param( 'body' ) );
			if ( '' === trim( wp_strip_all_tags( $body ) ) ) {
				return new WP_Error( 'missing_body', __( 'Comment body is required.', 'doublescale'), array( 'status' => 400 ) );
			}

			$updated = ActivityManager::instance()->update_activity(
				$activity->id,
				array( 'content' => $body ),
				get_current_user_id()
			);

			if ( ! $updated ) {
				return new WP_Error( 'update_failed', __( 'Failed to update comment.', 'doublescale'), array( 'status' => 403 ) );
			}

			$updated->load( 'user' );
			TaskCommentNotifier::notify( $task, $body, false );

			return new WP_REST_Response( $this->prepare_comment_for_response( $updated, $task->id ), 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Delete a task comment (author only).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_comment( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$activity = $this->find_task_comment( $task, (int) $request->get_param( 'comment_id' ) );
			if ( is_wp_error( $activity ) ) {
				return $activity;
			}

			$payload = $this->prepare_comment_for_response( $activity, $task->id );
			$deleted = ActivityManager::instance()->delete_activity( $activity->id, get_current_user_id() );

			if ( ! $deleted ) {
				return new WP_Error( 'delete_failed', __( 'Failed to delete comment.', 'doublescale'), array( 'status' => 403 ) );
			}

			return new WP_REST_Response( $payload, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Reply to a task comment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_comment_reply( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$activity = $this->find_task_comment( $task, (int) $request->get_param( 'comment_id' ) );
			if ( is_wp_error( $activity ) ) {
				return $activity;
			}

			$body = wp_kses_post( (string) $request->get_param( 'body' ) );
			if ( '' === trim( wp_strip_all_tags( $body ) ) ) {
				return new WP_Error( 'missing_body', __( 'Reply body is required.', 'doublescale'), array( 'status' => 400 ) );
			}

			$reply = ActivityManager::instance()->add_comment( $activity->id, $body, get_current_user_id() );
			if ( ! $reply ) {
				return new WP_Error( 'creation_failed', __( 'Failed to create reply.', 'doublescale'), array( 'status' => 500 ) );
			}

			$reply->load( 'user' );

			return new WP_REST_Response( $this->prepare_task_reply_for_response( $reply ), 201 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Update a reply on a task comment (author only).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_comment_reply( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$reply = $this->find_task_comment_reply(
				$task,
				(int) $request->get_param( 'comment_id' ),
				(int) $request->get_param( 'reply_id' )
			);
			if ( is_wp_error( $reply ) ) {
				return $reply;
			}

			$body = wp_kses_post( (string) $request->get_param( 'body' ) );
			if ( '' === trim( wp_strip_all_tags( $body ) ) ) {
				return new WP_Error( 'missing_body', __( 'Reply body is required.', 'doublescale'), array( 'status' => 400 ) );
			}

			$updated = ActivityManager::instance()->update_comment(
				$reply->id,
				$body,
				get_current_user_id()
			);

			if ( ! $updated ) {
				return new WP_Error( 'update_failed', __( 'Failed to update reply.', 'doublescale'), array( 'status' => 403 ) );
			}

			$updated->load( 'user' );

			return new WP_REST_Response( $this->prepare_task_reply_for_response( $updated ), 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Delete a reply on a task comment (author only).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_comment_reply( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$reply = $this->find_task_comment_reply(
				$task,
				(int) $request->get_param( 'comment_id' ),
				(int) $request->get_param( 'reply_id' )
			);
			if ( is_wp_error( $reply ) ) {
				return $reply;
			}

			$payload = $this->prepare_task_reply_for_response( $reply );
			$deleted = ActivityManager::instance()->delete_comment( $reply->id, get_current_user_id() );

			if ( ! $deleted ) {
				return new WP_Error( 'delete_failed', __( 'Failed to delete reply.', 'doublescale'), array( 'status' => 403 ) );
			}

			return new WP_REST_Response( $payload, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * List all activities (comments + audit events) for a task.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_activity( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$feed = $this->parse_task_feed_request( $request );

			$timeline = $this->build_task_activity_timeline( $task, $feed );
			if ( is_wp_error( $timeline ) ) {
				return $timeline;
			}

			return new WP_REST_Response( $timeline, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Merge task activities and threaded comment replies into one chronological feed.
	 *
	 * @param TaskModel $task Parent task.
	 * @param array{sort_order: string, page: int, per_page: int} $feed Parsed feed params.
	 * @return array{items: array<int, array<string, mixed>>, pagination: array<string, int|bool>}|WP_Error
	 */
	private function build_task_activity_timeline( $task, $feed ) {
		$activities = ActivityModel::with( array( 'user', 'comments.user', 'associations' ) )
			->whereHas(
				'associations',
				function ( $query ) use ( $task ) {
					$query->where( 'entity_type', ActivityAssociationModel::ENTITY_TYPE_TASK )
						->where( 'entity_id', $task->id );
				}
			)
			->get();

		$rows = array();
		foreach ( $activities as $activity ) {
			$rows[] = array(
				'sort_at' => (string) $activity->created_at,
				'entry'   => $this->prepare_activity_for_response( $activity, $task->id ),
			);

			if ( 'note' !== (string) $activity->activity_type || ! $activity->relationLoaded( 'comments' ) ) {
				continue;
			}

			foreach ( $activity->comments as $reply ) {
				$rows[] = array(
					'sort_at' => (string) $reply->created_at,
					'entry'   => $this->prepare_activity_reply_for_response( $reply, $activity, $task->id ),
				);
			}
		}

		usort(
			$rows,
			function ( $left, $right ) use ( $feed ) {
				$compare = strcmp( $left['sort_at'], $right['sort_at'] );
				return 'asc' === $feed['sort_order'] ? $compare : -$compare;
			}
		);

		$entries = array_map(
			static function ( $row ) {
				return $row['entry'];
			},
			$rows
		);

		if ( ! empty( $feed['type'] ) && 'all' !== $feed['type'] ) {
			$entries = array_values(
				array_filter(
					$entries,
					function ( $entry ) use ( $feed ) {
						return $this->matches_task_activity_type_filter( $entry, (string) $feed['type'] );
					}
				)
			);
		}

		$total   = count( $entries );
		$offset  = ( $feed['page'] - 1 ) * $feed['per_page'];
		$items   = array_slice( $entries, $offset, $feed['per_page'] );
		$has_more = ( $offset + $feed['per_page'] ) < $total;

		return array(
			'items'      => $items,
			'pagination' => array(
				'page'        => (int) $feed['page'],
				'per_page'    => (int) $feed['per_page'],
				'total'       => (int) $total,
				'total_pages' => (int) max( 1, (int) ceil( $total / $feed['per_page'] ) ),
				'has_more'    => $has_more,
			),
		);
	}

	/**
	 * Whether a shaped task activity entry matches a timeline type filter.
	 *
	 * @param array<string, mixed> $entry  Shaped activity entry.
	 * @param string               $filter Filter slug from the UI.
	 * @return bool
	 */
	private function matches_task_activity_type_filter( array $entry, string $filter ): bool {
		$activity_type = isset( $entry['activity_type'] ) ? (string) $entry['activity_type'] : '';
		$data          = isset( $entry['data'] ) && is_array( $entry['data'] ) ? $entry['data'] : array();
		$event_key     = isset( $entry['event_key'] ) ? (string) $entry['event_key'] : '';
		if ( '' === $event_key && isset( $data['event_key'] ) ) {
			$event_key = (string) $data['event_key'];
		}

		if ( 'files' === $filter ) {
			return in_array( $event_key, array( 'file_attached', 'file_removed' ), true );
		}

		if ( 'note' === $filter ) {
			return in_array( $activity_type, array( 'note', 'comment_reply' ), true );
		}

		if ( 'task' === $filter ) {
			return 'task_event' === $activity_type
				&& ! in_array( $event_key, array( 'file_attached', 'file_removed' ), true );
		}

		if ( in_array( $filter, array( 'call_logged', 'email_sent', 'meeting_scheduled' ), true ) ) {
			return $activity_type === $filter;
		}

		return true;
	}

	/**
	 * Find a note activity belonging to a task.
	 *
	 * @param TaskModel $task       Parent task.
	 * @param int       $comment_id Activity ID.
	 * @return ActivityModel|WP_Error
	 */
	private function find_task_comment( $task, $comment_id ) {
		$activity = ActivityModel::with( array( 'associations', 'user' ) )
			->where( 'id', $comment_id )
			->where( 'activity_type', 'note' )
			->first();

		if ( ! $activity || (int) $activity->task_id !== (int) $task->id ) {
			return new WP_Error( 'not_found', __( 'Comment not found.', 'doublescale'), array( 'status' => 404 ) );
		}

		return $activity;
	}

	/**
	 * Resolve a reply on a task comment.
	 *
	 * @param TaskModel $task        Task model.
	 * @param int       $comment_id  Parent comment activity ID.
	 * @param int       $reply_id    Reply row ID.
	 * @return ActivityCommentModel|WP_Error
	 */
	private function find_task_comment_reply( $task, $comment_id, $reply_id ) {
		$activity = $this->find_task_comment( $task, $comment_id );
		if ( is_wp_error( $activity ) ) {
			return $activity;
		}

		$reply = ActivityCommentModel::where( 'id', $reply_id )
			->where( 'activity_id', $activity->id )
			->first();

		if ( ! $reply ) {
			return new WP_Error( 'reply_not_found', __( 'Reply not found.', 'doublescale'), array( 'status' => 404 ) );
		}

		return $reply;
	}

	/**
	 * Shape a comment activity for API responses.
	 *
	 * @param ActivityModel $activity Activity model.
	 * @param int           $task_id  Task ID.
	 * @return array
	 */
	private function prepare_comment_for_response( $activity, $task_id ) {
		$data    = $activity->data ?? array();
		$content = (string) ( $data['content'] ?? '' );

		$response = array(
			'id'             => (int) $activity->id,
			'task_id'        => (int) $task_id,
			'body'           => $content,
			'formatted_body' => wp_kses_post( $content ),
			'created_at'     => (string) $activity->created_at,
			'updated_at'     => (string) $activity->updated_at,
			'edited'         => (string) $activity->updated_at > (string) $activity->created_at,
		);

		if ( $activity->relationLoaded( 'user' ) && $activity->user ) {
			$response['user'] = $this->prepare_activity_user_for_response( $activity->user );
		}

		$replies = array();
		if ( $activity->relationLoaded( 'comments' ) ) {
			foreach ( $activity->comments as $reply ) {
				$replies[] = $this->prepare_task_reply_for_response( $reply );
			}
		}
		$response['replies']       = $replies;
		$response['replies_count'] = count( $replies );

		return $response;
	}

	/**
	 * Shape a threaded reply for API responses.
	 *
	 * @param ActivityCommentModel $reply Reply model.
	 * @return array
	 */
	private function prepare_task_reply_for_response( $reply ) {
		$content = (string) $reply->content;

		$response = array(
			'id'             => (int) $reply->id,
			'activity_id'    => (int) $reply->activity_id,
			'body'           => $content,
			'formatted_body' => wp_kses_post( $content ),
			'created_at'     => (string) $reply->created_at,
			'updated_at'     => (string) $reply->updated_at,
			'edited'         => (string) $reply->updated_at > (string) $reply->created_at,
		);

		if ( $reply->relationLoaded( 'user' ) && $reply->user ) {
			$response['user'] = $this->prepare_activity_user_for_response( $reply->user );
		}

		return $response;
	}

	/**
	 * Shape a threaded reply for the task activity log.
	 *
	 * @param ActivityCommentModel $reply           Reply model.
	 * @param ActivityModel        $parent_activity Parent note activity.
	 * @param int                  $task_id         Task ID.
	 * @return array
	 */
	private function prepare_activity_reply_for_response( $reply, $parent_activity, $task_id ) {
		$content        = (string) $reply->content;
		$parent_data    = $parent_activity->data ?? array();
		$parent_content = (string) ( $parent_data['content'] ?? '' );
		$parent_user_id = (int) ( $parent_activity->user_id ?? 0 );
		$parent_name    = '';

		if ( $parent_activity->relationLoaded( 'user' ) && $parent_activity->user ) {
			$parent_arr  = $parent_activity->user->toArray();
			$parent_name = (string) ( $parent_arr['display_name'] ?? '' );
		}

		$response = array(
			'id'            => (int) $reply->id,
			'timeline_id'   => 'reply-' . (int) $reply->id,
			'task_id'       => (int) $task_id,
			'activity_type' => 'comment_reply',
			'event_key'     => null,
			'data'          => array(
				'content'                  => $content,
				'parent_comment_id'        => (int) $parent_activity->id,
				'parent_comment_author_id' => $parent_user_id,
				'parent_comment_author'    => $parent_name,
				'parent_comment_excerpt'   => wp_trim_words( wp_strip_all_tags( $parent_content ), 12, '…' ),
			),
			'created_at'    => (string) $reply->created_at,
		);

		if ( $reply->relationLoaded( 'user' ) && $reply->user ) {
			$response['user'] = $this->prepare_activity_user_for_response( $reply->user );
		}

		return $response;
	}

	/**
	 * Shape an activity entry for the task audit log.
	 *
	 * @param ActivityModel $activity Activity model.
	 * @param int           $task_id  Task ID.
	 * @return array
	 */
	private function prepare_activity_for_response( $activity, $task_id ) {
		$data = $activity->data ?? array();

		$response = array(
			'id'            => (int) $activity->id,
			'timeline_id'   => 'activity-' . (int) $activity->id,
			'task_id'       => (int) $task_id,
			'activity_type' => (string) $activity->activity_type,
			'event_key'     => isset( $data['event_key'] ) ? (string) $data['event_key'] : null,
			'data'          => $data,
			'created_at'    => (string) $activity->created_at,
		);

		if ( $activity->relationLoaded( 'user' ) && $activity->user ) {
			$response['user'] = $this->prepare_activity_user_for_response( $activity->user );
		}

		return $response;
	}

	/**
	 * Normalize a WP user model for API responses.
	 *
	 * @param UserModel $user User model.
	 * @return array{id: int, display_name: string, email: string}
	 */
	private function prepare_activity_user_for_response( $user ) {
		$arr = $user->toArray();

		return array(
			'id'           => (int) ( $arr['ID'] ?? $arr['id'] ?? 0 ),
			'display_name' => (string) ( $arr['display_name'] ?? '' ),
			'email'        => (string) ( $arr['user_email'] ?? '' ),
		);
	}

	/**
	 * Prepare task data from request
	 * Handles polymorphic entity mapping (contact_id/deal_id → entity_type/entity_id)
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return array|WP_Error Task data array or WP_Error
	 */
	private function prepare_task( $request ) {
		$data = array();

		// Map simple text fields from schema
		$text_fields = array( 'title', 'task_type', 'status', 'priority' );
		foreach ( $text_fields as $field ) {
			if ( $request->has_param( $field ) ) {
				$value = sanitize_text_field( $request->get_param( $field ) );
				if ( ! empty( $value ) ) {
					$data[ $field ] = $value;
				}
			}
		}

		// Handle datetime fields separately (don't use text sanitizer on structured data)
		$datetime_fields = array( 'due_date', 'due_time', 'reminder_at' );
		foreach ( $datetime_fields as $field ) {
			if ( $request->has_param( $field ) ) {
				$value = $request->get_param( $field );
				// Skip empty values to avoid 0000-00-00 dates
				if ( ! empty( $value ) ) {
					// Validate datetime format but don't sanitize with text sanitizer
					$data[ $field ] = $value;
				} elseif ( 'reminder_at' === $field ) {
					// Allow clearing reminder_at by setting it to null
					$data[ $field ] = null;
				}
			}
		}

		if ( ! empty( $data['due_time'] ) ) {
			$due_time = (string) $data['due_time'];
			if ( preg_match( '/^\d{2}:\d{2}$/', $due_time ) ) {
				$data['due_time'] = $due_time . ':00';
			}
		}

		// Handle textarea fields separately
		if ( $request->has_param( 'description' ) ) {
			$data['description'] = sanitize_textarea_field( $request->get_param( 'description' ) );
		}

		// Handle integer fields
		if ( $request->has_param( 'assigned_to' ) ) {
			$data['assigned_to'] = absint( $request->get_param( 'assigned_to' ) );
		}

		if ( $request->has_param( 'status_id' ) ) {
			$stage_param = $request->get_param( 'status_id' );
			$data['status_id'] = empty( $stage_param ) ? null : absint( $stage_param );
		}

		// Handle polymorphic entity mapping
		$entity_result = $this->prepare_entity_mapping( $request );
		if ( is_wp_error( $entity_result ) ) {
			return $entity_result;
		}
		$data = array_merge( $data, $entity_result );

		// Validate required fields for create operations
		if ( ! $request->get_param( 'id' ) ) {
			$validation_error = $this->validate_required_fields( $data );
			if ( is_wp_error( $validation_error ) ) {
				return $validation_error;
			}
		}

		return $data;
	}

	/**
	 * Prepare polymorphic entity mapping from request
	 * Frontend sends either contact_id OR deals (deal_id)
	 * Backend stores as entity_type + entity_id
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return array|WP_Error Entity data array or WP_Error
	 */
	private function prepare_entity_mapping( $request ) {
		$entity_data = array();

		if ( $request->has_param( 'contact_id' ) && $request->get_param( 'contact_id' ) ) {
			$entity_data['entity_type'] = TaskEntityType::CONTACT;
			$entity_data['entity_id']   = absint( $request->get_param( 'contact_id' ) );

			// Validate contact exists
			$contact = ContactModel::find( $entity_data['entity_id'] );
			if ( ! $contact ) {
				return new WP_Error( 'invalid_contact', __( 'Invalid contact ID.', 'doublescale'), array( 'status' => 400 ) );
			}
		} elseif ( $request->has_param( 'deals' ) && $request->get_param( 'deals' ) ) {
			// Note: Frontend form field is named "deals" (string ID from InfiniteScrollSelect)
			$entity_data['entity_type'] = TaskEntityType::DEAL;
			$entity_data['entity_id']   = absint( $request->get_param( 'deals' ) );

			if ( ! class_exists( \DoubleScale\Pro\Modules\Deals\Models\DealModel::class ) ) {
				return new WP_Error( 'pro_required', __( 'Deal tasks require the Pipelines & Deals module.', 'doublescale' ), array( 'status' => 403 ) );
			}

			// Validate deal exists (if Pro model is available)
			if ( class_exists( 'DoubleScale\\Pro\Modules\Deals\Models\DealModel' ) ) {
				$deal = \DoubleScale\Pro\Modules\Deals\Models\DealModel::find( $entity_data['entity_id'] );
				if ( ! $deal ) {
					return new WP_Error( 'invalid_deal', __( 'Invalid deal ID.', 'doublescale'), array( 'status' => 400 ) );
				}
			}
		} elseif ( $request->has_param( 'projects' ) && $request->get_param( 'projects' ) ) {
			$entity_data['entity_type'] = TaskEntityType::PROJECT;
			$entity_data['entity_id']   = absint( $request->get_param( 'projects' ) );

			if ( ! class_exists( \DoubleScale\Pro\Modules\Projects\Models\ProjectModel::class ) ) {
				return new WP_Error( 'pro_required', __( 'Project tasks require the Projects module.', 'doublescale' ), array( 'status' => 403 ) );
			}

			$project = \DoubleScale\Pro\Modules\Projects\Models\ProjectModel::find( $entity_data['entity_id'] );
			if ( ! $project ) {
				return new WP_Error( 'invalid_project', __( 'Invalid project ID.', 'doublescale' ), array( 'status' => 400 ) );
			}
		} elseif ( $request->has_param( 'entity_type' ) && $request->has_param( 'entity_id' ) ) {
			// Direct entity_type/entity_id (for Api clients that understand the polymorphic pattern)
			$entity_data['entity_type'] = absint( $request->get_param( 'entity_type' ) );
			$entity_data['entity_id']   = absint( $request->get_param( 'entity_id' ) );

			$entity_validation = $this->validate_task_entity_reference(
				$entity_data['entity_type'],
				$entity_data['entity_id']
			);
			if ( is_wp_error( $entity_validation ) ) {
				return $entity_validation;
			}
		}

		return $entity_data;
	}

	/**
	 * Validate polymorphic task entity references.
	 *
	 * @param int $entity_type Entity type constant.
	 * @param int $entity_id   Entity ID.
	 * @return true|WP_Error
	 */
	private function validate_task_entity_reference( $entity_type, $entity_id ) {
		if ( TaskEntityType::PROJECT === (int) $entity_type ) {
			if ( ! class_exists( \DoubleScale\Pro\Modules\Projects\Models\ProjectModel::class ) ) {
				return new WP_Error( 'pro_required', __( 'Project tasks require the Projects module.', 'doublescale' ), array( 'status' => 403 ) );
			}

			$stored  = get_option( 'doublescale_enabled_modules', array() );
			$enabled = is_array( $stored ) && ! empty( $stored['projects'] );
			$enabled = (bool) apply_filters( 'doublescale_module_enabled_projects', $enabled );
			if ( ! $enabled ) {
				return new WP_Error( 'projects_module_required', __( 'Project tasks require the Projects module to be enabled.', 'doublescale' ), array( 'status' => 403 ) );
			}

			$project = \DoubleScale\Pro\Modules\Projects\Models\ProjectModel::find( $entity_id );
			if ( ! $project ) {
				return new WP_Error( 'invalid_project', __( 'Invalid project ID.', 'doublescale' ), array( 'status' => 400 ) );
			}
		}

		return true;
	}

	/**
	 * Validate required fields for task creation
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Task data array
	 *
	 * @return true|WP_Error True if valid, WP_Error otherwise
	 */
	private function validate_required_fields( $data ) {
		$missing = array();

		// Validate entity is provided (contact_id, deals, or entity_id)
		if ( empty( $data['entity_id'] ) ) {
			$missing[] = 'entity_id';
		}

		// Validate assigned_to
		if ( empty( $data['assigned_to'] ) ) {
			$missing[] = 'assigned_to';
		}

		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'missing_required',
				/* translators: %s: comma-separated list of missing parameters */
				sprintf( __( 'Missing parameter(s): %s', 'doublescale'), implode( ', ', $missing ) ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Check if sales rep can view a task (parent assignee or subtask assignee).
	 *
	 * @since 1.0.0
	 *
	 * @param TaskModel $task The task to check access for.
	 *
	 * @return bool|WP_Error True if allowed, WP_Error if denied.
	 */
	private function check_sales_rep_view_access( $task ) {
		if ( Permissions::is_sales_rep() && ! TaskModel::salesRepCanView( $task ) ) {
			return new WP_Error( 'not_found', __( 'Task not found.', 'doublescale'), array( 'status' => 404 ) );
		}

		return true;
	}

	/**
	 * Check if sales rep owns a task (parent assignee only).
	 *
	 * @since 1.0.0
	 *
	 * @param TaskModel $task The task to check access for.
	 *
	 * @return bool|WP_Error True if allowed, WP_Error if denied.
	 */
	private function check_sales_rep_owner_access( $task ) {
		if ( Permissions::is_sales_rep() && ! TaskModel::salesRepOwns( $task ) ) {
			return new WP_Error( 'not_found', __( 'Task not found.', 'doublescale'), array( 'status' => 404 ) );
		}

		return true;
	}

	/**
	 * Restrict subtask updates for sales reps who only have subtask-level access.
	 *
	 * Task owners may change any subtask field. Subtask-only assignees may update
	 * only their own subtasks and only completion, notes, due date, and reminder.
	 *
	 * @since 1.0.0
	 *
	 * @param TaskModel    $task    Parent task.
	 * @param SubtaskModel $subtask Subtask being updated.
	 * @param WP_REST_Request $request Request.
	 *
	 * @return bool|WP_Error True if allowed, WP_Error if denied.
	 */
	private function check_sales_rep_subtask_mutation_access( $task, $subtask, $request ) {
		if ( ! Permissions::is_sales_rep() || TaskModel::salesRepOwns( $task ) ) {
			return true;
		}

		if ( (int) $subtask->assigned_to !== get_current_user_id() ) {
			return new WP_Error( 'not_found', __( 'Subtask not found.', 'doublescale'), array( 'status' => 404 ) );
		}

		$restricted_params = array( 'title', 'group_id', 'assigned_to' );
		foreach ( $restricted_params as $param ) {
			if ( $request->has_param( $param ) ) {
				return new WP_Error(
					'forbidden',
					__( 'You can only update completion, notes, due date, and reminder on your assigned subtasks.', 'doublescale'),
					array( 'status' => 403 )
				);
			}
		}

		return true;
	}

	/**
	 * Check if sales rep can access a specific deal (must own it)
	 *
	 * @since 1.0.0
	 *
	 * @param int $deal_id The deal ID to check access for
	 *
	 * @return bool|WP_Error True if allowed, WP_Error if denied
	 */
	private function check_sales_rep_deal_access( $deal_id ) {
		if ( ! class_exists( 'DoubleScale\\Pro\Modules\Deals\Models\DealModel' ) ) {
			return true;
		}

		$deal = \DoubleScale\Pro\Modules\Deals\Models\DealModel::find( $deal_id );
		if ( ! $deal || (int) $deal->owner_id !== get_current_user_id() ) {
			return new WP_Error( 'forbidden', __( 'You do not have access to this deal.', 'doublescale'), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Apply sales rep restrictions to task data
	 * - Forces assigned_to to current user
	 * - Validates deal ownership if entity is a deal
	 *
	 * @since 1.0.0
	 *
	 * @param array $task_data Task data array to modify
	 *
	 * @return array|WP_Error Modified task data or WP_Error if access denied
	 */
	private function apply_assignee_restrictions( $task_data ) {
		if ( ! Permissions::can_assign_task_assignee() ) {
			$current_user_id = get_current_user_id();

			if ( empty( $task_data['assigned_to'] ) || (int) $task_data['assigned_to'] !== $current_user_id ) {
				$task_data['assigned_to'] = $current_user_id;
			}
		}

		if ( Permissions::is_sales_rep() && isset( $task_data['entity_type'] ) && TaskEntityType::DEAL === (int) $task_data['entity_type'] ) {
			$deal_access = $this->check_sales_rep_deal_access( $task_data['entity_id'] );
			if ( is_wp_error( $deal_access ) ) {
				return $deal_access;
			}
		}

		return $task_data;
	}

	/**
	 * List all workspace task labels.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_labels( $request ) {
		try {
			$labels = TaskLabelModel::orderBy( 'id', 'asc' )->get();

			return new WP_REST_Response(
				$labels->map(
					function ( $label ) {
						return $this->prepare_label_for_response( $label );
					}
				)->values()->all(),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Create a workspace task label.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_label( $request ) {
		try {
			$prepared = $this->prepare_label_data( $request );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}

			$label = TaskLabelModel::create( $prepared );

			return new WP_REST_Response( $this->prepare_label_for_response( $label ), 201 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Update a workspace task label.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_label( $request ) {
		try {
			$label = TaskLabelModel::find( $request->get_param( 'label_id' ) );
			if ( ! $label ) {
				return new WP_Error( 'not_found', __( 'Label not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			$prepared = $this->prepare_label_data( $request, false );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}

			if ( ! empty( $prepared ) ) {
				$label->update( $prepared );
			}

			return new WP_REST_Response( $this->prepare_label_for_response( $label->fresh() ), 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Delete a workspace task label (detaches from all tasks).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_label( $request ) {
		try {
			$label = TaskLabelModel::find( $request->get_param( 'label_id' ) );
			if ( ! $label ) {
				return new WP_Error( 'not_found', __( 'Label not found.', 'doublescale'), array( 'status' => 404 ) );
			}

			$copy = $this->prepare_label_for_response( $label );
			$label->delete();

			return new WP_REST_Response( $copy, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * List labels assigned to a task.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_task_labels( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$labels = $task->labels()->get();

			return new WP_REST_Response(
				$labels->map(
					function ( $label ) {
						return $this->prepare_label_for_response( $label );
					}
				)->values()->all(),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Replace the label set on a task (sync).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_task_labels( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request, true );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$label_ids = $this->normalize_label_ids( $request->get_param( 'label_ids' ) );
			$sync_result = $this->sync_task_labels( $task, $label_ids );
			if ( is_wp_error( $sync_result ) ) {
				return $sync_result;
			}

			$labels = $task->labels()->get();

			return new WP_REST_Response(
				$labels->map(
					function ( $label ) {
						return $this->prepare_label_for_response( $label );
					}
				)->values()->all(),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Create a label and assign it to a task in one call.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_label_for_task( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request, true );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$prepared = $this->prepare_label_data( $request );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}

			$label = TaskLabelModel::create( $prepared );
			$task->labels()->syncWithoutDetaching( array( (int) $label->id ) );

			do_action( 'doublescale_task_label_added', $task, $label );

			$labels = $task->labels()->get();

			return new WP_REST_Response(
				$labels->map(
					function ( $item ) {
						return $this->prepare_label_for_response( $item );
					}
				)->values()->all(),
				201
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Shape a label model for API responses.
	 *
	 * @param TaskLabelModel $label Label.
	 * @return array{id:int,title:?string,color:string}
	 */
	private function prepare_label_for_response( $label ) {
		return array(
			'id'    => (int) $label->id,
			'title' => null !== $label->title && '' !== $label->title ? (string) $label->title : null,
			'color' => (string) $label->color,
		);
	}

	/**
	 * Validate and normalize label create/update payload.
	 *
	 * @param WP_REST_Request $request   Request.
	 * @param bool            $require_color Whether color is required (create).
	 * @return array<string, mixed>|WP_Error
	 */
	private function prepare_label_data( $request, $require_color = true ) {
		$data = array();

		if ( $request->has_param( 'title' ) ) {
			$title = $request->get_param( 'title' );
			$data['title'] = ( null === $title || '' === $title ) ? null : sanitize_text_field( (string) $title );
		}

		if ( $request->has_param( 'color' ) ) {
			$color = sanitize_text_field( (string) $request->get_param( 'color' ) );
			if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ) {
				return new WP_Error( 'invalid_color', __( 'Color must be a valid hex value (#RRGGBB).', 'doublescale'), array( 'status' => 400 ) );
			}
			$data['color'] = $color;
		} elseif ( $require_color ) {
			$data['color'] = '#6d78d8';
		}

		if ( $require_color && empty( $data['color'] ) ) {
			return new WP_Error( 'invalid_color', __( 'Color is required.', 'doublescale'), array( 'status' => 400 ) );
		}

		return $data;
	}

	/**
	 * Normalize label ID list from request input.
	 *
	 * @param mixed $label_ids Raw label_ids param.
	 * @return array<int, int>
	 */
	private function normalize_label_ids( $label_ids ) {
		if ( ! is_array( $label_ids ) ) {
			return array();
		}

		$ids = array();
		foreach ( $label_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Sync labels on a task and emit add/remove domain events.
	 *
	 * @param TaskModel    $task      Task.
	 * @param array<int>   $label_ids Label IDs to assign.
	 * @return true|WP_Error
	 */
	private function sync_task_labels( $task, array $label_ids ) {
		if ( ! empty( $label_ids ) ) {
			$existing_count = TaskLabelModel::whereIn( 'id', $label_ids )->count();
			if ( $existing_count !== count( $label_ids ) ) {
				return new WP_Error( 'invalid_label', __( 'One or more labels do not exist.', 'doublescale'), array( 'status' => 400 ) );
			}
		}

		$changes = $task->labels()->sync( $label_ids );

		if ( ! empty( $changes['attached'] ) ) {
			$labels = TaskLabelModel::whereIn( 'id', $changes['attached'] )->get();
			foreach ( $labels as $label ) {
				do_action( 'doublescale_task_label_added', $task, $label );
			}
		}

		if ( ! empty( $changes['detached'] ) ) {
			$labels = TaskLabelModel::whereIn( 'id', $changes['detached'] )->get();
			foreach ( $labels as $label ) {
				do_action( 'doublescale_task_label_removed', $task, $label );
			}
		}

		return true;
	}

	/**
	 * GET /tasks/{id}/recurrence — return the task's repeat rule or null.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_recurrence( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request, true );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$recurrence = TaskRecurrenceModel::where( 'template_task_id', $task->id )->first();

			return new WP_REST_Response(
				$recurrence ? $this->prepare_recurrence_for_response( $recurrence ) : null,
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * PUT /tasks/{id}/recurrence — create or update the repeat rule.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_recurrence( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request, true );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$prepared = $this->prepare_recurrence_data( $request );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}

			$next_run_at = TaskRecurrenceModel::compute_initial_next_run_at(
				(string) $task->due_date,
				$prepared
			);

			$existing = TaskRecurrenceModel::where( 'template_task_id', $task->id )->first();
			$is_new   = ! $existing;

			if ( $existing ) {
				$existing->fill(
					array_merge(
						$prepared,
						array(
							'is_active'   => true,
							'next_run_at' => $next_run_at,
						)
					)
				);
				$existing->save();
				$recurrence = $existing->fresh();
			} else {
				$recurrence = TaskRecurrenceModel::create(
					array_merge(
						$prepared,
						array(
							'template_task_id' => (int) $task->id,
							'is_active'        => true,
							'next_run_at'      => $next_run_at,
						)
					)
				);
			}

			/**
			 * Fires when a task recurrence rule is set or updated.
			 *
			 * @param TaskModel           $task       Template task.
			 * @param TaskRecurrenceModel $recurrence Recurrence rule.
			 * @param bool                $is_new     True when first created.
			 */
			do_action( 'doublescale_task_recurrence_set', $task, $recurrence, $is_new );

			TaskRecurrenceScheduler::instance()->kick_recurrence_processing( $recurrence );
			$recurrence = TaskRecurrenceModel::find( $recurrence->id );

			return new WP_REST_Response( $this->prepare_recurrence_for_response( $recurrence ), $is_new ? 201 : 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * DELETE /tasks/{id}/recurrence — stop repeating.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_recurrence( $request ) {
		try {
			$task = $this->get_task_for_subtask( $request, true );
			if ( is_wp_error( $task ) ) {
				return $task;
			}

			$recurrence = TaskRecurrenceModel::where( 'template_task_id', $task->id )->first();
			if ( ! $recurrence ) {
				return new WP_REST_Response( null, 200 );
			}

			$copy = $this->prepare_recurrence_for_response( $recurrence );
			$recurrence->delete();

			/**
			 * Fires when a task recurrence rule is removed.
			 *
			 * @param TaskModel $task Template task.
			 */
			do_action( 'doublescale_task_recurrence_removed', $task );

			return new WP_REST_Response( $copy, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Shape recurrence for API output.
	 *
	 * @param TaskRecurrenceModel $recurrence Recurrence model.
	 * @return array<string, mixed>
	 */
	private function prepare_recurrence_for_response( $recurrence ) {
		$weekdays = $recurrence->getWeekdaysArray();

		return array(
			'id'             => (int) $recurrence->id,
			'frequency'      => (string) $recurrence->frequency,
			'interval_count' => (int) $recurrence->interval_count,
			'weekdays'       => ! empty( $weekdays ) ? $weekdays : null,
			'month_day'      => null !== $recurrence->month_day ? (int) $recurrence->month_day : null,
			'month_mode'     => $recurrence->month_mode ? (string) $recurrence->month_mode : null,
			'year_month'     => null !== $recurrence->year_month ? (int) $recurrence->year_month : null,
			'time'           => $recurrence->time ? substr( (string) $recurrence->time, 0, 5 ) : null,
			'timezone'       => $recurrence->timezone ? (string) $recurrence->timezone : null,
			'is_active'             => (bool) $recurrence->is_active,
			'repeat_when_completed' => (bool) $recurrence->repeat_when_completed,
			'create_new_on_repeat'  => $recurrence->createsNewTaskOnRepeat(),
			'status_id'              => ! empty( $recurrence->status_id ) ? (int) $recurrence->status_id : null,
			'next_run_at'           => (string) $recurrence->next_run_at,
		);
	}

	/**
	 * Validate and normalize recurrence payload from request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	private function prepare_recurrence_data( $request ) {
		$frequency = $request->get_param( 'frequency' );
		if ( ! in_array( $frequency, array( 'day', 'week', 'month', 'year' ), true ) ) {
			return new WP_Error(
				'invalid_frequency',
				__( 'Frequency must be day, week, month, or year.', 'doublescale'),
				array( 'status' => 400 )
			);
		}

		$interval = (int) $request->get_param( 'interval_count' );
		if ( $interval < 1 ) {
			$interval = 1;
		}

		$data = array(
			'frequency'      => $frequency,
			'interval_count' => $interval,
			'month_mode'     => null,
			'year_month'     => null,
		);

		if ( $request->has_param( 'time' ) ) {
			$time = (string) $request->get_param( 'time' );
			if ( preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
				$data['time'] = $time . ':00';
			} elseif ( preg_match( '/^\d{2}:\d{2}:\d{2}$/', $time ) ) {
				$data['time'] = $time;
			}
		}

		if ( $request->has_param( 'timezone' ) ) {
			$tz = (string) $request->get_param( 'timezone' );
			$data['timezone'] = '' !== $tz ? $tz : null;
		} else {
			$data['timezone'] = wp_timezone_string();
		}

		if ( 'week' === $frequency ) {
			$weekdays = $request->get_param( 'weekdays' );
			if ( ! is_array( $weekdays ) || empty( $weekdays ) ) {
				return new WP_Error(
					'invalid_weekdays',
					__( 'Weekly recurrence requires at least one weekday.', 'doublescale'),
					array( 'status' => 400 )
				);
			}

			$model = new TaskRecurrenceModel();
			$model->setWeekdaysFromArray( $weekdays );
			$data['weekdays'] = $model->weekdays;
			$data['month_day'] = null;
		} else {
			$data['weekdays'] = null;
		}

		if ( 'month' === $frequency ) {
			$mode = (string) ( $request->get_param( 'month_mode' ) ?: 'day' );
			if ( ! in_array( $mode, array( 'day', 'first', 'last' ), true ) ) {
				$mode = 'day';
			}
			$data['month_mode'] = $mode;

			if ( 'first' === $mode ) {
				$data['month_day'] = 1;
			} elseif ( 'last' === $mode ) {
				$data['month_day'] = 31;
			} else {
				$month_day = (int) $request->get_param( 'month_day' );
				if ( $month_day < 1 || $month_day > 31 ) {
					return new WP_Error(
						'invalid_month_day',
						__( 'Monthly recurrence requires day of month between 1 and 31.', 'doublescale'),
						array( 'status' => 400 )
					);
				}
				$data['month_day'] = $month_day;
			}
		} elseif ( 'year' === $frequency ) {
			$mode = (string) ( $request->get_param( 'month_mode' ) ?: 'day' );
			if ( ! in_array( $mode, array( 'day', 'first', 'last' ), true ) ) {
				$mode = 'day';
			}
			$data['month_mode'] = $mode;

			if ( 'first' === $mode ) {
				$data['year_month'] = 1;
				$data['month_day']  = 1;
			} elseif ( 'last' === $mode ) {
				$data['year_month'] = 12;
				$data['month_day']  = 31;
			} else {
				$year_month = (int) $request->get_param( 'year_month' );
				if ( $year_month < 1 || $year_month > 12 ) {
					return new WP_Error(
						'invalid_year_month',
						__( 'Yearly recurrence requires a month between 1 and 12.', 'doublescale'),
						array( 'status' => 400 )
					);
				}
				$month_day = (int) $request->get_param( 'month_day' );
				if ( $month_day < 1 || $month_day > 31 ) {
					return new WP_Error(
						'invalid_month_day',
						__( 'Yearly recurrence requires day of month between 1 and 31.', 'doublescale'),
						array( 'status' => 400 )
					);
				}
				$data['year_month'] = $year_month;
				$data['month_day']  = $month_day;
			}
		} else {
			$data['month_day'] = null;
		}

		if ( $request->has_param( 'repeat_when_completed' ) ) {
			$data['repeat_when_completed'] = (bool) $request->get_param( 'repeat_when_completed' );
		} else {
			$data['repeat_when_completed'] = false;
		}

		if ( $request->has_param( 'create_new_on_repeat' ) ) {
			$data['create_new_on_repeat'] = (bool) $request->get_param( 'create_new_on_repeat' );
		} else {
			$data['create_new_on_repeat'] = true;
		}

		if ( $request->has_param( 'status_id' ) ) {
			$stage_param = $request->get_param( 'status_id' );
			$data['status_id'] = empty( $stage_param ) ? null : absint( $stage_param );
		}

		return $data;
	}

	/**
	 * Load task relationships (contact/deal) based on entity_type
	 *
	 * @since 1.0.0
	 *
	 * @param TaskModel $task The task to load relationships for
	 *
	 * @return void
	 */
	private function load_task_relationships( $task ) {
		$task->load(
			array(
				'labels',
				'recurrence',
				'kanbanStatus',
				'custom_fields',
				'subtasks.assignedUser',
				'subtaskGroups.subtasks.assignedUser',
			)
		);

		// Cast to int to handle both string and integer entity_type values
		$entity_type = (int) $task->entity_type;

		if ( $entity_type === TaskEntityType::CONTACT ) {
			$task->load( 'contact' );
		} elseif ( $entity_type === TaskEntityType::DEAL ) {
			if ( class_exists( 'DoubleScale\\Pro\Modules\Deals\Models\DealModel' ) ) {
				$task->load( 'deal' );
			}
		} elseif ( $entity_type === TaskEntityType::PROJECT ) {
			if ( class_exists( 'DoubleScale\\Pro\Modules\Projects\Models\ProjectModel' ) ) {
				$task->load( 'project' );
			}
		}
	}

	/**
	 * Prepare task for Api response
	 *
	 * Normalizes the task data for consistent Api output:
	 * - Converts user data to use lowercase 'id' (matching timeline endpoint)
	 *
	 * @since 1.0.0
	 *
	 * @param TaskModel $task The task to prepare
	 *
	 * @return array Normalized task data
	 */
	private function prepare_task_for_response( $task, $include_attachments = false ) {
		$data = $task->toArray();

		// Normalize assigned_user to use lowercase 'id' for consistency with timeline endpoint
		if ( isset( $data['assigned_user'] ) && is_array( $data['assigned_user'] ) ) {
			$user = $data['assigned_user'];
			$data['user'] = array(
				'id'           => (int) ( $user['ID'] ?? $user['id'] ?? 0 ),
				'display_name' => $user['display_name'] ?? '',
				'email'        => $user['user_email'] ?? '',
			);
			unset( $data['assigned_user'] );
		}

		// Ungrouped subtasks only in the flat list; grouped ones live under subtask_groups.
		if ( $task->relationLoaded( 'subtasks' ) ) {
			$data['subtasks'] = $task->subtasks
				->filter(
					function ( $subtask ) {
						return empty( $subtask->group_id );
					}
				)
				->map(
					function ( $subtask ) {
						return $this->prepare_subtask_for_response( $subtask );
					}
				)
				->values()
				->all();
		} elseif ( isset( $data['subtasks'] ) && is_array( $data['subtasks'] ) ) {
			$data['subtasks'] = array_values(
				array_filter(
					array_map(
						function ( $subtask ) {
							if ( ! empty( $subtask['group_id'] ) ) {
								return null;
							}

							return array(
								'id'           => (int) ( $subtask['id'] ?? 0 ),
								'task_id'      => (int) ( $subtask['task_id'] ?? 0 ),
								'group_id'     => null,
								'title'        => (string) ( $subtask['title'] ?? '' ),
								'is_completed' => (bool) ( $subtask['is_completed'] ?? false ),
								'position'     => (int) ( $subtask['position'] ?? 0 ),
								'assigned_to'  => ! empty( $subtask['assigned_to'] ) ? (int) $subtask['assigned_to'] : null,
								'due_date'     => ! empty( $subtask['due_date'] ) ? (string) $subtask['due_date'] : null,
							);
						},
						$data['subtasks']
					)
				)
			);
		}

		if ( $task->relationLoaded( 'subtaskGroups' ) ) {
			$data['subtask_groups'] = $task->subtaskGroups
				->map(
					function ( $group ) {
						return $this->prepare_group_for_response( $group );
					}
				)
				->values()
				->all();
		} else {
			unset( $data['subtask_groups'] );
		}

		if ( $include_attachments ) {
			$attachments           = $this->get_task_attachments_shaped( (int) $task->id );
			$data['attachments']   = $attachments;
			$data['attachment_count'] = count( $attachments );
		}

		if ( $task->relationLoaded( 'labels' ) ) {
			$data['labels'] = $task->labels
				->map(
					function ( $label ) {
						return $this->prepare_label_for_response( $label );
					}
				)
				->values()
				->all();
		} else {
			unset( $data['labels'] );
		}

		if ( $task->relationLoaded( 'recurrence' ) ) {
			$data['recurrence'] = $task->recurrence
				? $this->prepare_recurrence_for_response( $task->recurrence )
				: null;
		} else {
			unset( $data['recurrence'] );
		}

		if ( $task->relationLoaded( 'custom_fields' ) ) {
			$data['custom_fields'] = array();
			foreach ( $task->custom_fields as $custom_field ) {
				$data['custom_fields'][] = $custom_field->toArray();
			}
		}

		if ( $task->relationLoaded( 'kanbanStatus' ) && $task->kanbanStatus ) {
			$data['kanban_status'] = array(
				'id'           => (int) $task->kanbanStatus->id,
				'name'         => (string) $task->kanbanStatus->name,
				'status'       => (string) $task->kanbanStatus->status,
				'is_protected' => (bool) $task->kanbanStatus->is_protected,
				'color'        => (string) $task->kanbanStatus->color,
				'sort_order'   => (int) $task->kanbanStatus->sort_order,
			);
		}

		if ( array_key_exists( 'status_id', $data ) && null !== $data['status_id'] ) {
			$data['status_id'] = (int) $data['status_id'];
		}

		return $data;
	}

	/**
	 * Fetch active task attachments shaped for API responses.
	 *
	 * @param int $task_id Task ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_task_attachments_shaped( int $task_id ): array {
		$service = $this->attachment_service();
		$rows    = AttachmentModel::forType( self::TASK_ATTACHABLE_TYPE )
			->where( 'attachable_id', $task_id )
			->active()
			->orderBy( 'created_at', 'desc' )
			->get();

		$shaped = array();
		foreach ( $rows as $attachment ) {
			$shaped[] = $service->shape_for_api( $attachment );
		}

		return $shaped;
	}

	/**
	 * Resolve a task-scoped attachment row.
	 *
	 * @param TaskModel $task          Parent task.
	 * @param int       $attachment_id Attachment row id.
	 * @return AttachmentModel|WP_Error
	 */
	private function find_task_attachment( $task, int $attachment_id ) {
		$attachment = AttachmentModel::forType( self::TASK_ATTACHABLE_TYPE )
			->where( 'attachable_id', (int) $task->id )
			->where( 'id', $attachment_id )
			->first();

		if ( ! $attachment ) {
			return new WP_Error( 'not_found', __( 'Attachment not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		return $attachment;
	}

	/**
	 * Enforce the per-task attachment count cap.
	 *
	 * @param int $existing_count Active attachments already on the task.
	 * @return WP_Error|null
	 */
	private function guard_task_attachment_count( int $existing_count ): ?WP_Error {
		if ( $existing_count >= self::TASK_ATTACHMENT_MAX_COUNT ) {
			return new WP_Error(
				'too_many_files',
				sprintf(
					/* translators: %d: maximum number of files allowed per task */
					_n(
						'You can attach at most %d file to this task.',
						'You can attach at most %d files to this task.',
						self::TASK_ATTACHMENT_MAX_COUNT,
						'doublescale'
					),
					self::TASK_ATTACHMENT_MAX_COUNT
				),
				array( 'status' => 400 )
			);
		}

		return null;
	}

	/**
	 * @return AttachmentService
	 */
	private function attachment_service(): AttachmentService {
		return new AttachmentService();
	}

	/**
	 * Bulk delete tasks
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function bulk_delete( $request ) {
		try {
			$ids = $request->get_param( 'ids' );

			// Validate input
			if ( empty( $ids ) || ! is_array( $ids ) ) {
				return new WP_Error(
					'invalid_ids',
					__( 'Invalid task IDs provided', 'doublescale'),
					array( 'status' => 400 )
				);
			}

			// Build query with sales rep filtering
			$query = TaskModel::whereIn( 'id', $ids );

			// Sales reps can only delete their own tasks
			if ( Permissions::is_sales_rep() ) {
				$query->where( 'assigned_to', get_current_user_id() );
			}

			// Get tasks that will actually be deleted (for accurate count)
			$tasks = $query->get();

			if ( $tasks->isEmpty() ) {
				return new WP_Error(
					'no_tasks_found',
					__( 'No accessible tasks found with the provided IDs', 'doublescale'),
					array( 'status' => 404 )
				);
			}

			$affected_ids = $tasks->pluck( 'id' )->toArray();

			// Delete tasks
			TaskModel::destroy( $affected_ids );

			return new WP_REST_Response(
				array(
					'affected' => count( $affected_ids ),
					'message'  => __( 'Tasks deleted successfully', 'doublescale'),
				),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Bulk mark tasks as completed
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function bulk_complete( $request ) {
		try {
			$ids = $request->get_param( 'ids' );

			// Validate input
			if ( empty( $ids ) || ! is_array( $ids ) ) {
				return new WP_Error(
					'invalid_ids',
					__( 'Invalid task IDs provided', 'doublescale'),
					array( 'status' => 400 )
				);
			}

			// Build query with sales rep filtering
			$query = TaskModel::whereIn( 'id', $ids );

			// Sales reps can only complete their own tasks
			if ( Permissions::is_sales_rep() ) {
				$query->where( 'assigned_to', get_current_user_id() );
			}

			// Get tasks that will actually be updated (for accurate count)
			$affected_ids = $query->pluck( 'id' )->toArray();

			if ( empty( $affected_ids ) ) {
				return new WP_Error(
					'no_tasks_found',
					__( 'No accessible tasks found with the provided IDs', 'doublescale'),
					array( 'status' => 404 )
				);
			}

			// Execute bulk update — fire lifecycle hooks per task so activity log stays accurate.
			$tasks = TaskModel::whereIn( 'id', $affected_ids )->get();
			foreach ( $tasks as $task ) {
				$task->markCompleted();
			}

			return new WP_REST_Response(
				array(
					'affected' => count( $affected_ids ),
					'message'  => __( 'Tasks marked as completed', 'doublescale'),
				),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Bulk mark tasks as pending
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function bulk_pending( $request ) {
		try {
			$ids = $request->get_param( 'ids' );

			// Validate input
			if ( empty( $ids ) || ! is_array( $ids ) ) {
				return new WP_Error(
					'invalid_ids',
					__( 'Invalid task IDs provided', 'doublescale'),
					array( 'status' => 400 )
				);
			}

			// Build query with sales rep filtering
			$query = TaskModel::whereIn( 'id', $ids );

			// Sales reps can only update their own tasks
			if ( Permissions::is_sales_rep() ) {
				$query->where( 'assigned_to', get_current_user_id() );
			}

			// Get tasks that will actually be updated (for accurate count)
			$affected_ids = $query->pluck( 'id' )->toArray();

			if ( empty( $affected_ids ) ) {
				return new WP_Error(
					'no_tasks_found',
					__( 'No accessible tasks found with the provided IDs', 'doublescale'),
					array( 'status' => 404 )
				);
			}

			// Execute bulk update — fire lifecycle hooks per task so activity log stays accurate.
			$tasks = TaskModel::whereIn( 'id', $affected_ids )->get();
			foreach ( $tasks as $task ) {
				$task->markPending();
			}

			return new WP_REST_Response(
				array(
					'affected' => count( $affected_ids ),
					'message'  => __( 'Tasks marked as pending', 'doublescale'),
				),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Check if user can view tasks
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return bool
	 */
	public function get_items_permissions_check( $request ) {
		return $this->user_can_access_tasks( $request, false );
	}

	/**
	 * Check if user can view a task
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return bool
	 */
	public function get_item_permissions_check( $request ) {
		return $this->user_can_access_tasks( $request, false );
	}

	/**
	 * Check if user can create tasks
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return bool
	 */
	public function create_item_permissions_check( $request ) {
		return $this->user_can_access_tasks( $request, true );
	}

	/**
	 * Check if user can update tasks
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return bool
	 */
	public function update_item_permissions_check( $request ) {
		return $this->user_can_access_tasks( $request, true );
	}

	/**
	 * Check if user can delete tasks
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return bool
	 */
	public function delete_item_permissions_check( $request ) {
		return $this->user_can_access_tasks( $request, true );
	}

	/**
	 * Check if user can perform bulk actions on tasks
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return bool
	 */
	public function bulk_action_permissions_check( $request ) {
		return Permissions::has_sales_rep_access();
	}

	/**
	 * Check if user can read workspace task labels.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function get_labels_permissions_check( $request ) {
		return Permissions::can_access_tasks_api();
	}

	/**
	 * Allow sales users globally, or project users when scoped to a project they can access.
	 *
	 * @param WP_REST_Request $request        Full data about the request.
	 * @param bool            $require_manage When true, require project manage access.
	 *
	 * @return bool
	 */
	private function user_can_access_tasks( WP_REST_Request $request, bool $require_manage ): bool {
		if ( Permissions::has_sales_rep_access() ) {
			return true;
		}

		return $this->user_can_access_project_scoped_tasks( $request, $require_manage );
	}

	/**
	 * Project-only users may access tasks tied to a project they can read or manage.
	 *
	 * @param WP_REST_Request $request        Full data about the request.
	 * @param bool            $require_manage When true, require project manage access.
	 *
	 * @return bool
	 */
	private function user_can_access_project_scoped_tasks( WP_REST_Request $request, bool $require_manage ): bool {
		if ( ! Permissions::has_project_access() ) {
			return false;
		}

		$project_id = $this->resolve_project_id_from_task_request( $request );
		if ( ! $project_id ) {
			return false;
		}

		return $require_manage
			? Capabilities::can_manage_project( $project_id )
			: Capabilities::can_read_project( $project_id );
	}

	/**
	 * Resolve a project ID from task request params or an existing task record.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return int|null
	 */
	private function resolve_project_id_from_task_request( WP_REST_Request $request ): ?int {
		$entity_type = $request->get_param( 'entity_type' );
		$entity_id   = (int) $request->get_param( 'entity_id' );

		if ( TaskEntityType::PROJECT === (int) $entity_type && $entity_id > 0 ) {
			return $entity_id;
		}

		if ( $request->has_param( 'projects' ) && $request->get_param( 'projects' ) ) {
			return absint( $request->get_param( 'projects' ) );
		}

		$task_id = (int) $request->get_param( 'id' );
		if ( $task_id <= 0 ) {
			return null;
		}

		$task = TaskModel::find( $task_id );
		if ( ! $task || TaskEntityType::PROJECT !== (int) $task->entity_type ) {
			return null;
		}

		return (int) $task->entity_id;
	}

	/**
	 * Check if user can manage workspace task labels (CRUD).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function manage_labels_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}
}
