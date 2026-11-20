<?php
/**
 * Client Portal → "Book a meeting": which meetings a signed-in client can book
 * from the portal.
 *
 * - `off` (the default): no booking from the portal.
 * - `manager`: the listed events on the calendar of the client's
 *   account manager (their contact's owner); when there is none, or it has no
 *   listed events, every listed event.
 * - `events`: the events the team picked, whether listed or not.
 *
 * Booking itself happens on the event's normal booking page; the portal only
 * links there. Persisted under `doublescale_settings['client_portal']['booking']`.
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;
use DoubleScale\Modules\Booking\Models\CalendarModel;
use DoubleScale\Modules\Booking\Models\EventModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * PortalBookingOptions.
 */
final class PortalBookingOptions {

	public const SETTINGS_KEY = 'client_portal';

	public const MODES = array( 'manager', 'events', 'off' );

	/**
	 * @return array{mode: string, event_ids: int[]}
	 */
	public static function settings(): array {
		$raw     = Settings::get( self::SETTINGS_KEY, array() );
		$booking = is_array( $raw ) && isset( $raw['booking'] ) && is_array( $raw['booking'] ) ? $raw['booking'] : array();
		$mode    = isset( $booking['mode'] ) && in_array( $booking['mode'], self::MODES, true ) ? $booking['mode'] : 'off';

		return array(
			'mode'      => $mode,
			'event_ids' => array_values( array_filter( array_map( 'absint', (array) ( $booking['event_ids'] ?? array() ) ) ) ),
		);
	}

	/**
	 * @param array<string, mixed> $input `mode`, `event_ids`.
	 * @return array{mode: string, event_ids: int[]}
	 */
	public static function update( array $input ): array {
		$raw = Settings::get( self::SETTINGS_KEY, array() );
		$raw = is_array( $raw ) ? $raw : array();

		$current        = self::settings();
		$raw['booking'] = array(
			'mode'      => isset( $input['mode'] ) && in_array( $input['mode'], self::MODES, true ) ? $input['mode'] : $current['mode'],
			'event_ids' => array_key_exists( 'event_ids', $input )
				? array_values( array_unique( array_filter( array_map( 'absint', (array) $input['event_ids'] ) ) ) )
				: $current['event_ids'],
		);
		Settings::update( self::SETTINGS_KEY, $raw );

		return self::settings();
	}

	/**
	 * The event's booking page.
	 *
	 * @param EventModel $event Event.
	 * @return string
	 */
	public static function url( EventModel $event ): string {
		return add_query_arg( 'doublescale_booking_event', rawurlencode( (string) $event->slug ), home_url( '/' ) );
	}

	/**
	 * One event as the portal and the settings card show it.
	 *
	 * @param EventModel $event Event (with its calendar loaded).
	 * @return array{id: int, name: string, duration: int|null, host: string, url: string}
	 */
	public static function shape( EventModel $event ): array {
		return array(
			'id'       => (int) $event->id,
			'name'     => (string) $event->name,
			'duration' => $event->duration ? (int) $event->duration : null,
			'host'     => $event->calendar ? (string) $event->calendar->name : '',
			'url'      => esc_url_raw( self::url( $event ) ),
		);
	}

	/**
	 * Every event the team could offer (open for booking), for the settings card.
	 *
	 * @return array<int, array{id: int, name: string, duration: int|null, host: string, url: string}>
	 */
	public static function all_events(): array {
		return EventModel::with( 'calendar' )
			->where( 'is_disabled', 0 )
			->orderBy( 'calendar_id' )
			->orderBy( 'name' )
			->get()
			->map( array( self::class, 'shape' ) )
			->values()
			->all();
	}

	/**
	 * The events this contact may book from the portal, as {@see shape()}.
	 *
	 * @param ContactModel|null $contact The signed-in client's contact.
	 * @return array<int, array{id: int, name: string, duration: int|null, host: string, url: string}>
	 */
	public static function for_contact( ?ContactModel $contact ): array {
		$settings = self::settings();

		if ( 'off' === $settings['mode'] ) {
			return array();
		}

		if ( 'events' === $settings['mode'] ) {
			if ( empty( $settings['event_ids'] ) ) {
				return array();
			}
			$events = EventModel::with( 'calendar' )->whereIn( 'id', $settings['event_ids'] )->get()
				->filter( fn( EventModel $e ) => $e->is_open_for_booking() );

			return $events->map( array( self::class, 'shape' ) )->values()->all();
		}

		$listed = fn( $query ) => $query->get()->filter( fn( EventModel $e ) => $e->is_listed() );

		// The account manager's own calendar first.
		if ( $contact && ! empty( $contact->owner_id ) ) {
			$calendar_ids = CalendarModel::where( 'user_id', (int) $contact->owner_id )->pluck( 'id' )->all();
			if ( $calendar_ids ) {
				$own = $listed( EventModel::with( 'calendar' )->whereIn( 'calendar_id', $calendar_ids )->orderBy( 'name' ) );
				if ( $own->isNotEmpty() ) {
					return $own->map( array( self::class, 'shape' ) )->values()->all();
				}
			}
		}

		return $listed( EventModel::with( 'calendar' )->orderBy( 'calendar_id' )->orderBy( 'name' ) )
			->map( array( self::class, 'shape' ) )
			->values()
			->all();
	}

	/**
	 * "Book again" on a past meeting: the same event's page, when the client
	 * may still book it.
	 *
	 * @param EventModel|null   $event   The booking's event.
	 * @param ContactModel|null $contact The signed-in client's contact.
	 * @return string|null
	 */
	public static function book_again_url( ?EventModel $event, ?ContactModel $contact ): ?string {
		if ( ! $event || ! $event->is_open_for_booking() || 'off' === self::settings()['mode'] ) {
			return null;
		}
		// One list per request, however many bookings ask.
		static $cache = array();
		$key = $contact ? (int) $contact->id : 0;
		if ( ! isset( $cache[ $key ] ) ) {
			$cache[ $key ] = array_column( self::for_contact( $contact ), 'id' );
		}
		$ids = $cache[ $key ];

		return in_array( (int) $event->id, $ids, true ) ? esc_url_raw( self::url( $event ) ) : null;
	}
}
