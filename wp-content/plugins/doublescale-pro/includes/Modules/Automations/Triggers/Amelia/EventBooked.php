<?php
/**
 * Amelia "Event Booked" trigger.
 *
 * Fires from {@see BookingWatcher} (doublescale_amelia_booking_created).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Amelia;

defined( 'ABSPATH' ) || exit;

/**
 * EventBooked trigger
 */
class EventBooked extends AbstractAmeliaTrigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Event Booked';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'amelia_event_booked';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a customer books a place in an Amelia event.';

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
	protected $watch_hook = 'doublescale_amelia_booking_created';
}
