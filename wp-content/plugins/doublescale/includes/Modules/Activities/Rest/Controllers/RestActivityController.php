<?php

/**
 * REST Api: Activity controller
 * Unified controller for all activity types (notes, emails, calls, meetings)
 *
 * Two main endpoints:
 * - GET /doublescale/v1/activities - For activity type tabs (Notes, Calls, etc.)
 *   Uses Eloquent query, supports activity_type filtering
 *
 * - GET /doublescale/v1/timeline - For "All Activity" tab (unified timeline)
 *   Uses SQL UNION, returns activities + tasks when Pro is active
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 * @subpackage RestApi
 */

namespace DoubleScale\Modules\Activities\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Activities\Models\ActivityCommentModel;
use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;
use DoubleScale\Modules\Activities\Services\ActivityManager;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Core\Constants\ActivityTypes;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Activity REST Controller class
 */
class RestActivityController extends RestController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'activities';

	/**
	 * Register the routes for the controller.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Get activities filtered by type (for activity type tabs: Notes, Calls, etc.).
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
			)
		);

		// Unified timeline endpoint (activities + tasks when Pro is active).
		// Use this for the "All Activity" tab.
		register_rest_route(
			$this->namespace,
			'/timeline',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_timeline' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_timeline_params(),
				),
			)
		);

		// Single activity CRUD.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
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
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				),
			)
		);

		// Add note.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/notes',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_note' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
			)
		);

		// Log email activity.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/emails',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'log_email' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
			)
		);

		// Log call activity.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/calls',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'log_call' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
			)
		);

		// Schedule meeting activity.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/meetings',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'schedule_meeting' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
			)
		);

		// Activity comments.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<activity_id>[\d]+)/comments',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_comments' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_comment' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
				),
			)
		);

		// Comment CRUD.
		register_rest_route(
			$this->namespace,
			'/comments/(?P<comment_id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_comment' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_comment' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				),
			)
		);

		// Upcoming activities (today onward, sorted ascending).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/upcoming',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_upcoming' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'contact_id'  => array(
							'description' => __( 'Filter by contact ID.', 'doublescale' ),
							'type'        => 'integer',
						),
						'entity_id'   => array(
							'description' => __( 'Filter by entity ID (e.g., deal ID).', 'doublescale' ),
							'type'        => 'integer',
						),
						'entity_type' => array(
							'description' => __( 'Filter by entity type. Use "deal" or 1 for deals.', 'doublescale' ),
							'type'        => array( 'string', 'integer' ),
						),
						'user_id'     => array(
							'description' => __( 'Filter by user ID.', 'doublescale' ),
							'type'        => 'integer',
						),
						'per_page'    => array(
							'description' => __( 'Number of items per page.', 'doublescale' ),
							'type'        => 'integer',
							'default'     => 20,
						),
						'page'        => array(
							'description' => __( 'Page number.', 'doublescale' ),
							'type'        => 'integer',
							'default'     => 1,
						),
					),
				),
			)
		);

		// Activity statistics.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/statistics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_statistics' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
			)
		);

		// Bulk delete activities.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bulk-delete',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'bulk_delete' ),
				'permission_callback' => array( $this, 'delete_item_permissions_check' ),
			)
		);
	}

	/**
	 * Get activities filtered by type (for activity type tabs: Notes, Calls, etc.)
	 *
	 * Uses Eloquent query via get_activities()
	 * when filtering by activity_type.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_items( $request ) {
		// Check Pro availability.
		$pro_active = doublescale_pro_task_model_available();

		// Normalize entity_type to integer constant.
		$entity_type = $this->normalize_entity_type( $request->get_param( 'entity_type' ) );

		// Check Pro availability for deal entity type.
		if ( $entity_type === \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_DEAL && ! $pro_active ) {
			return new WP_Error(
				'pro_required',
				__( 'Deal activities require Plugin Pro plugin', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		// Check Pro availability for task entity type.
		if ( $entity_type === \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_TASK && ! $pro_active ) {
			return new WP_Error(
				'pro_required',
				__( 'Task activities require Plugin Pro plugin', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		// Build filters.
		$filters = array(
			'contact_id'    => $request->get_param( 'contact_id' ),
			'entity_id'     => $request->get_param( 'entity_id' ),
			'entity_type'   => $entity_type,
			'user_id'       => $request->get_param( 'user_id' ),
			'date_from'     => $request->get_param( 'date_from' ),
			'date_to'       => $request->get_param( 'date_to' ),
			'activity_type' => $request->get_param( 'activity_type' ),
			'sort_by'       => $request->get_param( 'sort_by' ) ?? 'activity_date',
			'sort_order'    => $request->get_param( 'sort_order' ) ?? 'desc',
		);

		// Remove null values.
		$filters = array_filter(
			$filters,
			function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);

		$per_page = intval( $request->get_param( 'per_page' ) ) ?: 20;
		$page     = intval( $request->get_param( 'page' ) ) ?: 1;

		// Use Eloquent-based get_activities() for activity type filtering.
		$activities = ActivityManager::instance()->get_activities( $filters, $per_page, $page );

		if ( null === $activities ) {
			return new WP_Error( 'access_denied', __( 'Access denied', 'doublescale' ), array( 'status' => 403 ) );
		}

		// Transform to unified format for frontend compatibility.
		$data = array();
		foreach ( $activities->items() as $activity ) {
			$data[] = $this->transform_activity_to_unified( $activity );
		}

		return new WP_REST_Response(
			array(
				'data' => $data,
				'meta' => array(
					'total'        => $activities->total(),
					'per_page'     => $per_page,
					'current_page' => $page,
					'total_pages'  => $activities->lastPage(),
					'pro_active'   => $pro_active,
				),
			),
			200
		);
	}

	/**
	 * Get unified timeline (activities + tasks when Pro is active)
	 *
	 * Uses SQL UNION query via get_unified_timeline() - designed for the
	 * "All Activity" tab that shows everything.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_timeline( $request ) {
		// Check Pro availability.
		$pro_active = doublescale_pro_task_model_available();

		// Normalize entity_type to integer constant.
		$entity_type = $this->normalize_entity_type( $request->get_param( 'entity_type' ) );

		// Check Pro availability for deal entity type.
		if ( $entity_type === \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_DEAL && ! $pro_active ) {
			return new WP_Error(
				'pro_required',
				__( 'Deal timeline requires Plugin Pro plugin', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		// Build filters (no activity_type - timeline shows everything).
		$filters = array(
			'contact_id'  => $request->get_param( 'contact_id' ),
			'entity_id'   => $request->get_param( 'entity_id' ),
			'entity_type' => $entity_type,
			'user_id'     => $request->get_param( 'user_id' ),
			'date_from'   => $request->get_param( 'date_from' ),
			'date_to'     => $request->get_param( 'date_to' ),
			'sort_by'     => $request->get_param( 'sort_by' ) ?? 'activity_date',
			'sort_order'  => $request->get_param( 'sort_order' ) ?? 'desc',
		);

		// Remove null values.
		$filters = array_filter(
			$filters,
			function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);

		$per_page = intval( $request->get_param( 'per_page' ) ) ?: 20;
		$page     = intval( $request->get_param( 'page' ) ) ?: 1;

		// Use unified timeline - returns activities + tasks (when Pro active).
		$result = ActivityManager::instance()->get_unified_timeline(
			$filters,
			$per_page,
			$page
		);

		// Check for error in result.
		if ( isset( $result['meta']['error'] ) ) {
			return $this->map_deal_error( $result['meta']['error'] );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Get upcoming activities and tasks (today onward, nearest first).
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_upcoming( $request ) {
		// Normalize entity_type to integer constant.
		$entity_type = $this->normalize_entity_type( $request->get_param( 'entity_type' ) );

		// Check Pro availability for deal entity type.
		$pro_active = doublescale_pro_task_model_available();
		if ( $entity_type === \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_DEAL && ! $pro_active ) {
			return new WP_Error(
				'pro_required',
				__( 'Deal timeline requires Plugin Pro plugin', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$filters = array(
			'contact_id'  => $request->get_param( 'contact_id' ),
			'entity_id'   => $request->get_param( 'entity_id' ),
			'entity_type' => $entity_type,
			'user_id'     => $request->get_param( 'user_id' ),
			'date_from'   => gmdate( 'Y-m-d' ),
			'sort_by'     => 'activity_date',
			'sort_order'  => 'asc',
		);

		// Remove null values.
		$filters = array_filter(
			$filters,
			function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);

		$per_page = intval( $request->get_param( 'per_page' ) ) ?: 20;
		$page     = intval( $request->get_param( 'page' ) ) ?: 1;

		$result = ActivityManager::instance()->get_unified_timeline(
			$filters,
			$per_page,
			$page
		);

		if ( isset( $result['meta']['error'] ) ) {
			return $this->map_deal_error( $result['meta']['error'] );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Transform Eloquent activity model to unified format
	 *
	 * Ensures consistent response format between get_activities() and get_unified_timeline().
	 *
	 * @since 1.0.0
	 *
	 * @param ActivityModel $activity Activity model.
	 *
	 * @return array Transformed activity data.
	 */
	private function transform_activity_to_unified( $activity ): array {
		$editable_types = ActivityTypes::get_editable_types();
		$system_types   = ActivityTypes::get_system_types();

		$user = null;
		if ( $activity->relationLoaded( 'user' ) && $activity->user ) {
			$user = array(
				'id'           => $activity->user->ID,
				'display_name' => $activity->user->display_name,
				'email'        => $activity->user->user_email,
			);
		}

		return array(
			'id'                => (int) $activity->id,
			'item_type'         => 'activity',
			'activity_type'     => $activity->activity_type,
			'contact_id'        => $activity->contact_id ? (int) $activity->contact_id : null,
			'deal_id'           => $activity->deal_id ? (int) $activity->deal_id : null,
			'data'              => $activity->data,
			'user_id'           => $activity->user_id ? (int) $activity->user_id : null,
			'user'              => $user,
			'formatted_message' => $activity->formatted_message,
			'is_editable'       => $activity->is_editable(),
			'is_system'         => in_array( $activity->activity_type, $system_types, true ),
			'comments_count'    => $activity->relationLoaded( 'comments' ) ? $activity->comments->count() : 0,
			'activity_date'     => (string) $activity->activity_date,
			'created_at'        => (string) $activity->created_at,
			'updated_at'        => (string) $activity->updated_at,
		);
	}

	/**
	 * Get timeline endpoint parameters
	 *
	 * @since 1.0.0
	 *
	 * @return array Parameters for timeline endpoint.
	 */
	public function get_timeline_params() {
		return array(
			// Contact filtering.
			'contact_id'  => array(
				'description' => __( 'Filter by contact ID.', 'doublescale' ),
				'type'        => 'integer',
			),
			// Entity (deal) filtering.
			'entity_id'   => array(
				'description' => __( 'Filter by entity ID (e.g., deal ID).', 'doublescale' ),
				'type'        => 'integer',
			),
			'entity_type' => array(
				'description' => __( 'Filter by entity type. Use "deal" or 1 for deals.', 'doublescale' ),
				'type'        => array( 'string', 'integer' ),
			),
			// User filtering.
			'user_id'     => array(
				'description' => __( 'Filter by user ID.', 'doublescale' ),
				'type'        => 'integer',
			),
			// Date filtering.
			'date_from'   => array(
				'description' => __( 'Filter by start date (YYYY-MM-DD).', 'doublescale' ),
				'type'        => 'string',
				'format'      => 'date',
			),
			'date_to'     => array(
				'description' => __( 'Filter by end date (YYYY-MM-DD).', 'doublescale' ),
				'type'        => 'string',
				'format'      => 'date',
			),
			// Sorting.
			'sort_by'     => array(
				'description' => __( 'Sort by field. activity_date sorts by the actual event time.', 'doublescale' ),
				'type'        => 'string',
				'default'     => 'activity_date',
				'enum'        => array( 'created_at', 'updated_at', 'activity_date' ),
			),
			'sort_order'  => array(
				'description' => __( 'Sort order.', 'doublescale' ),
				'type'        => 'string',
				'default'     => 'desc',
				'enum'        => array( 'asc', 'desc' ),
			),
			// Pagination.
			'per_page'    => array(
				'description' => __( 'Number of items per page.', 'doublescale' ),
				'type'        => 'integer',
				'default'     => 20,
			),
			'page'        => array(
				'description' => __( 'Page number.', 'doublescale' ),
				'type'        => 'integer',
				'default'     => 1,
			),
		);
	}

	/**
	 * Normalize entity_type to integer constant
	 *
	 * @since 1.0.0
	 *
	 * @param string|int|null $entity_type Entity type.
	 *
	 * @return int|null Normalized entity type.
	 */
	private function normalize_entity_type( $entity_type ) {
		if ( empty( $entity_type ) ) {
			return null;
		}

		// Already numeric.
		if ( is_numeric( $entity_type ) ) {
			return intval( $entity_type );
		}

		// String mapping.
		$mapped = \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::string_to_entity_type( (string) $entity_type );
		if ( null !== $mapped ) {
			return $mapped;
		}

		$legacy_map = array(
			'deal'     => \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_DEAL,
			'campaign' => \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_CAMPAIGN,
		);

		return $legacy_map[ strtolower( (string) $entity_type ) ] ?? null;
	}

	/**
	 * Get one activity
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_item( $request ) {
		$activity_id   = $request->get_param( 'id' );
		$with_comments = $request->get_param( 'with_comments' );

		$activity = ActivityManager::instance()->get_activity( $activity_id, $with_comments );

		if ( ! $activity ) {
			return new WP_Error( 'activity_not_found', __( 'Activity not found', 'doublescale' ), array( 'status' => 404 ) );
		}

		$data = $this->prepare_item_for_response( $activity, $request );

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Add note
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function add_note( $request ) {
		$data = array(
			'contact_id'  => $request->get_param( 'contact_id' ),
			'entity_id'   => $request->get_param( 'entity_id' ),
			'entity_type' => $this->normalize_entity_type( $request->get_param( 'entity_type' ) ),
			'title'       => sanitize_text_field( $request->get_param( 'title' ) ?? '' ),
			'content'     => wp_kses_post( $request->get_param( 'content' ) ?? '' ),
		);

		if ( empty( $data['contact_id'] ) && empty( $data['entity_id'] ) ) {
			return new WP_Error( 'missing_entity', __( 'Contact ID or Entity ID is required', 'doublescale' ), array( 'status' => 400 ) );
		}

		if ( empty( $data['content'] ) && empty( $data['title'] ) ) {
			return new WP_Error( 'missing_content', __( 'Note content or title is required', 'doublescale' ), array( 'status' => 400 ) );
		}

		$activity = ActivityManager::instance()->add_note( $data );

		if ( ! $activity ) {
			return new WP_Error( 'creation_failed', __( 'Failed to add note', 'doublescale' ), array( 'status' => 500 ) );
		}

		$activity->load(
			array_merge(
				array( 'user', 'associations' ),
				ActivityModel::morph_append_relations()
			)
		);
		$response_data = $this->prepare_item_for_response( $activity, $request );

		return new WP_REST_Response( $response_data, 201 );
	}

	/**
	 * Log email activity
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function log_email( $request ) {
		$email_data = $request->get_param( 'email_data' ) ?? array();

		$data = array(
			'contact_id'  => $request->get_param( 'contact_id' ),
			'entity_id'   => $request->get_param( 'entity_id' ),
			'entity_type' => $request->get_param( 'entity_type' ),
			'subject'     => $email_data['subject'] ?? '',
			'body'        => $email_data['body'] ?? '',
			'sent_at'     => $email_data['sent_at'] ?? current_time( 'mysql', true ),
		);

		// Only include contact_email/contact_name if provided with actual values.
		// This allows the manager to auto-populate from deal contact when not provided.
		if ( ! empty( $email_data['contact_email'] ) ) {
			$data['contact_email'] = $email_data['contact_email'];
		}
		if ( ! empty( $email_data['contact_name'] ) ) {
			$data['contact_name'] = $email_data['contact_name'];
		}

		if ( empty( $data['contact_id'] ) && empty( $data['entity_id'] ) ) {
			return new WP_Error( 'missing_entity', __( 'Contact ID or Entity ID is required', 'doublescale' ), array( 'status' => 400 ) );
		}

		$activity = ActivityManager::instance()->log_email( $data );

		if ( ! $activity ) {
			return new WP_Error( 'creation_failed', __( 'Failed to log email', 'doublescale' ), array( 'status' => 500 ) );
		}

		$activity->load(
			array_merge(
				array( 'user', 'associations' ),
				ActivityModel::morph_append_relations()
			)
		);
		$response_data = $this->prepare_item_for_response( $activity, $request );

		return new WP_REST_Response( $response_data, 201 );
	}

	/**
	 * Log call activity
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function log_call( $request ) {
		$call_data = $request->get_param( 'call_data' ) ?? array();

		$data = array(
			'contact_id'   => $request->get_param( 'contact_id' ),
			'entity_id'    => $request->get_param( 'entity_id' ),
			'entity_type'  => $request->get_param( 'entity_type' ),
			'duration'     => $call_data['duration'] ?? null,
			'outcome'      => $call_data['outcome'] ?? '',
			'notes'        => $call_data['notes'] ?? '',
			'called_at'    => $call_data['called_at'] ?? current_time( 'mysql', true ),
			'phone_number' => $call_data['phone_number'] ?? '',
		);

		if ( empty( $data['contact_id'] ) && empty( $data['entity_id'] ) ) {
			return new WP_Error( 'missing_entity', __( 'Contact ID or Entity ID is required', 'doublescale' ), array( 'status' => 400 ) );
		}

		$activity = ActivityManager::instance()->log_call( $data );

		if ( ! $activity ) {
			return new WP_Error( 'creation_failed', __( 'Failed to log call', 'doublescale' ), array( 'status' => 500 ) );
		}

		$activity->load(
			array_merge(
				array( 'user', 'associations' ),
				ActivityModel::morph_append_relations()
			)
		);
		$response_data = $this->prepare_item_for_response( $activity, $request );

		return new WP_REST_Response( $response_data, 201 );
	}

	/**
	 * Schedule meeting activity
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function schedule_meeting( $request ) {
		$meeting_data = $request->get_param( 'meeting_data' ) ?? array();

		$data = array(
			'contact_id'   => $request->get_param( 'contact_id' ),
			'entity_id'    => $request->get_param( 'entity_id' ),
			'entity_type'  => $request->get_param( 'entity_type' ),
			'title'        => $meeting_data['title'] ?? $meeting_data['meeting_title'] ?? '',
			'scheduled_at' => $meeting_data['scheduled_at'] ?? $meeting_data['meeting_date_time'] ?? '',
			'duration'     => $meeting_data['duration'] ?? 60,
			'location'     => $meeting_data['location'] ?? '',
			'description'  => $meeting_data['description'] ?? '',
		);

		// Only include primary_attendee fields if provided with actual values.
		// This allows the manager to auto-populate from deal contact when not provided.
		if ( ! empty( $meeting_data['primary_attendee_id'] ) ) {
			$data['primary_attendee_id'] = $meeting_data['primary_attendee_id'];
		}
		if ( ! empty( $meeting_data['primary_attendee_name'] ) ) {
			$data['primary_attendee_name'] = $meeting_data['primary_attendee_name'];
		}
		if ( ! empty( $meeting_data['primary_attendee_email'] ) ) {
			$data['primary_attendee_email'] = $meeting_data['primary_attendee_email'];
		}

		if ( empty( $data['contact_id'] ) && empty( $data['entity_id'] ) ) {
			return new WP_Error( 'missing_entity', __( 'Contact ID or Entity ID is required', 'doublescale' ), array( 'status' => 400 ) );
		}

		$activity = ActivityManager::instance()->schedule_meeting( $data );

		if ( ! $activity ) {
			return new WP_Error( 'creation_failed', __( 'Failed to schedule meeting', 'doublescale' ), array( 'status' => 500 ) );
		}

		$activity->load(
			array_merge(
				array( 'user', 'associations' ),
				ActivityModel::morph_append_relations()
			)
		);
		$response_data = $this->prepare_item_for_response( $activity, $request );

		return new WP_REST_Response( $response_data, 201 );
	}

	/**
	 * Update activity
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update_item( $request ) {
		$activity_id = intval( $request->get_param( 'id' ) );
		$user_id     = get_current_user_id();

		if ( empty( $activity_id ) ) {
			return new WP_Error( 'missing_activity_id', __( 'Activity ID is required', 'doublescale' ), array( 'status' => 400 ) );
		}

		// Get the activity to check its type.
		$activity = ActivityModel::find( $activity_id );

		if ( ! $activity ) {
			return new WP_Error( 'activity_not_found', __( 'Activity not found', 'doublescale' ), array( 'status' => 404 ) );
		}

		// Check if activity is editable.
		if ( ! $activity->is_editable() ) {
			return new WP_Error(
				'activity_not_editable',
				__( 'This activity type cannot be edited. Only user-created activities (notes, calls, emails, meetings) can be modified.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		// Prepare data based on activity type.
		$data = array();
		switch ( $activity->activity_type ) {
			case 'note':
				$title   = $request->get_param( 'title' );
				$content = $request->get_param( 'content' );
				if ( null !== $title ) {
					$data['title'] = $title;
				}
				if ( null !== $content ) {
					$data['content'] = $content;
				}
				break;

			case 'email_sent':
				$email_data = $request->get_param( 'email_data' );
				if ( ! empty( $email_data ) && is_array( $email_data ) ) {
					$data['email_data'] = $email_data;
				}
				break;

			case 'call_logged':
				$call_data = $request->get_param( 'call_data' );
				if ( ! empty( $call_data ) && is_array( $call_data ) ) {
					$data['call_data'] = $call_data;
				}
				break;

			case 'meeting_scheduled':
				$meeting_data = $request->get_param( 'meeting_data' );
				if ( ! empty( $meeting_data ) && is_array( $meeting_data ) ) {
					$data['meeting_data'] = $meeting_data;
				}
				break;
		}

		$updated_activity = ActivityManager::instance()->update_activity( $activity_id, $data, $user_id );

		if ( ! $updated_activity ) {
			return new WP_Error( 'update_failed', __( 'Failed to update activity or access denied', 'doublescale' ), array( 'status' => 500 ) );
		}

		$updated_activity->load(
			array_merge(
				array( 'user', 'associations' ),
				ActivityModel::morph_append_relations()
			)
		);
		$response_data = $this->prepare_item_for_response( $updated_activity, $request );

		return new WP_REST_Response( $response_data, 200 );
	}

	/**
	 * Delete activity
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function delete_item( $request ) {
		$activity_id = intval( $request->get_param( 'id' ) );
		$user_id     = get_current_user_id();

		if ( empty( $activity_id ) ) {
			return new WP_Error( 'missing_activity_id', __( 'Activity ID is required', 'doublescale' ), array( 'status' => 400 ) );
		}

		// Get the activity to check its type.
		$activity = ActivityModel::find( $activity_id );

		if ( ! $activity ) {
			return new WP_Error( 'activity_not_found', __( 'Activity not found', 'doublescale' ), array( 'status' => 404 ) );
		}

		// Check if activity is editable.
		if ( ! $activity->is_editable() ) {
			return new WP_Error(
				'activity_not_deletable',
				__( 'This activity type cannot be deleted. Only user-created activities (notes, calls, emails, meetings) can be removed.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		$deleted = ActivityManager::instance()->delete_activity( $activity_id, $user_id );

		if ( ! $deleted ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete activity or access denied', 'doublescale' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Bulk delete activities
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function bulk_delete( $request ) {
		$activity_ids = $request->get_param( 'activity_ids' );
		$user_id      = get_current_user_id();

		if ( ! is_array( $activity_ids ) || empty( $activity_ids ) ) {
			return new WP_Error( 'invalid_data', __( 'Activity IDs array is required', 'doublescale' ), array( 'status' => 400 ) );
		}

		$deleted_count = ActivityManager::instance()->bulk_delete_activities( $activity_ids, $user_id );

		return new WP_REST_Response( array( 'deleted_count' => $deleted_count ), 200 );
	}

	/**
	 * Get activity comments
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_comments( $request ) {
		$activity_id = $request->get_param( 'activity_id' );

		$activity = ActivityModel::with( 'comments.user' )->find( $activity_id );

		if ( ! $activity ) {
			return new WP_Error( 'activity_not_found', __( 'Activity not found', 'doublescale' ), array( 'status' => 404 ) );
		}

		$data = array();
		foreach ( $activity->comments as $comment ) {
			$data[] = $this->prepare_comment_for_response( $comment );
		}

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Add comment to activity
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function add_comment( $request ) {
		$activity_id = intval( $request->get_param( 'activity_id' ) );
		$content     = sanitize_textarea_field( $request->get_param( 'content' ) );
		$user_id     = get_current_user_id();

		if ( empty( $activity_id ) ) {
			return new WP_Error( 'missing_activity_id', __( 'Activity ID is required', 'doublescale' ), array( 'status' => 400 ) );
		}

		if ( empty( $content ) ) {
			return new WP_Error( 'missing_content', __( 'Comment content is required', 'doublescale' ), array( 'status' => 400 ) );
		}

		$comment = ActivityManager::instance()->add_comment( $activity_id, $content, $user_id );

		if ( ! $comment ) {
			return new WP_Error( 'creation_failed', __( 'Failed to add comment', 'doublescale' ), array( 'status' => 500 ) );
		}

		$comment->load( 'user' );
		$data = $this->prepare_comment_for_response( $comment );

		return new WP_REST_Response( $data, 201 );
	}

	/**
	 * Update comment
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update_comment( $request ) {
		$comment_id = intval( $request->get_param( 'comment_id' ) );
		$content    = sanitize_textarea_field( $request->get_param( 'content' ) );
		$user_id    = get_current_user_id();

		if ( empty( $comment_id ) ) {
			return new WP_Error( 'missing_comment_id', __( 'Comment ID is required', 'doublescale' ), array( 'status' => 400 ) );
		}

		if ( empty( $content ) ) {
			return new WP_Error( 'missing_content', __( 'Comment content is required', 'doublescale' ), array( 'status' => 400 ) );
		}

		$comment = ActivityManager::instance()->update_comment( $comment_id, $content, $user_id );

		if ( ! $comment ) {
			return new WP_Error( 'update_failed', __( 'Failed to update comment or access denied', 'doublescale' ), array( 'status' => 500 ) );
		}

		$comment->load( 'user' );
		$data = $this->prepare_comment_for_response( $comment );

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Delete comment
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function delete_comment( $request ) {
		$comment_id = intval( $request->get_param( 'comment_id' ) );
		$user_id    = get_current_user_id();

		if ( empty( $comment_id ) ) {
			return new WP_Error( 'missing_comment_id', __( 'Comment ID is required', 'doublescale' ), array( 'status' => 400 ) );
		}

		$deleted = ActivityManager::instance()->delete_comment( $comment_id, $user_id );

		if ( ! $deleted ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete comment or access denied', 'doublescale' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Get activity statistics
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_statistics( $request ) {
		// Normalize entity_type to integer constant.
		$entity_type = $this->normalize_entity_type( $request->get_param( 'entity_type' ) );

		$filters = array(
			'contact_id'  => $request->get_param( 'contact_id' ),
			'entity_id'   => $request->get_param( 'entity_id' ),
			'entity_type' => $entity_type,
			'user_id'     => $request->get_param( 'user_id' ),
			'date_from'   => $request->get_param( 'date_from' ),
			'date_to'     => $request->get_param( 'date_to' ),
		);

		// Remove null values.
		$filters = array_filter(
			$filters,
			function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);

		$statistics = ActivityManager::instance()->get_activity_statistics( $filters );

		return new WP_REST_Response( $statistics, 200 );
	}

	/**
	 * Prepare the item for the REST response
	 *
	 * @param ActivityModel   $activity Activity object.
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return array
	 */
	public function prepare_item_for_response( $activity, $request ) {
		$data = array(
			'id'                => $activity->id,
			'contact_id'        => $activity->contact_id,
			'deal_id'           => $activity->deal_id, // Convenience: first associated deal id.
			'activity_type'     => $activity->activity_type,
			'data'              => $activity->data,
			'user_id'           => $activity->user_id,
			'formatted_message' => $activity->formatted_message,
			'is_editable'       => $activity->is_editable(),
			'is_system'         => $activity->is_system_activity(),
			'activity_date'     => (string) $activity->activity_date,
			'created_at'        => $activity->created_at,
			'updated_at'        => $activity->updated_at,
		);

		// Include entity associations if loaded.
		if ( $activity->relationLoaded( 'associations' ) && $activity->associations ) {
			$data['associations'] = array();
			foreach ( $activity->associations as $association ) {
				$data['associations'][] = array(
					'entity_type' => $association->entity_type,
					'entity_id'   => $association->entity_id,
				);
			}
		}

		// Include relationships if loaded.
		if ( $activity->relationLoaded( 'user' ) && $activity->user ) {
			$data['user'] = array(
				'id'           => $activity->user->ID,
				'display_name' => $activity->user->display_name,
				'email'        => $activity->user->user_email,
			);
		}

		if ( $activity->relationLoaded( 'contact' ) && $activity->contact ) {
			$data['contact'] = array(
				'id'         => $activity->contact->id,
				'email'      => $activity->contact->email,
				'first_name' => $activity->contact->first_name,
				'last_name'  => $activity->contact->last_name,
			);
		}

		if ( $activity->relationLoaded( 'comments' ) ) {
			$data['comments'] = array();
			foreach ( $activity->comments as $comment ) {
				$data['comments'][] = $this->prepare_comment_for_response( $comment );
			}
		}

		return $data;
	}

	/**
	 * Prepare comment for response
	 *
	 * @param ActivityCommentModel $comment Comment object.
	 *
	 * @return array
	 */
	protected function prepare_comment_for_response( $comment ) {
		$data = array(
			'id'                => $comment->id,
			'activity_id'       => $comment->activity_id,
			'user_id'           => $comment->user_id,
			'content'           => $comment->content,
			'formatted_content' => $comment->formatted_content,
			'time_ago'          => $comment->time_ago,
			'created_at'        => $comment->created_at,
			'updated_at'        => $comment->updated_at,
		);

		if ( $comment->relationLoaded( 'user' ) && $comment->user ) {
			$data['user'] = array(
				'id'           => $comment->user->ID,
				'display_name' => $comment->user->display_name,
				'email'        => $comment->user->user_email,
			);
		}

		return $data;
	}

	/**
	 * Get collection params for activities endpoint
	 *
	 * @since 1.0.0
	 *
	 * @return array Collection parameters.
	 */
	public function get_collection_params() {
		return array(
			// Entity filtering.
			'contact_id'    => array(
				'description' => __( 'Filter by contact ID', 'doublescale' ),
				'type'        => 'integer',
			),
			'entity_type'   => array(
				'description' => __( 'Entity type: deal, campaign, or numeric (1=deal, 2=campaign)', 'doublescale' ),
				'type'        => 'string',
			),
			'entity_id'     => array(
				'description' => __( 'Entity ID (used with entity_type)', 'doublescale' ),
				'type'        => 'integer',
			),

			// User filtering.
			'user_id'       => array(
				'description' => __( 'Filter by user ID (activity creator or task assignee)', 'doublescale' ),
				'type'        => 'integer',
			),

			// Activity type filtering (filters activities only, tasks are excluded when this is set).
			'activity_type' => array(
				'description' => __( 'Filter by activity type (e.g., note, call_logged, email_sent, meeting_scheduled). When set, only activities of this type are returned (no tasks).', 'doublescale' ),
				'type'        => 'string',
			),

			// Date filtering.
			'date_from'     => array(
				'description' => __( 'Filter from date (Y-m-d)', 'doublescale' ),
				'type'        => 'string',
				'format'      => 'date',
			),
			'date_to'       => array(
				'description' => __( 'Filter to date (Y-m-d)', 'doublescale' ),
				'type'        => 'string',
				'format'      => 'date',
			),

			// Sorting.
			'sort_by'       => array(
				'description' => __( 'Sort field: activity_date (actual event time) or created_at (record creation)', 'doublescale' ),
				'type'        => 'string',
				'enum'        => array( 'activity_date', 'created_at' ),
				'default'     => 'activity_date',
			),
			'sort_order'    => array(
				'description' => __( 'Sort direction: asc, desc', 'doublescale' ),
				'type'        => 'string',
				'enum'        => array( 'asc', 'desc' ),
				'default'     => 'desc',
			),

			// Pagination.
			'per_page'      => array(
				'description' => __( 'Items per page', 'doublescale' ),
				'type'        => 'integer',
				'default'     => 20,
				'minimum'     => 1,
				'maximum'     => 100,
			),
			'page'          => array(
				'description' => __( 'Page number', 'doublescale' ),
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			),
		);
	}

	/**
	 * Check if user can access activities
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool
	 */
	public function get_items_permissions_check( $request ) {
		return $this->user_can_access_activities( $request, false );
	}

	/**
	 * Check if user can access single activity
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool
	 */
	public function get_item_permissions_check( $request ) {
		return $this->user_can_access_activities( $request, false );
	}

	/**
	 * Check if user can create activities
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool
	 */
	public function create_item_permissions_check( $request ) {
		return $this->user_can_access_activities( $request, true );
	}

	/**
	 * Check if user can update activities
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool
	 */
	public function update_item_permissions_check( $request ) {
		return $this->user_can_access_activities( $request, true );
	}

	/**
	 * Check if user can delete activities
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool
	 */
	public function delete_item_permissions_check( $request ) {
		return $this->user_can_access_activities( $request, true );
	}

	/**
	 * Allow sales users globally, or project users when scoped to a project they can access.
	 *
	 * @param WP_REST_Request $request        Full data about the request.
	 * @param bool            $require_manage When true, require project manage access.
	 *
	 * @return bool
	 */
	private function user_can_access_activities( WP_REST_Request $request, bool $require_manage ): bool {
		if ( Permissions::has_sales_rep_access() ) {
			return true;
		}

		return $this->user_can_access_project_scoped_activities( $request, $require_manage );
	}

	/**
	 * Project-only users may access activities tied to a project they can read or manage.
	 *
	 * @param WP_REST_Request $request        Full data about the request.
	 * @param bool            $require_manage When true, require project manage access.
	 *
	 * @return bool
	 */
	private function user_can_access_project_scoped_activities( WP_REST_Request $request, bool $require_manage ): bool {
		if ( ! class_exists( '\DoubleScale\Pro\Core\UserRoles\PermissionsCompat' ) ) {
			return false;
		}

		if ( ! \DoubleScale\Pro\Core\UserRoles\PermissionsCompat::has_project_access() ) {
			return false;
		}

		if ( ! class_exists( '\DoubleScale\Pro\Modules\Projects\Capabilities' ) ) {
			return false;
		}

		$project_id = $this->resolve_project_id_from_request( $request );
		if ( ! $project_id ) {
			return false;
		}

		return $require_manage
			? \DoubleScale\Pro\Modules\Projects\Capabilities::can_manage_project( $project_id )
			: \DoubleScale\Pro\Modules\Projects\Capabilities::can_read_project( $project_id );
	}

	/**
	 * Resolve a project ID from request params or an activity association.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return int|null
	 */
	private function resolve_project_id_from_request( WP_REST_Request $request ): ?int {
		$entity_type = $this->normalize_entity_type( $request->get_param( 'entity_type' ) );
		$entity_id   = (int) $request->get_param( 'entity_id' );

		if ( ActivityAssociationModel::ENTITY_TYPE_PROJECT === $entity_type && $entity_id > 0 ) {
			return $entity_id;
		}

		$activity_id = (int) $request->get_param( 'id' );
		if ( $activity_id <= 0 ) {
			return null;
		}

		$project_id = ActivityAssociationModel::query()
			->where( 'activity_id', $activity_id )
			->where( 'entity_type', ActivityAssociationModel::ENTITY_TYPE_PROJECT )
			->value( 'entity_id' );

		return null !== $project_id ? (int) $project_id : null;
	}

	/**
	 * Map a deal access error code to a WP_Error response.
	 *
	 * @since 1.0.0
	 *
	 * @param string $error_code Error code from check_deal_access().
	 *
	 * @return WP_Error
	 */
	private function map_deal_error( string $error_code ): WP_Error {
		switch ( $error_code ) {
			case 'not_found':
				return new WP_Error( 'deal_not_found', __( 'Deal not found', 'doublescale' ), array( 'status' => 404 ) );

			case 'no_pro':
				return new WP_Error( 'pro_required', __( 'Deal features require Plugin Pro', 'doublescale' ), array( 'status' => 400 ) );

			case 'forbidden':
				return new WP_Error( 'access_denied', __( 'You do not have permission to access this deal', 'doublescale' ), array( 'status' => 403 ) );

			default:
				return new WP_Error( 'access_denied', __( 'Access denied', 'doublescale' ), array( 'status' => 403 ) );
		}
	}
}
