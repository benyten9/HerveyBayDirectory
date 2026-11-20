<?php
/**
 * LatePoint "Appointment Booked" trigger.
 *
 * Fires from {@see BookingEvents} (doublescale_latepoint_booking_created).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Latepoint;

defined( 'ABSPATH' ) || exit;

/**
 * BookingCreated trigger
 */
class BookingCreated extends AbstractLatepointTrigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Booking Created';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'latepoint_booking_created';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a LatePoint appointment is booked.';

	/**
	 * The watcher hook this trigger listens to.
	 *
	 * @var string
	 */
	protected $watch_hook = 'doublescale_latepoint_booking_created';
}
