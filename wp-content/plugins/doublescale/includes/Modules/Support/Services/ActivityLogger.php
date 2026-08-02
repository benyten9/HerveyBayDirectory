<?php
/**
 * ActivityLogger — turns ticket-lifecycle domain events into
 * `activity_type='support_event'` rows so the ticket timeline shows a
 * human-readable audit trail.
 *
 * Listens to:
 *   - doublescale_support_ticket_created (logs a genesis "ticket_created" row)
 *   - doublescale_support_ticket_updated (one row per meaningful field change)
 *
 * Does NOT listen to:
 *   - reply / note created — those activities ARE the conversation rows;
 *     double-logging them would just duplicate the timeline.
 *   - ticket deleted — by the time the action fires the associations are gone
 *     (the model's `deleting` cascade detaches them), so there is nothing left
 *     to link an activity to. If a future audit-log table needs deletion
 *     records, it can listen to `doublescale_support_ticket_deleted` directly
 *     from TicketService — no shim needed here.
 *
 * Each system activity stores a structured payload under `data`:
 *   {
 *     "ticket_id": 42,
 *     "event_key": "status_changed",
 *     "field":     "status",
 *     "from":      "open",
 *     "to":        "resolved"
 *   }
 *
 * Renderers project that into a localized string at display time so message
 * formatting stays a frontend concern (and admins can swap labels per locale
 * without a data migration).
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\TagModel;
use DoubleScale\Modules\Support\Models\TicketModel;

/**
 * ActivityLogger class.
 */
class ActivityLogger {

	/**
	 * @var TicketService
	 */
	private $tickets;

	/**
	 * @param TicketService $tickets Used to write SUPPORT_EVENT activities.
	 */
	public function __construct( TicketService $tickets ) {
		$this->tickets = $tickets;
	}

	/**
	 * Register WP action listeners. Called from {@see Module::boot()} so the
	 * hooks are registered before any ticket workflow can fire them.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'doublescale_support_ticket_created', array( $this, 'on_ticket_created' ), 10, 1 );
		add_action( 'doublescale_support_ticket_updated', array( $this, 'on_ticket_updated' ), 10, 3 );
	}

	/**
	 * Ticket creation — log the genesis event so the timeline carries a
	 * system row marking ticket creation alongside the opening message.
	 *
	 * Ordering note: this listener runs AFTER the create_ticket transaction
	 * commits, so the SUPPORT_EVENT row's `created_at` is strictly later than
	 * the SUPPORT_REPLY opening message's `created_at`. With chronological
	 * (`asc`) ordering in {@see ActivityModel::scopeForTicket}, the opening
	 * message appears first and the "created" row appears just below it. This
	 * is intentional — the customer's words lead the thread; the audit row is
	 * supporting context.
	 *
	 * @param TicketModel $ticket Created ticket.
	 */
	public function on_ticket_created( $ticket ): void {
		if ( ! $ticket instanceof TicketModel ) {
			return;
		}

		$this->tickets->log_event(
			$ticket,
			'ticket_created',
			array(
				'status'        => $ticket->status,
				'priority'      => $ticket->priority,
				'mailbox_id'    => $ticket->mailbox_id,
				'agent_user_id' => $ticket->agent_user_id,
			)
		);
	}

	/**
	 * Ticket update — one system activity per meaningful field change.
	 *
	 * Splitting per-field (rather than one row with a diff blob) makes
	 * timeline filtering trivial — "show me all status changes for this
	 * ticket" is a single WHERE clause on `data.event_key`.
	 *
	 * @param TicketModel $ticket  Updated ticket.
	 * @param array       $updates Effective field deltas (post-normalization), new values.
	 * @param array       $before  Same keys, pre-save values — passed by {@see TicketService::update_ticket()}.
	 */
	public function on_ticket_updated( $ticket, $updates, $before = array() ): void {
		if ( ! $ticket instanceof TicketModel || ! is_array( $updates ) ) {
			return;
		}
		if ( ! is_array( $before ) ) {
			$before = array();
		}

		foreach ( $updates as $field => $new_value ) {
			$event_key = $this->event_key_for_field( $field );
			if ( null === $event_key ) {
				continue; // We don't log every column — only workflow-meaningful ones.
			}

			$payload = array(
				'field' => $field,
				'from'  => array_key_exists( $field, $before ) ? $before[ $field ] : null,
				'to'    => $new_value,
			);

			if ( 'tag_ids' === $field ) {
				$payload = array_merge(
					$payload,
					$this->tag_change_summary(
						$payload['from'],
						$payload['to']
					)
				);
			}

			$this->tickets->log_event( $ticket, $event_key, $payload );
		}
	}

	/**
	 * Map a ticket column to a stable `event_key` we want to record on the
	 * timeline. Columns not in this map (`custom_data`, `product`) are too
	 * freeform to deserve their own timeline row.
	 *
	 * `mailbox_id` IS logged: a department transfer is one of the
	 * highest-signal moves in a helpdesk workflow (changes who sees the
	 * ticket in their inbox), so we surface it here.
	 *
	 * @param string $field Column name.
	 * @return string|null Event key, or null to skip logging.
	 */
	private function event_key_for_field( string $field ): ?string {
		switch ( $field ) {
			case 'status':
				return 'status_changed';
			case 'priority':
				return 'priority_changed';
			case 'agent_user_id':
				return 'agent_changed';
			case 'mailbox_id':
				return 'mailbox_changed';
			case 'title':
				return 'title_changed';
			case 'tag_ids':
				return 'tags_changed';
			default:
				return null;
		}
	}

	/**
	 * Build a concise added/removed tag summary for a `tags_changed` event.
	 *
	 * @param mixed $from Previous `tag_ids` value.
	 * @param mixed $to   New `tag_ids` value.
	 * @return array{added: string[], removed: string[]}
	 */
	private function tag_change_summary( $from, $to ): array {
		$from_ids = is_array( $from ) ? array_values( array_map( 'intval', $from ) ) : array();
		$to_ids   = is_array( $to ) ? array_values( array_map( 'intval', $to ) ) : array();

		$added_ids   = array_values( array_diff( $to_ids, $from_ids ) );
		$removed_ids = array_values( array_diff( $from_ids, $to_ids ) );
		$lookup_ids  = array_values( array_unique( array_merge( $added_ids, $removed_ids ) ) );

		$names_by_id = array();
		if ( ! empty( $lookup_ids ) ) {
			$names_by_id = TagModel::query()
				->whereIn( 'id', $lookup_ids )
				->pluck( 'name', 'id' )
				->all();
		}

		$resolve = static function ( array $ids ) use ( $names_by_id ): array {
			$labels = array();
			foreach ( $ids as $id ) {
				$labels[] = isset( $names_by_id[ $id ] ) ? (string) $names_by_id[ $id ] : (string) $id;
			}
			return $labels;
		};

		return array(
			'added'   => $resolve( $added_ids ),
			'removed' => $resolve( $removed_ids ),
		);
	}
}
