<?php
/**
 * Booking activity logger: puts every booking on the guest's contact record.
 *
 * The contact timeline, Upcoming Activities and the Meetings tab already render the
 * BOOKING_* activity types; this writes them from the booking lifecycle hooks that
 * {@see EventBus} fires (`doublescale_booking_{created,confirmed,rescheduled,cancelled,completed}`).
 *
 * The "scheduled" row is dated at the meeting itself, so an upcoming booking shows in
 * Upcoming Activities and the Meetings tab; it moves when the booking is rescheduled and
 * leaves Upcoming when it's cancelled. The other rows are dated when the change happened.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Booking\Models\BookingModel;

final class BookingActivityLogger {

	/**
	 * Hook into the booking lifecycle.
	 */
	public static function register(): void {
		add_action( 'doublescale_booking_created', array( self::class, 'on_created' ), 20, 2 );
		add_action( 'doublescale_booking_confirmed', array( self::class, 'on_confirmed' ), 20, 2 );
		add_action( 'doublescale_booking_rescheduled', array( self::class, 'on_rescheduled' ), 20, 2 );
		add_action( 'doublescale_booking_cancelled', array( self::class, 'on_cancelled' ), 20, 2 );
		add_action( 'doublescale_booking_completed', array( self::class, 'on_completed' ), 20, 2 );
	}

	public static function on_created( $booking, $context = array() ): void {
		if ( ! $booking instanceof BookingModel ) {
			return;
		}
		self::log( $booking, ActivityTypes::BOOKING_SCHEDULED, __( 'Meeting booked', 'doublescale' ), self::start_utc( $booking ) );
	}

	public static function on_confirmed( $booking, $context = array() ): void {
		if ( ! $booking instanceof BookingModel ) {
			return;
		}
		self::log( $booking, ActivityTypes::BOOKING_CONFIRMED, __( 'Meeting confirmed', 'doublescale' ) );
	}

	public static function on_rescheduled( $booking, $context = array() ): void {
		if ( ! $booking instanceof BookingModel ) {
			return;
		}
		// the booked meeting moves to its new time
		$row = self::scheduled_row( $booking );
		if ( $row ) {
			$data                 = is_array( $row->data ) ? $row->data : array();
			$data['scheduled_at'] = self::iso( self::start_utc( $booking ) );
			ActivityModel::where( 'id', $row->id )->update(
				array(
					'data'          => wp_json_encode( $data ),
					'activity_date' => self::start_utc( $booking ),
				)
			);
		}
		self::log( $booking, ActivityTypes::BOOKING_RESCHEDULED, __( 'Meeting rescheduled', 'doublescale' ) );
	}

	public static function on_cancelled( $booking, $context = array() ): void {
		if ( ! $booking instanceof BookingModel ) {
			return;
		}
		// a cancelled meeting is no longer upcoming: date the booked row back to when it was made
		$row = self::scheduled_row( $booking );
		if ( $row ) {
			$data           = is_array( $row->data ) ? $row->data : array();
			$data['status'] = 'cancelled';
			ActivityModel::where( 'id', $row->id )->update(
				array(
					'data'          => wp_json_encode( $data ),
					'activity_date' => (string) $row->created_at,
				)
			);
		}
		self::log( $booking, ActivityTypes::BOOKING_CANCELLED, __( 'Meeting cancelled', 'doublescale' ) );
	}

	public static function on_completed( $booking, $context = array() ): void {
		if ( ! $booking instanceof BookingModel ) {
			return;
		}
		self::log( $booking, ActivityTypes::BOOKING_COMPLETED, __( 'Meeting completed', 'doublescale' ) );
	}

	/**
	 * Write one activity on the booking's contact. Never lets a timeline failure break a booking.
	 *
	 * @param object      $booking  Booking model.
	 * @param string      $type     Activity type.
	 * @param string      $title    Short title.
	 * @param string|null $date_utc When it happened (Y-m-d H:i:s, UTC); now when null.
	 */
	private static function log( $booking, string $type, string $title, $date_utc = null ): void {
		try {
			$contact_id = (int) ( $booking->contact_id ?? 0 );
			if ( $contact_id <= 0 || ! class_exists( ActivityModel::class ) ) {
				return;
			}
			ActivityModel::create(
				array(
					'contact_id'    => $contact_id,
					'activity_type' => $type,
					'data'          => array_merge( self::details( $booking ), array( 'title' => $title, 'type' => 'system' ) ),
					'user_id'       => get_current_user_id() ?: null,
					'activity_date' => $date_utc ?: current_time( 'mysql', true ),
				)
			);
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->warning(
				'Could not add a booking to the contact timeline',
				array(
					'source'     => 'booking-activity',
					'booking_id' => (int) ( $booking->id ?? 0 ),
					'error'      => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * What the timeline shows for a booking: event, meeting time (UTC, ISO), host, length.
	 */
	private static function details( $booking ): array {
		$event = $booking->event ?? null;
		$host  = '';
		try {
			$first = $booking->hosts ? $booking->hosts->first() : null;
			$host  = $first ? (string) $first->display_name : '';
		} catch ( \Throwable $e ) {
			$host = '';
		}
		if ( '' === $host && $booking->calendar ) {
			$host = (string) $booking->calendar->name;
		}
		return array(
			'booking_id'   => (int) $booking->id,
			'event_id'     => (int) ( $booking->event_id ?? 0 ),
			'event_name'   => $event ? (string) $event->name : '',
			'scheduled_at' => self::iso( self::start_utc( $booking ) ),
			'host_name'    => $host,
			'duration'     => (int) ( $booking->slot_time ?? 0 ),
			'location'     => self::location_label( $booking ),
		);
	}

	/** Where the meeting happens, as guests see it (e.g. "Online meeting", "Phone call"). */
	private static function location_label( $booking ): string {
		try {
			$loc = method_exists( $booking, 'get_meta' ) ? $booking->get_meta( 'location', array() ) : array();
			if ( ! is_array( $loc ) || empty( $loc['type'] ) ) {
				return '';
			}
			$manager = \DoubleScale\Modules\Booking\Managers\LocationsManager::instance();
			$label   = $manager->get_location( $loc['type'] ) ? (string) $manager->get_location_label( $loc ) : '';
			if ( '' === $label ) {
				$label = (string) \DoubleScale\Modules\Booking\Managers\LocationsManager::get_conferencing_label( $loc['type'] );
			}
			return $label;
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/** The booking's own "scheduled" row on the contact, if one was written. */
	private static function scheduled_row( $booking ) {
		// activities reach a contact through the associations table, not a column
		$ids = ActivityAssociationModel::forContact( (int) $booking->contact_id )->pluck( 'activity_id' )->all();
		if ( empty( $ids ) ) {
			return null;
		}
		$rows = ActivityModel::whereIn( 'id', $ids )
			->where( 'activity_type', ActivityTypes::BOOKING_SCHEDULED )
			->orderBy( 'id', 'desc' )
			->get();
		foreach ( $rows as $row ) {
			$data = is_array( $row->data ) ? $row->data : array();
			if ( (int) ( $data['booking_id'] ?? 0 ) === (int) $booking->id ) {
				return $row;
			}
		}
		return null;
	}

	/** Start time as stored (UTC, Y-m-d H:i:s). */
	private static function start_utc( $booking ): string {
		return (string) $booking->start_time;
	}

	/** UTC with a Z, so the browser reads it as UTC (and MySQL keeps it as is). */
	private static function iso( string $utc ): string {
		$ts = strtotime( $utc . ' UTC' );
		return $ts ? gmdate( 'Y-m-d\TH:i:s\Z', $ts ) : '';
	}
}
