<?php

/**
 * Class WaitingListHandler
 *
 * Handles waiting list notifications when bookings are cancelled.
 * Pro-only: notifies waitlisted customers when an active booking
 * is cancelled and a slot becomes available.
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Booking\Services;

use DoubleScale\Modules\Booking\Models\BookingModel;
use DoubleScale\Modules\Booking\Services\BookingEvents;
use DoubleScale\Modules\Booking\Traits\Singleton;

/**
 * WaitingListHandler class.
 */
class WaitingListHandler {

	use Singleton;

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'doublescale_booking_cancelled', array( $this, 'handle_cancellation' ), 100, 2 );
	}

	/**
	 * When an active booking is cancelled, notify waitlisted customers
	 * for the same slot.
	 *
	 * @param BookingModel|int $booking The cancelled booking (model from EventBus
	 *                                   tail-hook, or id from legacy callers).
	 * @param array            $context Lifecycle context (unused; signature
	 *                                   matches BookingEvents contract).
	 */
	public function handle_cancellation( $booking, $context = array() ) {
		if ( is_numeric( $booking ) ) {
			$booking = BookingModel::find( (int) $booking );
		}
		if ( ! $booking instanceof BookingModel ) {
			return;
		}

		if ( 'cancelled' !== $booking->status ) {
			return;
		}

		$was_waiting = $booking->get_meta( 'waiting_list_position' );
		if ( $was_waiting ) {
			return;
		}

		$entity = $booking->getBookableEntity();
		if ( ! $entity ) {
			// Bookings without a resolvable entity (e.g. service-only flows
			// the core BookingModel doesn't yet expose) are silently a
			// no-op today. Log so this isn't invisible if/when it matters.
			if ( function_exists( '\\doublescale_get_logger' ) ) {
				\doublescale_get_logger()->info(
					'Waiting-list handler: no bookable entity, skipping',
					array(
						'source'     => 'booking-waitlist',
						'booking_id' => (int) $booking->id,
						'event_id'   => (int) $booking->event_id,
					)
				);
			}
			return;
		}

		$wl_settings = $entity->waiting_list_settings;
		if ( empty( $wl_settings['auto_notify'] ) ) {
			return;
		}

		// Lock per (event, start, end) slot so two cancellations on the same
		// physical slot don't re-emit availability twice within a 5-minute
		// window. Hashing keeps the option_name short and stable regardless of
		// MySQL datetime formatting.
		$lock_key = 'wl_notify_' . substr(
			md5( $booking->event_id . '|' . $booking->start_time . '|' . $booking->end_time ),
			0,
			32
		);
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, true, 300 );

		// getBookableEntity() above guarantees event_id is non-null here.
		$waitlisted_bookings = BookingModel::where( 'status', 'waiting' )
			->where( 'start_time', $booking->start_time )
			->where( 'end_time', $booking->end_time )
			->where( 'event_id', $booking->event_id )
			->orderBy( 'created_at', 'asc' )
			->get();

		if ( $waitlisted_bookings->isEmpty() ) {
			return;
		}

		foreach ( $waitlisted_bookings as $wl_booking ) {
			BookingEvents::emit( 'waiting_list_available', (int) $wl_booking->id );
		}
	}
}
