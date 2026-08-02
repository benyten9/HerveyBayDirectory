<?php
/**
 * Shared calendar request-window helper.
 *
 * Validates + clamps a `[start, end]` date window and builds the INCLUSIVE
 * end-of-day upper bound, so a `datetime` column compared against a bare
 * `YYYY-MM-DD` end can't silently drop the last day's timed rows (the
 * datetime-vs-date midnight trap). Pure — no DB / no WP state — so it is
 * unit-testable in isolation and shared by every calendar feed: the customer
 * portal calendar ({@see \DoubleScale\Modules\Portal\Rest\Controllers\RestPortalCalendarController})
 * and the admin/staff calendar ({@see \DoubleScale\Core\Rest\Controllers\RestAdminCalendarController}).
 *
 * Single-sourcing this keeps the off-by-one / clamp logic from drifting between
 * two controllers.
 *
 * @package DoubleScale\Core\Utils
 */

namespace DoubleScale\Core\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * CalendarWindow helper.
 */
final class CalendarWindow {

	/**
	 * Largest window (in days) a single request may span. Bounds every
	 * provider's query so a `start=0000-01-01&end=9999-12-31` can't force a
	 * full-table scan. A month or week view is well within this.
	 */
	public const MAX_SPAN_DAYS = 92;

	/**
	 * Validate + clamp the request window and build the inclusive end bound.
	 *
	 * Returns `[ $start (Y-m-d), $end_inclusive (Y-m-d H:i:s) ]`, or null when the
	 * inputs are invalid (bad date, end-before-start). The end is clamped to
	 * {@see MAX_SPAN_DAYS} and normalized to an inclusive end-of-day.
	 *
	 * @param string $start_raw Raw `start` param.
	 * @param string $end_raw   Raw `end` param.
	 * @return array{0:string,1:string}|null
	 */
	public static function resolve( string $start_raw, string $end_raw ): ?array {
		$start = self::normalize_date( $start_raw );
		$end   = self::normalize_date( $end_raw );
		if ( null === $start || null === $end || $end < $start ) {
			return null;
		}

		// Clamp the span so an oversized window can't force every provider to
		// scan its whole table.
		$max_end = gmdate( 'Y-m-d', strtotime( $start . ' +' . self::MAX_SPAN_DAYS . ' days' ) );
		if ( $end > $max_end ) {
			$end = $max_end;
		}

		// Inclusive end-of-day so timed rows on the last day aren't dropped. Safe
		// for date-only columns too: comparing a date to '…-30 23:59:59' still
		// includes the 30th.
		return array( $start, $end . ' 23:59:59' );
	}

	/**
	 * Validate a `Y-m-d` date param, returning the normalized string or null.
	 *
	 * @param string $value Raw param.
	 * @return string|null
	 */
	private static function normalize_date( string $value ): ?string {
		$value = trim( $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}
		// Reject impossible dates (e.g. 2026-13-40) that match the pattern.
		$parts = array_map( 'intval', explode( '-', $value ) );
		if ( ! checkdate( $parts[1], $parts[2], $parts[0] ) ) {
			return null;
		}
		return $value;
	}
}
