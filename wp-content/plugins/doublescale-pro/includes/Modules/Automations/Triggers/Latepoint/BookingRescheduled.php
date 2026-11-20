<?php
/**
 * LatePoint "Appointment Rescheduled" trigger.
 *
 * Fires from {@see BookingEvents} (doublescale_latepoint_booking_rescheduled).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Latepoint;

defined( 'ABSPATH' ) || exit;

/**
 * BookingRescheduled trigger
 */
class BookingRescheduled extends AbstractLatepointTrigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Booking Rescheduled';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'latepoint_booking_rescheduled';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a LatePoint booking is moved to another time.';

	/**
	 * The watcher hook this trigger listens to.
	 *
	 * @var string
	 */
	protected $watch_hook = 'doublescale_latepoint_booking_rescheduled';

	/**
	 * The time it had before.
	 *
	 * @param array<string, mixed> $booking Booking.
	 * @param mixed                $first   Old start (UTC).
	 * @param mixed                $second  Unused.
	 * @return array<string, mixed>|null
	 */
	protected function extra_data( array $booking, $first, $second ): ?array {
		// A cancelled booking moved is not a reschedule for that customer.
		if ( 'cancelled' === $booking['status'] ) {
			return null;
		}

		return array( 'old_booking_start' => (string) $first );
	}
}
