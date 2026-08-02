<?php
/**
 * REST controller for the customer-facing portal (logged-in users only).
 *
 * Auth model (per product owner direction 2026-05-25):
 * - Every endpoint requires `is_user_logged_in()`. Logged-out callers get
 *   401 — there is no anonymous portal surface.
 * - The customer's `doublescale_contacts` row is resolved by EMAIL MATCH:
 *   `ContactModel::where('email', wp_get_current_user()->user_email)->first()`.
 *   If no contact exists, the portal shows an empty state on the frontend —
 *   this controller returns an empty list (NOT a 404) so the React layer can
 *   render "no tickets yet, submit one" without special-casing the API call.
 * - Submission lazily creates the contact on first ticket — we don't pre-create
 *   on portal visit. That keeps the contact table free of WP users who never
 *   actually file a ticket.
 * - Every read/write also performs an ownership check: the ticket's
 *   `contact_id` must equal the logged-in user's matched contact id. Prevents
 *   `?id=N` enumeration by URL-tampering.
 *
 * Routes registered (namespace `doublescale/v1`):
 *
 *   GET    /support/portal/mailboxes                       List active mailboxes (slug, name, email).
 *   GET    /support/portal/tickets                         Logged-in user's tickets, paginated.
 *   POST   /support/portal/tickets                         Open a new ticket as the logged-in user.
 *   GET    /support/portal/tickets/{id}                    Read one (ownership-gated).
 *   GET    /support/portal/tickets/{id}/conversation       Thread (notes excluded).
 *   POST   /support/portal/tickets/{id}/replies            Customer reply on own ticket.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Support\Models\MailboxModel;
use DoubleScale\Modules\Support\Models\TicketModel;
use DoubleScale\Modules\Support\Services\AttachmentService;
use DoubleScale\Modules\Support\Services\ContactResolver;
use DoubleScale\Modules\Support\Services\TicketService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPortalController class.
 */
class RestPortalController extends RestController {

	/**
	 * Route base. Routes live under `doublescale/v1/support/portal/...`.
	 *
	 * @var string
	 */
	protected $rest_base = 'support/portal';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/mailboxes',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_mailboxes' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/tickets',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_tickets' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_ticket' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/tickets/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_ticket' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/tickets/(?P<id>[\d]+)/conversation',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_conversation' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/tickets/(?P<id>[\d]+)/replies',
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
			'/' . $this->rest_base . '/tickets/(?P<ticket_id>[\d]+)/attachments',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_attachment' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		// Ticketless upload for a customer composing their FIRST ticket (no ticket
		// to scope to yet). A literal segment on the portal base — WP resolves it
		// ahead of the `tickets/(?P<ticket_id>…)` pattern above. Linked at create.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/attachments',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_attachment_unticketed' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Endpoints
	// ---------------------------------------------------------------------

	/**
	 * Mailbox list (slug + name + email only — no IMAP creds).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_mailboxes( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$rows = MailboxModel::orderBy( 'is_default', 'desc' )
			->orderBy( 'slug', 'asc' )
			->get();

		$data = array();
		foreach ( $rows as $mailbox ) {
			$data[] = array(
				'id'         => (int) $mailbox->id,
				'slug'       => (string) $mailbox->slug,
				'email'      => (string) $mailbox->email,
				'name'       => (string) $mailbox->name,
				'is_default' => (bool) $mailbox->is_default,
			);
		}

		return new WP_REST_Response( array( 'data' => $data ), 200 );
	}

	/**
	 * Logged-in user's tickets.
	 *
	 * Returns an empty result set if the user has no matching contact — the
	 * portal frontend renders "no tickets yet" in that case rather than 404.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_tickets( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$contact = $this->lookup_contact_for_current_user();
		if ( ! $contact ) {
			return new WP_REST_Response(
				array(
					'data' => array(),
					'meta' => array(
						'total'        => 0,
						'per_page'     => 20,
						'current_page' => 1,
						'last_page'    => 1,
					),
				),
				200
			);
		}

		$query = TicketModel::query()
			->where( 'contact_id', $contact->id )
			->with( array( 'mailbox' ) );

		// Filter: status (single or comma-separated).
		$status = $request->get_param( 'status' );
		if ( is_string( $status ) && '' !== $status ) {
			$valid    = array( 'open', 'pending', 'resolved', 'closed' );
			$selected = array_intersect(
				array_filter( array_map( 'trim', explode( ',', $status ) ) ),
				$valid
			);
			if ( ! empty( $selected ) ) {
				$query->whereIn( 'status', array_values( $selected ) );
			}
		}

		// Filter: search title contains.
		$search = $request->get_param( 'search' );
		if ( is_string( $search ) && '' !== trim( $search ) ) {
			$needle = '%' . str_replace(
				array( '%', '_' ),
				array( '\\%', '\\_' ),
				$search
			) . '%';
			$query->where( 'title', 'LIKE', $needle );
		}

		// Filter: mailbox scope. A per-mailbox portal (the shortcode's `box_id`)
		// narrows the customer's own tickets to that one mailbox, matching the
		// scoped create path so a scoped portal shows only its mailbox's tickets.
		$mailbox_id = (int) $request->get_param( 'mailbox_id' );
		if ( $mailbox_id > 0 ) {
			$query->where( 'mailbox_id', $mailbox_id );
		}

		$query->orderBy( 'updated_at', 'desc' );

		$per_page_raw = (int) $request->get_param( 'per_page' );
		$per_page     = max( 1, min( 50, $per_page_raw > 0 ? $per_page_raw : 20 ) );
		$page_raw     = (int) $request->get_param( 'page' );
		$page         = max( 1, $page_raw > 0 ? $page_raw : 1 );

		$paginator = $query->paginate( $per_page, array( '*' ), 'page', $page );

		$data = array();
		foreach ( $paginator->items() as $ticket ) {
			$data[] = $this->shape_portal_ticket( $ticket );
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
	 * Read one ticket (ownership-gated).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_ticket( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$ticket = $this->resolve_own_ticket( $request );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		return new WP_REST_Response( $this->shape_portal_ticket( $ticket ), 200 );
	}

	/**
	 * Read the conversation for a ticket (notes excluded).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_conversation( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$ticket = $this->resolve_own_ticket( $request );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		// Notes are agent-internal; never show them to a customer.
		$allowed = array(
			ActivityTypes::SUPPORT_REPLY,
			ActivityTypes::SUPPORT_EVENT,
		);

		$activities = ActivityModel::forTicket( $ticket->id )
			->whereIn( 'activity_type', $allowed )
			->with( 'user' )
			->get();

		$activity_ids    = array_map(
			static function ( $activity ) {
				return (int) $activity->id;
			},
			$activities->all()
		);
		$attachments_map = ( new AttachmentService() )->map_for_activities( $activity_ids );

		$data = array();
		foreach ( $activities as $activity ) {
			$data[] = $this->shape_portal_activity( $activity, $attachments_map );
		}

		return new WP_REST_Response(
			array(
				'data' => $data,
				'meta' => array(
					'total'     => count( $data ),
					'ticket_id' => (int) $ticket->id,
				),
			),
			200
		);
	}

	/**
	 * Create a new ticket as the logged-in user.
	 *
	 * Lazily creates the matching `doublescale_contacts` row if one does not
	 * yet exist for this user's email — that's the "first interaction"
	 * onboarding path.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_ticket( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$user = wp_get_current_user();

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$title = isset( $params['title'] ) ? trim( wp_strip_all_tags( (string) $params['title'] ) ) : '';
		if ( '' === $title ) {
			return new WP_Error(
				'missing_title',
				__( 'Please provide a short title.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$content = isset( $params['content'] ) ? wp_kses_post( (string) $params['content'] ) : '';
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return new WP_Error(
				'missing_content',
				__( 'Please describe what you need help with.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$create_data = array(
			'email'      => $user->user_email,
			'first_name' => ! empty( $user->first_name ) ? $user->first_name : null,
			'last_name'  => ! empty( $user->last_name ) ? $user->last_name : null,
			'title'      => $title,
			'content'    => $content,
			'source'     => 'web',
		);

		if ( isset( $params['priority'] ) ) {
			$create_data['priority'] = (string) $params['priority'];
		}

		// Honor the client's mailbox choice only if it names a real mailbox (any
		// type — both web and email boxes are portal channels). An unknown id is
		// dropped silently so TicketService::resolve_mailbox_id() falls back to
		// the default channel, rather than surfacing a routing error to the
		// customer or writing an invalid FK.
		if ( isset( $params['mailbox_id'] ) ) {
			$requested_mailbox_id = (int) $params['mailbox_id'];
			if ( $requested_mailbox_id > 0 && MailboxModel::where( 'id', $requested_mailbox_id )->exists() ) {
				$create_data['mailbox_id'] = $requested_mailbox_id;
			}
		}

		if ( ! empty( $params['custom_data'] ) && is_array( $params['custom_data'] ) ) {
			$create_data['custom_data']         = $params['custom_data'];
			$create_data['custom_fields_scope'] = 'portal';
		}
		if ( ! empty( $params['attachment_hashes'] ) && is_array( $params['attachment_hashes'] ) ) {
			$create_data['attachment_hashes'] = $params['attachment_hashes'];
		}

		$ticket = $this->service()->create_ticket( $create_data );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		return new WP_REST_Response( $this->shape_portal_ticket( $ticket ), 201 );
	}

	/**
	 * Customer reply on their own ticket.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_reply( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$ticket = $this->resolve_own_ticket( $request );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		// TicketService::sanitize_content() is the authoritative sanitizer; this
		// pass is a harmless second kses (it is idempotent) that lets the portal
		// return a customer-friendly 400 for empty content before the service runs.
		$content = isset( $params['content'] ) ? wp_kses_post( (string) $params['content'] ) : '';
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return new WP_Error(
				'missing_content',
				__( 'Please type a reply first.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		// Force `author_user_id = null` — customer replies are never attributed
		// to an agent even if the WP user happens to also have an agent role.
		// CC is intentionally NOT accepted from the portal: a customer-set Cc list
		// is a spam/abuse vector. Outbound CC is an agent-only action (admin REST).
		$reply_data = array(
			'content'        => $content,
			'source'         => 'web',
			'author_user_id' => null,
		);
		if ( ! empty( $params['attachment_hashes'] ) && is_array( $params['attachment_hashes'] ) ) {
			$reply_data['attachment_hashes'] = $params['attachment_hashes'];
		}

		$activity = $this->service()->add_reply( $ticket, $reply_data );
		if ( is_wp_error( $activity ) ) {
			return $activity;
		}

		// Customer replying to a closed/resolved ticket re-opens it so the
		// agent inbox sees the new message. Matches the QuillSupport behaviour.
		if ( in_array( $ticket->status, array( 'resolved', 'closed' ), true ) ) {
			$this->service()->update_ticket( $ticket, array( 'status' => 'open' ) );
		}

		$attachments_map = ( new AttachmentService() )->map_for_activities( array( (int) $activity->id ) );
		return new WP_REST_Response( $this->shape_portal_activity( $activity, $attachments_map ), 201 );
	}

	/**
	 * Customer upload on an owned ticket.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_attachment( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$contact = $this->lookup_contact_for_current_user();
		if ( ! $contact ) {
			return new WP_Error( 'not_found', __( 'Ticket not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$ticket_id = (int) $request->get_param( 'ticket_id' );
		$ticket    = TicketModel::where( 'id', $ticket_id )
			->where( 'contact_id', $contact->id )
			->first();
		if ( ! $ticket ) {
			return new WP_Error( 'not_found', __( 'Ticket not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;
		if ( ! $file ) {
			return new WP_Error( 'no_file', __( 'No file was uploaded.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$service  = new AttachmentService();
		$too_many = $service->guard_file_count( (int) $request->get_param( 'pending_count' ) );
		if ( $too_many ) {
			return $too_many;
		}

		$attachment = $service->store_upload(
			$file,
			(int) $ticket->id,
			array( 'contact_id' => (int) $contact->id )
		);
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		return new WP_REST_Response(
			array(
				'file_hash' => (string) $attachment->file_hash,
				'file_name' => (string) $attachment->file_name,
				'file_size' => (int) $attachment->file_size,
				'file_type' => (string) $attachment->file_type,
			),
			201
		);
	}

	/**
	 * Customer upload BEFORE the ticket exists (first-ticket composer). Stores an
	 * unticketed temp row tagged with the customer's contact when one already
	 * exists (a returning customer); a brand-new customer has no contact row yet,
	 * so the row is uploaded contact-less and linked at create time by its hash.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_attachment_unticketed( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;
		if ( ! $file ) {
			return new WP_Error( 'no_file', __( 'No file was uploaded.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$contact  = $this->lookup_contact_for_current_user();
		$uploader = $contact ? array( 'contact_id' => (int) $contact->id ) : array();

		$service  = new AttachmentService();
		$too_many = $service->guard_file_count( (int) $request->get_param( 'pending_count' ) );
		if ( $too_many ) {
			return $too_many;
		}

		$attachment = $service->store_upload( $file, 0, $uploader );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		return new WP_REST_Response(
			array(
				'file_hash' => (string) $attachment->file_hash,
				'file_name' => (string) $attachment->file_name,
				'file_size' => (int) $attachment->file_size,
				'file_type' => (string) $attachment->file_type,
			),
			201
		);
	}

	// ---------------------------------------------------------------------
	// Permissions
	// ---------------------------------------------------------------------

	/**
	 * Shared portal gate: must be logged in. WP REST adds the `X-WP-Nonce`
	 * for cookie-auth automatically; `apiFetch` on the frontend forwards it.
	 *
	 * @param WP_REST_Request $request Unused — present for the framework contract.
	 * @return bool|WP_Error
	 */
	public function permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You must be logged in to access the support portal.', 'doublescale' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	// ---------------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------------

	/**
	 * Look up the `doublescale_contacts` row matching the logged-in user.
	 *
	 * @return ContactModel|null
	 */
	private function lookup_contact_for_current_user(): ?ContactModel {
		$user = wp_get_current_user();
		if ( ! $user || empty( $user->user_email ) ) {
			return null;
		}
		$contact = ContactModel::where( 'email', strtolower( $user->user_email ) )->first();
		return $contact instanceof ContactModel ? $contact : null;
	}

	/**
	 * Resolve the `{id}` route param to a ticket that the current user owns.
	 *
	 * Returns 404 (not 403) when ownership fails — telling an enumerating
	 * attacker that "ticket N exists but isn't yours" leaks information.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return TicketModel|WP_Error
	 */
	private function resolve_own_ticket( WP_REST_Request $request ) {
		$contact = $this->lookup_contact_for_current_user();
		if ( ! $contact ) {
			return new WP_Error(
				'not_found',
				__( 'Ticket not found.', 'doublescale' ),
				array( 'status' => 404 )
			);
		}

		$id     = (int) $request->get_param( 'id' );
		$ticket = TicketModel::with( array( 'mailbox' ) )
			->where( 'id', $id )
			->where( 'contact_id', $contact->id )
			->first();

		if ( ! $ticket ) {
			return new WP_Error(
				'not_found',
				__( 'Ticket not found.', 'doublescale' ),
				array( 'status' => 404 )
			);
		}

		return $ticket;
	}

	/**
	 * Shape a TicketModel into the portal payload. Strips agent identity and
	 * mailbox email — operators may not want those exposed customer-side.
	 *
	 * @param TicketModel $ticket Ticket model.
	 * @return array
	 */
	private function shape_portal_ticket( TicketModel $ticket ): array {
		$custom_data = is_array( $ticket->custom_data ) ? $ticket->custom_data : array();
		return array(
			'id'             => (int) $ticket->id,
			'title'          => (string) $ticket->title,
			'status'         => (string) $ticket->status,
			'priority'       => (string) $ticket->priority,
			'response_count' => (int) $ticket->response_count,
			'custom_data'    => $custom_data,
			'custom_fields'  => self::render_custom_fields( $custom_data ),
			'mailbox'        => $ticket->mailbox ? array(
				'name' => $ticket->mailbox->name,
			) : null,
			'created_at'     => $ticket->created_at ? (string) $ticket->created_at : null,
			'updated_at'     => $ticket->updated_at ? (string) $ticket->updated_at : null,
		);
	}

	/**
	 * Shape an activity row for the portal conversation view.
	 *
	 * @param ActivityModel                                $activity Activity row.
	 * @param array<int, array<int, array<string, mixed>>> $attachments_map Attachments keyed by activity id.
	 * @return array
	 */
	private function shape_portal_activity( ActivityModel $activity, array $attachments_map = array() ): array {
		$kind_map = array(
			ActivityTypes::SUPPORT_REPLY => 'reply',
			ActivityTypes::SUPPORT_EVENT => 'event',
		);
		$kind     = $kind_map[ $activity->activity_type ] ?? 'event';
		$data     = is_array( $activity->data ) ? $activity->data : array();

		// Match the admin RestReplyController shape so the renderer can reuse
		// the same `ConversationItem` type. Portal-specific additions: strip
		// `contact_id` (agent-internal) and add `is_self` so the renderer can
		// right-align the customer's own bubbles.
		//
		// "Self" = the message was authored by the customer, not an agent. Two
		// shapes count as the customer's own message. (a) NULL `user_id`:
		// customer replies are deliberately written that way (see add_reply():
		// `author_user_id = null`), as are inbound email replies. (b) `user_id`
		// === the current (owning) user: the opening message of a web-created
		// ticket is credited to the logged-in customer (TicketService, source
		// `web`, no explicit author), so it carries their WP id, not NULL. The
		// portal only ever returns tickets the current user OWNS, so matching
		// get_current_user_id() is safe and correctly flags that opening message
		// as self. An agent reply (or an agent filing on the customer's behalf)
		// carries a different id and stays "not self". Testing only
		// `empty(user_id)` mislabels the customer's own opening message.
		$current_user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$is_self         = ActivityTypes::SUPPORT_REPLY === $activity->activity_type
			&& ( empty( $activity->user_id ) || (int) $activity->user_id === $current_user_id );

		// Resolve the `user` shown to the customer. We expose only the WP
		// display_name — never the agent's WP id or email:
		// - The customer's OWN reply → their own id + display name.
		// - A staff (agent) REPLY → the agent's display_name, so the customer
		// sees who helped them (no id, no email).
		// - Lifecycle events (created, status changed, …) → authorless rows.
		$user = null;
		if ( $is_self ) {
			if ( $activity->relationLoaded( 'user' ) && $activity->user ) {
				$user = array(
					'id'           => (int) $activity->user->ID,
					'display_name' => (string) $activity->user->display_name,
				);
			}
		} elseif (
			ActivityTypes::SUPPORT_REPLY === $activity->activity_type
			&& $activity->relationLoaded( 'user' )
			&& $activity->user
		) {
			$user = array(
				'display_name' => (string) $activity->user->display_name,
			);
		}

		$aid = (int) $activity->id;

		return array(
			'id'          => $aid,
			'kind'        => $kind,
			'type'        => $activity->activity_type,
			'user_id'     => $is_self && $activity->user_id ? (int) $activity->user_id : null,
			'data'        => $data,
			'is_self'     => $is_self,
			'user'        => $user,
			'attachments' => isset( $attachments_map[ $aid ] ) ? $attachments_map[ $aid ] : array(),
			'created_at'  => $activity->created_at ? (string) $activity->created_at : null,
			'updated_at'  => $activity->updated_at ? (string) $activity->updated_at : null,
		);
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
