<?php
/**
 * Shared helpers for the admin/staff calendar providers.
 *
 * Batched WP-user display-name resolution (so a provider never fires one
 * `get_userdata()` per row) and the site timezone the staff calendar renders in.
 * Used by every `doublescale_admin_calendar_events` provider — Free (Booking,
 * Sales) and Pro (Tasks, Deals) — so they shape the `assignee` field and time
 * zone consistently.
 *
 * @package DoubleScale\Core\Utils
 */

namespace DoubleScale\Core\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * CalendarSupport helper.
 */
final class CalendarSupport {

	/**
	 * Resolve `[ user_id => display_name ]` for a set of ids in ONE query.
	 *
	 * @param array<int, int> $user_ids Candidate user ids (0/dupes tolerated).
	 * @return array<int, string>
	 */
	public static function user_names( array $user_ids ): array {
		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( empty( $user_ids ) ) {
			return array();
		}

		$users = get_users(
			array(
				'include' => $user_ids,
				'fields'  => array( 'ID', 'display_name' ),
			)
		);

		$map = array();
		foreach ( $users as $user ) {
			$map[ (int) $user->ID ] = (string) $user->display_name;
		}

		return $map;
	}

	/**
	 * The timezone the staff calendar renders in (site timezone).
	 *
	 * IANA name when the site is set to a city ('America/New_York'); a `±HH:MM`
	 * offset otherwise (which the client falls back to UTC for — an accepted edge
	 * for offset-only sites).
	 *
	 * @return string
	 */
	public static function site_timezone(): string {
		return function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : 'UTC';
	}
}
