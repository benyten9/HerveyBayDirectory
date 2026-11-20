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
use DoubleScale\Vendor\Illuminate\Support\Arr;
use DoubleScale\Modules\Booking\Models\UserModel;
use DoubleScale\Modules\Booking\Capabilities;

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


	/**
	 * Run an organizer action from an emailed link
	 * (?doublescale_booking_action=reject|confirm&id=<hash>).
	 *
	 * The booking hash is not a secret from the attendee (it is in their own
	 * confirmation link), so it cannot authorize anything: the visitor must be
	 * logged in and allowed to manage the booking, and only a booking still
	 * awaiting approval can be approved or rejected here. Attendee cancel and
	 * reschedule go through the booking pages, which apply the event's rules.
	 */
	public function process_booking_action( $action_type, $new_status, $log_message, $log_details ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- emailed organizer link; authorized by login + capability below.
		$action = sanitize_key( (string) Arr::get( $_GET, 'doublescale_booking_action', '' ) );
		if ( $action_type !== $action ) {
			return;
		}

		$id = sanitize_text_field( wp_unslash( (string) Arr::get( $_GET, 'id', '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! is_user_logged_in() ) {
			$back = add_query_arg(
				array(
					'doublescale_booking_action' => $action_type,
					'id'                         => $id,
				),
				home_url( '/' )
			);
			wp_safe_redirect( wp_login_url( $back ) );
			exit;
		}

		try {
			$booking = $this->bookingValidatorClass::validate_booking( $id );

			if ( ! Capabilities::can_manage_booking( $booking->id ) ) {
				throw new \Exception( esc_html__( 'You do not have permission to manage this booking.', 'doublescale' ) );
			}

			if ( $booking->status === $new_status ) {
				/* translators: %s: booking status */
				throw new \Exception( esc_html( sprintf( __( 'Booking is already %s', 'doublescale' ), $new_status ) ) );
			}

			if ( 'pending' !== $booking->status ) {
				/* translators: %s: booking status */
				throw new \Exception( esc_html( sprintf( __( 'This booking is %s and can no longer be changed from this link.', 'doublescale' ), $booking->status ) ) );
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

			wp_safe_redirect( $booking->getDetailsUrl() );
			exit;
		} catch ( \Exception $e ) {
			wp_die(
				esc_html( $e->getMessage() ),
				/* translators: %s: action verb such as Reject or Confirm */
				esc_html( sprintf( __( '%s Failed', 'doublescale' ), ucfirst( $action_type ) ) ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
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
	}
}
