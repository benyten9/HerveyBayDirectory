<?php
/**
 * Deals ⇄ admin calendar bridge.
 *
 * Contributes deals (placed on `expected_close_date`, all-day) to the cross-module
 * admin/staff calendar feed via Free's `doublescale_admin_calendar_events` filter.
 * Deals without an expected close date are naturally excluded (a NULL never falls
 * inside the window).
 *
 * Role scoping mirrors {@see \DoubleScale\Pro\Modules\Deals\Rest\Controllers\RestDealController}
 * exactly: a restricted sales rep (rep without manager access) sees only deals they
 * own; managers see all, optionally scoped to one staffer via `$view_user`. The
 * `doublescale_view_all_deals` cap exists but is NOT what the controller checks, so
 * we mirror the role helpers instead of guessing a cap.
 *
 * Registered in {@see \DoubleScale\Pro\Modules\Deals\Module::boot()} so it only
 * attaches while the Deals module is enabled.
 *
 * @package DoubleScale\Pro\Modules\Deals
 */

namespace DoubleScale\Pro\Modules\Deals\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Core\Utils\CalendarSupport;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\Deals\Models\DealModel;
use DoubleScale\Pro\PermissionsCompat;

/**
 * DealCalendarProvider.
 */
final class DealCalendarProvider {

	/**
	 * Safety cap on deals projected for one window.
	 */
	private const MAX_ROWS = 500;

	public function __construct() {
		add_filter( 'doublescale_admin_calendar_events', array( $this, 'add_events' ), 10, 4 );
	}

	/**
	 * Project the viewer's in-window deals as all-day calendar events.
	 *
	 * @param array<int, array<string, mixed>> $events    Events collected so far.
	 * @param array{0:string,1:string}         $window    [ start (Y-m-d), end_inclusive (Y-m-d H:i:s) ].
	 * @param int                              $viewer_id Current staff user id.
	 * @param int                              $view_user Manager-only "view as assignee" id (0 = all / self).
	 * @return array<int, array<string, mixed>>
	 */
	public function add_events( array $events, array $window, int $viewer_id, int $view_user ): array {
		if ( ! function_exists( 'doublescale_is_module_active' ) || ! doublescale_is_module_active( 'deals' ) ) {
			return $events;
		}

		list( $start, $end_inclusive ) = $window;
		$end_date = substr( $end_inclusive, 0, 10 ); // expected_close_date is a DATE column.

		// Mirror RestDealController: a restricted rep sees only deals they own;
		// managers see all, or one staffer when $view_user is set.
		$restricted = Permissions::is_sales_rep( $viewer_id ) && ! PermissionsCompat::has_sales_manager_access( $viewer_id );
		$scope_user = $restricted ? $viewer_id : ( $view_user > 0 ? $view_user : 0 );

		$query = DealModel::whereBetween( 'expected_close_date', array( $start, $end_date ) );
		if ( $scope_user > 0 ) {
			$query->where( 'owner_id', $scope_user );
		}

		$deals = $query->orderBy( 'expected_close_date' )->limit( self::MAX_ROWS )->get();
		if ( $deals->isEmpty() ) {
			return $events;
		}

		// Batch-resolve owner names + contact names across the set.
		$names    = CalendarSupport::user_names( $deals->pluck( 'owner_id' )->all() );
		$contacts = self::contact_names( $deals->pluck( 'contact_id' )->all() );

		foreach ( $deals as $deal ) {
			$owner_id   = (int) $deal->owner_id;
			$contact_id = (int) $deal->contact_id;

			$events[] = array(
				'id'       => 'deal-' . (int) $deal->id,
				'kind'     => 'deal',
				'title'    => (string) $deal->title,
				'start'    => (string) $deal->expected_close_date,
				'end'      => null,
				'all_day'  => true,
				'timezone' => null,
				'status'   => (string) $deal->status,
				'assignee' => $owner_id > 0 ? array(
					'id'   => $owner_id,
					'name' => $names[ $owner_id ] ?? '',
				) : null,
				'contact'  => $contact_id > 0 ? array(
					'id'   => $contact_id,
					'name' => $contacts[ $contact_id ] ?? '',
				) : null,
				'route'    => 'pipeline/deal/' . (int) $deal->id,
			);
		}

		return $events;
	}

	/**
	 * Resolve `[ contact_id => display_name ]` for a set of ids in one query.
	 *
	 * @param array<int, int> $contact_ids Candidate contact ids.
	 * @return array<int, string>
	 */
	private static function contact_names( array $contact_ids ): array {
		$contact_ids = array_values( array_unique( array_filter( array_map( 'intval', $contact_ids ) ) ) );
		if ( empty( $contact_ids ) ) {
			return array();
		}

		$map = array();
		foreach ( ContactModel::whereIn( 'id', $contact_ids )->get() as $contact ) {
			$name                      = trim( (string) ( $contact->first_name ?? '' ) . ' ' . (string) ( $contact->last_name ?? '' ) );
			$map[ (int) $contact->id ] = '' !== $name ? $name : (string) ( $contact->email ?? '' );
		}

		return $map;
	}
}
