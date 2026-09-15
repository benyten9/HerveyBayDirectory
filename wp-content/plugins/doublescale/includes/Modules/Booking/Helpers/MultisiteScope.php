<?php
/**
 * Multisite data isolation for booking queries.
 *
 * WordPress shares the users table network-wide; booking tables are per-blog.
 * Without scoping, a subsite that inherited rows from a parent clone (or an
 * admin listing `user=all`) can surface calendars/bookings for WP users who are
 * not members of the current blog.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Blog-membership scoping helpers for booking Eloquent queries.
 */
final class MultisiteScope {

	/**
	 * Per-request cache of user IDs on the current blog.
	 *
	 * @var int[]|null
	 */
	private static $member_user_ids = null;

	/**
	 * Whether booking list queries should be scoped to the current blog.
	 */
	public static function is_active(): bool {
		$forced = apply_filters( 'doublescale_booking_multisite_scope_active', null );
		if ( null !== $forced ) {
			return (bool) $forced;
		}

		return is_multisite();
	}

	/**
	 * WordPress user IDs that belong to the current blog.
	 *
	 * @return int[]
	 */
	public static function member_user_ids(): array {
		if ( ! self::is_active() ) {
			return array();
		}

		if ( null !== self::$member_user_ids ) {
			return self::$member_user_ids;
		}

		$override = apply_filters( 'doublescale_booking_multisite_member_user_ids', null );
		if ( is_array( $override ) ) {
			self::$member_user_ids = array_map( 'intval', $override );
			return self::$member_user_ids;
		}

		$blog_id = get_current_blog_id();
		$ids     = get_users(
			array(
				'blog_id' => $blog_id,
				'fields'  => 'ID',
			)
		);

		self::$member_user_ids = array_map( 'intval', $ids );


		if ( is_user_logged_in() && is_super_admin( get_current_user_id() ) ) {
			self::$member_user_ids[] = (int) get_current_user_id();
			self::$member_user_ids = array_values( array_unique( self::$member_user_ids ) );
		}
		return self::$member_user_ids;
	}

	/**
	 * Whether a user belongs to the current blog.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function is_member( int $user_id ): bool {
		if ( ! self::is_active() ) {
			return true;
		}

		if ( $user_id <= 0 ) {
			return false;
		}

		if ( is_user_logged_in() && is_super_admin( get_current_user_id() ) && $user_id === (int) get_current_user_id() ) {
			return true;
		}

		return in_array( $user_id, self::member_user_ids(), true );
	}

	/**
	 * Restrict a query to rows owned by users on the current blog.
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query  Eloquent builder.
	 * @param string                                $column User-id column name.
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public static function apply_user_id_scope( $query, string $column = 'user_id' ) {
		if ( ! self::is_active() ) {
			return $query;
		}

		$member_ids = self::member_user_ids();
		if ( array() === $member_ids ) {
			return $query->whereRaw( '0 = 1' );
		}

		return $query->whereIn( $column, $member_ids );
	}

	/**
	 * Restrict bookings to calendars owned by users on the current blog.
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Eloquent builder.
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public static function apply_calendar_owner_scope( $query ) {
		if ( ! self::is_active() ) {
			return $query;
		}

		$member_ids = self::member_user_ids();
		if ( array() === $member_ids ) {
			return $query->whereRaw( '0 = 1' );
		}

		return $query->whereHas(
			'calendar',
			static function ( $calendar_query ) use ( $member_ids ) {
				$calendar_query->whereIn( 'user_id', $member_ids );
			}
		);
	}

	/**
	 * Whether a booking resource owner belongs to the current site.
	 *
	 * @param int $user_id Calendar/event owner user id.
	 */
	public static function owner_is_site_member( int $user_id ): bool {
		if ( ! self::is_active() ) {
			return true;
		}

		return self::is_member( $user_id );
	}

	/**
	 * Reset the per-request member cache (tests only).
	 */
	public static function reset_cache_for_tests(): void {
		self::$member_user_ids = null;
	}
}
