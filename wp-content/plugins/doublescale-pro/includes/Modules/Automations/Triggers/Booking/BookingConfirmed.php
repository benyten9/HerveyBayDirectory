<?php
/**
 * Automation trigger: booking confirmed.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Booking;

defined( 'ABSPATH' ) || exit;

final class BookingConfirmed extends AbstractBookingLifecycleTrigger {

	protected $booking_event_suffix = 'confirmed';

	public $name = 'Booking confirmed';

	public $slug = 'booking_confirmed';

	public $description = 'Fires when a booking is confirmed.';

	public $attributes = array();
}
