<?php
/**
 * REST API: Project controller.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\Rest\Controllers;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Core\Models\AttachmentModel;
use DoubleScale\Core\Services\AttachmentService;
use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Pro\Modules\Deals\Models\DealModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;
use DoubleScale\Pro\Modules\Projects\Rest\ProjectShaper;
use DoubleScale\Pro\Modules\Projects\Models\ProjectDiscussionModel;
use DoubleScale\Pro\Modules\Projects\Services\ProjectActivityLogger;
use DoubleScale\Pro\Modules\Projects\Services\ProjectManager;
use DoubleScale\Pro\Core\UserRoles\PermissionsCompat;
use DoubleScale\Pro\Modules\Projects\Capabilities;
use Exception;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class RestProjectController extends RestController {

	/**
	 * Activity feed type filters accepted by the project activity endpoint.
	 *
	 * Mirrored by the Type dropdown in the project Activity tab; keep the two in
	 * sync or the UI will request a value the route rejects with a 400.
	 */
	private const PROJECT_FEED_TYPES = array(
		'all',
		'project',
		'note',
		'files',
		'email_sent',
		'comments',
		'documents',
	);

	/**
	 * Event keys produced when a project comment or reply is posted.
	 */
	private const COMMENT_EVENT_KEYS = array( 'comment_posted', 'comment_replied' );

	/**
	 * Event keys produced when an invoice or proposal is linked to a project.
	 */
	private const DOCUMENT_EVENT_KEYS = array( 'invoice_linked', 'proposal_linked' );

	/**
	 * @var string
	 */
	protected $rest_base = 'projects';

	/**
	 * Polymorphic attachable_type for project file attachments.
	 */
	private const PROJECT_ATTACHABLE_TYPE = 'project';

	/**
	 * Maximum upload size per project attachment (10 MB).
	 */
	private const PROJECT_ATTACHMENT_MAX_BYTES = 10485760;

	/**
	 * Maximum active attachments per project.
	 */
	private const PROJECT_ATTACHMENT_MAX_COUNT = 20;

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
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/reorder',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'reorder_items' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/convert-from-deal',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'convert_from_deal' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
			)
		);

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

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/financials',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_financials' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/attach-invoice',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'attach_invoice' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/attach-proposal',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'attach_proposal' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
			)
		);

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
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_attachment' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/activity',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_activity' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => $this->get_project_feed_collection_params(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/discussions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_discussions' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_discussion' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/discussions/(?P<discussion_id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_discussion' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$view = $request->get_param( 'view' ) ?: 'table';
		$filters = $this->build_filters( $request );

		if ( 'board' === $view ) {
			$board = ProjectManager::instance()->get_board_data( $filters );
			return new WP_REST_Response( $board, 200 );
		}

		$projects = ProjectManager::instance()->get_projects_with_filters( $filters );
		$total    = ProjectManager::instance()->count_projects_with_filters( $filters );
		$data     = array();
		foreach ( $projects as $project ) {
			$data[] = $this->prepare_item_for_response( $project, $request );
		}

		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'X-Total-Count', $total );
		return $response;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$project_id = (int) $request->get_param( 'id' );
		$with       = array( 'status', 'contact', 'deal', 'owner', 'custom_fields' );

		$project = ProjectModel::with( $with )->find( $project_id );
		if ( ! $project ) {
			return new WP_Error( 'project_not_found', __( 'Project not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		if ( ! $this->can_access_project( $project ) ) {
			return new WP_Error( 'project_not_found', __( 'Project not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $this->prepare_item_for_response( $project, $request ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
		if ( '' === $title ) {
			return new WP_Error( 'missing_title', __( 'Project title is required.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$status_id = (int) $request->get_param( 'status_id' );
		if ( $status_id <= 0 ) {
			return new WP_Error( 'missing_status', __( 'Project status is required.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$owner_id = $request->get_param( 'owner_id' );
		if ( ! PermissionsCompat::can_assign_project_owner() ) {
			$owner_id = get_current_user_id();
		}

		$data = array(
			'title'       => $title,
			'description' => $request->get_param( 'description' ) ? wp_kses_post( (string) $request->get_param( 'description' ) ) : null,
			'status_id'   => $status_id,
			'contact_id'  => $request->get_param( 'contact_id' ) ? (int) $request->get_param( 'contact_id' ) : null,
			'deal_id'     => $request->get_param( 'deal_id' ) ? (int) $request->get_param( 'deal_id' ) : null,
			'budget'      => null !== $request->get_param( 'budget' ) ? (float) $request->get_param( 'budget' ) : null,
			'start_date'  => $this->sanitize_date( $request->get_param( 'start_date' ) ),
			'due_date'    => $this->sanitize_date( $request->get_param( 'due_date' ) ),
			'owner_id'    => $owner_id ? (int) $owner_id : null,
			'progress'    => null !== $request->get_param( 'progress' ) ? max( 0, min( 100, (int) $request->get_param( 'progress' ) ) ) : 0,
			'calculate_progress' => (bool) $request->get_param( 'calculate_progress' ),
		);

		$project = ProjectManager::instance()->create_project( $data );
		if ( ! $project ) {
			return new WP_Error( 'creation_failed', __( 'Failed to create project.', 'doublescale' ), array( 'status' => 500 ) );
		}

		$custom_fields = $request->get_param( 'custom_fields' );
		if ( is_array( $custom_fields ) ) {
			$result = $project->sync_custom_fields( $custom_fields );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return new WP_REST_Response( $this->prepare_item_for_response( $project->fresh( array( 'status', 'contact', 'owner' ) ), $request ), 201 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$project_id = (int) $request->get_param( 'id' );
		$project    = ProjectModel::find( $project_id );
		if ( ! $project ) {
			return new WP_Error( 'project_not_found', __( 'Project not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		if ( ! $this->can_modify_project( $project, $request ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot update this project.', 'doublescale' ), array( 'status' => 403 ) );
		}

		$data = array();
		$fields = array( 'title', 'description', 'status_id', 'contact_id', 'deal_id', 'budget', 'start_date', 'due_date', 'owner_id', 'position', 'progress', 'calculate_progress' );
		foreach ( $fields as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				$data[ $field ] = $request->get_param( $field );
			}
		}

		if ( isset( $data['progress'] ) ) {
			$data['progress'] = max( 0, min( 100, (int) $data['progress'] ) );
		}
		if ( isset( $data['calculate_progress'] ) ) {
			$data['calculate_progress'] = (bool) $data['calculate_progress'];
		}

		if ( isset( $data['title'] ) ) {
			$data['title'] = sanitize_text_field( (string) $data['title'] );
		}
		if ( isset( $data['description'] ) ) {
			$data['description'] = wp_kses_post( (string) $data['description'] );
		}
		if ( isset( $data['start_date'] ) ) {
			$data['start_date'] = $this->sanitize_date( $data['start_date'] );
		}
		if ( isset( $data['due_date'] ) ) {
			$data['due_date'] = $this->sanitize_date( $data['due_date'] );
		}

		$old_status_id = (int) $project->status_id;
		$new_status_id = $request->get_param( 'status_id' );
		unset( $data['status_id'] );
		$project       = ProjectManager::instance()->update_project( $project_id, $data );
		if ( ! $project ) {
			return new WP_Error( 'update_failed', __( 'Failed to update project.', 'doublescale' ), array( 'status' => 500 ) );
		}

		if ( null !== $new_status_id && (int) $new_status_id !== $old_status_id ) {
			$project->moveToStatus( (int) $new_status_id, get_current_user_id() );
			$project->position = ProjectManager::instance()->next_position_for_status( (int) $new_status_id );
			$project->save();
		}

		$custom_fields = $request->get_param( 'custom_fields' );
		if ( is_array( $custom_fields ) ) {
			$result = $project->sync_custom_fields( $custom_fields );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return new WP_REST_Response( $this->prepare_item_for_response( $project->fresh( array( 'status', 'contact', 'owner', 'custom_fields' ) ), $request ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$ids = array( (int) $request->get_param( 'id' ) );
		$bulk_ids = $request->get_param( 'ids' );
		if ( is_array( $bulk_ids ) && ! empty( $bulk_ids ) ) {
			$ids = array_map( 'intval', $bulk_ids );
		}

		$deleted = 0;
		foreach ( $ids as $project_id ) {
			$project = ProjectModel::find( $project_id );
			if ( ! $project ) {
				continue;
			}
			if ( ! PermissionsCompat::can_manage_all_projects() ) {
				continue;
			}
			if ( ! Capabilities::can_manage_project( $project_id ) ) {
				continue;
			}
			if ( $project->delete() ) {
				++$deleted;
			}
		}

		return new WP_REST_Response( array( 'deleted' => $deleted ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reorder_items( $request ) {
		$project_ids = $request->get_param( 'project_ids' );
		$status_id   = (int) $request->get_param( 'status_id' );

		if ( ! is_array( $project_ids ) || empty( $project_ids ) || $status_id <= 0 ) {
			return new WP_Error( 'invalid_reorder', __( 'project_ids and status_id are required.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$ok = ProjectManager::instance()->reorder_projects( array_map( 'intval', $project_ids ), $status_id, get_current_user_id() );
		if ( ! $ok ) {
			return new WP_Error( 'reorder_failed', __( 'Failed to reorder projects.', 'doublescale' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function convert_from_deal( $request ) {
		$deal_id = (int) $request->get_param( 'deal_id' );
		if ( $deal_id <= 0 ) {
			return new WP_Error( 'missing_deal', __( 'deal_id is required.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$deal = DealModel::find( $deal_id );
		if ( ! $deal ) {
			return new WP_Error( 'deal_not_found', __( 'Deal not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		if ( 'lost' === (string) $deal->status ) {
			return new WP_Error(
				'deal_lost',
				__( 'Lost deals cannot be converted to projects.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$existing = ProjectModel::where( 'deal_id', $deal_id )->first();
		if ( $existing ) {
			if ( ! $this->can_access_project( $existing ) ) {
				return new WP_Error( 'project_not_found', __( 'Project not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			return new WP_Error(
				'deal_already_converted',
				__( 'This deal already has a linked project.', 'doublescale' ),
				array(
					'status'     => 409,
					'project_id' => (int) $existing->id,
				)
			);
		}

		$project = ProjectManager::instance()->convert_from_deal( $deal_id );
		if ( ! $project ) {
			return new WP_Error( 'conversion_failed', __( 'Failed to convert deal to project.', 'doublescale' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( $this->prepare_item_for_response( $project->fresh( array( 'status', 'contact', 'deal', 'owner' ) ), $request ), 201 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_financials( $request ) {
		$project_id = (int) $request->get_param( 'id' );
		$project    = ProjectModel::find( $project_id );
		if ( ! $project || ! $this->can_access_project( $project ) ) {
			return new WP_Error( 'project_not_found', __( 'Project not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( ProjectManager::instance()->get_financials( $project_id ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function attach_invoice( $request ) {
		$project_id = (int) $request->get_param( 'id' );
		$invoice_id = (int) $request->get_param( 'invoice_id' );
		$project    = ProjectModel::find( $project_id );

		if ( ! $project || ! $this->can_modify_project( $project, $request ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot modify this project.', 'doublescale' ), array( 'status' => 403 ) );
		}
		if ( $invoice_id <= 0 ) {
			return new WP_Error( 'missing_invoice', __( 'invoice_id is required.', 'doublescale' ), array( 'status' => 400 ) );
		}

		if ( ! ProjectManager::instance()->attach_invoice( $project_id, $invoice_id ) ) {
			return new WP_Error( 'attach_failed', __( 'Failed to attach invoice.', 'doublescale' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( ProjectManager::instance()->get_financials( $project_id ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function attach_proposal( $request ) {
		$project_id  = (int) $request->get_param( 'id' );
		$proposal_id = (int) $request->get_param( 'proposal_id' );
		$project     = ProjectModel::find( $project_id );

		if ( ! $project || ! $this->can_modify_project( $project, $request ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot modify this project.', 'doublescale' ), array( 'status' => 403 ) );
		}
		if ( $proposal_id <= 0 ) {
			return new WP_Error( 'missing_proposal', __( 'proposal_id is required.', 'doublescale' ), array( 'status' => 400 ) );
		}

		if ( ! ProjectManager::instance()->attach_proposal( $project_id, $proposal_id ) ) {
			return new WP_Error( 'attach_failed', __( 'Failed to attach proposal.', 'doublescale' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( ProjectManager::instance()->get_financials( $project_id ), 200 );
	}

	/**
	 * List project discussion comments (top-level with replies).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_discussions( $request ) {
		$project_id = (int) $request->get_param( 'id' );
		$project    = ProjectModel::find( $project_id );
		if ( ! $project || ! $this->can_access_project( $project ) ) {
			return new WP_Error( 'project_not_found', __( 'Project not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$items = ProjectDiscussionModel::with( array( 'user', 'replies.user' ) )
			->where( 'project_id', $project_id )
			->whereNull( 'parent_id' )
			->orderBy( 'created_at', 'desc' )
			->get();

		$shaped = array();
		foreach ( $items as $item ) {
			$shaped[] = $this->shape_discussion( $item );
		}

		return new WP_REST_Response( array( 'items' => $shaped ), 200 );
	}

	/**
	 * Create a project discussion comment or reply.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_discussion( $request ) {
		$project_id = (int) $request->get_param( 'id' );
		$project    = ProjectModel::find( $project_id );
		if ( ! $project || ! $this->can_modify_project( $project, $request ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot modify this project.', 'doublescale' ), array( 'status' => 403 ) );
		}

		$body = trim( wp_kses_post( (string) $request->get_param( 'body' ) ) );
		if ( '' === $body ) {
			return new WP_Error( 'missing_body', __( 'Comment body is required.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$parent    = null;
		$parent_id = $request->get_param( 'parent_id' ) ? (int) $request->get_param( 'parent_id' ) : null;
		if ( $parent_id ) {
			$parent = ProjectDiscussionModel::where( 'id', $parent_id )
				->where( 'project_id', $project_id )
				->whereNull( 'parent_id' )
				->first();
			if ( ! $parent ) {
				return new WP_Error( 'invalid_parent', __( 'Parent comment not found.', 'doublescale' ), array( 'status' => 400 ) );
			}
		}

		$discussion = ProjectDiscussionModel::create(
			array(
				'project_id' => $project_id,
				'parent_id'  => $parent_id,
				'user_id'    => get_current_user_id(),
				'body'       => $body,
			)
		);

		if ( ! $discussion ) {
			return new WP_Error( 'create_failed', __( 'Failed to post comment.', 'doublescale' ), array( 'status' => 500 ) );
		}

		$discussion->load( array( 'user', 'replies.user' ) );

		/**
		 * Fires after a project comment (or reply) is posted.
		 *
		 * @param ProjectModel                $project    Parent project.
		 * @param ProjectDiscussionModel      $discussion Posted comment or reply.
		 * @param ProjectDiscussionModel|null $parent     Parent comment when replying.
		 */
		do_action( 'doublescale_project_comment_posted', $project, $discussion, $parent );

		return new WP_REST_Response( $this->shape_discussion( $discussion ), 201 );
	}

	/**
	 * Delete a project discussion comment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_discussion( $request ) {
		$project_id    = (int) $request->get_param( 'id' );
		$discussion_id = (int) $request->get_param( 'discussion_id' );
		$project       = ProjectModel::find( $project_id );

		if ( ! $project || ! $this->can_access_project( $project ) ) {
			return new WP_Error( 'project_not_found', __( 'Project not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$discussion = ProjectDiscussionModel::where( 'id', $discussion_id )
			->where( 'project_id', $project_id )
			->first();

		if ( ! $discussion ) {
			return new WP_Error( 'not_found', __( 'Comment not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$is_author  = (int) $discussion->user_id === (int) get_current_user_id();
		$can_manage = $this->can_modify_project( $project, $request );
		if ( ! $is_author && ! $can_manage ) {
			return new WP_Error( 'forbidden', __( 'You cannot delete this comment.', 'doublescale' ), array( 'status' => 403 ) );
		}

		$discussion->delete();

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * @param ProjectDiscussionModel $discussion Discussion.
	 * @return array<string, mixed>
	 */
	private function shape_discussion( ProjectDiscussionModel $discussion ) {
		$author = null;
		if ( $discussion->relationLoaded( 'user' ) && $discussion->user ) {
			$user_id = (int) $discussion->user->ID;
			$author  = array(
				'id'         => $user_id,
				'name'       => (string) $discussion->user->display_name,
				'avatar_url' => get_avatar_url( $user_id, array( 'size' => 48 ) ),
			);
		}

		$replies = array();
		if ( $discussion->relationLoaded( 'replies' ) ) {
			foreach ( $discussion->replies as $reply ) {
				$replies[] = $this->shape_discussion( $reply );
			}
		}

		return array(
			'id'         => (int) $discussion->id,
			'project_id' => (int) $discussion->project_id,
			'parent_id'  => $discussion->parent_id ? (int) $discussion->parent_id : null,
			'user_id'    => (int) $discussion->user_id,
			'body'       => (string) $discussion->body,
			'created_at' => (string) $discussion->created_at,
			'updated_at' => (string) $discussion->updated_at,
			'author'     => $author,
			'replies'    => $replies,
		);
	}

	/**
	 * @param ProjectModel    $project Project.
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function prepare_item_for_response( $project, $request ) {
		$data = $project->toArray();
		if ( $project->relationLoaded( 'status' ) && $project->status ) {
			$data['status'] = $project->status->toArray();
		}
		if ( $project->relationLoaded( 'contact' ) && $project->contact ) {
			$data['contact'] = array(
				'id'   => (int) $project->contact->id,
				'name' => (string) ( $project->contact->full_name ?? $project->contact->email ?? '' ),
			);
		}
		if ( $project->relationLoaded( 'deal' ) && $project->deal ) {
			$data['deal'] = array(
				'id'    => (int) $project->deal->id,
				'title' => (string) $project->deal->title,
			);
		}
		if ( $project->relationLoaded( 'owner' ) && $project->owner ) {
			$data['owner'] = array(
				'id'   => (int) $project->owner->ID,
				'name' => (string) $project->owner->display_name,
			);
		}
		if ( $project->relationLoaded( 'custom_fields' ) ) {
			$data['custom_fields'] = $project->custom_fields->map(
				function ( $field ) {
					return array(
						'id'    => (int) $field->id,
						'name'  => (string) $field->name,
						'value' => (string) ( $field->pivot->value ?? '' ),
					);
				}
			)->values()->all();
		}

		$data['task_progress']     = $project->task_progress;
		$data['resolved_progress'] = $project->resolveProgress();

		return array_merge( $data, ProjectShaper::public_url_fields( $project ) );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function get_collection_params() {
		return array(
			'view'       => array( 'type' => 'string', 'enum' => array( 'board', 'table' ), 'default' => 'table' ),
			'search'     => array( 'type' => 'string' ),
			'status_id'  => array( 'type' => 'integer' ),
			'contact_id' => array( 'type' => 'integer' ),
			'deal_id'    => array( 'type' => 'integer' ),
			'owner_id'   => array( 'type' => 'integer' ),
			'page'       => array( 'type' => 'integer', 'default' => 1 ),
			'per_page'   => array( 'type' => 'integer', 'default' => 50 ),
			'sort_by'    => array(
				'type'    => 'string',
				'default' => 'position',
				'enum'    => array( 'position', 'title', 'budget', 'start_date', 'due_date', 'created_at', 'updated_at', 'status_id' ),
			),
			'sort_order' => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'asc' ),
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function build_filters( $request ) {
		$filters = array(
			'search'     => $request->get_param( 'search' ),
			'status_id'  => $request->get_param( 'status_id' ),
			'contact_id' => $request->get_param( 'contact_id' ),
			'deal_id'    => $request->get_param( 'deal_id' ),
			'owner_id'   => $request->get_param( 'owner_id' ),
			'page'       => $request->get_param( 'page' ),
			'per_page'   => $request->get_param( 'per_page' ),
			'sort_by'    => $request->get_param( 'sort_by' ),
			'sort_order' => $request->get_param( 'sort_order' ),
		);
		return array_filter(
			$filters,
			static function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);
	}

	/**
	 * @param mixed $value Date value.
	 * @return string|null
	 */
	private function sanitize_date( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$clean = sanitize_text_field( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $clean ) ? $clean : null;
	}

	/**
	 * @param ProjectModel $project Project.
	 * @return bool
	 */
	private function can_access_project( $project ) {
		return Capabilities::can_read_project( (int) $project->id );
	}

	/**
	 * @param ProjectModel    $project Project.
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	private function can_modify_project( $project, $request ) {
		return Capabilities::can_manage_project( (int) $project->id );
	}

	public function get_items_permissions_check( $request ) {
		return PermissionsCompat::has_project_access();
	}

	public function get_item_permissions_check( $request ) {
		return PermissionsCompat::has_project_access();
	}

	public function create_item_permissions_check( $request ) {
		return user_can( get_current_user_id(), 'doublescale_project_manage_own_projects' )
			|| user_can( get_current_user_id(), 'doublescale_project_manage_all_projects' );
	}

	public function update_item_permissions_check( $request ) {
		$project_id = (int) $request->get_param( 'id' );
		if ( $project_id <= 0 ) {
			return user_can( get_current_user_id(), 'doublescale_project_manage_own_projects' )
				|| user_can( get_current_user_id(), 'doublescale_project_manage_all_projects' );
		}

		return Capabilities::can_manage_project( $project_id );
	}

	public function delete_item_permissions_check( $request ) {
		return PermissionsCompat::can_manage_all_projects();
	}

	/**
	 * List active file attachments for a project.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_attachments( $request ) {
		try {
			$project = $this->get_project_for_attachment( $request );
			if ( is_wp_error( $project ) ) {
				return $project;
			}

			return new WP_REST_Response(
				array(
					'items' => $this->get_project_attachments_shaped( (int) $project->id ),
				),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Upload a file attachment to a project.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_attachment( $request ) {
		try {
			$project = $this->get_project_for_attachment( $request, true );
			if ( is_wp_error( $project ) ) {
				return $project;
			}

			$files = $request->get_file_params();
			$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;
			if ( ! $file ) {
				return new WP_Error( 'no_file', __( 'No file was uploaded.', 'doublescale' ), array( 'status' => 400 ) );
			}

			$existing_count = AttachmentModel::forType( self::PROJECT_ATTACHABLE_TYPE )
				->where( 'attachable_id', (int) $project->id )
				->active()
				->count();

			$too_many = $this->guard_project_attachment_count( (int) $existing_count );
			if ( $too_many ) {
				return $too_many;
			}

			$service    = $this->attachment_service();
			$attachment = $service->store_upload(
				$file,
				self::PROJECT_ATTACHABLE_TYPE,
				(int) $project->id,
				array( 'user_id' => get_current_user_id() ),
				array(
					'status'         => 'active',
					'max_size_bytes' => self::PROJECT_ATTACHMENT_MAX_BYTES,
					'meta'           => array( 'project_id' => (int) $project->id ),
				)
			);

			if ( is_wp_error( $attachment ) ) {
				return $attachment;
			}

			do_action( 'doublescale_project_file_attached', $project, $attachment );

			return new WP_REST_Response(
				$service->shape_for_api( $attachment ),
				201
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Delete a project file attachment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_attachment( $request ) {
		try {
			$project = $this->get_project_for_attachment( $request, true );
			if ( is_wp_error( $project ) ) {
				return $project;
			}

			$attachment = $this->find_project_attachment( $project, (int) $request->get_param( 'attachment_id' ) );
			if ( is_wp_error( $attachment ) ) {
				return $attachment;
			}

			do_action( 'doublescale_project_file_removed', $project, $attachment );

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
	 * Resolve a project for attachment endpoints with ownership scoping.
	 *
	 * @param WP_REST_Request $request        Request.
	 * @param bool            $require_update Whether update access is required.
	 * @return ProjectModel|WP_Error
	 */
	private function get_project_for_attachment( $request, bool $require_update = false ) {
		$project_id = (int) $request->get_param( 'id' );
		$project    = ProjectModel::find( $project_id );

		if ( ! $project ) {
			return new WP_Error( 'project_not_found', __( 'Project not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		if ( ! $this->can_access_project( $project ) ) {
			return new WP_Error( 'project_not_found', __( 'Project not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		if ( $require_update && ! $this->can_modify_project( $project, $request ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to edit this project.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		return $project;
	}

	/**
	 * Fetch active project attachments shaped for API responses.
	 *
	 * @param int $project_id Project ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_project_attachments_shaped( int $project_id ): array {
		$service = $this->attachment_service();
		$rows    = AttachmentModel::forType( self::PROJECT_ATTACHABLE_TYPE )
			->where( 'attachable_id', $project_id )
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
	 * Resolve a project-scoped attachment row.
	 *
	 * @param ProjectModel $project       Parent project.
	 * @param int          $attachment_id Attachment row id.
	 * @return AttachmentModel|WP_Error
	 */
	private function find_project_attachment( $project, int $attachment_id ) {
		$attachment = AttachmentModel::forType( self::PROJECT_ATTACHABLE_TYPE )
			->where( 'attachable_id', (int) $project->id )
			->where( 'id', $attachment_id )
			->first();

		if ( ! $attachment ) {
			return new WP_Error( 'not_found', __( 'Attachment not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		return $attachment;
	}

	/**
	 * Enforce the per-project attachment count cap.
	 *
	 * @param int $existing_count Active attachments already on the project.
	 * @return WP_Error|null
	 */
	private function guard_project_attachment_count( int $existing_count ): ?WP_Error {
		if ( $existing_count >= self::PROJECT_ATTACHMENT_MAX_COUNT ) {
			return new WP_Error(
				'too_many_files',
				sprintf(
					/* translators: %d: maximum number of files allowed per project */
					_n(
						'You can attach at most %d file to this project.',
						'You can attach at most %d files to this project.',
						self::PROJECT_ATTACHMENT_MAX_COUNT,
						'doublescale'
					),
					self::PROJECT_ATTACHMENT_MAX_COUNT
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
	 * Shared query params for paginated project activity feeds.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_project_feed_collection_params() {
		return array(
			'order'    => array(
				'description' => __( 'Sort order: newest (default) or oldest.', 'doublescale' ),
				'type'        => 'string',
				'enum'        => array( 'newest', 'oldest' ),
				'default'     => 'newest',
			),
			'page'     => array(
				'description' => __( 'Page number.', 'doublescale' ),
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			),
			'per_page' => array(
				'description' => __( 'Items per page.', 'doublescale' ),
				'type'        => 'integer',
				'default'     => 10,
				'minimum'     => 1,
				'maximum'     => 50,
			),
			'type'     => array(
				'description' => __( 'Activity type filter.', 'doublescale' ),
				'type'        => 'string',
				'enum'        => self::PROJECT_FEED_TYPES,
				'default'     => 'all',
			),
		);
	}

	/**
	 * Parse sort/page params for project activity feeds.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{sort_order: string, page: int, per_page: int, type: string}
	 */
	private function parse_project_feed_request( $request ) {
		$order = strtolower( (string) ( $request->get_param( 'order' ) ?: 'newest' ) );
		$page  = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$size  = (int) ( $request->get_param( 'per_page' ) ?: 10 );
		$size  = max( 1, min( 50, $size ) );
		$type  = strtolower( (string) ( $request->get_param( 'type' ) ?: 'all' ) );
		if ( ! in_array( $type, self::PROJECT_FEED_TYPES, true ) ) {
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
	 * List all activities associated with a project.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_activity( $request ) {
		try {
			$project_id = (int) $request->get_param( 'id' );
			$project    = ProjectModel::find( $project_id );
			if ( ! $project ) {
				return new WP_Error( 'project_not_found', __( 'Project not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			if ( ! $this->can_access_project( $project ) ) {
				return new WP_Error( 'project_not_found', __( 'Project not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			$feed     = $this->parse_project_feed_request( $request );
			$timeline = $this->build_project_activity_timeline( $project, $feed );

			return new WP_REST_Response( $timeline, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Merge project activities into one chronological feed.
	 *
	 * @param ProjectModel $project Parent project.
	 * @param array        $feed    Parsed feed params.
	 * @return array{items: array<int, array<string, mixed>>, pagination: array<string, int|bool>}
	 */
	private function build_project_activity_timeline( $project, $feed ) {
		$activities = ActivityModel::with( array( 'user', 'associations' ) )
			->whereHas(
				'associations',
				function ( $query ) use ( $project ) {
					$query->where( 'entity_type', ActivityAssociationModel::ENTITY_TYPE_PROJECT )
						->where( 'entity_id', $project->id );
				}
			)
			->get();

		$rows = array();
		foreach ( $activities as $activity ) {
			$rows[] = array(
				'sort_at' => (string) $activity->created_at,
				'entry'   => $this->prepare_project_activity_for_response( $activity, (int) $project->id ),
			);
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
						return $this->matches_project_activity_type_filter( $entry, (string) $feed['type'] );
					}
				)
			);
		}

		$total    = count( $entries );
		$offset   = ( $feed['page'] - 1 ) * $feed['per_page'];
		$items    = array_slice( $entries, $offset, $feed['per_page'] );
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
	 * Whether a shaped project activity entry matches a timeline type filter.
	 *
	 * @param array<string, mixed> $entry  Shaped activity entry.
	 * @param string               $filter Filter slug from the UI.
	 * @return bool
	 */
	private function matches_project_activity_type_filter( array $entry, string $filter ): bool {
		$activity_type = isset( $entry['activity_type'] ) ? (string) $entry['activity_type'] : '';
		$data          = isset( $entry['data'] ) && is_array( $entry['data'] ) ? $entry['data'] : array();
		$event_key     = isset( $entry['event_key'] ) ? (string) $entry['event_key'] : '';
		if ( '' === $event_key && isset( $data['event_key'] ) ) {
			$event_key = (string) $data['event_key'];
		}

		if ( 'files' === $filter ) {
			return in_array( $event_key, array( 'file_attached', 'file_removed' ), true )
				|| in_array( $activity_type, array( ActivityTypes::FILE_ATTACHED, ActivityTypes::FILE_REMOVED ), true );
		}

		if ( 'note' === $filter ) {
			return ActivityTypes::NOTE === $activity_type;
		}

		if ( 'email_sent' === $filter ) {
			return ActivityTypes::EMAIL_SENT === $activity_type;
		}

		if ( 'comments' === $filter ) {
			return in_array( $event_key, self::COMMENT_EVENT_KEYS, true );
		}

		if ( 'documents' === $filter ) {
			return in_array( $event_key, self::DOCUMENT_EVENT_KEYS, true );
		}

		if ( 'project' === $filter ) {
			// Comments, documents and files each have their own filter; keep this
			// one to bare project lifecycle events.
			if ( in_array( $activity_type, array( ActivityTypes::FILE_ATTACHED, ActivityTypes::FILE_REMOVED, ActivityTypes::NOTE, ActivityTypes::EMAIL_SENT ), true ) ) {
				return false;
			}

			if ( in_array( $event_key, array_merge( self::COMMENT_EVENT_KEYS, self::DOCUMENT_EVENT_KEYS ), true ) ) {
				return false;
			}

			return in_array(
				$activity_type,
				array(
					ActivityTypes::PROJECT_EVENT,
					ActivityTypes::PROJECT_CREATED,
					ActivityTypes::PROJECT_STATUS_CHANGED,
					ActivityTypes::STATUS_CHANGED,
				),
				true
			);
		}

		return true;
	}

	/**
	 * Shape an activity entry for the project audit log.
	 *
	 * @param ActivityModel $activity  Activity model.
	 * @param int           $project_id Project ID.
	 * @return array<string, mixed>
	 */
	private function prepare_project_activity_for_response( $activity, $project_id ) {
		$data       = is_array( $activity->data ) ? $activity->data : array();
		$event_key  = isset( $data['event_key'] ) ? (string) $data['event_key'] : null;
		$type       = (string) $activity->activity_type;

		if ( ActivityTypes::PROJECT_CREATED === $type ) {
			$event_key = 'created';
		} elseif ( ActivityTypes::PROJECT_STATUS_CHANGED === $type ) {
			$event_key = 'stage_changed';
			if ( ! isset( $data['from_name'] ) && isset( $data['old_status_id'] ) ) {
				$data['from']      = (int) $data['old_status_id'];
				$data['from_name'] = ProjectActivityLogger::resolve_status_label( $data['old_status_id'] );
			}
			if ( ! isset( $data['to_name'] ) && isset( $data['new_status_id'] ) ) {
				$data['to']      = (int) $data['new_status_id'];
				$data['to_name'] = ProjectActivityLogger::resolve_status_label( $data['new_status_id'] );
			}
		} elseif ( ActivityTypes::FILE_ATTACHED === $type ) {
			$event_key = 'file_attached';
		} elseif ( ActivityTypes::FILE_REMOVED === $type ) {
			$event_key = 'file_removed';
		} elseif ( ActivityTypes::STATUS_CHANGED === $type && ( isset( $data['invoice_id'] ) || isset( $data['proposal_id'] ) ) ) {
			$event_key = isset( $data['invoice_id'] ) ? 'invoice_linked' : 'proposal_linked';
		}

		$response = array(
			'id'            => (int) $activity->id,
			'timeline_id'   => 'activity-' . (int) $activity->id,
			'project_id'    => (int) $project_id,
			'activity_type' => $type,
			'event_key'     => $event_key,
			'data'          => $data,
			'created_at'    => (string) $activity->created_at,
		);

		if ( $activity->relationLoaded( 'user' ) && $activity->user ) {
			$user_arr            = $activity->user->toArray();
			$response['user']    = array(
				'id'           => (int) ( $user_arr['ID'] ?? $user_arr['id'] ?? 0 ),
				'display_name' => (string) ( $user_arr['display_name'] ?? '' ),
				'email'        => (string) ( $user_arr['user_email'] ?? '' ),
			);
		}

		return $response;
	}
}
