<?php
/**
 * Amelia "Appointment Canceled" trigger.
 *
 * Fires from {@see BookingWatcher} (doublescale_amelia_booking_status_changed).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Amelia;

defined( 'ABSPATH' ) || exit;

/**
 * AppointmentCanceled trigger
 */
class AppointmentCanceled extends AbstractAmeliaTrigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Appointment Canceled';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'amelia_appointment_canceled';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when an Amelia appointment booking is canceled.';

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
	protected $watch_hook = 'doublescale_amelia_booking_status_changed';

	/**
	 * Only a change to "canceled".
	 *
	 * @param array<string, mixed> $booking Booking.
	 * @param mixed                $first   Old status.
	 * @param mixed                $second  New status.
	 * @return array<string, mixed>|null
	 */
	protected function extra_data( array $booking, $first, $second ): ?array {
		if ( 'canceled' !== $second ) {
			return null;
		}

		return array( 'old_status' => (string) $first );
	}
}
