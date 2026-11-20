<?php
/**
 * Watches Amelia bookings and reports what really changed.
 *
 * Amelia's own "after" hooks are not a reliable signal: the customer's
 * cancel link fires `amelia_after_booking_canceled` only when a "cancel
 * success" page is set, payloads differ per screen, and the admin edit
 * dialog can change a booking's status, time and attendees in one save.
 * So the watcher records each booking's status and start time from Amelia's
 * tables before Amelia touches it (its "before" hooks), reads them again
 * once the request is done, and reports the difference:
 *
 * - `doublescale_amelia_booking_created`        ( $booking )
 * - `doublescale_amelia_booking_status_changed` ( $booking, $old_status, $new_status )
 * - `doublescale_amelia_booking_rescheduled`    ( $booking, $old_start )
 *
 * `$booking` is {@see AmeliaBookings::find_booking()}. Each booking is
 * reported at most once per kind of change per request.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Amelia;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\AmeliaBookings;

/**
 * BookingWatcher class
 */
final class BookingWatcher {

	/**
	 * Booking status Amelia can be asked to set from its "booking status" screens.
	 */
	private const STATUSES = array( 'approved', 'pending', 'canceled', 'rejected', 'no-show', 'waiting' );

	/**
	 * Statuses a booking is over in. A move between two of them is not news to
	 * the customer: changing a whole appointment rewrites every booking on it,
	 * the ones canceled or rejected long before too.
	 */
	private const CLOSED = array( 'canceled', 'rejected' );

	/**
	 * Whether the Amelia hooks are added.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Bookings as they were before Amelia changed them (status is the effective one).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static $before = array();

	/**
	 * Appointments being changed: their bookings are compared, and new ones on them reported.
	 *
	 * @var array<int, bool>
	 */
	private static $appointments = array();

	/**
	 * Bookings Amelia created in this request.
	 *
	 * @var array<int, bool>
	 */
	private static $created = array();

	/**
	 * Start watching (idempotent).
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		// Before Amelia changes anything: remember how it was.
		add_action( 'amelia_before_appointment_status_updated', array( self::class, 'before_appointment' ), 1, 1 );
		add_action( 'amelia_before_appointment_updated', array( self::class, 'before_appointment' ), 1, 1 );
		add_action( 'amelia_before_booking_rescheduled', array( self::class, 'before_rescheduled' ), 1, 2 );
		add_action( 'amelia_before_booking_approved_link', array( self::class, 'before_booking' ), 1, 1 );
		add_action( 'amelia_before_booking_rejected_link', array( self::class, 'before_booking' ), 1, 1 );
		add_action( 'amelia_before_event_booking_updated', array( self::class, 'before_booking' ), 1, 1 );
		add_action( 'amelia_before_event_status_updated', array( self::class, 'before_event' ), 1, 3 );
		add_action( 'amelia_before_appointment_deleted', array( self::class, 'before_appointment' ), 1, 1 );
		add_action( 'amelia_before_event_booking_deleted', array( self::class, 'before_booking' ), 1, 1 );
		// Payment completion and timeouts change statuses without a status hook.
		add_action( 'amelia_before_payment_processed', array( self::class, 'before_payment_processed' ), 1, 2 );
		add_action( 'amelia_before_payment_updated', array( self::class, 'before_payment_updated' ), 1, 1 );
		// `amelia_before_booking_{status}` from the status screens; `_canceled` is also the cancel link's.
		foreach ( self::STATUSES as $status ) {
			add_action( 'amelia_before_booking_' . $status, array( self::class, 'before_booking' ), 1, 1 );
		}

		// New bookings, from the booking form, the admin and event attendees.
		add_action( 'amelia_after_booking_added', array( self::class, 'after_booking_added' ), 20, 1 );
		add_action( 'amelia_after_appointment_booking_saved', array( self::class, 'after_booking_saved' ), 20, 1 );
		add_action( 'amelia_after_event_booking_saved', array( self::class, 'after_booking_saved' ), 20, 1 );
		add_action( 'amelia_after_appointment_added', array( self::class, 'after_appointment_added' ), 20, 1 );

		// Amelia commits at the end of its request; compare then.
		add_action( 'shutdown', array( self::class, 'flush' ), 1 );
	}

	/**
	 * An id from an Amelia array or entity.
	 *
	 * @param mixed  $value Amelia payload.
	 * @param string $key   Key.
	 */
	private static function id_of( $value, string $key = 'id' ): int {
		if ( is_array( $value ) ) {
			return (int) ( $value[ $key ] ?? 0 );
		}
		if ( is_object( $value ) && method_exists( $value, 'toArray' ) ) {
			$array = $value->toArray();
			return is_array( $array ) ? (int) ( $array[ $key ] ?? 0 ) : 0;
		}

		return 0;
	}

	/**
	 * Remember a booking's state, once (the first read is the one before any change).
	 *
	 * @param int $booking_id Booking id.
	 */
	private static function remember( int $booking_id ): void {
		if ( $booking_id <= 0 || isset( self::$before[ $booking_id ] ) || isset( self::$created[ $booking_id ] ) ) {
			return;
		}

		$booking = AmeliaBookings::find_booking( $booking_id, true );
		if ( ! $booking ) {
			return;
		}

		$booking['status']           = self::effective_status( $booking );
		self::$before[ $booking_id ] = $booking;
		if ( $booking['appointment'] > 0 ) {
			self::remember_appointment( (int) $booking['appointment'] );
		}
	}

	/**
	 * Remember every booking on an appointment.
	 *
	 * @param int $appointment_id Appointment id.
	 */
	private static function remember_appointment( int $appointment_id ): void {
		if ( $appointment_id <= 0 || isset( self::$appointments[ $appointment_id ] ) ) {
			return;
		}
		self::$appointments[ $appointment_id ] = true;

		foreach ( AmeliaBookings::appointment_booking_ids( $appointment_id ) as $booking_id ) {
			self::remember( $booking_id );
		}
	}

	/**
	 * Before an appointment's status or details change.
	 *
	 * @param mixed $appointment Amelia appointment (array or entity).
	 */
	public static function before_appointment( $appointment ): void {
		self::remember_appointment( self::id_of( $appointment ) );
	}

	/**
	 * Before a booking is moved to another time (or another appointment).
	 *
	 * @param mixed $old_appointment Appointment it leaves.
	 * @param mixed $booking         Booking that moves, when only one does.
	 */
	public static function before_rescheduled( $old_appointment, $booking = null ): void {
		self::remember( self::id_of( $booking ) );
		self::remember_appointment( self::id_of( $old_appointment ) );
	}

	/**
	 * Before one booking's status changes.
	 *
	 * @param mixed $booking Amelia booking.
	 */
	public static function before_booking( $booking ): void {
		self::remember( self::id_of( $booking ) );
	}

	/**
	 * Before an event's status changes. Amelia changes the event row only; its
	 * bookings keep their status, so {@see effective_status()} reads the event.
	 *
	 * @param mixed $event          Amelia event.
	 * @param mixed $status         Requested status.
	 * @param mixed $apply_globally Also the event's later repeats.
	 */
	public static function before_event( $event, $status = null, $apply_globally = false ): void {
		$event_ids = array( self::id_of( $event ) );
		// later_repeat_ids() came with a newer free plugin than the triggers did.
		if ( $apply_globally && method_exists( AmeliaBookings::class, 'later_repeat_ids' ) ) {
			$event_ids = array_merge( $event_ids, AmeliaBookings::later_repeat_ids( $event_ids[0] ) );
		}
		foreach ( $event_ids as $event_id ) {
			foreach ( AmeliaBookings::event_booking_ids( (int) $event_id ) as $booking_id ) {
				self::remember( $booking_id );
			}
		}
	}

	/**
	 * Before Amelia settles a payment for a reservation.
	 *
	 * @param mixed $payment     Payment data.
	 * @param mixed $reservation Appointment or event.
	 */
	public static function before_payment_processed( $payment, $reservation = null ): void {
		if ( is_array( $payment ) ) {
			self::remember( self::id_of( $payment, 'customerBookingId' ) );
		}
		if ( is_array( $reservation ) && 'event' !== ( $reservation['type'] ?? '' ) ) {
			self::remember_appointment( self::id_of( $reservation ) );
		}
	}

	/**
	 * Before a payment row changes.
	 *
	 * @param mixed $payment Payment.
	 */
	public static function before_payment_updated( $payment ): void {
		self::remember( self::id_of( $payment, 'customerBookingId' ) );
	}

	/**
	 * The status the customer is in: a booking on a canceled or rejected
	 * event is canceled, whatever its own row says.
	 *
	 * @param array<string, mixed> $booking Booking.
	 */
	private static function effective_status( array $booking ): string {
		$status = (string) $booking['status'];
		if ( 'event' === $booking['type'] && in_array( (string) ( $booking['event_status'] ?? '' ), self::CLOSED, true ) && ! in_array( $status, self::CLOSED, true ) ) {
			return 'canceled';
		}

		return $status;
	}

	/**
	 * Run one report; a failing workflow never stops the others.
	 *
	 * @param string $hook Watcher hook.
	 * @param array  $args Hook args.
	 */
	private static function report( string $hook, array $args ): void {
		try {
			do_action( $hook, ...$args );
		} catch ( \Throwable $e ) {
			$booking = is_array( $args[0] ?? null ) ? (int) ( $args[0]['id'] ?? 0 ) : 0;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a lost workflow start must leave a trace.
			error_log( sprintf( 'DoubleScale Amelia: %s for booking %d failed: %s', $hook, $booking, $e->getMessage() ) );
		}
	}

	/**
	 * A booking made from Amelia's booking form (one per appointment for carts and recurring).
	 *
	 * @param mixed $data Amelia's booking result.
	 */
	public static function after_booking_added( $data ): void {
		if ( ! is_array( $data ) ) {
			return;
		}

		self::created( self::id_of( $data['booking'] ?? null ) );
		foreach ( (array) ( $data['recurring'] ?? array() ) as $item ) {
			self::created( self::id_of( is_array( $item ) ? ( $item['booking'] ?? null ) : null ) );
		}
	}

	/**
	 * A booking Amelia saved.
	 *
	 * @param mixed $booking Amelia booking.
	 */
	public static function after_booking_saved( $booking ): void {
		self::created( self::id_of( $booking ) );
	}

	/**
	 * An appointment the admin created, with its customers.
	 *
	 * @param mixed $appointment Amelia appointment.
	 */
	public static function after_appointment_added( $appointment ): void {
		foreach ( AmeliaBookings::appointment_booking_ids( self::id_of( $appointment ) ) as $booking_id ) {
			self::created( $booking_id );
		}
	}

	/**
	 * Note a new booking (not one that was already there before this request changed it).
	 *
	 * @param int $booking_id Booking id.
	 */
	private static function created( int $booking_id ): void {
		if ( $booking_id > 0 && ! isset( self::$before[ $booking_id ] ) ) {
			self::$created[ $booking_id ] = true;
		}
	}

	/**
	 * Compare and report. Runs on shutdown; tests call it directly.
	 */
	public static function flush(): void {
		$before       = self::$before;
		$created      = self::$created;
		$appointments = self::$appointments;
		self::reset();

		if ( empty( $before ) && empty( $created ) ) {
			return;
		}

		// Amelia changed the rows after the first read.
		AmeliaBookings::reset_cache();

		// Customers the admin added to an appointment while editing it.
		foreach ( array_keys( $appointments ) as $appointment_id ) {
			foreach ( AmeliaBookings::appointment_booking_ids( (int) $appointment_id ) as $booking_id ) {
				if ( ! isset( $before[ $booking_id ] ) ) {
					$created[ $booking_id ] = true;
				}
			}
		}

		$ids = array_keys( $created );
		sort( $ids );
		foreach ( $ids as $booking_id ) {
			$booking = AmeliaBookings::find_booking( (int) $booking_id );
			if ( $booking ) {
				self::report( 'doublescale_amelia_booking_created', array( $booking ) );
			}
		}

		foreach ( $before as $booking_id => $was ) {
			$booking = AmeliaBookings::find_booking( (int) $booking_id );
			if ( ! $booking ) {
				// Deleted from Amelia's admin: Amelia tells the customer it was rejected.
				if ( ! in_array( $was['status'], self::CLOSED, true ) ) {
					self::report( 'doublescale_amelia_booking_status_changed', array( array_merge( $was, array( 'status' => 'rejected' ) ), $was['status'], 'rejected' ) );
				}
				continue;
			}

			$booking['status'] = self::effective_status( $booking );
			if ( $booking['status'] !== $was['status'] && ! ( in_array( $was['status'], self::CLOSED, true ) && in_array( $booking['status'], self::CLOSED, true ) ) ) {
				self::report( 'doublescale_amelia_booking_status_changed', array( $booking, $was['status'], $booking['status'] ) );
			}
			if ( '' !== $was['start'] && $booking['start'] !== $was['start'] ) {
				self::report( 'doublescale_amelia_booking_rescheduled', array( $booking, $was['start'] ) );
			}
		}
	}

	/**
	 * Forget the request's state.
	 */
	public static function reset(): void {
		self::$before       = array();
		self::$appointments = array();
		self::$created      = array();
	}
}
