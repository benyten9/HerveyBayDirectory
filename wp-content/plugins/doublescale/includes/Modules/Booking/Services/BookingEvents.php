<?php
/**
 * Booking lifecycle event emission helper.
 *
 * Single emission API for every booking lifecycle event. Internally delegates
 * to {@see EventBus}, which handles handler resolution, idempotency keying
 * via the `workflow_runs` table, and a 5-minute cron retry sweeper. The bus
 * also fires a bare `doublescale_booking_{event}` action after structured
 * handlers run, so existing integration and notification subscribers keep
 * working without registering with the bus directly.
 *
 * Lifecycle events:
 *   - First-class (have structured handlers in {@see EventBus::get_default_handlers()}):
 *     `created`, `confirmed`, `cancelled`, `rescheduled`, `pending`.
 *   - Tail-hook only (dispatched but no first-party handlers — third-party
 *     listeners can subscribe via `do_action`): `completed`, `rejected`,
 *     `waiting_list_joined`, `waiting_list_available`.
 *
 * Hook contracts (post-dispatch, fired for every event regardless of class):
 *   do_action( "doublescale_booking_{$event}", BookingModel $booking, array $context )
 *
 * Note: emission reloads the booking via {@see BookingModel::find()}. For a hard
 * delete, fire cancellation from a {@see BookingModel} `deleting` hook (before the
 * row is removed), not `deleted`, or subscribers that need DB state will not run.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Services;

use DoubleScale\Modules\Booking\Models\BookingModel;

defined( 'ABSPATH' ) || exit;

final class BookingEvents {

	/**
	 * Emit a booking lifecycle event.
	 *
	 * @param string $event      Lifecycle event name (e.g. `created`, `cancelled`).
	 * @param int    $booking_id Booking row primary key.
	 * @param array  $context    Optional context bag (e.g. `['actor' => 'organizer']`).
	 */
	public static function emit( string $event, int $booking_id, array $context = array() ): void {
		$booking = BookingModel::find( $booking_id );
		if ( ! $booking ) {
			return;
		}

		EventBus::dispatch( "booking.{$event}", $booking, $context );
	}
}
