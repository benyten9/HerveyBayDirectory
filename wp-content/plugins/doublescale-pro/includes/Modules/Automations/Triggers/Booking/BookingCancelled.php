<?php
/**
 * Automation trigger: booking cancelled.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Booking;

defined( 'ABSPATH' ) || exit;

final class BookingCancelled extends AbstractBookingLifecycleTrigger {

	protected $booking_event_suffix = 'cancelled';

	public $name = 'Booking cancelled';

	public $slug = 'booking_cancelled';

	public $description = 'Fires when a booking is cancelled.';

	public $attributes = array();
}
