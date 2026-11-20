<?php
/**
 * Merging a ticket into another of the same customer (Intercom style).
 *
 * Nothing moves: the merged ticket keeps its messages and becomes a closed,
 * read-only page pointing to the main one; both say so in their history. It
 * is closed straight in the table, so no "status changed" runs: the customer
 * gets no "closed" email, automations see no close, reports do not count it
 * as solved. From then on the customer's messages on it land on the main
 * ticket ({@see TicketService::add_reply()}), and the team replies there.
 *
 * Never an unverified ticket (address typed by a visitor, never proven).
 *
 * Only the same customer: another customer's ticket would show this
 * customer's messages to someone else (Intercom keeps that off by default).
 *
 * Two merges at once (A into B, B into A) never make a loop: the merge runs
 * under a lock named for this site, reading both tickets again inside it.
 *
 * @package DoubleScale\Modules\Support\Services
 */

namespace DoubleScale\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Support\Constants\TicketStatus;
use DoubleScale\Modules\Support\Models\TicketModel;
use WP_Error;

/**
 * TicketMergeService class.
 */
final class TicketMergeService {

	/**
	 * Merges in a row followed to find the main ticket.
	 */
	public const CHAIN = 5;

	/**
	 * Seconds to wait for another merge to finish.
	 */
	private const WAIT = 5;

	/**
	 * @var TicketService
	 */
	private $tickets;

	/**
	 * @param TicketService $tickets Tickets.
	 */
	public function __construct( TicketService $tickets ) {
		$this->tickets = $tickets;
	}

	/**
	 * The ticket that now holds this one's conversation (itself when not merged).
	 *
	 * @param TicketModel $ticket Ticket.
	 * @return TicketModel
	 */
	public static function follow( TicketModel $ticket ): TicketModel {
		for ( $hop = 0; $hop < self::CHAIN && (int) $ticket->merged_into_id > 0; $hop++ ) {
			$next = TicketModel::find( (int) $ticket->merged_into_id );
			if ( ! $next ) {
				break;
			}
			$ticket = $next;
		}

		return $ticket;
	}

	/**
	 * Merge $source into $target.
	 *
	 * @param TicketModel $source Ticket to merge (closed after).
	 * @param TicketModel $target Main ticket.
	 * @return array{merged: int, merged_into: int}|WP_Error
	 */
	public function merge( TicketModel $source, TicketModel $target ) {
		if ( (int) $source->id === (int) $target->id ) {
			return new WP_Error( 'merge_same', __( 'Choose another ticket to merge into.', 'doublescale' ), array( 'status' => 400 ) );
		}
		// Nobody proved the address on an unverified ticket (public form, chat
		// handoff): merging it would hand later replies on the real ticket to
		// whoever typed someone else's address.
		if ( TicketService::is_unverified( $source ) || TicketService::is_unverified( $target ) ) {
			return new WP_Error( 'merge_unverified', __( 'A ticket whose email address was never verified can’t be merged. Mark the address as verified on that ticket first, once you are sure it is the customer.', 'doublescale' ), array( 'status' => 400 ) );
		}
		if ( (int) $source->contact_id !== (int) $target->contact_id ) {
			return new WP_Error( 'merge_other_customer', __( 'Only tickets of the same customer can be merged.', 'doublescale' ), array( 'status' => 400 ) );
		}

		// Shared with a company or not: the merged ticket's next messages would
		// reach people who never saw it (colleagues), or leave those who did.
		if ( (int) $source->company_id !== (int) $target->company_id ) {
			return new WP_Error( 'merge_other_sharing', __( 'Only tickets shared with the same people can be merged.', 'doublescale' ), array( 'status' => 400 ) );
		}

		// The main ticket is copied to people the merged one was not: they would
		// get the team's answers to the merged ticket's next messages.
		if ( array() !== array_diff( self::copies( $target ), self::copies( $source ) ) ) {
			return new WP_Error( 'merge_other_copies', __( 'That ticket is copied to people this one is not. Only tickets copied to the same people can be merged.', 'doublescale' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'doublescale_support_tickets';
		// Lock names are shared by every database on the server: this one names
		// this site's tickets table.
		$lock = 'ds_merge_' . md5( DB_NAME . '|' . $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::WAIT ) ) ) {
			return new WP_Error( 'merge_busy', __( 'Another merge is running. Please try again.', 'doublescale' ), array( 'status' => 409 ) );
		}

		try {
			// Read both again inside the lock: another merge may have just run.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, merged_into_id FROM {$table} WHERE id IN (%d, %d)", (int) $source->id, (int) $target->id ), OBJECT_K );
			if ( ! isset( $rows[ (int) $source->id ], $rows[ (int) $target->id ] ) ) {
				return new WP_Error( 'not_found', __( 'Ticket not found.', 'doublescale' ), array( 'status' => 404 ) );
			}
			if ( null !== $rows[ (int) $source->id ]->merged_into_id ) {
				return new WP_Error( 'merge_already_merged', __( 'This ticket was already merged.', 'doublescale' ), array( 'status' => 400 ) );
			}
			if ( null !== $rows[ (int) $target->id ]->merged_into_id ) {
				return new WP_Error( 'merge_into_merged', __( 'That ticket was merged into another one. Choose that one instead.', 'doublescale' ), array( 'status' => 400 ) );
			}

			// Closed in the table, not through update_ticket(): no "closed" email
			// to the customer, no close for automations or reports. Whoever
			// handles the main ticket may read this one's history too.
			$sets   = 'merged_into_id = %d, status = %s, updated_at = %s';
			$values = array( (int) $target->id, TicketStatus::CLOSED, current_time( 'mysql', true ) );
			if ( (int) $target->agent_user_id > 0 ) {
				$sets    .= ', agent_user_id = %d';
				$values[] = (int) $target->agent_user_id;
			}
			$values[] = (int) $source->id;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$done = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$sets} WHERE id = %d AND merged_into_id IS NULL", $values ) );
			if ( 1 !== $done ) {
				return new WP_Error( 'merge_already_merged', __( 'This ticket was already merged.', 'doublescale' ), array( 'status' => 400 ) );
			}
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}

		$this->tickets->log_event(
			(int) $source->id,
			'merged_into',
			array(
				'into'  => (int) $target->id,
				'title' => (string) $target->title,
			)
		);
		$this->tickets->log_event(
			(int) $target->id,
			'merged_from',
			array(
				'from'  => (int) $source->id,
				'title' => (string) $source->title,
			)
		);

		$merged = TicketModel::find( (int) $source->id );

		/**
		 * Fires after a ticket was merged into another of the same customer.
		 *
		 * @param TicketModel $merged The merged ticket (closed, merged_into_id set).
		 * @param TicketModel $target The main ticket.
		 */
		do_action( 'doublescale_support_ticket_merged', $merged, $target );

		return array(
			'merged'      => (int) $source->id,
			'merged_into' => (int) $target->id,
		);
	}

	/**
	 * The people a ticket is copied to (lower case).
	 *
	 * @param TicketModel $ticket Ticket.
	 * @return string[]
	 */
	private static function copies( TicketModel $ticket ): array {
		$data = is_array( $ticket->custom_data ) ? $ticket->custom_data : array();
		$cc   = isset( $data['cc_recipients'] ) && is_array( $data['cc_recipients'] ) ? $data['cc_recipients'] : array();

		return array_values( array_unique( array_map( static fn( $email ) => strtolower( trim( (string) $email ) ), $cc ) ) );
	}

	/**
	 * Tickets $source may be merged into: the same customer's other tickets,
	 * not merged themselves, that $can_open allows; newest first.
	 *
	 * @param TicketModel $source   Ticket to merge.
	 * @param callable    $can_open fn( TicketModel ): bool.
	 * @param int         $limit    Most rows.
	 * @return array<int, array{id: int, title: string, status: string, created_at: string|null}>
	 */
	public function targets( TicketModel $source, callable $can_open, int $limit = 50 ): array {
		$out = array();
		$rows = TicketModel::query()
			->where( 'contact_id', (int) $source->contact_id )
			->where( 'id', '<>', (int) $source->id )
			->whereNull( 'merged_into_id' )
			->orderBy( 'created_at', 'desc' )
			->orderBy( 'id', 'desc' )
			->limit( 200 )
			->get();
		// Nothing to merge into for an unverified ticket, and unverified
		// tickets are never a target.
		if ( TicketService::is_unverified( $source ) ) {
			return $out;
		}
		foreach ( $rows as $ticket ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			if ( TicketService::is_unverified( $ticket ) ) {
				continue;
			}
			if ( $can_open( $ticket ) ) {
				$out[] = array(
					'id'         => (int) $ticket->id,
					'title'      => (string) $ticket->title,
					'status'     => (string) $ticket->status,
					'created_at' => $ticket->created_at ? (string) $ticket->created_at : null,
				);
			}
		}

		return $out;
	}
}
