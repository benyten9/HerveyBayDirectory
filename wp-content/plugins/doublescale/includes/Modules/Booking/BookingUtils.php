<?php
/**
 * Booking-specific utility helpers.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking;

defined( 'ABSPATH' ) || exit;

class BookingUtils {

	/**
	 * Create a DateTime object from a date string or timestamp.
	 *
	 * @param string|int $datetime      Date string or Unix timestamp.
	 * @param string     $from_timezone Timezone of the input.
	 * @param bool       $to_utc        Whether to convert to UTC.
	 * @return \DateTime
	 */
	public static function create_date_time( $datetime, $from_timezone, $to_utc = true ) {
		$is_timestamp = is_numeric( $datetime );
		$date         = $is_timestamp
			? new \DateTime( '@' . $datetime, new \DateTimeZone( 'UTC' ) )
			: new \DateTime( $datetime, new \DateTimeZone( $from_timezone ) );

		if ( ! $is_timestamp ) {
			$date->setTimezone( new \DateTimeZone( $from_timezone ) );
		}

		if ( $to_utc ) {
			$date->setTimezone( new \DateTimeZone( 'UTC' ) );
		}

		return $date;
	}

	/**
	 * Get available timezone list.
	 *
	 * @return array
	 */
	public static function get_timezones(): array {
		return \DateTimeZone::listIdentifiers();
	}
}
