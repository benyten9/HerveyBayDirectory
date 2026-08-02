<?php

/**
 * Booking Tasks
 *
 * This class is responsible for handling the booking tasks
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Models\BookingModel;

use Illuminate\Support\Arr;

/**
 * Booking Tasks class
 */
class BookingTasks {


	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'doublescale_booking_created', array( $this, 'schedule_booking_tasks' ), 10, 2 );
		add_action( 'doublescale_booking_confirmed', array( $this, 'schedule_booking_tasks' ), 10, 2 );
		add_action( 'doublescale_booking_rescheduled', array( $this, 'schedule_booking_tasks' ), 10, 2 );
		add_action( 'doublescale_booking_cancelled', array( $this, 'cancel_scheduled_reminders' ), 10, 2 );
	}

	/**
	 * Schedule reminder cron events for the given booking.
	 *
	 * @param BookingModel|int $booking The booking model (preferred) or its primary key.
	 * @param array            $context Lifecycle context (unused; signature matches BookingEvents contract).
	 */
	public function schedule_booking_tasks( $booking, $context = array() ) {
		// EventBus delivers the BookingModel; cron callbacks may pass the
		// primary key directly, so resolve numeric ids here.
		if ( is_numeric( $booking ) ) {
			$booking = BookingModel::find( (int) $booking );
		}
		if ( ! $booking instanceof BookingModel ) {
			return;
		}

		// Reschedule paths fire `cancelled` then `rescheduled`; clear any prior
		// queue first so we don't pile up duplicate reminders.
		$this->clear_reminder_hooks( $booking->id );

		$this->schedule_reminders( $booking, 'organizer_reminder', 'booking_organizer_reminder' );
		$this->schedule_reminders( $booking, 'attendee_reminder', 'booking_attendee_reminder' );
	}

	/**
	 * Cancel any outstanding reminder cron events for the given booking.
	 *
	 * @param BookingModel|int $booking The booking model (preferred) or its primary key.
	 * @param array            $context Lifecycle context (unused; signature matches BookingEvents contract).
	 */
	public function cancel_scheduled_reminders( $booking, $context = array() ) {
		if ( is_numeric( $booking ) ) {
			$booking_id = (int) $booking;
		} elseif ( $booking instanceof BookingModel ) {
			$booking_id = (int) $booking->id;
		} else {
			return;
		}
		$this->clear_reminder_hooks( $booking_id );
	}

	/**
	 * Clear any pending wp-cron reminder events for a booking id.
	 *
	 * @param int $booking_id The booking primary key.
	 */
	private function clear_reminder_hooks( int $booking_id ): void {
		wp_clear_scheduled_hook( 'booking_organizer_reminder', array( $booking_id ) );
		wp_clear_scheduled_hook( 'booking_attendee_reminder', array( $booking_id ) );
	}

	/**
	 * Schedule reminders for a specific type (organizer or attendee).
	 *
	 * @param BookingModel $booking The booking model.
	 * @param string       $type    The type of reminder (e.g., 'organizer_reminder').
	 * @param string       $hook    The hook to trigger.
	 */
	private function schedule_reminders( $booking, $type, $hook ) {
		$notification_settings = $booking->getNotificationSettings();
		$reminders_enabled     = Arr::get( $notification_settings, "{$type}.enabled", false );
		if ( ! $reminders_enabled ) {
			return;
		}

		$times = Arr::get( $notification_settings, "{$type}.times", array() );
		if ( empty( $times ) ) {
			return;
		}

		foreach ( $times as $time ) {
			$value = Arr::get( $time, 'value', 0 );
			$unit  = strtolower( Arr::get( $time, 'unit', 'minutes' ) );

			$interval = $this->get_date_interval( $value, $unit );
			if ( ! $interval ) {
				continue;
			}

			$start_timestamp    = strtotime( $booking->start_time );
			$reminder_timestamp = $start_timestamp - $this->get_seconds_from_interval( $interval );

			if ( $reminder_timestamp > time() ) {
				wp_schedule_single_event( $reminder_timestamp, $hook, array( $booking->id ) );
			} else {
				wp_schedule_single_event( time() + 10, $hook, array( $booking->id ) );
			}
		}
	}

	/**
	 * Get DateInterval object based on value and unit.
	 *
	 * @param int    $value The time value.
	 * @param string $unit  The time unit (minutes, hours, days).
	 *
	 * @return \DateInterval|null
	 */
	private function get_date_interval( $value, $unit ): ?\DateInterval {
		$unit = strtolower( $unit );
		try {
			switch ( $unit ) {
				case 'minutes':
					$interval = new \DateInterval( 'PT' . abs( $value ) . 'M' );
					break;
				case 'hours':
					$interval = new \DateInterval( 'PT' . abs( $value ) . 'H' );
					break;
				case 'days':
					$interval = new \DateInterval( 'P' . abs( $value ) . 'D' );
					break;
				default:
					return null;
			}

			// ✨ Add this block
			if ( $value < 0 ) {
				$interval->invert = 1;
			}

			return $interval;
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Convert a DateInterval object to seconds.
	 *
	 * @param \DateInterval $interval The DateInterval object.
	 *
	 * @return int
	 */
	private function get_seconds_from_interval( $interval ) {
		$seconds = ( $interval->d * 24 * 60 * 60 ) +
			( $interval->h * 60 * 60 ) +
			( $interval->i * 60 ) +
			$interval->s; // Include seconds

		// Return negative seconds if the interval is inverted
		return $interval->invert === 1 ? -$seconds : $seconds;
	}
}
