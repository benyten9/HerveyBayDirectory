<?php
/**
 * Automation trigger: booking completed.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Booking;

defined( 'ABSPATH' ) || exit;

final class BookingCompleted extends AbstractBookingLifecycleTrigger {

	protected $booking_event_suffix = 'completed';

	public $name = 'Booking completed';

	public $slug = 'booking_completed';

	public $description = 'Fires when a booking is marked completed.';

	public $attributes = array();
}
