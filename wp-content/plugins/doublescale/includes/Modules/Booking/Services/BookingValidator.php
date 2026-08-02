<?php
/**
 * Booking validation service.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Exceptions\BookingNotFoundException;
use DoubleScale\Modules\Booking\Exceptions\InvalidBookingHashException;
use DoubleScale\Modules\Booking\Models\BookingModel;
use DoubleScale\Modules\Booking\Models\EventModel;
use DateTime;
use DateTimeZone;
use Exception;

class BookingValidator {

	/**
	 * @param mixed  $id         Booking hash ID.
	 * @param string $modelClass The Booking Model class name to use.
	 * @return BookingModel
	 * @throws InvalidBookingHashException If the hash id is missing/empty.
	 * @throws BookingNotFoundException If no booking matches the hash id.
	 */
	public static function validate_booking( $id, string $modelClass = BookingModel::class ) {
		if ( empty( $id ) ) {
			throw new InvalidBookingHashException( esc_html__( 'Invalid booking ID.', 'doublescale' ) );
		}

		$booking = call_user_func( array( $modelClass, 'getByHashId' ), $id );

		if ( ! $booking ) {
			throw new BookingNotFoundException( esc_html__( 'Invalid booking.', 'doublescale' ) );
		}

		return $booking;
	}

	/**
	 * @param mixed  $id         Event ID.
	 * @param string $modelClass The Event Model class name to use.
	 * @return EventModel
	 * @throws Exception If the event is invalid.
	 */
	public static function validate_event( $id, string $modelClass = EventModel::class ) {
		$event_id_abs = is_numeric( $id ) ? absint( $id ) : 0;

		if ( empty( $event_id_abs ) ) {
			throw new Exception( esc_html__( 'Invalid event ID.', 'doublescale' ) );
		}

		$event = call_user_func( array( $modelClass, 'find' ), $event_id_abs );

		if ( ! $event ) {
			throw new Exception( esc_html__( 'Invalid event.', 'doublescale' ) );
		}

		return $event;
	}

	/**
	 * @param string $start_date Start date string.
	 * @param string $timezone   Timezone identifier.
	 * @return DateTime
	 * @throws Exception If the start date is invalid or in the past.
	 */
	public static function validate_start_date( $start_date, $timezone ) {
		if ( empty( $start_date ) || empty( $timezone ) ) {
			throw new Exception( esc_html__( 'Invalid start date or timezone provided.', 'doublescale' ) );
		}

		try {
			$tz_object = new DateTimeZone( $timezone );
			$start     = new DateTime( $start_date, $tz_object );
			$now       = new DateTime( 'now', $tz_object );
		} catch ( \Exception $e ) {
			throw new Exception( esc_html__( 'Invalid date format or timezone: ', 'doublescale' ) . esc_html( $e->getMessage() ) );
		}

		if ( $start->getTimestamp() <= $now->getTimestamp() ) {
			throw new Exception( esc_html__( 'Invalid start date. Date must be in the future.', 'doublescale' ) );
		}

		return $start;
	}

	/**
	 * @param mixed $duration         Input duration.
	 * @param mixed $default_duration Default duration if input is invalid.
	 * @return int Validated positive integer duration.
	 * @throws Exception If the duration cannot be resolved.
	 */
	public static function validate_duration( $duration, $default_duration = null ) {
		$validated_duration = 0;

		if ( $duration !== null && $duration !== '' && is_numeric( $duration ) ) {
			$duration_int = (int) $duration;
			if ( $duration_int > 0 ) {
				$validated_duration = $duration_int;
			}
		}

		if ( $validated_duration <= 0 && $default_duration !== null && is_numeric( $default_duration ) ) {
			$default_duration_int = (int) $default_duration;
			if ( $default_duration_int > 0 ) {
				$validated_duration = $default_duration_int;
			}
		}

		if ( $validated_duration <= 0 ) {
			throw new Exception( esc_html__( 'Invalid duration. Must be a positive number.', 'doublescale' ) );
		}

		return $validated_duration;
	}
}
