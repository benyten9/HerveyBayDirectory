<?php
/**
 * Read-only access to LatePoint's bookings, for automations.
 *
 * One row per booking in `latepoint_bookings`; the price lives on its order
 * item. LatePoint keeps `start_datetime_utc` in UTC next to the local date
 * and minutes, and reports every change with the booking before and after
 * (`latepoint_booking_updated`), so the triggers compare those two.
 *
 * @package DoubleScale\Core\Services
 */

namespace DoubleScale\Core\Services;

defined( 'ABSPATH' ) || exit;

// LatePoint's own tables, read directly; table names are fixed, every value is bound through prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

/**
 * LatepointBookings class
 */
final class LatepointBookings {

	/**
	 * Per-request cache of normalised bookings.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private static $bookings = array();

	/**
	 * Whether LatePoint is active.
	 */
	public static function is_active(): bool {
		// DoubleScale loads before LatePoint, so its constants are not defined yet early on.
		return function_exists( 'doublescale_is_plugin_active' )
			? doublescale_is_plugin_active( 'latepoint' )
			: defined( 'LATEPOINT_VERSION' );
	}

	/**
	 * Booking statuses, with the extra ones the site added in LatePoint's settings.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		if ( class_exists( '\OsBookingHelper' ) && method_exists( '\OsBookingHelper', 'get_statuses_list' ) ) {
			$list = (array) \OsBookingHelper::get_statuses_list();
			if ( ! empty( $list ) ) {
				return array_map( 'strval', $list );
			}
		}

		return array(
			'approved'        => __( 'Approved', 'doublescale' ),
			'pending'         => __( 'Pending Approval', 'doublescale' ),
			'payment_pending' => __( 'Payment Pending', 'doublescale' ),
			'cancelled'       => __( 'Cancelled', 'doublescale' ),
			'no_show'         => __( 'No Show', 'doublescale' ),
			'completed'       => __( 'Completed', 'doublescale' ),
		);
	}

	/**
	 * Forget the cached reads.
	 */
	public static function reset_cache(): void {
		self::$bookings = array();
	}

	/**
	 * LatePoint table name.
	 *
	 * @param string $name Table name without the `latepoint_` prefix.
	 */
	private static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'latepoint_' . $name;
	}

	/**
	 * A booking, normalised: customer, service, agent, when, where, price.
	 *
	 * @param int  $booking_id LatePoint booking id.
	 * @param bool $fresh      Skip the per-request cache.
	 * @return array<string, mixed>|null
	 */
	public static function find_booking( int $booking_id, bool $fresh = false ): ?array {
		global $wpdb;

		if ( $booking_id <= 0 || ! self::is_active() ) {
			return null;
		}
		if ( ! $fresh && array_key_exists( $booking_id, self::$bookings ) ) {
			return self::$bookings[ $booking_id ];
		}

		$b  = self::table( 'bookings' );
		$c  = self::table( 'customers' );
		$s  = self::table( 'services' );
		$a  = self::table( 'agents' );
		$l  = self::table( 'locations' );
		$oi = self::table( 'order_items' );

		$suppressed = $wpdb->suppress_errors( true );
		$row        = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT b.id, b.booking_code, b.status, b.start_datetime_utc, b.end_datetime_utc, b.service_id, b.agent_id, b.location_id, b.total_attendees,
					c.email, c.first_name, c.last_name, c.phone,
					s.name AS service_name, a.first_name AS agent_first, a.last_name AS agent_last, a.display_name AS agent_display,
					l.name AS location_name, l.full_address AS location_address, oi.total AS item_total, oi.variant AS item_variant
				FROM {$b} b
				LEFT JOIN {$c} c ON c.id = b.customer_id
				LEFT JOIN {$s} s ON s.id = b.service_id
				LEFT JOIN {$a} a ON a.id = b.agent_id
				LEFT JOIN {$l} l ON l.id = b.location_id
				LEFT JOIN {$oi} oi ON oi.id = b.order_item_id
				WHERE b.id = %d",
				$booking_id
			),
			ARRAY_A
		);
		$wpdb->suppress_errors( $suppressed );

		if ( ! $row ) {
			if ( '' !== (string) $wpdb->last_error ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a LatePoint schema change must not fail silently.
				error_log( sprintf( 'DoubleScale LatePoint: reading booking %d failed: %s', $booking_id, $wpdb->last_error ) );
			}
			self::$bookings[ $booking_id ] = null;
			return null;
		}

		$agent = trim( (string) $row['agent_display'] );
		if ( '' === $agent ) {
			$agent = trim( $row['agent_first'] . ' ' . $row['agent_last'] );
		}

		$location = trim( (string) $row['location_name'] );
		$address  = trim( (string) $row['location_address'] );
		if ( '' !== $address && $address !== $location ) {
			$location = '' !== $location ? $location . ', ' . $address : $address;
		}

		$booking = array(
			'id'          => (int) $row['id'],
			'code'        => (string) $row['booking_code'],
			'status'      => (string) $row['status'],
			'email'       => sanitize_email( (string) $row['email'] ),
			'first_name'  => (string) $row['first_name'],
			'last_name'   => (string) $row['last_name'],
			'phone'       => trim( (string) $row['phone'] ),
			'service_id'  => (int) $row['service_id'],
			'agent_id'    => (int) $row['agent_id'],
			'location_id' => (int) $row['location_id'],
			'name'        => (string) $row['service_name'],
			'agent'       => $agent,
			'location'    => $location,
			'start'       => (string) $row['start_datetime_utc'],
			'end'         => (string) $row['end_datetime_utc'],
			// A bundle is priced once for all its bookings: no price per booking.
			'total'       => 'bundle' === (string) $row['item_variant'] ? null : round( (float) $row['item_total'], 2 ),
		);

		self::$bookings[ $booking_id ] = $booking;

		return $booking;
	}

	/**
	 * The booking a workflow run is about: as it is now, or as it was when it
	 * started when LatePoint has deleted it since.
	 *
	 * @param mixed $contact Automation contact.
	 * @return array<string, mixed>|null
	 */
	public static function for_run( $contact ): ?array {
		if ( ! is_object( $contact ) || ! method_exists( $contact, 'get_data' ) ) {
			return null;
		}

		// A booking LatePoint deleted is told as it was then, even while its row is still there.
		$snapshot = $contact->get_data( 'latepoint_booking', null );
		if ( is_array( $snapshot ) && ! empty( $snapshot['deleted'] ) && ! empty( $snapshot['id'] ) ) {
			return $snapshot;
		}

		$booking = self::find_booking( (int) $contact->get_data( 'latepoint_booking_id', 0 ) );
		if ( $booking ) {
			return $booking;
		}

		return is_array( $snapshot ) && ! empty( $snapshot['id'] ) ? $snapshot : null;
	}

	/**
	 * Booking ids on an order item (a bundle has several).
	 *
	 * @param int $order_item_id Order item id.
	 * @return int[]
	 */
	public static function order_item_booking_ids( int $order_item_id ): array {
		global $wpdb;

		if ( $order_item_id <= 0 || ! self::is_active() ) {
			return array();
		}

		$suppressed = $wpdb->suppress_errors( true );
		$ids        = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::table( 'bookings' ) . ' WHERE order_item_id = %d', $order_item_id ) );
		$wpdb->suppress_errors( $suppressed );

		return array_map( 'intval', $ids );
	}

	/**
	 * A stored UTC date as a site-local date or time.
	 *
	 * @param string $utc    `Y-m-d H:i:s` in UTC.
	 * @param string $format PHP date format; empty = the site's date format.
	 */
	public static function local( string $utc, string $format = '' ): string {
		$time = '' !== $utc ? strtotime( $utc . ' UTC' ) : false;
		if ( false === $time ) {
			return '';
		}

		return wp_date( '' !== $format ? $format : (string) get_option( 'date_format', 'F j, Y' ), $time );
	}

	/**
	 * A price the way LatePoint shows it (its currency symbol and decimals).
	 *
	 * @param float $amount Amount.
	 */
	public static function price( float $amount ): string {
		if ( class_exists( '\OsMoneyHelper' ) && method_exists( '\OsMoneyHelper', 'format_price' ) ) {
			return wp_strip_all_tags( (string) \OsMoneyHelper::format_price( $amount, true, false ) );
		}

		return number_format( $amount, 2, '.', '' );
	}

	/**
	 * Options for a picker.
	 *
	 * @param string $sql SQL returning `id` and `label`.
	 * @return array<string, string>
	 */
	private static function options( string $sql ): array {
		global $wpdb;

		if ( ! self::is_active() ) {
			return array();
		}

		$suppressed = $wpdb->suppress_errors( true );
		$rows       = (array) $wpdb->get_results( $sql, ARRAY_A );
		$wpdb->suppress_errors( $suppressed );

		$options = array();
		foreach ( $rows as $row ) {
			$label                          = trim( (string) $row['label'] );
			$options[ (string) $row['id'] ] = '' !== $label ? $label : '#' . $row['id'];
		}

		return $options;
	}

	/**
	 * Services, for the service filter.
	 *
	 * @return array<string, string>
	 */
	public static function service_options(): array {
		return self::options( 'SELECT id, name AS label FROM ' . self::table( 'services' ) . ' ORDER BY name ASC' );
	}

	/**
	 * Agents, for the agent filter.
	 *
	 * @return array<string, string>
	 */
	public static function agent_options(): array {
		return self::options( "SELECT id, COALESCE(NULLIF(display_name, ''), CONCAT(first_name, ' ', last_name)) AS label FROM " . self::table( 'agents' ) . ' ORDER BY first_name ASC, last_name ASC' );
	}

	/**
	 * Trigger args: the contact from the customer, the booking in `data`.
	 *
	 * @param array<string, mixed> $booking Normalised booking.
	 * @param array<string, mixed> $extra   Extra data.
	 * @return array<string, mixed>
	 */
	public static function trigger_args( array $booking, array $extra = array() ): array {
		$args = array(
			'email'      => $booking['email'],
			'first_name' => $booking['first_name'],
			'last_name'  => $booking['last_name'],
			'data'       => array_merge(
				array(
					'latepoint_booking_id' => $booking['id'],
					'booking_status'       => $booking['status'],
					'service_id'           => $booking['service_id'],
					'agent_id'             => $booking['agent_id'],
					'booking_start'        => $booking['start'],
					// The booking as it was, for the merge tags once LatePoint deletes it.
					'latepoint_booking'    => array_intersect_key( $booking, array_flip( array( 'id', 'code', 'status', 'name', 'agent', 'location', 'start', 'end', 'total', 'deleted' ) ) ),
				),
				$extra
			),
		);

		// An empty phone never blanks the one the contact already has.
		if ( '' !== $booking['phone'] ) {
			$args['phone'] = $booking['phone'];
		}

		return $args;
	}

	/**
	 * Whether an id passes a multi-select filter: nothing chosen, or one of the chosen.
	 *
	 * @param mixed $chosen Chosen ids.
	 * @param int   $id     The booking's id.
	 */
	public static function filter_matches( $chosen, int $id ): bool {
		$chosen = array_filter( array_map( 'intval', (array) $chosen ) );

		return empty( $chosen ) || in_array( $id, $chosen, true );
	}
}
// phpcs:enable
