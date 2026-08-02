<?php
/**
 * REST controller for support tickets.
 *
 * Routes registered (namespace `doublescale/v1`):
 *
 *   GET    /support/tickets                          List with filters + pagination.
 *   POST   /support/tickets                          Create a ticket + opening message.
 *   GET    /support/tickets/{id}                     Read one (with relations).
 *   PUT    /support/tickets/{id}                     Update mutable fields.
 *   DELETE /support/tickets/{id}                     Delete (cascade attachments + associations).
 *   GET    /support/tickets/by-hash/{hash}           Public hash lookup (portal-friendly).
 *
 * All routes go through {@see TicketService} so the canonical domain events
 * fire once per change, regardless of caller. The controller is intentionally
 * thin: it parses the request, calls the service, and shapes the response.
 *
 * Filtering rules for the list endpoint:
 *   - `status`, `priority`, `agent_user_id`, `contact_id`, `mailbox_id` — exact match.
 *   - `status` may also be a comma-separated list (`open,pending`) so the inbox
 *     "active" filter can map to ['open','pending','resolved'] in one call.
 *   - `tag_id` — single JSON_CONTAINS filter against `tag_ids`.
 *   - `search` — LIKE on `title` (case-insensitive via collation).
 *   - `sort_by` — `created_at`, `updated_at`, or `priority`; default `updated_at` so
 *     the inbox bubbles recently-active tickets to the top.
 *   - `sort_order` — `asc` | `desc`; default `desc`.
 *   - `per_page` — 1-100, default 20.
 *   - `page` — 1+, default 1.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Core\UserRoles\UserRoles;
use DoubleScale\Modules\Support\Constants\TicketPriority;
use DoubleScale\Modules\Support\Constants\TicketStatus;
use DoubleScale\Modules\Support\Models\TicketModel;
use DoubleScale\Modules\Support\Services\ContactResolver;
use DoubleScale\Modules\Support\Services\TicketService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestTicketController class.
 */
class RestTicketController extends RestController {

	/**
	 * Route base. Combined with parent's $namespace gives `doublescale/v1/support/tickets`.
	 *
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
			'/' . $this->rest_base . '/by-hash/(?P<hash>[a-f0-9]{32})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item_by_hash' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
			)
		);

		// Assignable agents for the "Assign to" picker. Managers receive every
		// support-capable user; agents/reps receive only themselves (they may
		// only ever assign tickets to themselves).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/agents',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_assignable_agents' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
			)
		);
	}

	/**
	 * Standard collection param schema; called by core REST validation.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'status'        => array(
				'description' => __( 'Filter by status. Comma-separated list permitted ("open,pending").', 'doublescale' ),
				'type'        => 'string',
			),
			'priority'      => array(
				'description' => __( 'Filter by priority.', 'doublescale' ),
				'type'        => 'string',
				'enum'        => TicketPriority::all(),
			),
			'agent_user_id' => array(
				'description' => __( 'Filter by assigned agent.', 'doublescale' ),
				'type'        => 'integer',
			),
			'contact_id'    => array(
				'description' => __( 'Filter by customer.', 'doublescale' ),
				'type'        => 'integer',
			),
			'mailbox_id'    => array(
				'description' => __( 'Filter by mailbox channel.', 'doublescale' ),
				'type'        => 'integer',
			),
			'tag_id'        => array(
				'description' => __( 'Filter to tickets tagged with this id.', 'doublescale' ),
				'type'        => 'integer',
			),
			'search'        => array(
				'description' => __( 'Match on ticket title.', 'doublescale' ),
				'type'        => 'string',
			),
			'sort_by'       => array(
				'description' => __( 'Column to sort by.', 'doublescale' ),
				'type'        => 'string',
				'enum'        => array( 'created_at', 'updated_at', 'priority' ),
				'default'     => 'updated_at',
			),
			'sort_order'    => array(
				'description' => __( 'Sort direction.', 'doublescale' ),
				'type'        => 'string',
				'enum'        => array( 'asc', 'desc' ),
				'default'     => 'desc',
			),
			'per_page'      => array(
				'description' => __( 'Items per page (1-100).', 'doublescale' ),
				'type'        => 'integer',
				'default'     => 20,
				'minimum'     => 1,
				'maximum'     => 100,
			),
			'page'          => array(
				'description' => __( 'Page number.', 'doublescale' ),
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			),
		);
	}

	// ---------------------------------------------------------------------
	// Endpoints
	// ---------------------------------------------------------------------

	/**
	 * List tickets.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$query = TicketModel::query()->with( array( 'contact', 'agent', 'mailbox' ) );

		$status = $request->get_param( 'status' );
		if ( null !== $status && '' !== $status ) {
			$statuses = array_values( array_filter( array_map( 'trim', explode( ',', (string) $status ) ) ) );
			$valid    = array_values( array_intersect( $statuses, TicketStatus::all() ) );
			if ( ! empty( $valid ) ) {
				$query->whereIn( 'status', $valid );
			}
		}

		$priority = $request->get_param( 'priority' );
		if ( $priority && TicketPriority::is_valid( (string) $priority ) ) {
			$query->where( 'priority', $priority );
		}

		foreach ( array( 'agent_user_id', 'contact_id', 'mailbox_id' ) as $int_filter ) {
			$value = $request->get_param( $int_filter );
			if ( null !== $value && '' !== $value ) {
				$query->where( $int_filter, (int) $value );
			}
		}

		// Ownership scope: users without `doublescale_manage_all_tickets`
		// (Support Agents, Sales Reps) only ever see tickets assigned to them.
		// This overrides any `agent_user_id` filter the client may have sent.
		if ( ! Permissions::can_manage_all_tickets() ) {
			$query->where( 'agent_user_id', get_current_user_id() );
		}

		$tag_id = $request->get_param( 'tag_id' );
		if ( null !== $tag_id && '' !== $tag_id ) {
			$query->withTag( (int) $tag_id );
		}

		$search = $request->get_param( 'search' );
		if ( is_string( $search ) && '' !== trim( $search ) ) {
			$query->where( 'title', 'LIKE', '%' . str_replace( array( '%', '_' ), array( '\\%', '\\_' ), $search ) . '%' );
		}

		$sort_by    = in_array( $request->get_param( 'sort_by' ), array( 'created_at', 'updated_at', 'priority' ), true )
			? $request->get_param( 'sort_by' )
			: 'updated_at';
		$sort_order = 'asc' === $request->get_param( 'sort_order' ) ? 'asc' : 'desc';
		$query->orderBy( $sort_by, $sort_order );

		$per_page_raw = (int) $request->get_param( 'per_page' );
		$per_page     = max( 1, min( 100, $per_page_raw > 0 ? $per_page_raw : 20 ) );
		$page_raw     = (int) $request->get_param( 'page' );
		$page         = max( 1, $page_raw > 0 ? $page_raw : 1 );

		$paginator = $query->paginate( $per_page, array( '*' ), 'page', $page );

		// Always emit the contact / agent / mailbox relation summaries — the
		// query above already eager-loaded them via `->with(...)`, so this is
		// free. Without this, the inbox column for "Customer" / "Mailbox" /
		// "Agent" can only render a numeric id, which is unhelpful at a glance.
		$data = array();
		foreach ( $paginator->items() as $ticket ) {
			$data[] = $this->shape_ticket( $ticket, true );
		}

		return new WP_REST_Response(
			array(
				'data' => $data,
				'meta' => array(
					'total'        => $paginator->total(),
					'per_page'     => $per_page,
					'current_page' => $page,
					'last_page'    => max( 1, (int) ceil( $paginator->total() / $per_page ) ),
				),
			),
			200
		);
	}

	/**
	 * Get one ticket by id.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$id     = (int) $request->get_param( 'id' );
		$ticket = TicketModel::with( array( 'contact', 'agent', 'mailbox' ) )->find( $id );
		if ( ! $ticket ) {
			return new WP_Error( 'not_found', __( 'Ticket not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$forbidden = $this->require_ticket_ownership( $ticket );
		if ( $forbidden ) {
			return $forbidden;
		}

		return new WP_REST_Response( $this->shape_ticket( $ticket, true ), 200 );
	}

	/**
	 * Get one ticket by its public hash (portal).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item_by_hash( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$hash   = (string) $request->get_param( 'hash' );
		$ticket = TicketModel::with( array( 'contact', 'agent', 'mailbox' ) )
			->where( 'hash', $hash )
			->first();
		if ( ! $ticket ) {
			return new WP_Error( 'not_found', __( 'Ticket not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $this->shape_ticket( $ticket, true ), 200 );
	}

	/**
	 * List the agents the current user may assign a ticket to.
	 *
	 * Managers (`doublescale_manage_all_tickets`) get every support-capable
	 * user — anyone who can be assigned and work tickets. Non-managers
	 * (Support Agents, Sales Reps) get a single-entry list: themselves. This
	 * mirrors the server-side assignment guard in {@see update_item()} so the
	 * UI can only ever offer choices the API would actually accept.
	 *
	 * @param WP_REST_Request $request Unused — present for the framework contract. // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_assignable_agents( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		if ( ! Permissions::can_manage_all_tickets() ) {
			$self = wp_get_current_user();
			return new WP_REST_Response( array( $this->shape_agent( $self ) ), 200 );
		}

		// Every support-capable role: Administrators plus the DoubleScale roles
		// (CRM Manager, Sales Manager, Sales Rep, Support Manager, Support
		// Agent). We query by role rather than capability because WP_User_Query
		// has no capability filter, then keep only users who actually hold
		// `doublescale_view_support` (covers custom role tweaks / multiple roles
		// cleanly). Administrators are included explicitly because the role slug
		// list omits them, but they're granted every support cap on activation.
		$roles = array_merge(
			array( UserRoles::ADMINISTRATOR ),
			UserRoles::get_assignable_role_slugs()
		);

		$users = get_users(
			array(
				'role__in' => $roles,
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);

		$agents = array();
		foreach ( $users as $user ) {
			if ( Permissions::has_support_access( $user->ID ) ) {
				$agents[] = $this->shape_agent( $user );
			}
		}

		return new WP_REST_Response( $agents, 200 );
	}

	/**
	 * Create a ticket. Delegates entirely to TicketService.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$result = $this->service()->create_ticket( (array) $params );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $this->shape_ticket( $result, true ), 201 );
	}

	/**
	 * Update mutable ticket fields. Delegates to TicketService.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$id     = (int) $request->get_param( 'id' );
		$ticket = TicketModel::find( $id );
		if ( ! $ticket ) {
			return new WP_Error( 'not_found', __( 'Ticket not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$forbidden = $this->require_ticket_ownership( $ticket );
		if ( $forbidden ) {
			return $forbidden;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		// Drop URL-only parameters so they're not treated as updates.
		unset( $params['id'] );

		// Assignment rules:
		// - Managers (`doublescale_manage_all_tickets`) may assign to anyone.
		// - Non-managers (Support Agents, Sales Reps) may only assign to
		// themselves; any attempt to assign someone else is rejected so the
		// UI and API agree. Sending their own id (or omitting the field) is
		// fine and passes through unchanged.
		if ( ! Permissions::can_manage_all_tickets() && array_key_exists( 'agent_user_id', $params ) ) {
			if ( (int) $params['agent_user_id'] !== (int) get_current_user_id() ) {
				return new WP_Error(
					'not_allowed',
					__( 'You can only assign tickets to yourself.', 'doublescale' ),
					array( 'status' => 403 )
				);
			}
		}

		$result = $this->service()->update_ticket( $id, (array) $params );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Re-load relations so the response carries the same shape as get_item.
		$result->load( array( 'contact', 'agent', 'mailbox' ) );
		return new WP_REST_Response( $this->shape_ticket( $result, true ), 200 );
	}

	/**
	 * Delete a ticket. Delegates to TicketService (cascades attachments + associations).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		// Only Support Managers / Sales Managers / CRM Managers / Admins can
		// delete tickets. Agents are limited to reply / status changes.
		if ( ! Permissions::can_manage_all_tickets() ) {
			return new WP_Error(
				'not_allowed',
				__( 'Only Support Managers can delete tickets.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		$id     = (int) $request->get_param( 'id' );
		$result = $this->service()->delete_ticket( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( null, 204 );
	}

	// ---------------------------------------------------------------------
	// Permission checks
	// ---------------------------------------------------------------------

	/**
	 * Permission callbacks all share the same gate: anyone with sales-rep
	 * access can hit the support endpoints today. They take $request only
	 * because WP REST's framework hands it to permission_callback by contract.
	 *
	 * @param WP_REST_Request $request Unused — present for the framework contract. // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST always passes $request to permission_callback.
	 * @return bool|WP_Error
	 */
	public function get_items_permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return $this->require_support_access();
	}

	/**
	 * @param WP_REST_Request $request Unused — see {@see get_items_permissions_check()}.
	 * @return bool|WP_Error
	 */
	public function get_item_permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return $this->require_support_access();
	}

	/**
	 * Ticket creation is a manager-only action: assigning an `agent_user_id`
	 * to a new ticket is part of the create payload, so we don't let agents
	 * create unassigned tickets and avoid sales reps spinning up support work.
	 *
	 * @param WP_REST_Request $request Unused — see {@see get_items_permissions_check()}.
	 * @return bool|WP_Error
	 */
	public function create_item_permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( Permissions::can_manage_all_tickets() ) {
			return true;
		}
		return new WP_Error(
			'not_allowed',
			__( 'Only Support Managers can create tickets.', 'doublescale' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * @param WP_REST_Request $request Unused — see {@see get_items_permissions_check()}.
	 * @return bool|WP_Error
	 */
	public function update_item_permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return $this->require_support_access();
	}

	/**
	 * @param WP_REST_Request $request Unused — see {@see get_items_permissions_check()}.
	 * @return bool|WP_Error
	 */
	public function delete_item_permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return $this->require_support_access();
	}

	// ---------------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------------

	/**
	 * Resolve TicketService. We instantiate directly rather than going through
	 * the container so REST keeps working even when Module::register() hasn't
	 * bound the singleton (e.g. in unit tests). A filter lets tests / Pro
	 * substitute a custom implementation.
	 *
	 * @return TicketService
	 */
	private function service(): TicketService {
		/**
		 * Filter the TicketService instance used by REST callbacks.
		 *
		 * @param TicketService|null $service Pre-built service, or null to let the controller build one.
		 */
		$override = apply_filters( 'doublescale_support_ticket_service_instance', null );
		if ( $override instanceof TicketService ) {
			return $override;
		}
		return new TicketService( new ContactResolver() );
	}

	/**
	 * Shape a ticket model into the REST response shape.
	 *
	 * Heavy fields (`custom_data`, `tag_ids`) are always included; the
	 * conversation thread itself is fetched through a separate endpoint to
	 * keep `GET /tickets` responses light for inbox views.
	 *
	 * @param TicketModel $ticket  Ticket model with relations loaded.
	 * @param bool        $detail  Include relation summaries (single-ticket view).
	 * @return array
	 */
	private function shape_ticket( TicketModel $ticket, bool $detail = false ): array {
		$payload = array(
			'id'             => (int) $ticket->id,
			'hash'           => (string) $ticket->hash,
			'title'          => (string) $ticket->title,
			'status'         => (string) $ticket->status,
			'priority'       => (string) $ticket->priority,
			'mailbox_id'     => $ticket->mailbox_id ? (int) $ticket->mailbox_id : null,
			'contact_id'     => (int) $ticket->contact_id,
			'agent_user_id'  => $ticket->agent_user_id ? (int) $ticket->agent_user_id : null,
			'product'        => $ticket->product,
			'response_count' => (int) $ticket->response_count,
			'tag_ids'        => is_array( $ticket->tag_ids ) ? array_values( array_map( 'intval', $ticket->tag_ids ) ) : array(),
			'custom_data'    => is_array( $ticket->custom_data ) ? $ticket->custom_data : new \stdClass(),
			// Accumulated union of every CC recipient ever used on this ticket.
			// Lives inside custom_data; surfaced as a top-level array so the client
			// has a stable contract without reaching into custom_data.
			'cc_recipients'  => ( is_array( $ticket->custom_data ) && isset( $ticket->custom_data['cc_recipients'] ) && is_array( $ticket->custom_data['cc_recipients'] ) )
				? array_values( $ticket->custom_data['cc_recipients'] )
				: array(),
			'created_at'     => $ticket->created_at ? (string) $ticket->created_at : null,
			'updated_at'     => $ticket->updated_at ? (string) $ticket->updated_at : null,
		);

		if ( $detail ) {
			$payload['contact'] = $ticket->contact ? array(
				'id'         => (int) $ticket->contact->id,
				'email'      => $ticket->contact->email,
				'first_name' => $ticket->contact->first_name,
				'last_name'  => $ticket->contact->last_name,
			) : null;
			$payload['agent']   = $ticket->agent ? array(
				'id'           => (int) $ticket->agent->ID,
				'display_name' => $ticket->agent->display_name,
				'email'        => $ticket->agent->user_email,
			) : null;
			$payload['mailbox'] = $ticket->mailbox ? array(
				'id'    => (int) $ticket->mailbox->id,
				'slug'  => $ticket->mailbox->slug,
				'email' => $ticket->mailbox->email,
				'name'  => $ticket->mailbox->name,
			) : null;

			$custom_data = is_array( $ticket->custom_data ) ? $ticket->custom_data : array();
			$payload['custom_fields'] = self::render_custom_fields( $custom_data );
		}

		return $payload;
	}

	/**
	 * Shape a WP_User into the agent summary used by the "Assign to" picker.
	 * Matches the `agent` shape emitted by {@see shape_ticket()} so the client
	 * can reuse the same `AgentSummary` type.
	 *
	 * @param \WP_User $user User to shape.
	 * @return array
	 */
	private function shape_agent( \WP_User $user ): array {
		return array(
			'id'           => (int) $user->ID,
			'display_name' => $user->display_name,
			'email'        => $user->user_email,
		);
	}

	/**
	 * Baseline access guard. Anyone with `doublescale_view_support` can hit
	 * support endpoints; ownership filtering is applied separately on
	 * collection / per-item callbacks via {@see require_ticket_ownership()}
	 * and the list-scope `where('agent_user_id', current_user_id)` clause.
	 *
	 * @return bool|WP_Error
	 */
	private function require_support_access() {
		if ( Permissions::has_support_access() ) {
			return true;
		}
		return new WP_Error( 'not_allowed', __( 'You do not have permission to access support tickets.', 'doublescale' ), array( 'status' => 403 ) );
	}

	/**
	 * Per-ticket ownership guard. Returns null when the current user is
	 * allowed (manager-of-all-tickets OR the assigned agent), or a 403
	 * WP_Error otherwise. Returning null (not true) makes the call site
	 * read cleanly: `if ( $err = $this->require_...() ) return $err;`.
	 *
	 * @param TicketModel $ticket The ticket model to authorize against.
	 * @return WP_Error|null
	 */
	private function require_ticket_ownership( TicketModel $ticket ) {
		if ( Permissions::can_manage_all_tickets() ) {
			return null;
		}
		if ( (int) $ticket->agent_user_id === (int) get_current_user_id() ) {
			return null;
		}
		return new WP_Error(
			'not_allowed',
			__( 'You can only access tickets assigned to you.', 'doublescale' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * @param array<string, mixed> $custom_data Stored ticket custom_data.
	 * @return array<string, mixed>
	 */
	private static function render_custom_fields( array $custom_data ): array {
		$class = '\\DoubleScale\\Pro\\Modules\\Support\\Services\\CustomFieldsService';
		if ( ! class_exists( $class ) ) {
			return array();
		}
		return ( new $class() )->render( $custom_data );
	}
}
