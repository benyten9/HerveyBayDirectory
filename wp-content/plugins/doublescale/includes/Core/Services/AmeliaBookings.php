<?php
/**
 * Read-only access to Amelia Booking's bookings, for automations.
 *
 * Amelia keeps one row per customer booking (`amelia_customer_bookings`),
 * attached either to an appointment (`appointmentId`) or to event periods
 * (`amelia_customer_bookings_to_events_periods`). Every read here goes to
 * Amelia's own tables, so the data is the one Amelia shows, whichever of its
 * screens or links changed it. Amelia stores dates in UTC.
 *
 * @package DoubleScale\Core\Services
 */

namespace DoubleScale\Core\Services;

defined( 'ABSPATH' ) || exit;

// Amelia's own tables, read directly; table names are fixed, every value is bound through prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

/**
 * AmeliaBookings class
 */
final class AmeliaBookings {

	/**
	 * Per-request cache of normalised bookings.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private static $bookings = array();

	/**
	 * Whether Amelia's tables exist (null = not checked yet).
	 *
	 * @var bool|null
	 */
	private static $has_tables = null;

	/**
	 * Booking statuses Amelia uses, with their labels.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return array(
			'approved' => __( 'Approved', 'doublescale' ),
			'pending'  => __( 'Pending', 'doublescale' ),
			'canceled' => __( 'Canceled', 'doublescale' ),
			'rejected' => __( 'Rejected', 'doublescale' ),
			'no-show'  => __( 'No-show', 'doublescale' ),
			'waiting'  => __( 'Waiting list', 'doublescale' ),
		);
	}

	/**
	 * Whether Amelia (Lite or Pro, same plugin file) is active.
	 */
	public static function is_active(): bool {
		// DoubleScale loads before Amelia, so its constants are not defined yet early on.
		return function_exists( 'doublescale_is_plugin_active' )
			? doublescale_is_plugin_active( 'ameliabooking' )
			: defined( 'AMELIA_VERSION' );
	}

	/**
	 * Amelia table name.
	 *
	 * @param string $name Table name without the `amelia_` prefix.
	 */
	private static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'amelia_' . $name;
	}

	/**
	 * Whether Amelia's tables exist (a deactivated-then-deleted Amelia leaves none).
	 */
	private static function has_tables(): bool {
		global $wpdb;

		if ( null === self::$has_tables ) {
			self::$has_tables = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table( 'customer_bookings' ) ) ) );
		}

		return self::$has_tables;
	}

	/**
	 * Forget the cached reads (tests create the tables after boot; a watcher
	 * re-reads a booking after Amelia changed it).
	 */
	public static function reset_cache(): void {
		self::$bookings   = array();
		self::$has_tables = null;
	}

	/**
	 * A booking, normalised: customer, what was booked, when, with whom.
	 *
	 * @param int  $booking_id Amelia customer booking id.
	 * @param bool $fresh      Skip the per-request cache.
	 * @return array<string, mixed>|null
	 */
	public static function find_booking( int $booking_id, bool $fresh = false ): ?array {
		global $wpdb;

		if ( $booking_id <= 0 || ! self::is_active() || ! self::has_tables() ) {
			return null;
		}
		if ( ! $fresh && array_key_exists( $booking_id, self::$bookings ) ) {
			return self::$bookings[ $booking_id ];
		}

		$b = self::table( 'customer_bookings' );
		$u = self::table( 'users' );
		$a = self::table( 'appointments' );
		$s = self::table( 'services' );
		$l = self::table( 'locations' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT b.id, b.status, b.price, b.persons, b.aggregatedPrice, b.appointmentId, b.info,
					c.email, c.firstName, c.lastName, c.phone,
					a.status AS appointment_status, a.bookingStart, a.bookingEnd, a.serviceId, a.providerId,
					a.googleMeetUrl, a.microsoftTeamsUrl, a.zoomMeeting, a.lessonSpace,
					s.name AS service_name, p.firstName AS provider_first, p.lastName AS provider_last,
					l.name AS location_name, l.address AS location_address
				FROM {$b} b
				LEFT JOIN {$u} c ON c.id = b.customerId
				LEFT JOIN {$a} a ON a.id = b.appointmentId
				LEFT JOIN {$s} s ON s.id = a.serviceId
				LEFT JOIN {$u} p ON p.id = a.providerId
				LEFT JOIN {$l} l ON l.id = a.locationId
				WHERE b.id = %d",
				$booking_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			self::$bookings[ $booking_id ] = null;
			return null;
		}

		$info = json_decode( (string) $row['info'], true );
		$info = is_array( $info ) ? $info : array();

		$booking = array(
			'id'           => (int) $row['id'],
			'type'         => $row['appointmentId'] ? 'appointment' : 'event',
			'status'       => (string) $row['status'],
			'email'        => sanitize_email( (string) $row['email'] ),
			// Who Amelia booked: its customer record, the one its emails go to.
			// Amelia files a booking under an existing customer when the phone
			// matches, whatever name was typed; that name stays in `info` and is
			// used only when the record has none, so one person's typed name
			// never renames another person's contact.
			'first_name'   => (string) ( '' !== trim( (string) $row['firstName'] ) ? $row['firstName'] : ( $info['firstName'] ?? '' ) ),
			'last_name'    => (string) ( '' !== trim( (string) $row['lastName'] ) ? $row['lastName'] : ( $info['lastName'] ?? '' ) ),
			// The phone only from the record: a typed one could be anyone's.
			'phone'        => trim( (string) $row['phone'] ),
			'persons'      => max( 1, (int) $row['persons'] ),
			'total'        => round( (float) $row['price'] * ( $row['aggregatedPrice'] ? max( 1, (int) $row['persons'] ) : 1 ) + self::extras_total( (int) $row['id'], max( 1, (int) $row['persons'] ) ), 2 ),
			'currency'     => self::currency(),
			'appointment'  => (int) $row['appointmentId'],
			'service_id'   => (int) $row['serviceId'],
			'provider_id'  => (int) $row['providerId'],
			'event_id'     => 0,
			'event_root'   => 0,
			'event_status' => '',
			'name'         => (string) $row['service_name'],
			'employee'     => trim( $row['provider_first'] . ' ' . $row['provider_last'] ),
			'start'        => (string) $row['bookingStart'],
			'end'          => (string) $row['bookingEnd'],
			'location'     => self::location_text( (string) $row['location_name'], (string) $row['location_address'] ),
			'meeting_url'  => self::meeting_url( $row ),
		);

		if ( 'event' === $booking['type'] ) {
			$booking = self::with_event( $booking );
		}

		self::$bookings[ $booking_id ] = $booking;

		return $booking;
	}

	/**
	 * Fill the event half of an event booking: the event, its first period, organizer.
	 *
	 * @param array<string, mixed> $booking Booking.
	 * @return array<string, mixed>
	 */
	private static function with_event( array $booking ): array {
		global $wpdb;

		$bep = self::table( 'customer_bookings_to_events_periods' );
		$ep  = self::table( 'events_periods' );
		$e   = self::table( 'events' );
		$u   = self::table( 'users' );
		$l   = self::table( 'locations' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT e.id AS event_id, COALESCE(e.parentId, e.id) AS event_root, e.status AS event_status, e.name, e.organizerId, e.customLocation,
					ep.periodStart, ep.periodEnd, ep.googleMeetUrl, ep.microsoftTeamsUrl, ep.zoomMeeting, ep.lessonSpace,
					o.firstName AS provider_first, o.lastName AS provider_last,
					l.name AS location_name, l.address AS location_address
				FROM {$bep} bep
				INNER JOIN {$ep} ep ON ep.id = bep.eventPeriodId
				INNER JOIN {$e} e ON e.id = ep.eventId
				LEFT JOIN {$u} o ON o.id = e.organizerId
				LEFT JOIN {$l} l ON l.id = e.locationId
				WHERE bep.customerBookingId = %d
				ORDER BY ep.periodStart ASC
				LIMIT 1",
				$booking['id']
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return $booking;
		}

		$booking['event_id']     = (int) $row['event_id'];
		$booking['event_root']   = (int) $row['event_root'];
		$booking['event_status'] = (string) $row['event_status'];
		$booking['provider_id']  = (int) $row['organizerId'];
		$booking['name']         = (string) $row['name'];
		$booking['employee']     = trim( $row['provider_first'] . ' ' . $row['provider_last'] );
		$booking['start']        = (string) $row['periodStart'];
		$booking['end']          = (string) $row['periodEnd'];
		$booking['location']     = '' !== (string) $row['customLocation']
			? (string) $row['customLocation']
			: self::location_text( (string) $row['location_name'], (string) $row['location_address'] );
		$booking['meeting_url']  = self::meeting_url( $row );

		return $booking;
	}

	/**
	 * Location name, with the address when there is one.
	 *
	 * @param string $name    Name.
	 * @param string $address Address.
	 */
	private static function location_text( string $name, string $address ): string {
		$name    = trim( $name );
		$address = trim( $address );
		if ( '' !== $name && '' !== $address && $name !== $address ) {
			return $name . ', ' . $address;
		}

		return '' !== $name ? $name : $address;
	}

	/**
	 * The link the customer joins the meeting with: Zoom, Google Meet, Teams or Lesson Space.
	 *
	 * @param array<string, mixed> $row Appointment or event period row.
	 */
	private static function meeting_url( array $row ): string {
		$zoom = json_decode( (string) ( $row['zoomMeeting'] ?? '' ), true );
		if ( is_array( $zoom ) && ! empty( $zoom['joinUrl'] ) && wp_http_validate_url( (string) $zoom['joinUrl'] ) ) {
			return esc_url_raw( (string) $zoom['joinUrl'] );
		}
		foreach ( array( 'googleMeetUrl', 'microsoftTeamsUrl', 'lessonSpace' ) as $key ) {
			$url = (string) ( $row[ $key ] ?? '' );
			if ( '' !== $url && wp_http_validate_url( $url ) ) {
				return esc_url_raw( $url );
			}
		}

		return '';
	}

	/**
	 * Amelia's currency code.
	 */
	public static function currency(): string {
		$settings = get_option( 'amelia_settings' );
		if ( is_string( $settings ) ) {
			$settings = json_decode( $settings, true );
		}

		$currency = is_array( $settings ) ? (string) ( $settings['payments']['currency'] ?? '' ) : '';

		return '' !== $currency ? strtoupper( $currency ) : 'USD';
	}

	/**
	 * A stored UTC date as a site-local date or time.
	 *
	 * @param string $utc    `Y-m-d H:i:s` in UTC.
	 * @param string $format PHP date format; empty = the site's date format.
	 */
	public static function local( string $utc, string $format = '' ): string {
		if ( '' === $utc ) {
			return '';
		}
		$time = strtotime( $utc . ' UTC' );
		if ( false === $time ) {
			return '';
		}

		return wp_date( '' !== $format ? $format : (string) get_option( 'date_format', 'F j, Y' ), $time );
	}

	/**
	 * Every booking id on an appointment.
	 *
	 * @param int $appointment_id Appointment id.
	 * @return int[]
	 */
	public static function appointment_booking_ids( int $appointment_id ): array {
		global $wpdb;

		if ( $appointment_id <= 0 || ! self::is_active() || ! self::has_tables() ) {
			return array();
		}

		$b = self::table( 'customer_bookings' );
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$b} WHERE appointmentId = %d", $appointment_id ) ) );
	}

	/**
	 * Extras booked with a booking: price per unit, per person when Amelia says so.
	 *
	 * @param int $booking_id Booking id.
	 * @param int $persons    Persons on the booking.
	 */
	private static function extras_total( int $booking_id, int $persons ): float {
		global $wpdb;

		$x = self::table( 'customer_bookings_to_extras' );
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $x ) ) ) ) {
			return 0.0;
		}

		$total = 0.0;
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT quantity, price, aggregatedPrice FROM {$x} WHERE customerBookingId = %d", $booking_id ), ARRAY_A ) as $extra ) {
			$total += (float) $extra['price'] * max( 1, (int) $extra['quantity'] ) * ( $extra['aggregatedPrice'] ? $persons : 1 );
		}

		return $total;
	}

	/**
	 * Later repeats of a recurring event (Amelia stores each repeat as its own event).
	 *
	 * @param int $event_id Event id.
	 * @return int[]
	 */
	public static function later_repeat_ids( int $event_id ): array {
		global $wpdb;

		if ( $event_id <= 0 || ! self::is_active() || ! self::has_tables() ) {
			return array();
		}

		$e    = self::table( 'events' );
		$root = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(parentId, id) FROM {$e} WHERE id = %d", $event_id ) );

		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$e} WHERE ( parentId = %d OR id = %d ) AND id > %d", $root, $root, $event_id ) ) );
	}

	/**
	 * Every booking id on an event.
	 *
	 * @param int $event_id Event id.
	 * @return int[]
	 */
	public static function event_booking_ids( int $event_id ): array {
		global $wpdb;

		if ( $event_id <= 0 || ! self::is_active() || ! self::has_tables() ) {
			return array();
		}

		$bep = self::table( 'customer_bookings_to_events_periods' );
		$ep  = self::table( 'events_periods' );
		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT bep.customerBookingId FROM {$bep} bep INNER JOIN {$ep} ep ON ep.id = bep.eventPeriodId WHERE ep.eventId = %d",
					$event_id
				)
			)
		);
	}

	/**
	 * Options for a picker.
	 *
	 * @param string $sql SQL returning `id` and `label`.
	 * @return array<string, string>
	 */
	private static function options( string $sql ): array {
		global $wpdb;

		if ( ! self::is_active() || ! self::has_tables() ) {
			return array();
		}

		$rows    = (array) $wpdb->get_results( $sql, ARRAY_A );
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
		return self::options( 'SELECT id, name AS label FROM ' . self::table( 'services' ) . " WHERE status <> 'disabled' ORDER BY name ASC" );
	}

	/**
	 * Employees, for the employee filter.
	 *
	 * @return array<string, string>
	 */
	public static function employee_options(): array {
		return self::options( 'SELECT id, CONCAT(firstName, \' \', lastName) AS label FROM ' . self::table( 'users' ) . " WHERE type = 'provider' ORDER BY firstName ASC, lastName ASC" );
	}

	/**
	 * Events (parents only: a recurring event's copies share its name), for the event filter.
	 *
	 * @return array<string, string>
	 */
	public static function event_options(): array {
		return self::options( 'SELECT id, name AS label FROM ' . self::table( 'events' ) . ' WHERE parentId IS NULL ORDER BY name ASC' );
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
					'amelia_booking_id' => $booking['id'],
					'booking_type'      => $booking['type'],
					'booking_status'    => $booking['status'],
					'service_id'        => $booking['service_id'],
					'employee_id'       => $booking['provider_id'],
					'event_id'          => $booking['event_id'],
					'event_root_id'     => $booking['event_root'],
					'booking_start'     => $booking['start'],
				),
				$extra
			),
		);

		// An empty phone never blanks the one the contact already has.
		if ( '' !== trim( (string) $booking['phone'] ) ) {
			$args['phone'] = trim( (string) $booking['phone'] );
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
