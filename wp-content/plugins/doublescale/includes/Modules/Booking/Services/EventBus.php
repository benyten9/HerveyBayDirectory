<?php
/**
 * Booking lifecycle event bus.
 *
 * Structured replacement for scatter-shot `do_action()` calls. Provides:
 *
 *  1. Idempotency — each `(booking, event, action)` runs exactly once,
 *     enforced by the `idempotency_key UNIQUE` index on `workflow_runs`.
 *  2. Audit log — every dispatch leaves a row with timing and error info.
 *  3. Retry — failed handlers get exponential-backoff retries via
 *     {@see process_retries()}, swept by a 5-minute WP-Cron job.
 *  4. Extensibility — third-party code registers handlers via the
 *     `doublescale_booking_event_handlers` filter.
 *
 * Usage:
 *   EventBus::dispatch( 'booking.created', $booking );
 *   EventBus::dispatch( 'booking.cancelled', $booking, [ 'actor' => 'attendee' ] );
 *
 * After the structured handler loop runs, the bus also fires a bare WP
 * action `doublescale_booking_{event}` so third-party subscribers
 * (integrations, notifications) can hook in without coupling to the bus
 * itself. The `booking.` namespace is stripped from the event name first.
 * Example: `'booking.created'` → `'doublescale_booking_created'`.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Services;

use DoubleScale\Modules\Booking\Models\BookingModel;
use DoubleScale\Modules\Booking\Models\WorkflowRunModel;

defined( 'ABSPATH' ) || exit;

class EventBus {

	/**
	 * Dispatch a booking lifecycle event.
	 *
	 * Each registered handler runs exactly once per `(booking, event, action)`
	 * thanks to the idempotency key recorded in `workflow_runs`.
	 *
	 * @param string       $event_name e.g. `'booking.created'`, `'booking.cancelled'`.
	 * @param BookingModel $booking    The booking the event refers to.
	 * @param array        $context    Optional context bag (e.g. `['actor' => 'organizer']`).
	 *
	 * @return array<string, string> Per-handler status keyed by action name.
	 */
	public static function dispatch( $event_name, BookingModel $booking, array $context = array() ) {
		$handlers = apply_filters(
			'doublescale_booking_event_handlers',
			self::get_default_handlers( $event_name ),
			$event_name,
			$booking
		);

		$results = array();

		foreach ( $handlers as $action_name => $handler ) {
			$idempotency_key = WorkflowRunModel::make_key(
				$booking->id,
				$event_name,
				$action_name
			);

			if ( WorkflowRunModel::already_completed( $idempotency_key ) ) {
				$results[ $action_name ] = 'skipped:idempotent';
				continue;
			}

			$run = WorkflowRunModel::firstOrCreate(
				array( 'idempotency_key' => $idempotency_key ),
				array(
					'booking_id'   => $booking->id,
					'event_name'   => $event_name,
					'action_name'  => $action_name,
					'status'       => 'pending',
					'attempts'     => 0,
					'max_attempts' => 3,
					'payload'      => maybe_serialize( $context ),
				)
			);

			// Defensive re-check after firstOrCreate, in case a parallel
			// request completed the run between the already_completed()
			// query and the firstOrCreate row resolution.
			if ( 'completed' === $run->status ) {
				$results[ $action_name ] = 'skipped:idempotent';
				continue;
			}

			$run->markStarted();

			try {
				$action_result = call_user_func( $handler, $booking, $context );
				$run->markCompleted( maybe_serialize( $action_result ) );
				$results[ $action_name ] = 'completed';
			} catch ( \Throwable $e ) {
				$run->markFailed( $e->getMessage() );
				$results[ $action_name ] = 'failed:' . $e->getMessage();
			}
		}

		// Bare-hook compatibility tail. Integrations and notifiers subscribe
		// to `doublescale_booking_{event}` rather than registering as
		// structured bus handlers, so most subscribers use WP actions
		// directly and never need to know the bus exists. We strip the
		// `booking.` namespace from the event name so the public hook name
		// is `doublescale_booking_{event}` — matching every subscriber
		// across the integrations and services.
		$short_event = preg_replace( '/^booking\./', '', $event_name );
		do_action( "doublescale_booking_{$short_event}", $booking, $context );

		return $results;
	}

	/**
	 * Retry pending workflow runs whose `next_retry_at` has passed.
	 *
	 * Invoked by WP-Cron via the schedule registered in
	 * {@see register_cron()}. Idempotent: completed and permanently-failed
	 * rows are ignored.
	 */
	public static function process_retries() {
		$due_runs = WorkflowRunModel::where( 'status', 'pending' )
			->where( 'attempts', '>', 0 )
			->where( 'next_retry_at', '<=', gmdate( 'Y-m-d H:i:s' ) )
			->get();

		$retried = 0;

		foreach ( $due_runs as $run ) {
			++$retried;

			$booking = BookingModel::find( $run->booking_id );
			if ( ! $booking ) {
				$run->status        = 'failed';
				$run->error_message = 'Booking no longer exists';
				$run->completed_at  = gmdate( 'Y-m-d H:i:s' );
				$run->save();
				continue;
			}

			$handlers = apply_filters(
				'doublescale_booking_event_handlers',
				self::get_default_handlers( $run->event_name ),
				$run->event_name,
				$booking
			);

			if ( ! isset( $handlers[ $run->action_name ] ) ) {
				$run->status        = 'failed';
				$run->error_message = 'Handler no longer registered';
				$run->completed_at  = gmdate( 'Y-m-d H:i:s' );
				$run->save();
				continue;
			}

			$run->markStarted();

			try {
				$context = maybe_unserialize( $run->payload ) ?: array();
				$result  = call_user_func( $handlers[ $run->action_name ], $booking, $context );
				$run->markCompleted( maybe_serialize( $result ) );
			} catch ( \Throwable $e ) {
				$run->markFailed( $e->getMessage() );
			}
		}

		return $retried;
	}

	/**
	 * Register the WP-Cron schedule and hook for retry processing.
	 *
	 * Called once during module boot. Adds a `doublescale_five_minutes`
	 * recurring schedule and binds {@see process_retries()} to it.
	 */
	public static function register_cron() {
		add_filter(
			'cron_schedules',
			static function ( $schedules ) {
				if ( ! isset( $schedules['doublescale_five_minutes'] ) ) {
					$schedules['doublescale_five_minutes'] = array(
						'interval' => 300,
						'display'  => __( 'Every 5 minutes (DoubleScale)', 'doublescale' ),
					);
				}
				return $schedules;
			}
		);

		if ( ! wp_next_scheduled( 'doublescale_process_booking_workflow_retries' ) ) {
			wp_schedule_event( time(), 'doublescale_five_minutes', 'doublescale_process_booking_workflow_retries' );
		}

		add_action( 'doublescale_process_booking_workflow_retries', array( __CLASS__, 'process_retries' ) );
	}

	/**
	 * Default handler map for a given event name.
	 *
	 * Each handler is a trampoline that fires a `doublescale_workflow_*`
	 * action so first-party workflow code can subscribe at the
	 * trampoline-hook level (and gain idempotency through `workflow_runs`).
	 * The bare-hook tail in {@see dispatch()} covers subscribers that
	 * prefer to listen at the event level instead.
	 */
	private static function get_default_handlers( $event_name ) {
		$map = array(
			'booking.created'     => array(
				'notify_organizer' => array( __CLASS__, 'handle_notify_organizer' ),
				'notify_attendee'  => array( __CLASS__, 'handle_notify_attendee' ),
				'fire_webhooks'    => array( __CLASS__, 'handle_webhooks' ),
			),
			'booking.confirmed'   => array(
				'notify_confirmed' => array( __CLASS__, 'handle_notify_confirmed' ),
				'fire_webhooks'    => array( __CLASS__, 'handle_webhooks' ),
			),
			'booking.cancelled'   => array(
				'release_slot'     => array( __CLASS__, 'handle_release_slot' ),
				'notify_cancelled' => array( __CLASS__, 'handle_notify_cancelled' ),
				'fire_webhooks'    => array( __CLASS__, 'handle_webhooks' ),
			),
			'booking.rescheduled' => array(
				'notify_rescheduled' => array( __CLASS__, 'handle_notify_rescheduled' ),
				'fire_webhooks'      => array( __CLASS__, 'handle_webhooks' ),
			),
			'booking.pending'     => array(
				'notify_pending' => array( __CLASS__, 'handle_notify_pending' ),
			),
			'booking.completed'   => array(
				'notify_completed' => array( __CLASS__, 'handle_notify_completed' ),
				'fire_webhooks'    => array( __CLASS__, 'handle_webhooks' ),
			),
			'booking.rejected'    => array(
				'release_slot'    => array( __CLASS__, 'handle_release_slot' ),
				'notify_rejected' => array( __CLASS__, 'handle_notify_rejected' ),
				'fire_webhooks'   => array( __CLASS__, 'handle_webhooks' ),
			),
			'booking.no_show'     => array(
				'notify_no_show' => array( __CLASS__, 'handle_notify_no_show' ),
				'fire_webhooks'  => array( __CLASS__, 'handle_webhooks' ),
			),
		);

		return isset( $map[ $event_name ] ) ? $map[ $event_name ] : array();
	}

	public static function handle_notify_organizer( $booking, $context = array() ) {
		do_action( 'doublescale_workflow_notify_organizer', $booking, $context );
		return true;
	}

	public static function handle_notify_attendee( $booking, $context = array() ) {
		do_action( 'doublescale_workflow_notify_attendee', $booking, $context );
		return true;
	}

	public static function handle_webhooks( $booking, $context = array() ) {
		do_action( 'doublescale_workflow_fire_webhooks', $booking, $context );
		return true;
	}

	public static function handle_notify_confirmed( $booking, $context = array() ) {
		do_action( 'doublescale_workflow_notify_confirmed', $booking, $context );
		return true;
	}

	public static function handle_release_slot( $booking, $context = array() ) {
		\DoubleScale\Modules\Booking\Models\BookedSlotModel::release( $booking->id );
		return true;
	}

	public static function handle_notify_cancelled( $booking, $context = array() ) {
		do_action( 'doublescale_workflow_notify_cancelled', $booking, $context );
		return true;
	}

	public static function handle_notify_rescheduled( $booking, $context = array() ) {
		do_action( 'doublescale_workflow_notify_rescheduled', $booking, $context );
		return true;
	}

	public static function handle_notify_pending( $booking, $context = array() ) {
		do_action( 'doublescale_workflow_notify_pending', $booking, $context );
		return true;
	}

	public static function handle_notify_completed( $booking, $context = array() ) {
		do_action( 'doublescale_workflow_notify_completed', $booking, $context );
		return true;
	}

	public static function handle_notify_rejected( $booking, $context = array() ) {
		do_action( 'doublescale_workflow_notify_rejected', $booking, $context );
		return true;
	}

	public static function handle_notify_no_show( $booking, $context = array() ) {
		do_action( 'doublescale_workflow_notify_no_show', $booking, $context );
		return true;
	}
}
