<?php
/**
 * Shared helpers for writing a booking's location onto a remote calendar event.
 *
 * @package DoubleScale\Pro\Modules\Booking\Integrations
 */

namespace DoubleScale\Pro\Modules\Booking\Integrations;

use DoubleScale\Modules\Booking\EventLocations\Zoom;

defined( 'ABSPATH' ) || exit;

/**
 * Location text and type checks used by the Google and Outlook integrations.
 */
trait DescribesBookingLocation {

	/**
	 * Location type slug of a booking ('' when none is set).
	 *
	 * @param object $booking Booking model with a `location` accessor.
	 */
	protected function booking_location_type( $booking ): string {
		$location = $booking->location;
		return is_array( $location ) ? (string) ( $location['type'] ?? '' ) : '';
	}

	/**
	 * Whether the booking location is Zoom. The Zoom integration creates the
	 * meeting first, so calendars write these bookings on a later priority.
	 *
	 * @param object $booking Booking model with a `location` accessor.
	 */
	protected function booking_has_zoom_location( $booking ): bool {
		return Zoom::instance()->slug === $this->booking_location_type( $booking );
	}

	/**
	 * Human-readable location for the remote event, e.g. "In person: 12 Main St".
	 *
	 * @param object $booking Booking model with a `location` accessor.
	 */
	protected function booking_location_text( $booking ): string {
		$location = $booking->location;
		if ( ! is_array( $location ) ) {
			return '';
		}

		$label = trim( (string) ( $location['label'] ?? '' ) );
		$value = $location['value'] ?? '';
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value || $value === $label ) {
			return $label;
		}
		if ( '' === $label ) {
			return $value;
		}

		return $label . ': ' . $value;
	}
}
