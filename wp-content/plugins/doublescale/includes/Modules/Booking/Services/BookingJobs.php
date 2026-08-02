<?php

/**
 * Booking Jobs class
 *
 * Handle the actions related to booking jobs, such as update booking status based on payment checks.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Booking\Booking
 */

namespace DoubleScale\Modules\Booking\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Tasks;
use DoubleScale\Modules\Booking\Models\BookingModel;
use DoubleScale\Modules\Booking\Services\BookingEvents;
use DoubleScale\Modules\Booking\Helpers\BookingSettings;

/**
 * Booking Jobs class
 */
class BookingJobs {

	/**
	 * Task group name
	 */
	const PAYMENT_TASK_GROUP = 'doublescale_booking_payment';

	/**
	 * Task group name for completion
	 */
	const COMPLETION_TASK_GROUP = 'doublescale_booking_completion';

	/**
	 * Tasks instance
	 *
	 * @var Tasks
	 */
	private $tasks;

	/**
	 * Completion tasks instance
	 *
	 * @var Tasks
	 */
	private $completion_tasks;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->tasks            = new Tasks( self::PAYMENT_TASK_GROUP );
		$this->completion_tasks = new Tasks( self::COMPLETION_TASK_GROUP );

		// Register callback for checking payment status
		$this->tasks->register_callback( 'check_payment_status', array( $this, 'check_payment_status' ) );

		// Register callback for marking booking as completed
		$this->completion_tasks->register_callback( 'mark_booking_completed', array( $this, 'mark_booking_completed' ) );

		// Hook into booking creation to schedule payment check
		add_action( 'doublescale_booking_payment_status', array( $this, 'schedule_payment_check' ), 10, 1 );

		// Hook into booking creation and other relevant events to schedule completion check
		add_action( 'doublescale_booking_created', array( $this, 'schedule_completion_check' ), 10, 2 );
		add_action( 'doublescale_booking_rescheduled', array( $this, 'schedule_completion_check' ), 10, 2 );
	}

	/**
	 * Schedule a payment status check for a booking
	 *
	 * @param \DoubleScale\Modules\Booking\Models\BookingModel $booking Booking model
	 * @return void
	 */
	public function schedule_payment_check( $booking ) {
		// Only schedule checks for bookings that require payment
		if ( ! $booking->requiresPayment() ) {
			return;
		}

		// Only schedule checks for bookings that are in 'pending' status
		if ( 'pending' !== $booking->status ) {
			return;
		}
		// `auto_cancel_after` is stored as minutes (the admin UI populates the
		// option with values like `10`, `60`, `1440` for "10 min", "1 hour",
		// "1 day"). Convert to seconds before adding to `time()`. Guard
		// against a corrupted-to-zero option so we never schedule a job in
		// the past, which would re-cancel the booking instantly.
		$settings = BookingSettings::all();
		$minutes  = (int) ( $settings['general']['auto_cancel_after'] ?? 30 );
		if ( $minutes <= 0 ) {
			$minutes = 30;
		}
		$timestamp = time() + ( $minutes * MINUTE_IN_SECONDS );
		$this->tasks->schedule_single( $timestamp, 'check_payment_status', $booking->id );
	}

	/**
	 * Check payment status for a booking
	 *
	 * @param int $booking_id Booking ID
	 * @return void
	 */
	public function check_payment_status( $booking_id ) {
		// Get the booking
		$booking = BookingModel::find( $booking_id );

		// If booking doesn't exist, bail
		if ( ! $booking ) {
			return;
		}

		// If booking has been cancelled or completed already, bail
		if ( $booking->isCancelled() || $booking->isCompleted() || 'pending' !== $booking->status ) {
			return;
		}

		// Check the payment status, if it's still pending, cancel the booking
		if ( 'pending' === $booking->status ) {
			// Give the active gateway a chance to override the cancel — e.g.
			// Stripe webhook delivery is delayed but the PaymentIntent is
			// actually succeeded upstream. Without this, the cron races the
			// webhook: money taken, slot released, booking cancelled.
			$should_cancel = (bool) apply_filters(
				'doublescale_booking_should_cancel_for_payment_timeout',
				true,
				$booking
			);
			if ( ! $should_cancel ) {
				$booking->logs()->create(
					array(
						'type'    => 'info',
						'message' => __( 'Payment timeout deferred', 'doublescale' ),
						'details' => __( 'Gateway reported the payment is still in-flight; skipping auto-cancel.', 'doublescale' ),
					)
				);
				return;
			}

			$booking->cancelled_by = array(
				'type'   => 'system',
				'reason' => 'payment_timeout',
			);
			$booking->status       = 'cancelled';
			$booking->save();

			$booking->logs()->create(
				array(
					'type'    => 'info',
					'message' => __( 'Booking automatically cancelled', 'doublescale' ),
					'details' => __( 'Booking was cancelled because payment was not completed', 'doublescale' ),
				)
			);

			BookingEvents::emit(
				'cancelled',
				(int) $booking->id,
				array(
					'actor'  => 'system',
					'reason' => 'payment_timeout',
				)
			);
		}
	}

	/**
	 * Schedule a completion check for a booking.
	 *
	 * @param BookingModel|int $booking Booking model from EventBus tail-hook,
	 *                                   or a numeric primary key when invoked
	 *                                   directly from cron callbacks.
	 * @param array            $context Lifecycle context (unused).
	 * @return void
	 */
	public function schedule_completion_check( $booking, $context = array() ) {
		if ( is_numeric( $booking ) ) {
			$booking = BookingModel::find( (int) $booking );
		}
		if ( ! $booking instanceof BookingModel ) {
			return;
		}

		// Only schedule checks for bookings that are in 'scheduled' status
		if ( 'scheduled' !== $booking->status ) {
			return;
		}

		// Calculate when to run the completion check. Like `auto_cancel_after`,
		// the option is stored as minutes — convert to seconds before adding
		// to the end-time epoch. Same defensive guard against a zero/negative
		// option as `schedule_payment_check`.
		$end_time = new \DateTime( $booking->end_time );
		$settings = BookingSettings::all();
		$minutes  = (int) ( $settings['general']['auto_complete_after'] ?? 60 );
		if ( $minutes <= 0 ) {
			$minutes = 60;
		}
		$end_timestamp        = (int) $end_time->format( 'U' );
		$completion_timestamp = $end_timestamp + ( $minutes * MINUTE_IN_SECONDS );

		// Schedule the task to run at the calculated time
		$this->completion_tasks->schedule_single( $completion_timestamp, 'mark_booking_completed', $booking->id );
	}

	/**
	 * Mark a booking as completed
	 *
	 * @param int $booking_id Booking ID
	 * @return void
	 */
	public function mark_booking_completed( $booking_id ) {
		// Get the booking
		$booking = BookingModel::find( $booking_id );

		// If booking doesn't exist, bail
		if ( ! $booking ) {
			return;
		}

		// If booking has been cancelled or already explicitly marked as completed, bail
		if ( $booking->isCancelled() || 'completed' === $booking->status ) {
			return;
		}

		// Mark the booking as completed
		$booking->status = 'completed';
		$booking->save();

		// Log the completion. Settings value is in minutes — surface that to
		// the operator so the log line matches what they see in the UI.
		$settings        = BookingSettings::all();
		$time_in_minutes = (int) ( $settings['general']['auto_complete_after'] ?? 60 );

		$booking->logs()->create(
			array(
				'type'    => 'info',
				'message' => __( 'Booking automatically completed', 'doublescale' ),
				/* translators: %d: number of minutes after end time */
				'details' => sprintf( __( 'Booking was marked as completed automatically %d minute(s) after the end time', 'doublescale' ), $time_in_minutes ),
			)
		);

		BookingEvents::emit( 'completed', (int) $booking->id );
	}
}
