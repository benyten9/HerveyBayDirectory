<?php
/**
 * Booking ⇄ Client Portal bridge.
 *
 * Contributes the Bookings section, the "upcoming bookings" dashboard summary
 * card, and the booking-lifecycle timeline items to the portal. Booking
 * lifecycle is projected straight from `doublescale_bookings` here because the
 * `booking_*` activity types are never written to `doublescale_activities` (see
 * PortalActivityWhitelist).
 *
 * Resolved in {@see \DoubleScale\Modules\Booking\Module::boot()} so its filters
 * are only registered while the Booking module is enabled.
 *
 * @package DoubleScale\Modules\Booking
 */

namespace DoubleScale\Modules\Booking\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Models\BookingModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * BookingPortalProvider.
 */
final class BookingPortalProvider {

	public function __construct() {
		add_filter( 'doublescale_portal_sections', array( $this, 'register_section' ) );
		add_filter( 'doublescale_portal_summary_cards', array( $this, 'add_summary_card' ), 10, 2 );
		add_filter( 'doublescale_portal_timeline_items', array( $this, 'add_timeline_items' ), 10, 2 );
		add_filter( 'doublescale_portal_calendar_events', array( $this, 'add_calendar_events' ), 10, 4 );
	}

	/**
	 * Contribute the Bookings section descriptor.
	 *
	 * @param array<int, array<string, mixed>> $sections Section descriptors.
	 * @return array<int, array<string, mixed>>
	 */
	public function register_section( array $sections ): array {
		$sections[] = array(
			'slug'         => 'bookings',
			'label'        => __( 'Bookings', 'doublescale' ),
			'icon'         => 'calendar',
			'order'        => 20,
			'is_available' => static fn() => doublescale_is_module_active( 'booking' ),
			'badge'        => static fn( $contact ) => self::count_upcoming( $contact ),
		);

		return $sections;
	}

	/**
	 * Add the "upcoming bookings" summary card.
	 *
	 * @param array<int, array<string, mixed>> $cards   Summary cards.
	 * @param ContactModel|null                $contact Resolved contact.
	 * @return array<int, array<string, mixed>>
	 */
	public function add_summary_card( array $cards, $contact ): array {
		$cards[] = array(
			'key'   => 'upcoming_bookings',
			'label' => __( 'Upcoming bookings', 'doublescale' ),
			'value' => self::count_upcoming( $contact ),
			'route' => 'bookings',
		);

		return $cards;
	}

	/**
	 * Project booking lifecycle rows into the timeline.
	 *
	 * @param array<int, array<string, mixed>> $items   Timeline items.
	 * @param ContactModel|null                $contact Resolved contact.
	 * @return array<int, array<string, mixed>>
	 */
	public function add_timeline_items( array $items, $contact ): array {
		if ( ! $contact instanceof ContactModel ) {
			return $items;
		}

		// Cap at the 50 most recent bookings. Combined with the timeline
		// controller's 200-activity cap, the merged feed maxes out at ~250 rows
		// before paging — fine for a customer's recent-activity glance (the
		// dashboard only ever shows the first page). Bump both caps together if
		// the timeline ever grows a "load more".
		$bookings = BookingModel::with( array( 'event' ) )
			->where( 'contact_id', (int) $contact->id )
			->orderBy( 'id', 'desc' )
			->limit( 50 )
			->get();

		foreach ( $bookings as $booking ) {
			$event_name = $booking->event ? (string) $booking->event->name : __( 'Booking', 'doublescale' );

			$items[] = array(
				'id'         => 'booking-' . (int) $booking->id,
				'kind'       => 'booking',
				'type'       => self::lifecycle_type( $booking ),
				'date'       => (string) $booking->created_at,
				'title'      => $event_name,
				'status'     => (string) $booking->status,
				'start_time' => (string) $booking->start_time,
				'timezone'   => $booking->timezone ? (string) $booking->timezone : 'UTC',
				'booking_id' => (int) $booking->id,
			);
		}

		return $items;
	}

	/**
	 * Project the contact's bookings in the window onto the calendar feed.
	 *
	 * Eager-loads `event` (for the title) AND `meta` (for the timezone) so the
	 * per-row `timezone` accessor — which is backed by a `booking_meta` query,
	 * not a column — doesn't fire one query per booking across an uncapped
	 * window. The timezone is read from the loaded meta collection here, falling
	 * back to 'UTC' (bookings never emit a null tz).
	 *
	 * @param array<int, array<string, mixed>> $events        Calendar events.
	 * @param ContactModel|null                $contact       Resolved contact.
	 * @param string                           $start         Window start (Y-m-d).
	 * @param string                           $end_inclusive Window end, inclusive end-of-day (Y-m-d H:i:s).
	 * @return array<int, array<string, mixed>>
	 */
	public function add_calendar_events( array $events, $contact, string $start, string $end_inclusive ): array {
		if ( ! $contact instanceof ContactModel ) {
			return $events;
		}

		$bookings = BookingModel::with( array( 'event', 'meta' ) )
			->where( 'contact_id', (int) $contact->id )
			->whereBetween( 'start_time', array( $start, $end_inclusive ) )
			->get();

		foreach ( $bookings as $booking ) {
			$event_name = $booking->event ? (string) $booking->event->name : __( 'Booking', 'doublescale' );

			$events[] = array(
				'id'       => 'booking-' . (int) $booking->id,
				'kind'     => 'booking',
				'title'    => $event_name,
				'start'    => (string) $booking->start_time,
				'end'      => (string) $booking->end_time,
				'all_day'  => false,
				'timezone' => self::resolve_timezone( $booking ),
				'status'   => (string) $booking->status,
				'route'    => '/bookings/' . (int) $booking->id,
			);
		}

		return $events;
	}

	/**
	 * Read the booking timezone from the already-loaded `meta` collection,
	 * avoiding the per-row query the `timezone` accessor would run.
	 *
	 * @param BookingModel $booking Booking with `meta` eager-loaded.
	 * @return string
	 */
	private static function resolve_timezone( BookingModel $booking ): string {
		$meta = $booking->relationLoaded( 'meta' ) ? $booking->getRelation( 'meta' ) : $booking->meta;
		foreach ( $meta as $row ) {
			if ( 'timezone' === (string) $row->meta_key ) {
				$value = maybe_unserialize( $row->meta_value );
				if ( is_string( $value ) && '' !== $value ) {
					return $value;
				}
			}
		}

		return 'UTC';
	}

	/**
	 * Map a booking to a projected lifecycle activity-type slug.
	 *
	 * @param BookingModel $booking Booking.
	 * @return string
	 */
	private static function lifecycle_type( BookingModel $booking ): string {
		if ( $booking->isCancelled() ) {
			return 'booking_cancelled';
		}
		if ( $booking->isCompleted() ) {
			return 'booking_completed';
		}
		if ( 'pending' === $booking->status ) {
			return 'booking_pending';
		}

		return 'booking_scheduled';
	}

	/**
	 * Count a contact's upcoming (not-cancelled, not-yet-ended) bookings.
	 *
	 * Mirrors the Upcoming tab in {@see \DoubleScale\Modules\Booking\Rest\Controllers\RestPortalBookingController::get_bookings}:
	 * excludes only `cancelled` (not `active()`) so waitlisted bookings are
	 * counted here too and the nav badge / summary card match the tab content.
	 *
	 * @param ContactModel|null $contact Resolved contact.
	 * @return int
	 */
	private static function count_upcoming( $contact ): int {
		if ( ! $contact instanceof ContactModel ) {
			return 0;
		}

		if (
			function_exists( 'doublescale_is_module_storage_ready' )
			&& ! doublescale_is_module_storage_ready( 'booking', BookingModel::class )
		) {
			return 0;
		}

		try {
			return (int) BookingModel::where( 'contact_id', (int) $contact->id )
				->whereNotIn( 'status', array( 'cancelled' ) )
				->where( 'end_time', '>=', gmdate( 'Y-m-d H:i:s' ) )
				->count();
		} catch ( \Throwable $e ) {
			return 0;
		}
	}
}
