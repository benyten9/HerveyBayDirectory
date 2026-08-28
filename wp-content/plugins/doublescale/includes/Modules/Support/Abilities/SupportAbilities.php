<?php
/**
 * Support ticket abilities.
 *
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\AbilityCategories;
use DoubleScale\Core\Abilities\AbilityInput;
use DoubleScale\Core\Abilities\AbilityResult;
use DoubleScale\Core\Abilities\AbilityScope;
use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Support\Constants\TicketPriority;
use DoubleScale\Modules\Support\Constants\TicketStatus;
use DoubleScale\Modules\Support\Models\TicketModel;
use DoubleScale\Modules\Support\Services\ContactResolver;
use DoubleScale\Modules\Support\Services\TicketService;

/**
 * Support scoping is keyed on `agent_user_id`.
 *
 * Support roles hold no sales capabilities at all, so this module exercises a
 * permission path that shares nothing with Documents — which is exactly why it
 * is in the first phase.
 */
final class SupportAbilities {

	/**
	 * Ability definitions.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$permission = array( Permissions::class, 'has_support_access' );

		return array(
			'doublescale/list-tickets'        => array(
				'module_slug'      => 'support',
				'label'            => __( 'List support tickets', 'doublescale' ),
				'description'      => __( 'List support tickets with title, status, priority, contact, and assigned agent. Unless you can manage all tickets you see only tickets assigned to you — check get-context first.', 'doublescale' ),
				'category'         => AbilityCategories::SUPPORT,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'status'     => array(
							'type'        => 'string',
							'description' => 'Filter by ticket status.',
							'enum'        => TicketStatus::all(),
						),
						'priority'   => array(
							'type'        => 'string',
							'description' => 'Filter by priority.',
							'enum'        => TicketPriority::all(),
						),
						'contact_id' => array(
							'type'        => 'integer',
							'description' => 'Only tickets opened by this contact.',
						),
						'search'     => array(
							'type'        => 'string',
							'description' => 'Match on ticket title.',
						),
						'limit'      => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'offset'     => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
				),
				'execute_callback' => array( self::class, 'list_tickets' ),
			),

			'doublescale/get-ticket'          => array(
				'module_slug'      => 'support',
				'label'            => __( 'Get support ticket', 'doublescale' ),
				'description'      => __( 'One support ticket with its status, priority, contact, agent, and reply count.', 'doublescale' ),
				'category'         => AbilityCategories::SUPPORT,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'Ticket id.',
						),
					),
					'required'   => array( 'id' ),
				),
				'execute_callback' => array( self::class, 'get_ticket' ),
			),

			'doublescale/get-ticket-thread'   => array(
				'module_slug'      => 'support',
				'label'            => __( 'Get ticket thread', 'doublescale' ),
				'description'      => __( 'Chronological replies, internal notes, and events on one ticket. Long message bodies are truncated — check the truncated flag before quoting one as complete.', 'doublescale' ),
				'category'         => AbilityCategories::SUPPORT,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'ticket_id' => array(
							'type'        => 'integer',
							'description' => 'Ticket id.',
						),
						'kinds'     => array(
							'type'        => 'array',
							'description' => 'Restrict to some entry kinds. Defaults to all.',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'reply', 'note', 'event' ),
							),
						),
						'limit'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
							'default' => 25,
						),
					),
					'required'   => array( 'ticket_id' ),
				),
				'execute_callback' => array( self::class, 'get_ticket_thread' ),
			),

			'doublescale/get-support-summary' => array(
				'module_slug'      => 'support',
				'label'            => __( 'Get support summary', 'doublescale' ),
				'description'      => __( 'Ticket counts grouped by status and priority, scoped to what you can see. If your support scope is "own", these are your tickets only.', 'doublescale' ),
				'category'         => AbilityCategories::SUPPORT,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'group_by' => array(
							'type'        => 'string',
							'description' => 'Grouping dimension. Defaults to status.',
							'enum'        => array( 'status', 'priority' ),
						),
					),
				),
				'execute_callback' => array( self::class, 'get_support_summary' ),
			),

			'doublescale/add-ticket-note'     => array(
				'module_slug'      => 'support',
				'label'            => __( 'Add an internal note to a ticket', 'doublescale' ),
				'description'      => __( 'Append an internal note to a ticket. Notes are staff-only — the customer never sees them and no email is sent. Use this to record context; use reply-to-ticket to actually answer the customer.', 'doublescale' ),
				'category'         => AbilityCategories::SUPPORT,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'ticket_id' => array(
							'type'        => 'integer',
							'description' => 'Ticket to note against.',
						),
						'content'   => array(
							'type'        => 'string',
							'description' => 'The note text.',
						),
					),
					'required'   => array( 'ticket_id', 'content' ),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						'destructive'   => false,
						// Calling twice appends two notes.
						'idempotent'    => false,
						'openWorldHint' => true,
					),
				),
				'execute_callback' => array( self::class, 'add_ticket_note' ),
			),

			'doublescale/reply-to-ticket'     => array(
				'module_slug'      => 'support',
				'label'            => __( 'Reply to a support ticket', 'doublescale' ),
				'description'      => __( 'Send a reply to the customer on a support ticket. THIS EMAILS THE CUSTOMER IMMEDIATELY and the reply cannot be edited or recalled, so only call it when the user has asked you to send a specific reply — never to draft, test, or check formatting. For staff-only context use add-ticket-note instead.', 'doublescale' ),
				'category'         => AbilityCategories::SUPPORT,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'ticket_id' => array(
							'type'        => 'integer',
							'description' => 'Ticket to reply on.',
						),
						'content'   => array(
							'type'        => 'string',
							'description' => 'The reply the customer will receive.',
						),
					),
					'required'   => array( 'ticket_id', 'content' ),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						// Nothing is overwritten or removed, but the message
						// leaves the building and cannot be taken back.
						'destructive'   => false,
						'idempotent'    => false,
						'openWorldHint' => true,
					),
				),
				'execute_callback' => array( self::class, 'reply_to_ticket' ),
			),

			'doublescale/update-ticket'       => array(
				'module_slug'      => 'support',
				'label'            => __( 'Update a support ticket', 'doublescale' ),
				'description'      => __( 'Change a ticket\'s status, priority, or assigned agent. Reassigning notifies the new agent. The ticket title and the customer it belongs to are deliberately not editable here.', 'doublescale' ),
				'category'         => AbilityCategories::SUPPORT,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'ticket_id'     => array(
							'type'        => 'integer',
							'description' => 'Ticket id.',
						),
						'status'        => array(
							'type'        => 'string',
							'description' => 'New status.',
							'enum'        => TicketStatus::all(),
						),
						'priority'      => array(
							'type'        => 'string',
							'description' => 'New priority.',
							'enum'        => TicketPriority::all(),
						),
						'agent_user_id' => array(
							'type'        => 'integer',
							'description' => 'WordPress user id of the agent to assign. Requires permission to manage all tickets.',
						),
					),
					'required'   => array( 'ticket_id' ),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						'destructive'   => false,
						'idempotent'    => true,
						'openWorldHint' => true,
					),
				),
				'execute_callback' => array( self::class, 'update_ticket' ),
			),

			'doublescale/create-ticket'       => array(
				'module_slug'      => 'support',
				'label'            => __( 'Create a support ticket', 'doublescale' ),
				'description'      => __( 'Open a new support ticket for an existing contact. Requires title, content, and contact_id. The default mailbox is used — this tool does not accept a mailbox, recipient, CC, or email address. May email the customer a confirmation if that notification is enabled on this site. The ticket is assigned to you.', 'doublescale' ),
				'category'         => AbilityCategories::SUPPORT,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'contact_id' => array(
							'type'        => 'integer',
							'description' => 'Existing contact the ticket belongs to.',
						),
						'title'      => array(
							'type'        => 'string',
							'description' => 'Ticket title.',
						),
						'content'    => array(
							'type'        => 'string',
							'description' => 'Opening message recorded on the ticket.',
						),
						'priority'   => array(
							'type'        => 'string',
							'description' => 'Priority. Defaults to normal.',
							'enum'        => TicketPriority::all(),
						),
					),
					'required'   => array( 'contact_id', 'title', 'content' ),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						'destructive'   => false,
						'idempotent'    => false,
						'openWorldHint' => true,
					),
				),
				'execute_callback' => array( self::class, 'create_ticket' ),
			),
		);
	}

	/**
	 * Whether the caller sees every ticket or only their own.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private static function sees_all_tickets(): bool {
		return Permissions::can_manage_all_tickets();
	}

	/**
	 * List tickets, scoped by agent_user_id.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_tickets( array $input ): array {
		$limit  = AbilityResult::limit( $input );
		$offset = AbilityResult::offset( $input );

		$query = TicketModel::query()->with( array( 'contact', 'agent' ) );

		if ( ! empty( $input['status'] ) ) {
			$query->where( 'status', (string) $input['status'] );
		}
		if ( ! empty( $input['priority'] ) ) {
			$query->where( 'priority', (string) $input['priority'] );
		}
		if ( ! empty( $input['contact_id'] ) ) {
			$query->where( 'contact_id', (int) $input['contact_id'] );
		}

		$search = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';
		if ( '' !== $search ) {
			$query->where( 'title', 'LIKE', '%' . $search . '%' );
		}

		// Applied LAST and unconditionally, so no caller-supplied filter can
		// widen it. Mirrors RestTicketController's scope clause.
		AbilityScope::apply( $query, 'agent_user_id', self::sees_all_tickets() );

		$total = (int) $query->count();

		$rows = $query->orderBy( 'created_at', 'desc' )
			->limit( $limit )
			->offset( $offset )
			->get();

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::shape_ticket( $row );
		}

		return AbilityResult::collection(
			$items,
			$total,
			$limit,
			$offset,
			array( 'scope' => AbilityScope::label( self::sees_all_tickets() ) )
		);
	}

	/**
	 * Get one ticket.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_ticket( array $input ) {
		$ticket = self::resolve_ticket( isset( $input['id'] ) ? (int) $input['id'] : 0 );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		return self::shape_ticket( $ticket );
	}

	/**
	 * Get the conversation thread for one ticket.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_ticket_thread( array $input ) {
		$ticket = self::resolve_ticket( isset( $input['ticket_id'] ) ? (int) $input['ticket_id'] : 0 );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		$limit = AbilityResult::limit( $input, 25, 50 );
		$types = self::resolve_kinds( $input['kinds'] ?? null );

		$query = ActivityModel::forTicket( $ticket->id )
			->whereIn( 'activity_type', $types )
			->with( 'user' );

		$total = (int) $query->count();

		$rows = $query->orderBy( 'activity_date', 'desc' )
			->limit( $limit )
			->get();

		$items = array();
		foreach ( $rows as $row ) {
			$body = self::extract_body( $row );

			$items[] = array(
				'id'        => (int) $row->id,
				'kind'      => self::kind_for_type( (string) $row->activity_type ),
				'author'    => is_object( $row->user ?? null ) ? $row->user->display_name : null,
				'date'      => $row->activity_date,
				'body'      => $body['text'],
				'truncated' => $body['truncated'],
			);
		}

		// Oldest first reads better as a conversation.
		$items = array_reverse( $items );

		return array(
			'ticket_id' => (int) $ticket->id,
			'title'     => self::decode_subject( (string) $ticket->title ),
			'items'     => $items,
			'total'     => $total,
			'limit'     => $limit,
			'has_more'  => $total > count( $items ),
		);
	}

	/**
	 * Ticket counts grouped by status or priority.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function get_support_summary( array $input ): array {
		$group_by = isset( $input['group_by'] ) && 'priority' === $input['group_by'] ? 'priority' : 'status';

		$query = TicketModel::query();

		AbilityScope::apply( $query, 'agent_user_id', self::sees_all_tickets() );

		$counts = array();
		$total  = 0;

		foreach ( $query->get() as $row ) {
			$key = (string) $row->{$group_by};
			if ( ! isset( $counts[ $key ] ) ) {
				$counts[ $key ] = 0;
			}
			++$counts[ $key ];
			++$total;
		}

		return array(
			'group_by' => $group_by,
			'total'    => $total,
			'groups'   => $counts,
			'scope'    => AbilityScope::label( self::sees_all_tickets() ),
		);
	}

	/**
	 * Add a staff-only note.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function add_ticket_note( array $input ) {
		return self::append_to_thread( $input, 'note' );
	}

	/**
	 * Send a customer-visible reply.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function reply_to_ticket( array $input ) {
		return self::append_to_thread( $input, 'reply' );
	}

	/**
	 * Shared path for note and reply — identical except for which service
	 * method runs, and whether the customer gets an email.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @param string               $kind  'note' or 'reply'.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function append_to_thread( array $input, string $kind ) {
		$invalid = AbilityInput::first_error(
			array(
				AbilityInput::required( $input, array( 'ticket_id', 'content' ) ),
				AbilityInput::id( $input['ticket_id'] ?? null, 'ticket_id' ),
			)
		);
		if ( $invalid ) {
			return $invalid;
		}

		$ticket = self::resolve_ticket( (int) $input['ticket_id'] );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		$service = self::ticket_service();
		$payload = array(
			'content' => (string) $input['content'],
			'source'  => 'web',
		);

		// The service sanitises content, records the activity, and fires the
		// hook EmailNotifications listens on. Going through it rather than
		// writing the activity directly is what makes an agent reply behave
		// exactly like an agent reply typed in the dashboard.
		$activity = 'reply' === $kind
			? $service->add_reply( $ticket, $payload )
			: $service->add_note( $ticket, $payload );

		if ( is_wp_error( $activity ) ) {
			return $activity;
		}

		return array(
			'created'     => true,
			'ticket_id'   => (int) $ticket->id,
			'activity_id' => (int) $activity->id,
			'kind'        => $kind,
			// Stated explicitly so the agent can tell the user whether the
			// customer has been contacted.
			'emailed_customer' => 'reply' === $kind,
		);
	}

	/**
	 * Update status, priority, or assignee.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function update_ticket( array $input ) {
		$invalid = AbilityInput::first_error(
			array(
				AbilityInput::required( $input, array( 'ticket_id' ) ),
				AbilityInput::id( $input['ticket_id'] ?? null, 'ticket_id' ),
				AbilityInput::id( $input['agent_user_id'] ?? null, 'agent_user_id' ),
				AbilityInput::enum( $input['status'] ?? null, TicketStatus::all(), 'status' ),
				AbilityInput::enum( $input['priority'] ?? null, TicketPriority::all(), 'priority' ),
			)
		);
		if ( $invalid ) {
			return $invalid;
		}

		$ticket = self::resolve_ticket( (int) $input['ticket_id'] );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		$updates = array();
		foreach ( array( 'status', 'priority' ) as $field ) {
			if ( isset( $input[ $field ] ) && '' !== $input[ $field ] ) {
				$updates[ $field ] = (string) $input[ $field ];
			}
		}

		if ( ! empty( $input['agent_user_id'] ) ) {
			// Handing a ticket to someone else is a management action, distinct
			// from working the tickets you already hold.
			if ( ! Permissions::can_manage_all_tickets() ) {
				return AbilityResult::forbidden(
					__( 'You do not have permission to reassign tickets.', 'doublescale' )
				);
			}
			$updates['agent_user_id'] = (int) $input['agent_user_id'];
		}

		if ( array() === $updates ) {
			return new \WP_Error(
				'doublescale_nothing_to_update',
				__( 'Provide at least one of status, priority, or agent_user_id.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$result = self::ticket_service()->update_ticket( $ticket, $updates );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'updated'   => true,
			'ticket_id' => (int) $ticket->id,
			'changed'   => array_keys( $updates ),
		);
	}

	/**
	 * Open a ticket for an existing contact.
	 *
	 * Delegates to {@see TicketService::create_ticket()} so mailbox resolution,
	 * the opening message, and the created hook match the dashboard.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function create_ticket( array $input ) {
		$invalid = AbilityInput::first_error(
			array(
				AbilityInput::required( $input, array( 'contact_id', 'title', 'content' ) ),
				AbilityInput::id( $input['contact_id'] ?? null, 'contact_id' ),
				AbilityInput::enum( $input['priority'] ?? null, TicketPriority::all(), 'priority' ),
			)
		);
		if ( $invalid ) {
			return $invalid;
		}

		$result = self::ticket_service()->create_ticket(
			array(
				'contact_id'    => (int) $input['contact_id'],
				'title'         => (string) $input['title'],
				'content'       => (string) $input['content'],
				'priority'      => isset( $input['priority'] ) ? (string) $input['priority'] : TicketPriority::NORMAL,
				'agent_user_id' => get_current_user_id(),
				'source'        => 'web',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$shaped = self::shape_ticket( $result );
		$shaped['created'] = true;

		return $shaped;
	}

	/**
	 * Build the ticket service, honouring the same override filter the REST
	 * controller respects so tests can inject a double.
	 *
	 * @since 1.0.0
	 *
	 * @return TicketService
	 */
	private static function ticket_service(): TicketService {
		$override = apply_filters( 'doublescale_support_ticket_service_instance', null );
		if ( $override instanceof TicketService ) {
			return $override;
		}

		return new TicketService( new ContactResolver() );
	}

	/**
	 * Load a ticket and enforce Gate 3.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Ticket id.
	 * @return TicketModel|\WP_Error
	 */
	private static function resolve_ticket( int $id ) {
		if ( $id <= 0 ) {
			return AbilityResult::not_found( __( 'Provide a valid ticket id.', 'doublescale' ) );
		}

		$ticket = TicketModel::query()->with( array( 'contact', 'agent' ) )->where( 'id', $id )->first();
		if ( ! $ticket ) {
			return AbilityResult::not_found( __( 'No ticket found with that id.', 'doublescale' ) );
		}

		$forbidden = AbilityScope::assert_owns(
			$ticket,
			'agent_user_id',
			self::sees_all_tickets(),
			__( 'This ticket is not assigned to you.', 'doublescale' )
		);
		if ( $forbidden ) {
			return $forbidden;
		}

		return $ticket;
	}

	/**
	 * Map requested kind names to activity types.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $kinds Requested kinds.
	 * @return array<int, string>
	 */
	private static function resolve_kinds( $kinds ): array {
		$map = self::kind_map();

		if ( ! is_array( $kinds ) || array() === $kinds ) {
			return array_values( $map );
		}

		$allowed = array();
		foreach ( $kinds as $kind ) {
			$kind = strtolower( trim( (string) $kind ) );
			if ( isset( $map[ $kind ] ) ) {
				$allowed[] = $map[ $kind ];
			}
		}

		return array() === $allowed ? array_values( $map ) : $allowed;
	}

	/**
	 * Kind name => activity type.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	private static function kind_map(): array {
		return array(
			'reply' => ActivityTypes::SUPPORT_REPLY,
			'note'  => ActivityTypes::SUPPORT_NOTE,
			'event' => ActivityTypes::SUPPORT_EVENT,
		);
	}

	/**
	 * Activity type => kind name.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type Activity type.
	 * @return string
	 */
	private static function kind_for_type( string $type ): string {
		$flipped = array_flip( self::kind_map() );
		return $flipped[ $type ] ?? 'event';
	}

	/**
	 * Pull the human-readable body out of an activity's data payload.
	 *
	 * @since 1.0.0
	 *
	 * @param object $activity Activity row.
	 * @return array{text: string, truncated: bool}
	 */
	private static function extract_body( $activity ): array {
		$data = $activity->data ?? array();
		if ( is_string( $data ) ) {
			$data = json_decode( $data, true );
		}
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$body = '';
		foreach ( array( 'content', 'message', 'body', 'note', 'description' ) as $key ) {
			if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				$body = $data[ $key ];
				break;
			}
		}

		return AbilityResult::truncate( $body );
	}

	/**
	 * Decode an RFC 2047 encoded-word subject line.
	 *
	 * Tickets created from inbound email store the raw header, so a non-ASCII
	 * subject reaches us as "=?UTF-8?B?...?=". Passing that through would give
	 * an agent an unreadable title it cannot match, summarise, or quote back.
	 *
	 * @since 1.0.0
	 *
	 * @param string $subject Stored ticket title.
	 * @return string
	 */
	private static function decode_subject( string $subject ): string {
		if ( '' === $subject || false === strpos( $subject, '=?' ) ) {
			return $subject;
		}
		if ( ! function_exists( 'mb_decode_mimeheader' ) ) {
			return $subject;
		}

		$decoded = mb_decode_mimeheader( $subject );

		return '' !== trim( $decoded ) ? $decoded : $subject;
	}

	/**
	 * Shape a ticket row.
	 *
	 * @since 1.0.0
	 *
	 * @param object $ticket Ticket.
	 * @return array<string, mixed>
	 */
	private static function shape_ticket( $ticket ): array {
		$contact = $ticket->contact ?? null;
		$agent   = $ticket->agent ?? null;

		$contact_name = '';
		if ( is_object( $contact ) ) {
			$contact_name = trim( (string) $contact->first_name . ' ' . (string) $contact->last_name );
			if ( '' === $contact_name ) {
				$contact_name = (string) $contact->email;
			}
		}

		return array(
			'id'             => (int) $ticket->id,
			'title'          => self::decode_subject( (string) $ticket->title ),
			'status'         => $ticket->status,
			'priority'       => $ticket->priority,
			'contact'        => is_object( $contact )
				? array(
					'id'    => (int) $contact->id,
					'name'  => $contact_name,
					'email' => $contact->email,
				)
				: null,
			'agent'          => is_object( $agent )
				? array(
					'id'   => (int) $ticket->agent_user_id,
					'name' => $agent->display_name,
				)
				: null,
			'response_count' => (int) $ticket->response_count,
			'created_at'     => $ticket->created_at,
		);
	}
}
