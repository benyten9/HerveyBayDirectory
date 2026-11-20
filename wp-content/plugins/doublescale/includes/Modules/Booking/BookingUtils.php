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
	 * The picture shown for a host: the photo on their booking calendar, or their WordPress avatar.
	 *
	 * @param int $user_id Host user id.
	 * @return string Image URL.
	 */
	public static function host_image_url( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return '';
		}
		$calendar = \DoubleScale\Modules\Booking\Models\CalendarModel::where( 'user_id', $user_id )->where( 'type', 'host' )->orderBy( 'id' )->first();
		if ( $calendar ) {
			$avatar = $calendar->avatar;
			if ( is_array( $avatar ) && ! empty( $avatar['url'] ) ) {
				return (string) $avatar['url'];
			}
		}
		return (string) get_avatar_url( $user_id );
	}


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
