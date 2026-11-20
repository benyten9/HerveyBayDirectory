<?php
/**
 * Amelia "Appointment Booked" trigger.
 *
 * Fires from {@see BookingWatcher} (doublescale_amelia_booking_created).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Amelia;

defined( 'ABSPATH' ) || exit;

/**
 * AppointmentBooked trigger
 */
class AppointmentBooked extends AbstractAmeliaTrigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Appointment Booked';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'amelia_appointment_booked';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a customer books an Amelia appointment.';

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
	protected $watch_hook = 'doublescale_amelia_booking_created';
}
