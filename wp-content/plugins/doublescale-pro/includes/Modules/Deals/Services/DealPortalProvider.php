<?php
/**
 * Deals ⇄ Client Portal bridge.
 *
 * Projects the contact's own deal close dates onto the portal calendar.
 *
 * Deliberately narrower than {@see DealCalendarProvider} (the admin feed): a
 * customer must never see pipeline internals, so this emits only the deal title,
 * its expected close date, and an open/won/lost status — never the stage,
 * probability, value, owner, lost reason, or any other party's deal.
 *
 * There is no customer-visibility flag on deals (unlike contracts'
 * `hide_from_customer`), so scoping is `contact_id` only. Lost deals are dropped:
 * surfacing "we lost you" back to the customer is noise at best.
 *
 * Resolved in {@see \DoubleScale\Pro\Modules\Deals\Module::boot()} so the filter
 * is only registered while the Deals module is enabled.
 *
 * @package DoubleScale\Pro\Modules\Deals
 */

namespace DoubleScale\Pro\Modules\Deals\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\Deals\Models\DealModel;

/**
 * DealPortalProvider.
 */
final class DealPortalProvider {

	/**
	 * Cap on rows projected into a single calendar window.
	 */
	private const MAX_ROWS = 200;

	public function __construct() {
		add_filter( 'doublescale_portal_calendar_events', array( $this, 'add_calendar_events' ), 10, 4 );
	}

	/**
	 * Project the contact's expected close dates onto the portal calendar.
	 *
	 * `expected_close_date` is a DATE column, so the window's end-of-day bound is
	 * trimmed to its civil date before comparing (mirrors DealCalendarProvider).
	 *
	 * @param array<int, array<string, mixed>> $events        Calendar events.
	 * @param ContactModel|null                $contact       Resolved contact.
	 * @param string                           $start         Window start (Y-m-d).
	 * @param string                           $end_inclusive Window end (Y-m-d H:i:s).
	 * @return array<int, array<string, mixed>>
	 */
	public function add_calendar_events( array $events, $contact, string $start, string $end_inclusive ): array {
		if ( ! $this->is_active() || ! $contact instanceof ContactModel ) {
			return $events;
		}

		$deals = DealModel::where( 'contact_id', (int) $contact->id )
			->where( 'status', '!=', 'lost' )
			->whereNotNull( 'expected_close_date' )
			->whereBetween( 'expected_close_date', array( $start, substr( $end_inclusive, 0, 10 ) ) )
			->orderBy( 'expected_close_date' )
			->limit( self::MAX_ROWS )
			->get();

		foreach ( $deals as $deal ) {
			$events[] = array(
				'id'       => 'deal-' . (int) $deal->id,
				'kind'     => 'deal',
				'title'    => (string) $deal->title,
				'start'    => (string) $deal->expected_close_date,
				'end'      => null,
				'all_day'  => true,
				'timezone' => null,
				'status'   => (string) $deal->status,
				// No portal detail view for deals — the chip is informational only.
				'route'    => null,
			);
		}

		return $events;
	}

	/**
	 * @return bool
	 */
	private function is_active(): bool {
		return function_exists( 'doublescale_is_module_active' )
			&& doublescale_is_module_active( 'deals' );
	}
}
