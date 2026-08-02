<?php
/**
 * Booking ⇄ admin calendar bridge.
 *
 * Contributes bookings (placed on `start_time`, in the site timezone) to the
 * cross-module admin/staff calendar feed via `doublescale_admin_calendar_events`.
 *
 * Role scoping mirrors the Booking list endpoints: a manager
 * ({@see Permissions::can_manage_all_bookings()}) sees every booking in the
 * window; everyone else sees only the bookings they host (a row in
 * `booking_hosts` with their `user_id`). A manager may scope to one staffer via
 * the `$view_user` param.
 *
 * Resolved in {@see \DoubleScale\Modules\Booking\Module::boot()} so it only
 * registers while the Booking module is enabled.
 *
 * @package DoubleScale\Modules\Booking
 */

namespace DoubleScale\Modules\Booking\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Core\Utils\CalendarSupport;
use DoubleScale\Modules\Booking\Models\BookingHostsModel;
use DoubleScale\Modules\Booking\Models\BookingModel;

/**
 * BookingCalendarProvider.
 */
final class BookingCalendarProvider {

	/**
	 * Safety cap on bookings projected for one window (a manager's busy month).
	 */
	private const MAX_ROWS = 500;

	public function __construct() {
		add_filter( 'doublescale_admin_calendar_events', array( $this, 'add_events' ), 10, 4 );
	}

	/**
	 * Project the viewer's in-window bookings as calendar events.
	 *
	 * @param array<int, array<string, mixed>> $events    Events collected so far.
	 * @param array{0:string,1:string}         $window    [ start (Y-m-d), end_inclusive (Y-m-d H:i:s) ].
	 * @param int                              $viewer_id Current staff user id.
	 * @param int                              $view_user Manager-only "view as assignee" id (0 = all / self).
	 * @return array<int, array<string, mixed>>
	 */
	public function add_events( array $events, array $window, int $viewer_id, int $view_user ): array {
		if ( ! function_exists( 'doublescale_is_module_active' ) || ! doublescale_is_module_active( 'booking' ) ) {
			return $events;
		}

		list( $start, $end_inclusive ) = $window;

		$query = BookingModel::with( array( 'event', 'contact' ) )
			->whereBetween( 'start_time', array( $start, $end_inclusive ) );

		// Scope: managers see all (or one staffer); everyone else only what they host.
		$scope_user = Permissions::can_manage_all_bookings( $viewer_id )
			? ( $view_user > 0 ? $view_user : 0 )
			: $viewer_id;

		if ( $scope_user > 0 ) {
			$hosted_ids = BookingHostsModel::where( 'user_id', $scope_user )->pluck( 'booking_id' )->all();
			$query->whereIn( 'id', empty( $hosted_ids ) ? array( 0 ) : $hosted_ids );
		}

		$bookings = $query->orderBy( 'start_time' )->limit( self::MAX_ROWS )->get();
		if ( $bookings->isEmpty() ) {
			return $events;
		}

		// Batch-resolve the host display name shown as the event's assignee.
		$host_rows         = BookingHostsModel::whereIn( 'booking_id', $bookings->pluck( 'id' )->all() )->get();
		$names             = CalendarSupport::user_names( $host_rows->pluck( 'user_id' )->all() );
		$first_host_per_id = array();
		foreach ( $host_rows as $row ) {
			$bid = (int) $row->booking_id;
			if ( ! isset( $first_host_per_id[ $bid ] ) ) {
				$first_host_per_id[ $bid ] = (int) $row->user_id;
			}
		}

		$site_tz = CalendarSupport::site_timezone();

		foreach ( $bookings as $booking ) {
			$host_id = $first_host_per_id[ (int) $booking->id ] ?? 0;

			$events[] = array(
				'id'       => 'booking-' . (int) $booking->id,
				'kind'     => 'booking',
				'title'    => $booking->event ? (string) $booking->event->name : __( 'Booking', 'doublescale' ),
				'start'    => (string) $booking->start_time,
				'end'      => (string) $booking->end_time,
				'all_day'  => false,
				'timezone' => $site_tz,
				'status'   => (string) $booking->status,
				'assignee' => $host_id > 0 ? array(
					'id'   => $host_id,
					'name' => $names[ $host_id ] ?? '',
				) : null,
				'contact'  => $booking->contact ? array(
					'id'   => (int) $booking->contact->id,
					'name' => $booking->getContactDisplayName(),
				) : null,
				'route'    => 'booking/bookings/' . (int) $booking->id,
			);
		}

		return $events;
	}
}
