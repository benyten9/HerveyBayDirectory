<?php
/**
 * Booking Data Formatter
 *
 * Handles all data formatting logic for templates
 */

namespace DoubleScale\Modules\Booking\Data;

defined( 'ABSPATH' ) || exit;

use DateTime;
use DateTimeZone;
use Exception;
use DoubleScale\Vendor\Illuminate\Support\Arr;

class BookingDataFormatter {

	/**
	 * A booking day for guests, in the site's language and Date Format:
	 * "jueves, 5 de noviembre de 2026" on a Spanish site, "Thursday,
	 * November 5, 2026" with the default English settings. DateTime::format()
	 * always printed English day and month names.
	 *
	 * @param \DateTimeInterface $date Date in the booking's time zone.
	 * @return string
	 */
	public static function long_date( \DateTimeInterface $date ): string {
		$format = (string) get_option( 'date_format' );
		if ( '' === $format ) {
			$format = 'F j, Y';
		}
		// Add the weekday unless the site's format already shows one.
		if ( ! preg_match( '/(?<!\\\\)[lD]/', $format ) ) {
			$format = 'l, ' . $format;
		}

		$formatted = wp_date( $format, $date->getTimestamp(), $date->getTimezone() );

		return false === $formatted ? $date->format( 'l, F j, Y' ) : $formatted;
	}

	/**
	 * Format booking data for templates
	 */
	public function format_booking_data( $booking, $time_format = '12' ): array {
		$booking_array = $booking->toArray();

		// Format time range
		$booking_array['formatted_time_range'] = $this->format_time_range( $booking_array, $time_format );

		// Format location (needs booking model for Zoom join URL from meta when async task has finished).
		$booking_array = $this->format_location( $booking, $booking_array );

		return $booking_array;
	}

	/**
	 * Format time range string
	 */
	private function format_time_range( array $booking_array, $time_format = '12' ): string {
		if (
			empty( $booking_array['start_time'] ) ||
			empty( $booking_array['timezone'] ) ||
			empty( $booking_array['slot_time'] )
		) {
			return '';
		}

		try {
			$start = new DateTime( $booking_array['start_time'], new DateTimeZone( 'UTC' ) );
			$start->setTimezone( new DateTimeZone( $booking_array['timezone'] ) );

			$end = clone $start;
			$end->modify( "+{$booking_array['slot_time']} minutes" );

			// Choose time format based on time_format parameter
			$php_time_format = $time_format === '24' ? 'H:i' : 'g:i A';

			return sprintf(
				'%s - %s, %s',
				$start->format( $php_time_format ),
				$end->format( $php_time_format ),
				self::long_date( $start )
			);
		} catch ( Exception $e ) {
			return '';
		}
	}

	/**
	 * Format location data
	 *
	 * @param \DoubleScale\Modules\Booking\Models\BookingModel $booking Booking model.
	 */
	private function format_location( $booking, array $booking_array ): array {
		if ( empty( $booking_array['location'] ) ) {
			return $booking_array;
		}

		$type  = isset( $booking_array['location']['type'] ) ? strtolower( $booking_array['location']['type'] ) : '';
		$label = $booking_array['location']['label'] ?? '';
		$value = $booking_array['location']['value'] ?? '';

		// Zoom meeting link is written asynchronously (Action Scheduler). Confirm page can load
		// before `location.value` is updated; join URL is also stored on zoom_event_details.
		if ( 'zoom' === $type && ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
			$zoom_meta = $booking->get_meta( 'zoom_event_details', array() );
			$join_url  = Arr::get( $zoom_meta, 'meeting.join_url', '' );
			if ( is_string( $join_url ) && filter_var( $join_url, FILTER_VALIDATE_URL ) ) {
				$value                              = $join_url;
				$booking_array['location']['value'] = $join_url;
				$booking_array['location']['label'] = $booking_array['location']['label'] ?? __( 'Zoom', 'doublescale' );
			}
		}

		$booking_array['location_value'] = $value;

		$conferencing_types = array( 'zoom', 'ms-teams', 'google-meet' );
		$link               = LocationLink::for_location( $type, is_scalar( $value ) ? (string) $value : '' );

		// A meeting goes by its name (conferencing labels stand alone, with no
		// trailing ":" when the join URL is absent); any other place in full.
		if ( in_array( $type, $conferencing_types, true ) || ( $link && LocationLink::MEETING === $link['kind'] && '' !== $label ) ) {
			$text = '' !== $label ? $label : $value;
		} elseif ( '' !== $label && '' !== $value ) {
			$text = $label . ' : ' . $value;
		} else {
			$text = '' !== $label ? $label : $value;
		}

		// The meeting and the map open in a new tab; a call hands over to the phone.
		$booking_array['location'] = $link
			? sprintf(
				'<a class="link" href="%s"%s>%s</a>',
				esc_url( $link['url'] ),
				LocationLink::CALL === $link['kind'] ? '' : ' target="_blank" rel="noopener noreferrer"',
				esc_html( $text )
			)
			: esc_html( $text );

		return $booking_array;
	}
}
