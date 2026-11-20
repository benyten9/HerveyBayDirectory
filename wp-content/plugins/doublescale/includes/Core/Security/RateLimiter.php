<?php
/**
 * Atomic counters for public limits.
 *
 *   hit( $key, $window )     Count one request; returns the count in the
 *                            current window (1 = first).
 *   once( $key, $ttl )       True the first time within $ttl only.
 *   acquire( $pool, $max )   Take one of $max slots (concurrent work), or
 *                            null when all are taken; release() frees it.
 *
 * One table, one statement per count: an INSERT … ON DUPLICATE KEY UPDATE
 * that resets an expired window and increments otherwise, and hands the new
 * count back through LAST_INSERT_ID(expr) on the same connection. Two
 * requests at the same instant can never both read the old count — the
 * transient read-then-write this replaces could, and filled the options
 * table with one row per visitor besides.
 *
 * Slots are leases with an expiry, so a request that dies mid-way frees its
 * slot by itself. Taking one inserts first and counts after: under a race
 * both requests may be refused, never both admitted past the cap.
 *
 * @since 1.7.1
 * @package DoubleScale\Core\Security
 */

namespace DoubleScale\Core\Security;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migrations\RateLimitsTable;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- counters must hit the database every time; the table name is ours.

/**
 * RateLimiter class.
 */
final class RateLimiter {

	private const READY_OPTION = 'doublescale_rate_limits_schema';

	private const SCHEMA_VERSION = '1';

	/**
	 * One request in this many sweeps expired rows.
	 */
	private const SWEEP_ODDS = 50;

	/**
	 * Count one hit on $key and return the count in its current window.
	 *
	 * @param string $key    Any string; hashed before storage.
	 * @param int    $window Window length in seconds, from the first hit.
	 * @return int The count including this hit; 0 when it could not be counted
	 *             (no table, a failed query). Callers decide whether that
	 *             fails open (a view count) or closed (anything that costs).
	 */
	public static function hit( string $key, int $window ): int {
		if ( ! self::ready() ) {
			return 0;
		}

		global $wpdb;
		$table   = self::table();
		$now     = time();
		$expires = $now + max( 1, $window );

		$affected = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (bucket, scope, hits, expires_at) VALUES (%s, '', 1, %d)
				ON DUPLICATE KEY UPDATE
					hits = LAST_INSERT_ID( IF( expires_at <= %d, 1, hits + 1 ) ),
					expires_at = IF( expires_at <= %d, %d, expires_at )",
				self::bucket_id( $key ),
				$expires,
				$now,
				$now,
				$expires
			)
		);

		self::maybe_sweep( $now );

		if ( false === $affected ) {
			return 0;
		}

		// 1 row affected: a new row, the first hit. 2: an existing row was
		// updated and LAST_INSERT_ID() holds the count it was set to.
		if ( 1 === (int) $affected ) {
			return 1;
		}

		return (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
	}

	/**
	 * True the first time $key is seen within $ttl.
	 *
	 * @param string $key Key.
	 * @param int    $ttl Seconds.
	 * @return bool
	 */
	public static function once( string $key, int $ttl ): bool {
		return 1 === self::hit( $key, $ttl );
	}

	/**
	 * Hits on $key in its current window, without counting one.
	 *
	 * @param string $key Key.
	 * @return int
	 */
	public static function count( string $key ): int {
		if ( ! self::ready() ) {
			return 0;
		}

		global $wpdb;
		$table = self::table();

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM {$table} WHERE bucket = %s AND expires_at > %d", self::bucket_id( $key ), time() ) );
	}

	/**
	 * Take one of $max slots in $pool for at most $lease seconds.
	 *
	 * @param string $pool  Pool name (64 characters at most).
	 * @param int    $max   Slots.
	 * @param int    $lease Seconds before an unreleased slot frees itself.
	 * @return string|null Token for release(), or null when every slot is taken
	 *                     or the table is unavailable.
	 */
	public static function acquire( string $pool, int $max, int $lease ): ?string {
		if ( $max < 1 || ! self::ready() ) {
			return null;
		}

		global $wpdb;
		$table = self::table();
		$now   = time();
		$token = wp_generate_password( 20, false, false );

		$inserted = $wpdb->insert(
			$table,
			array(
				'bucket'     => self::bucket_id( $pool . '|' . $token ),
				'scope'      => $pool,
				'hits'       => 1,
				'expires_at' => $now + max( 1, $lease ),
			),
			array( '%s', '%s', '%d', '%d' )
		);
		if ( false === $inserted ) {
			return null;
		}

		if ( self::busy( $pool ) > $max ) {
			self::release( $pool, $token );
			return null;
		}

		return $token;
	}

	/**
	 * Give a slot back.
	 *
	 * @param string $pool  Pool.
	 * @param string $token Token from acquire().
	 * @return void
	 */
	public static function release( string $pool, string $token ): void {
		if ( '' === $token || ! self::ready() ) {
			return;
		}

		global $wpdb;
		$wpdb->delete( self::table(), array( 'bucket' => self::bucket_id( $pool . '|' . $token ) ), array( '%s' ) );
	}

	/**
	 * Slots in use in $pool.
	 *
	 * @param string $pool Pool.
	 * @return int
	 */
	public static function busy( string $pool ): int {
		if ( ! self::ready() ) {
			return 0;
		}

		global $wpdb;
		$table = self::table();

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE scope = %s AND expires_at > %d", $pool, time() ) );
	}

	/**
	 * Whether the table exists (created on first use on sites that installed
	 * before it did). Callers that must not run unlimited check this.
	 *
	 * @return bool
	 */
	public static function ready(): bool {
		static $ready = null;
		if ( true === $ready ) {
			return true;
		}

		if ( self::SCHEMA_VERSION === get_option( self::READY_OPTION ) ) {
			$ready = true;
			return true;
		}

		global $wpdb;
		$table = self::table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			( new RateLimitsTable() )->run();
		}

		$ready = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		if ( $ready ) {
			update_option( self::READY_OPTION, self::SCHEMA_VERSION, true );
		}

		return $ready;
	}

	/**
	 * Stored id of a key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function bucket_id( string $key ): string {
		return sha1( $key );
	}

	/**
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'doublescale_rate_limits';
	}

	/**
	 * Now and then, drop rows whose window ended.
	 *
	 * @param int $now Current time.
	 * @return void
	 */
	private static function maybe_sweep( int $now ): void {
		if ( 1 !== wp_rand( 1, self::SWEEP_ODDS ) ) {
			return;
		}

		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at < %d LIMIT 500", $now ) );
	}
}
