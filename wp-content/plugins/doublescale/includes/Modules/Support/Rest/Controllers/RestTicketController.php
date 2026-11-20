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

use DoubleScale\Modules\Support\Services\TicketProject;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Core\UserRoles\UserRoles;
use DoubleScale\Modules\Support\Constants\TicketPriority;
use DoubleScale\Modules\Support\Constants\TicketStatus;
use DoubleScale\Modules\Support\Models\TicketModel;
use DoubleScale\Modules\Support\Services\ContactResolver;
use DoubleScale\Modules\Support\Services\TicketMergeService;
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
			'/' . $this->rest_base . '/by-hash/(?P<hash>[A-Za-z0-9]{32})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item_by_hash' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
			)
		);

		// Merge into another ticket of the same customer, and the tickets it
		// may be merged into (that customer's, the user may open).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/merge',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'merge_item' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
				'args'                => array(
					'into' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/merge-targets',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'merge_targets' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
			)
		);

		// A team member checked the customer's address on a ticket opened with an
		// address nobody proved (public form, chat): clears the flag.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/verify-contact',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'verify_contact' ),
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
			'view'          => array(
				'description' => __( 'A named view, such as live_chats.', 'doublescale' ),
				'type'        => 'string',
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

		foreach ( array( 'agent_user_id', 'contact_id', 'mailbox_id', 'project_id' ) as $int_filter ) {
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

		// A named view another module defines (e.g. the Help Center's "live_chats").
		$view = sanitize_key( (string) $request->get_param( 'view' ) );
		if ( '' !== $view ) {
			/**
			 * The ticket ids in a named inbox view, or null for a view nobody knows.
			 *
			 * @param int[]|null       $ids     Ticket ids.
			 * @param string           $view    View name.
			 * @param \WP_REST_Request $request Request.
			 */
			$ids = apply_filters( 'doublescale_support_ticket_view_ids', null, $view, $request );
			if ( is_array( $ids ) ) {
				$query->whereIn( 'id', $ids ? array_map( 'intval', $ids ) : array( 0 ) );
			}
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
					'total'         => $paginator->total(),
					'per_page'      => $per_page,
					'current_page'  => $page,
					'last_page'     => max( 1, (int) ceil( $paginator->total() / $per_page ) ),
					'status_counts' => $this->status_counts(),
					// agents can't read the mailbox list, where these also live
					'attachment_limits' => \DoubleScale\Modules\Support\Services\AttachmentSettings::to_payload(),
				),
			),
			200
		);
	}

	/**
	 * Tickets per status across everything the user can see (filters aside),
	 * for the overview cards above the inbox.
	 *
	 * @return array<string, int> One count per status, plus `total`.
	 */
	private function status_counts(): array {
		$query = TicketModel::query();
		if ( ! Permissions::can_manage_all_tickets() ) {
			$query->where( 'agent_user_id', get_current_user_id() );
		}
		$rows = $query->selectRaw( 'status, COUNT(*) as c' )->groupBy( 'status' )->get();

		$counts = array_fill_keys( TicketStatus::all(), 0 );
		foreach ( $rows as $row ) {
			if ( isset( $counts[ (string) $row->status ] ) ) {
				$counts[ (string) $row->status ] = (int) $row->c;
			}
		}
		$counts['total'] = array_sum( $counts );
		return $counts;
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
	 * Merge this ticket into another of the same customer. Whoever may open
	 * both tickets may merge them.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function merge_item( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}
		$source = TicketModel::find( (int) $request->get_param( 'id' ) );
		$target = TicketModel::find( (int) $request->get_param( 'into' ) );
		if ( ! $source || ! $target ) {
			return new WP_Error( 'not_found', __( 'Ticket not found.', 'doublescale' ), array( 'status' => 404 ) );
		}
		foreach ( array( $source, $target ) as $ticket ) {
			$forbidden = $this->require_ticket_ownership( $ticket );
			if ( $forbidden ) {
				return $forbidden;
			}
		}

		$result = ( new TicketMergeService( $this->service() ) )->merge( $source, $target );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Mark the address on an unverified ticket as checked by the team.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function verify_contact( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}
		$ticket = TicketModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $ticket ) {
			return new WP_Error( 'not_found', __( 'Ticket not found.', 'doublescale' ), array( 'status' => 404 ) );
		}
		$forbidden = $this->require_ticket_ownership( $ticket );
		if ( $forbidden ) {
			return $forbidden;
		}
		if ( TicketService::is_unverified( $ticket ) ) {
			TicketService::clear_unverified( $ticket );
		}

		return new WP_REST_Response( array( 'unverified_contact' => false ), 200 );
	}

	/**
	 * The tickets this one may be merged into.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function merge_targets( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}
		$source = TicketModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $source ) {
			return new WP_Error( 'not_found', __( 'Ticket not found.', 'doublescale' ), array( 'status' => 404 ) );
		}
		$forbidden = $this->require_ticket_ownership( $source );
		if ( $forbidden ) {
			return $forbidden;
		}

		$targets = ( new TicketMergeService( $this->service() ) )->targets(
			$source,
			function ( TicketModel $ticket ): bool {
				return null === $this->require_ticket_ownership( $ticket );
			}
		);

		return new WP_REST_Response( array( 'data' => $targets ), 200 );
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

		// Project link: checked before anything is saved (see TicketProject).
		$project = $this->take_project_param( $params );
		if ( $project['id'] && ! empty( $params['contact_id'] ) ) {
			$checked = TicketProject::check( $project['id'], (int) $params['contact_id'], $this->company_for( (int) $params['contact_id'], $params['company_id'] ?? null ) );
			if ( is_wp_error( $checked ) ) {
				return $checked;
			}
		}

		// TicketService keeps company_id only when the contact belongs to it.
		$result = $this->service()->create_ticket( (array) $params );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$warnings = array();
		if ( $project['id'] ) {
			// Contact given by email (created with the ticket): check against the saved ticket.
			$checked = empty( $params['contact_id'] )
				? TicketProject::check( $project['id'], (int) $result->contact_id, $result->company_id ? (int) $result->company_id : null )
				: true;
			if ( true === $checked ) {
				$result->project_id = $project['id'];
				$result->save();
			} else {
				$warnings[] = $checked->get_error_message();
			}
		}

		$shaped = $this->shape_ticket( $result, true );
		if ( $warnings ) {
			$shaped['warnings'] = $warnings;
		}
		return new WP_REST_Response( $shaped, 201 );
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

		// A merged ticket stays closed: its customer's messages go to the main
		// ticket, so opening it again would leave it waiting for nothing.
		if ( (int) $ticket->merged_into_id > 0 && array_key_exists( 'status', $params ) && TicketStatus::CLOSED !== (string) $params['status'] ) {
			return new WP_Error(
				'ticket_merged',
				/* translators: %d: ticket number */
				sprintf( __( 'This ticket was merged into #%d. Work on that one.', 'doublescale' ), (int) $ticket->merged_into_id ),
				array( 'status' => 409 )
			);
		}

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

		$has_company = array_key_exists( 'company_id', $params );
		$company_id  = $has_company ? $params['company_id'] : $ticket->company_id;
		unset( $params['company_id'] );

		// Project link: linking needs the project check; removing a link only
		// needs the right to edit the ticket (checked above).
		$project        = $this->take_project_param( $params );
		$target_contact = array_key_exists( 'contact_id', $params ) ? (int) $params['contact_id'] : (int) $ticket->contact_id;
		if ( $project['id'] && ( (int) $project['id'] !== (int) $ticket->project_id || $has_company || array_key_exists( 'contact_id', $params ) ) ) {
			$checked = TicketProject::check( $project['id'], $target_contact, $this->company_for( $target_contact, $company_id ) );
			if ( is_wp_error( $checked ) ) {
				return $checked;
			}
		}

		$result = $this->service()->update_ticket( $id, (array) $params );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Sharing follows the contact: re-check on a new contact or company.
		$client_changed = $has_company || array_key_exists( 'contact_id', $params );
		if ( $client_changed ) {
			$this->apply_company( $result, $company_id );
		}

		if ( $project['set'] ) {
			$result->project_id = $project['id'];
			$result->save();
		} elseif ( $client_changed && $result->project_id ) {
			// The project must still belong to the ticket's client. Only that is
			// re-checked here: the editor not being able to open the project
			// is no reason to drop the link.
			if ( ! TicketProject::matches_client( (int) $result->project_id, (int) $result->contact_id, $result->company_id ? (int) $result->company_id : null ) ) {
				$result->project_id = null;
				$result->save();
			}
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
			'company_id'     => $ticket->company_id ? (int) $ticket->company_id : null,
			'project_id'     => $ticket->project_id ? (int) $ticket->project_id : null,
			// Name and link only for people who can open the project.
			'project'        => TicketProject::summary( $ticket->project_id ? (int) $ticket->project_id : null ),
			'agent_user_id'  => $ticket->agent_user_id ? (int) $ticket->agent_user_id : null,
			'product'        => $ticket->product,
			'response_count' => (int) $ticket->response_count,
			'tag_ids'        => is_array( $ticket->tag_ids ) ? array_values( array_map( 'intval', $ticket->tag_ids ) ) : array(),
			'custom_data'    => is_array( $ticket->custom_data ) ? $ticket->custom_data : new \stdClass(),
			// Accumulated union of every CC recipient ever used on this ticket.
			// Lives inside custom_data; surfaced as a top-level array so the client
			// has a stable contract without reaching into custom_data.
			// The address was typed by whoever opened the ticket and never proven.
			'unverified_contact' => TicketService::is_unverified( $ticket ),
			'cc_recipients'  => ( is_array( $ticket->custom_data ) && isset( $ticket->custom_data['cc_recipients'] ) && is_array( $ticket->custom_data['cc_recipients'] ) )
				? array_values( $ticket->custom_data['cc_recipients'] )
				: array(),
			'created_at'     => $ticket->created_at ? (string) $ticket->created_at : null,
			'updated_at'     => $ticket->updated_at ? (string) $ticket->updated_at : null,
			// Merged into another ticket of the same customer (closed, read-only).
			'merged_into_id' => (int) $ticket->merged_into_id > 0 ? (int) $ticket->merged_into_id : null,
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

	/**
	 * Share a ticket with its contact's company (or stop sharing).
	 *
	 * @param TicketModel $ticket    Ticket.
	 * @param mixed       $requested Requested company id (0/null = not shared).
	 * @return void
	 */
	/**
	 * Pull `project_id` out of the request params.
	 *
	 * @param array $params Request params (project_id is removed).
	 * @return array{set: bool, id: int|null} Whether it was sent, and the project (null = remove the link).
	 */
	private function take_project_param( array &$params ): array {
		if ( ! array_key_exists( 'project_id', $params ) ) {
			return array(
				'set' => false,
				'id'  => null,
			);
		}
		$id = (int) $params['project_id'];
		unset( $params['project_id'] );
		return array(
			'set' => true,
			'id'  => $id > 0 ? $id : null,
		);
	}

	/**
	 * The company a ticket of this contact would be shared with.
	 *
	 * @param int   $contact_id Contact.
	 * @param mixed $requested  Requested company id.
	 * @return int|null
	 */
	private function company_for( int $contact_id, $requested ): ?int {
		$company = function_exists( 'doublescale_record_company_id' ) ? doublescale_record_company_id( $contact_id, $requested ) : null;
		return $company ? (int) $company : null;
	}

	private function apply_company( TicketModel $ticket, $requested ): void {
		$company_id = doublescale_record_company_id( $ticket->contact_id, $requested );
		if ( (int) $ticket->company_id !== (int) $company_id ) {
			$ticket->company_id = $company_id;
			$ticket->save();
		}
	}
}
