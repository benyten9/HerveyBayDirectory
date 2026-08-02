<?php
/**
 * Automation trigger: booking rescheduled.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Booking;

defined( 'ABSPATH' ) || exit;

final class BookingRescheduled extends AbstractBookingLifecycleTrigger {

	protected $booking_event_suffix = 'rescheduled';

	public $name = 'Booking rescheduled';

	public $slug = 'booking_rescheduled';

	public $description = 'Fires when a booking is rescheduled.';

	public $attributes = array();
}
