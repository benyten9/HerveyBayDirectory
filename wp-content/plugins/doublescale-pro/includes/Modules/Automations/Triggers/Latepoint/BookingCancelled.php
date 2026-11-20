<?php
/**
 * LatePoint "Appointment Canceled" trigger.
 *
 * Fires from {@see BookingEvents} (doublescale_latepoint_booking_status_changed).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Latepoint;

defined( 'ABSPATH' ) || exit;

/**
 * BookingCancelled trigger
 */
class BookingCancelled extends AbstractLatepointTrigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Booking Cancelled';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'latepoint_booking_cancelled';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a LatePoint booking is cancelled.';

	/**
	 * The watcher hook this trigger listens to.
	 *
	 * @var string
	 */
	protected $watch_hook = 'doublescale_latepoint_booking_status_changed';

	/**
	 * Only a change to "cancelled".
	 *
	 * @param array<string, mixed> $booking Booking.
	 * @param mixed                $first   Old status.
	 * @param mixed                $second  New status.
	 * @return array<string, mixed>|null
	 */
	protected function extra_data( array $booking, $first, $second ): ?array {
		if ( 'cancelled' !== $second ) {
			return null;
		}

		return array( 'old_status' => (string) $first );
	}
}
