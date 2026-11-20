<?php
/**
 * Amelia "Event Booking Canceled" trigger.
 *
 * Fires from {@see BookingWatcher} (doublescale_amelia_booking_status_changed).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Amelia;

defined( 'ABSPATH' ) || exit;

/**
 * EventBookingCanceled trigger
 */
class EventBookingCanceled extends AbstractAmeliaTrigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Event Booking Canceled';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'amelia_event_booking_canceled';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when an Amelia event booking is canceled.';

	/**
	 * What the trigger is about.
	 *
	 * @var string
	 */
	protected $booking_type = 'event';

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
