<?php
/**
 * REST controller for ticket conversations — replies, internal notes, and
 * the chronological thread view.
 *
 * Routes registered (namespace `doublescale/v1`):
 *
 *   GET  /support/tickets/{ticket_id}/conversation   The full thread: replies + notes + system events.
 *   POST /support/tickets/{ticket_id}/replies        Customer-visible reply (delegates to TicketService::add_reply).
 *   POST /support/tickets/{ticket_id}/notes          Internal agent note (delegates to TicketService::add_note).
 *
 * Why the conversation thread isn't a sub-resource of /activities: the support
 * timeline filters by ticket via `activity_associations(entity_type=3, entity_id=X)`,
 * which is encapsulated by {@see \DoubleScale\Modules\Activities\Models\ActivityModel::scopeForTicket}.
 * Surfacing it under `/support/tickets/{id}/conversation` keeps the support
 * domain self-contained for the portal and admin SPA — neither needs to know
 * about the underlying activities table.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Support\Models\TicketModel;
use DoubleScale\Modules\Support\Services\AttachmentService;
use DoubleScale\Modules\Support\Services\ContactResolver;
use DoubleScale\Modules\Support\Services\TicketService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestReplyController class.
 */
class RestReplyController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'support/tickets';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<ticket_id>[\d]+)/conversation',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_conversation' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'per_page' => array(
							'description' => __( 'Items per page (1-200).', 'doublescale' ),
							'type'        => 'integer',
							'default'     => 100,
							'minimum'     => 1,
							'maximum'     => 200,
						),
						'page'     => array(
							'description' => __( 'Page number.', 'doublescale' ),
							'type'        => 'integer',
							'default'     => 1,
							'minimum'     => 1,
						),
						'kinds'    => array(
							'description' => __( 'Comma-separated activity kinds to include: reply,note,event. Defaults to all three.', 'doublescale' ),
							'type'        => 'string',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<ticket_id>[\d]+)/replies',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_reply' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<ticket_id>[\d]+)/notes',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_note' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Endpoints
	// ---------------------------------------------------------------------

	/**
	 * Return the chronological conversation log for a ticket.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_conversation( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$ticket = $this->resolve_ticket( $request );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		$allowed_types = $this->resolve_kinds( $request->get_param( 'kinds' ) );

		$query = ActivityModel::forTicket( $ticket->id )
			->whereIn( 'activity_type', $allowed_types )
			->withMorphAppends()
			->with( 'user' );

		$per_page_raw = (int) $request->get_param( 'per_page' );
		$per_page     = max( 1, min( 200, $per_page_raw > 0 ? $per_page_raw : 100 ) );
		$page_raw     = (int) $request->get_param( 'page' );
		$page         = max( 1, $page_raw > 0 ? $page_raw : 1 );

		$paginator = $query->paginate( $per_page, array( '*' ), 'page', $page );

		$items           = $paginator->items();
		$activity_ids    = array_map(
			static function ( $activity ) {
				return (int) $activity->id;
			},
			$items
		);
		$attachments_map = ( new AttachmentService() )->map_for_activities( $activity_ids );

		$data = array();
		foreach ( $items as $activity ) {
			$data[] = $this->shape_activity( $activity, $attachments_map );
		}

		return new WP_REST_Response(
			array(
				'data' => $data,
				'meta' => array(
					'total'        => $paginator->total(),
					'per_page'     => $per_page,
					'current_page' => $page,
					'last_page'    => max( 1, (int) ceil( $paginator->total() / $per_page ) ),
					'ticket_id'    => (int) $ticket->id,
				),
			),
			200
		);
	}

	/**
	 * Add a customer-visible reply to a ticket.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_reply( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$ticket = $this->resolve_ticket( $request );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		unset( $params['ticket_id'] );

		// Content + `cc` are sanitized/validated inside the service
		// ({@see TicketService::sanitize_content()} / `sanitize_cc_list()`), which is
		// the single choke point shared with the portal and inbound paths — do not
		// re-sanitize here. The full param bag (including `cc`) is forwarded as-is.
		$activity = $this->service()->add_reply( $ticket, (array) $params );
		if ( is_wp_error( $activity ) ) {
			return $activity;
		}

		$attachments_map = ( new AttachmentService() )->map_for_activities( array( (int) $activity->id ) );
		$activity->load( ActivityModel::morph_append_relations() );
		return new WP_REST_Response( $this->shape_activity( $activity, $attachments_map ), 201 );
	}

	/**
	 * Add an internal note to a ticket.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_note( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$ticket = $this->resolve_ticket( $request );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		unset( $params['ticket_id'] );

		$activity = $this->service()->add_note( $ticket, (array) $params );
		if ( is_wp_error( $activity ) ) {
			return $activity;
		}

		$attachments_map = ( new AttachmentService() )->map_for_activities( array( (int) $activity->id ) );
		$activity->load( ActivityModel::morph_append_relations() );
		return new WP_REST_Response( $this->shape_activity( $activity, $attachments_map ), 201 );
	}

	// ---------------------------------------------------------------------
	// Permissions
	// ---------------------------------------------------------------------

	/**
	 * Shared guard for every endpoint on this controller. `$request` is unused
	 * but required by WP REST's permission_callback contract.
	 *
	 * @param WP_REST_Request $request Unused — present for the framework contract.
	 * @return bool|WP_Error
	 */
	public function permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( Permissions::has_support_access() ) {
			return true;
		}
		return new WP_Error( 'not_allowed', __( 'You do not have permission to access support tickets.', 'doublescale' ), array( 'status' => 403 ) );
	}

	// ---------------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------------

	/**
	 * @return TicketModel|WP_Error
	 */
	private function resolve_ticket( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'ticket_id' );
		$ticket = TicketModel::find( $id );
		if ( ! $ticket ) {
			return new WP_Error( 'not_found', __( 'Ticket not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		// Ownership: users without `doublescale_manage_all_tickets` can only
		// read/reply on tickets where they are the assigned agent.
		if ( ! Permissions::can_manage_all_tickets()
			&& (int) $ticket->agent_user_id !== (int) get_current_user_id()
		) {
			return new WP_Error(
				'not_allowed',
				__( 'You can only access tickets assigned to you.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		return $ticket;
	}

	/**
	 * Map the `kinds` query param ('reply,note,event') to the matching set of
	 * ActivityType constants. Anything unrecognised is dropped. Default is the
	 * full conversation: reply + note + event.
	 *
	 * @param mixed $kinds Raw param value.
	 * @return string[] Activity type constants.
	 */
	private function resolve_kinds( $kinds ): array {
		$map = array(
			'reply' => ActivityTypes::SUPPORT_REPLY,
			'note'  => ActivityTypes::SUPPORT_NOTE,
			'event' => ActivityTypes::SUPPORT_EVENT,
		);
		if ( ! is_string( $kinds ) || '' === trim( $kinds ) ) {
			return array_values( $map );
		}
		$requested = array_filter( array_map( 'trim', explode( ',', strtolower( $kinds ) ) ) );
		$allowed   = array();
		foreach ( $requested as $kind ) {
			if ( isset( $map[ $kind ] ) ) {
				$allowed[] = $map[ $kind ];
			}
		}
		return empty( $allowed ) ? array_values( $map ) : $allowed;
	}

	/**
	 * Shape an activity row for the conversation response. The `kind` key flips
	 * `support_reply` → `'reply'`, etc., so frontend renderers can switch on a
	 * short stable token without knowing the underlying activity type names.
	 *
	 * @param ActivityModel                                $activity Activity row.
	 * @param array<int, array<int, array<string, mixed>>> $attachments_map Attachments keyed by activity id.
	 * @return array
	 */
	private function shape_activity( ActivityModel $activity, array $attachments_map = array() ): array {
		$data = is_array( $activity->data ) ? $activity->data : array();

		$kind_map = array(
			ActivityTypes::SUPPORT_REPLY => 'reply',
			ActivityTypes::SUPPORT_NOTE  => 'note',
			ActivityTypes::SUPPORT_EVENT => 'event',
		);
		$kind     = $kind_map[ $activity->activity_type ] ?? 'event';

		$payload = array(
			'id'         => (int) $activity->id,
			'kind'       => $kind,
			'type'       => $activity->activity_type,
			'contact_id' => $activity->contact_id ? (int) $activity->contact_id : null,
			'user_id'    => $activity->user_id ? (int) $activity->user_id : null,
			'data'       => $data,
			// CC recipients on this reply (also present in `data.cc`); surfaced at
			// the top level so the client renders the per-message Cc line without
			// reaching into `data`.
			'cc'         => isset( $data['cc'] ) && is_array( $data['cc'] ) ? array_values( $data['cc'] ) : array(),
			'created_at' => $activity->created_at ? (string) $activity->created_at : null,
			'updated_at' => $activity->updated_at ? (string) $activity->updated_at : null,
		);

		if ( $activity->relationLoaded( 'user' ) && $activity->user ) {
			$payload['user'] = array(
				'id'           => (int) $activity->user->ID,
				'display_name' => $activity->user->display_name,
				'email'        => $activity->user->user_email,
			);
		} else {
			$payload['user'] = null;
		}

		$aid                    = (int) $activity->id;
		$payload['attachments'] = isset( $attachments_map[ $aid ] ) ? $attachments_map[ $aid ] : array();

		return $payload;
	}

	/**
	 * Lazy TicketService accessor — mirrors {@see RestTicketController::service()}.
	 *
	 * @return TicketService
	 */
	private function service(): TicketService {
		/** This filter is documented in RestTicketController::service(). */
		$override = apply_filters( 'doublescale_support_ticket_service_instance', null );
		if ( $override instanceof TicketService ) {
			return $override;
		}
		return new TicketService( new ContactResolver() );
	}
}
