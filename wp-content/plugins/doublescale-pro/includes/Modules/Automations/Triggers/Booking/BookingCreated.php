<?php
/**
 * Automation trigger: booking created.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Booking;

defined( 'ABSPATH' ) || exit;

final class BookingCreated extends AbstractBookingLifecycleTrigger {

	protected $booking_event_suffix = 'created';

	public $name = 'Booking created';

	public $slug = 'booking_created';

	public $description = 'Fires when a new booking is created.';

	public $attributes = array();
}
