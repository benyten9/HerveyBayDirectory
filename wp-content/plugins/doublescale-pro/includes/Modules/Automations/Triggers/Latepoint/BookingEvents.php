<?php
/**
 * Turns LatePoint's booking hooks into the changes the triggers care about.
 *
 * LatePoint reports a change with the booking before and after
 * (`latepoint_booking_updated`), but also reports every booking of an order
 * the admin saves, changed or not. So each report is compared here and only
 * real changes go on, once per request:
 *
 * - `doublescale_latepoint_booking_created`        ( $booking )
 * - `doublescale_latepoint_booking_status_changed` ( $booking, $old_status, $new_status )
 * - `doublescale_latepoint_booking_rescheduled`    ( $booking, $old_start )
 *
 * `$booking` is {@see LatepointBookings::find_booking()}, read after the save.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Latepoint;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\LatepointBookings;

/**
 * BookingEvents class
 */
final class BookingEvents {

	/**
	 * Whether the LatePoint hooks are added.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Changes already reported in this request.
	 *
	 * @var array<string, bool>
	 */
	private static $reported = array();

	/**
	 * Start listening (idempotent).
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'latepoint_booking_created', array( self::class, 'created' ), 20, 1 );
		add_action( 'latepoint_booking_updated', array( self::class, 'updated' ), 20, 2 );
		add_action( 'latepoint_booking_will_be_deleted', array( self::class, 'will_be_deleted' ), 20, 1 );
		// Removing an item from an order deletes its bookings without a booking hook.
		add_action( 'latepoint_order_item_will_be_deleted', array( self::class, 'order_item_will_be_deleted' ), 20, 1 );
	}

	/**
	 * A field of a LatePoint model (or array).
	 *
	 * @param mixed  $model Model.
	 * @param string $field Field.
	 */
	private static function field( $model, string $field ): string {
		if ( is_object( $model ) && isset( $model->$field ) ) {
			return (string) $model->$field;
		}
		if ( is_array( $model ) && isset( $model[ $field ] ) ) {
			return (string) $model[ $field ];
		}

		return '';
	}

	/**
	 * Whether this change is new in the request; marks it.
	 *
	 * @param string $key Change key.
	 */
	private static function first_time( string $key ): bool {
		if ( isset( self::$reported[ $key ] ) ) {
			return false;
		}
		self::$reported[ $key ] = true;

		return true;
	}

	/**
	 * A new booking.
	 *
	 * @param mixed $booking LatePoint booking model.
	 */
	public static function created( $booking ): void {
		$id = (int) self::field( $booking, 'id' );
		if ( $id <= 0 || ! self::first_time( 'created:' . $id ) ) {
			return;
		}

		$row = LatepointBookings::find_booking( $id, true );
		if ( $row ) {
			self::report( 'doublescale_latepoint_booking_created', array( $row ) );
		}
	}

	/**
	 * A booking saved: compare it with how it was.
	 *
	 * @param mixed $booking     Booking after.
	 * @param mixed $old_booking Booking before.
	 */
	public static function updated( $booking, $old_booking = null ): void {
		$id = (int) self::field( $booking, 'id' );
		if ( $id <= 0 || ! $old_booking ) {
			return;
		}

		$row = LatepointBookings::find_booking( $id, true );
		if ( ! $row ) {
			return;
		}

		$old_status = self::field( $old_booking, 'status' );
		if ( '' !== $old_status && $old_status !== $row['status'] && self::first_time( "status:{$id}:{$old_status}:{$row['status']}" ) ) {
			self::report( 'doublescale_latepoint_booking_status_changed', array( $row, $old_status, $row['status'] ) );
		}

		$old_start = self::field( $old_booking, 'start_datetime_utc' );
		if ( '' !== $old_start && '' !== $row['start'] && strtotime( $old_start . ' UTC' ) !== strtotime( $row['start'] . ' UTC' ) && self::first_time( "start:{$id}:{$old_start}:{$row['start']}" ) ) {
			self::report( 'doublescale_latepoint_booking_rescheduled', array( $row, $old_start ) );
		}
	}

	/**
	 * A booking about to be deleted (removed from its order): for the customer it is cancelled.
	 *
	 * @param mixed $booking_id Booking id.
	 */
	public static function will_be_deleted( $booking_id ): void {
		$row = LatepointBookings::find_booking( (int) $booking_id, true );
		// Already over for the customer (cleaning up old orders) or already cancelled: nothing to tell.
		if ( ! $row || in_array( $row['status'], array( 'cancelled', 'completed', 'no_show' ), true ) || ! self::first_time( 'deleted:' . $row['id'] ) ) {
			return;
		}

		$old_status     = $row['status'];
		$row['status']  = 'cancelled';
		$row['deleted'] = true;
		self::report( 'doublescale_latepoint_booking_status_changed', array( $row, $old_status, 'cancelled' ) );
	}

	/**
	 * An order item about to be deleted, with its bookings.
	 *
	 * @param mixed $order_item_id Order item id.
	 */
	public static function order_item_will_be_deleted( $order_item_id ): void {
		// order_item_booking_ids() came with a newer free plugin than the triggers did.
		if ( ! method_exists( LatepointBookings::class, 'order_item_booking_ids' ) ) {
			return;
		}
		foreach ( LatepointBookings::order_item_booking_ids( (int) $order_item_id ) as $booking_id ) {
			self::will_be_deleted( $booking_id );
		}
	}

	/**
	 * Run one report; a failing workflow never breaks LatePoint's save.
	 *
	 * @param string $hook Hook.
	 * @param array  $args Args.
	 */
	private static function report( string $hook, array $args ): void {
		try {
			do_action( $hook, ...$args );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a lost workflow start must leave a trace.
			error_log( sprintf( 'DoubleScale LatePoint: %s for booking %d failed: %s', $hook, (int) ( $args[0]['id'] ?? 0 ), $e->getMessage() ) );
		}
	}

	/**
	 * Forget the request's reports (tests).
	 */
	public static function reset(): void {
		self::$reported = array();
	}
}
