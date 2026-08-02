<?php

/**
 * Booking Actions
 *
 * This class is responsible for handling booking actions
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Services;

defined( 'ABSPATH' ) || exit;

use DateTime;
use DateTimeZone;
use Exception;
use DoubleScale\Modules\Booking\Services\BookingValidator;
use DoubleScale\Modules\Booking\Services\BookingEvents;
use DoubleScale\Modules\Booking\Models\CalendarModel;
use DoubleScale\Modules\Booking\Models\EventModel;
use Illuminate\Support\Arr;
use DoubleScale\Modules\Booking\Models\UserModel;

class BookingActions {

	// --- Dependency Properties ---
	private string $calendarModelClass;
	private string $eventModelClass;
	private string $bookingValidatorClass; // Inject validator class name too

	public function __construct(
		string $calendarModelClass = CalendarModel::class,
		string $eventModelClass = EventModel::class,
		string $bookingValidatorClass = BookingValidator::class
	) {
		$this->calendarModelClass    = $calendarModelClass;
		$this->eventModelClass       = $eventModelClass;
		$this->bookingValidatorClass = $bookingValidatorClass;

		add_action( 'wp_loaded', array( $this, 'init' ) );
	}



	public function init() {
		$this->booking_actions();
	}


	public function process_booking_action( $action_type, $new_status, $log_message, $log_details ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public booking action link (cancel/reschedule); identity comes from the hash `id`; validate_booking() below verifies the booking is real before mutating anything.
		$action = Arr::get( $_GET, 'doublescale_booking_action', null );
		if ( $action_type !== $action ) {
			return;
		}

		try {
			$id = sanitize_text_field( wp_unslash( Arr::get( $_GET, 'id', null ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
			$booking = $this->bookingValidatorClass::validate_booking( $id );

			if ( $booking->status === $new_status ) {
				/* translators: %s: booking status */
				throw new \Exception( esc_html( sprintf( __( 'Booking is already %s', 'doublescale' ), $new_status ) ) );
			}

			$booking->status = $new_status;
			$booking->save();

			$booking->logs()->create(
				array(
					'type'    => 'info',
					'message' => $log_message,
					'details' => $log_details,
				)
			);

			$lifecycle_event = self::action_to_lifecycle_event( $action_type );
			if ( $lifecycle_event ) {
				BookingEvents::emit( $lifecycle_event, (int) $booking->id, array( 'actor' => 'organizer' ) );
			}

			wp_send_json_success( $this->generate_success_message( ucfirst( $action_type ), $new_status ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( $this->generate_error_message( ucfirst( $action_type ), $e->getMessage() ) );
		}
	}

	/**
	 * Map the public booking-action verb to its lifecycle event name.
	 */
	private static function action_to_lifecycle_event( string $action_type ): ?string {
		$map = array(
			'reject'     => 'rejected',
			'confirm'    => 'confirmed',
			'reschedule' => 'rescheduled',
			'cancel'     => 'cancelled',
		);
		return $map[ $action_type ] ?? null;
	}

	public function generate_success_message( $action, $status ) {
		return array(
			'status'  => 'success',
			/* translators: %s: action verb such as Confirm or Reschedule */
			'title'   => sprintf( __( '%s Successful', 'doublescale' ), ucfirst( $action ) ),
			/* translators: %s: booking status verb such as confirmed or rescheduled */
			'message' => sprintf( __( 'The booking has been successfully %s.', 'doublescale' ), $status ),
		);
	}

	public function generate_error_message( $action, $message ) {
		return array(
			'status'  => 'error',
			/* translators: %s: action verb such as Confirm or Reschedule */
			'title'   => sprintf( __( '%s Failed', 'doublescale' ), ucfirst( $action ) ),
			'message' => $message,
		);
	}



	private function booking_actions() {
		$this->process_booking_action( 'reject', 'rejected', __( 'Booking rejected', 'doublescale' ), __( 'Booking rejected by Organizer', 'doublescale' ) );
		$this->process_booking_action( 'confirm', 'scheduled', __( 'Booking confirmed', 'doublescale' ), __( 'Booking confirmed by Organizer', 'doublescale' ) );
		$this->process_booking_action( 'reschedule', 'rescheduled', __( 'Booking rescheduled', 'doublescale' ), __( 'Booking rescheduled by Attendee', 'doublescale' ) );
		$this->process_booking_action( 'cancel', 'cancelled', __( 'Booking cancelled', 'doublescale' ), __( 'Booking cancelled by Attendee', 'doublescale' ) );
	}
}
