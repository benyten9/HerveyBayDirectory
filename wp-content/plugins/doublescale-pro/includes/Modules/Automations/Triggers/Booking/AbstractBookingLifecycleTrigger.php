<?php
/**
 * Base class for booking lifecycle automation triggers (listens on {@see EventBus} tail hooks).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Booking;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Booking\Models\BookingModel;

defined( 'ABSPATH' ) || exit;

abstract class AbstractBookingLifecycleTrigger extends Trigger {

	/**
	 * Event suffix for `doublescale_booking_{suffix}` (e.g. `created`, `confirmed`).
	 *
	 * @var string
	 */
	protected $booking_event_suffix = '';

	public $source = 'booking';

	public $group = 'booking';

	public function load_hooks(): void {
		if ( '' === $this->booking_event_suffix ) {
			return;
		}
		add_action(
			'doublescale_booking_' . $this->booking_event_suffix,
			array( $this, 'handle_booking_event' ),
			10,
			2
		);
	}

	/**
	 * @param mixed $booking  {@see BookingModel} instance.
	 * @param mixed $context Optional context bag from the booking event bus.
	 */
	public function handle_booking_event( $booking, $context = array() ): void {
		if ( ! $booking instanceof BookingModel ) {
			return;
		}
		if ( ! is_array( $context ) ) {
			$context = array();
		}
		$contact = $booking->contact;
		if ( ! $contact ) {
			return;
		}
		$this->process(
			array(
				'contact' => $contact,
				'booking' => $booking,
				'context' => $context,
				'data'    => array(
					'booking_id' => (int) $booking->id,
					'context'    => $context,
				),
			)
		);
	}
}
