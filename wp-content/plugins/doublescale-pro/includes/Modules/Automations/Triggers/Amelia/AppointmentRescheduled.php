<?php
/**
 * Amelia "Appointment Rescheduled" trigger.
 *
 * Fires from {@see BookingWatcher} (doublescale_amelia_booking_rescheduled).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Amelia;

defined( 'ABSPATH' ) || exit;

/**
 * AppointmentRescheduled trigger
 */
class AppointmentRescheduled extends AbstractAmeliaTrigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Appointment Rescheduled';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'amelia_appointment_rescheduled';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when an Amelia appointment is moved to another time.';

	/**
	 * What the trigger is about.
	 *
	 * @var string
	 */
	protected $booking_type = 'appointment';

	/**
	 * The watcher hook this trigger listens to.
	 *
	 * @var string
	 */
	protected $watch_hook = 'doublescale_amelia_booking_rescheduled';

	/**
	 * The time it had before.
	 *
	 * @param array<string, mixed> $booking Booking.
	 * @param mixed                $first   Old start (UTC).
	 * @param mixed                $second  Unused.
	 * @return array<string, mixed>|null
	 */
	protected function extra_data( array $booking, $first, $second ): ?array {
		// A canceled booking moved along with its appointment is not a reschedule for that customer.
		if ( in_array( $booking['status'], array( 'canceled', 'rejected' ), true ) ) {
			return null;
		}

		return array( 'old_booking_start' => (string) $first );
	}
}
