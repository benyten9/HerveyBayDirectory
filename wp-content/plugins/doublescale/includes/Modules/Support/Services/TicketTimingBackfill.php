<?php
/**
 * Fill first_response_at / resolved_at on tickets from before those columns,
 * from each ticket's own history, in the background.
 *
 *   first_response_at  the first reply written by someone with helpdesk access
 *                      (not the opening message, not a system message)
 *   resolved_at        solved or closed tickets only: the last time the status
 *                      became solved or closed, else updated_at (an estimate)
 *
 * Walks every ticket once, oldest first, a batch at a time (a cursor option
 * remembers where it is), and queues the next batch while there is more.
 * Queued once per schema revision from the support module's boot.
 *
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;
use DoubleScale\Modules\Support\Constants\TicketStatus;

/**
 * TicketTimingBackfill class.
 */
final class TicketTimingBackfill {

	/**
	 * Background hook.
	 */
	public const HOOK = 'doublescale_support_timing_backfill';

	/**
	 * Last ticket id done.
	 */
	private const CURSOR = 'doublescale_support_timing_backfill_cursor';

	private const BATCH = 200;

	/**
	 * @return void
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	/**
	 * Start (or start again) from the first ticket.
	 *
	 * @return void
	 */
	public static function queue(): void {
		delete_option( self::CURSOR );
		self::queue_next();
	}

	/**
	 * One batch, then the next one queued while there is more.
	 *
	 * @return void
	 */
	public static function run(): void {
		if ( self::BATCH === self::run_batch( self::BATCH ) ) {
			self::queue_next();
		}
	}

	/**
	 * Fill up to $limit tickets after the cursor.
	 *
	 * @param int $limit Tickets to look at.
	 * @return int Tickets looked at (fewer than $limit: done).
	 */
	public static function run_batch( int $limit ): int {
		global $wpdb;
		$tickets = $wpdb->prefix . 'doublescale_support_tickets';
		$after   = (int) get_option( self::CURSOR, 0 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, status, first_response_at, resolved_at, updated_at FROM {$tickets} WHERE id > %d ORDER BY id ASC LIMIT %d", $after, $limit ) );

		foreach ( (array) $rows as $row ) {
			$id      = (int) $row->id;
			$changes = array();

			if ( null === $row->first_response_at ) {
				$first = self::first_team_reply( $id );
				if ( null !== $first ) {
					$changes['first_response_at'] = $first;
				}
			}
			if ( null === $row->resolved_at && in_array( (string) $row->status, array( TicketStatus::RESOLVED, TicketStatus::CLOSED ), true ) ) {
				$solved                  = self::last_solved( $id );
				$changes['resolved_at'] = null !== $solved ? $solved : (string) $row->updated_at;
			}

			if ( $changes ) {
				// updated_at kept: filling history is not an edit.
				$sets = array();
				foreach ( $changes as $column => $value ) {
					$sets[] = $wpdb->prepare( "{$column} = %s", $value ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( $wpdb->prepare( "UPDATE {$tickets} SET " . implode( ', ', $sets ) . ', updated_at = updated_at WHERE id = %d', $id ) );
			}
			update_option( self::CURSOR, $id, false );
		}

		return count( (array) $rows );
	}

	/**
	 * When someone with helpdesk access first replied (not the opening
	 * message, not a system message), or null.
	 *
	 * @param int $ticket_id Ticket.
	 * @return string|null
	 */
	private static function first_team_reply( int $ticket_id ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$replies = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.user_id, a.created_at FROM {$wpdb->prefix}doublescale_activities a
				INNER JOIN {$wpdb->prefix}doublescale_activity_associations x ON x.activity_id = a.id
				WHERE x.entity_type = %d AND x.entity_id = %d AND a.activity_type = %s AND a.user_id IS NOT NULL
					AND JSON_EXTRACT(a.data, '$.opening') IS NULL AND JSON_EXTRACT(a.data, '$.system') IS NULL
				ORDER BY a.created_at ASC, a.id ASC LIMIT 20",
				ActivityAssociationModel::ENTITY_TYPE_TICKET,
				$ticket_id,
				ActivityTypes::SUPPORT_REPLY
			)
		);
		foreach ( (array) $replies as $reply ) {
			if ( Permissions::has_support_access( (int) $reply->user_id ) ) {
				return (string) $reply->created_at;
			}
		}

		return null;
	}

	/**
	 * The last time the status became solved or closed, or null.
	 *
	 * @param int $ticket_id Ticket.
	 * @return string|null
	 */
	private static function last_solved( int $ticket_id ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$at = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT a.created_at FROM {$wpdb->prefix}doublescale_activities a
				INNER JOIN {$wpdb->prefix}doublescale_activity_associations x ON x.activity_id = a.id
				WHERE x.entity_type = %d AND x.entity_id = %d AND a.activity_type = %s
					AND JSON_UNQUOTE(JSON_EXTRACT(a.data, '$.event_key')) = 'status_changed'
					AND JSON_UNQUOTE(JSON_EXTRACT(a.data, '$.to')) IN (%s, %s)
				ORDER BY a.created_at DESC, a.id DESC LIMIT 1",
				ActivityAssociationModel::ENTITY_TYPE_TICKET,
				$ticket_id,
				ActivityTypes::SUPPORT_EVENT,
				TicketStatus::RESOLVED,
				TicketStatus::CLOSED
			)
		);

		return null === $at ? null : (string) $at;
	}

	/**
	 * Queue the next batch. Before init (the support module boots earlier)
	 * the scheduler is not ready and drops the request without a word, so
	 * it waits for init.
	 *
	 * @return void
	 */
	private static function queue_next(): void {
		if ( ! did_action( 'init' ) ) {
			add_action( 'init', array( self::class, 'enqueue_now' ), 20 );
			return;
		}
		self::enqueue_now();
	}

	/**
	 * @return void
	 */
	public static function enqueue_now(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array(), 'doublescale' );
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::HOOK );
		}
	}
}
