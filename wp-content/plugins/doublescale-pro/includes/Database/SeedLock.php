<?php
/**
 * One request at a time for steps that create default rows.
 *
 * @package DoubleScale\Pro\Database
 */

namespace DoubleScale\Pro\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Default rows are created by "is the table empty? then insert". Two requests
 * can both answer yes before either one inserts, and each then writes a full
 * set: the Tasks board on a new site showed every default column twice. The
 * check and the insert therefore run under a lock, and the request holding it
 * checks again.
 */
final class SeedLock {

	/**
	 * Seconds a request waits for another one that is already seeding.
	 */
	private const WAIT = 10;

	/**
	 * Run $seed while no other request is seeding the same rows.
	 *
	 * $seed has to check again that the rows are still missing: the request
	 * that held the lock before this one has usually just created them.
	 *
	 * @param string   $name What is seeded, e.g. 'task_statuses'.
	 * @param callable $seed Checks again, then creates the rows.
	 * @return bool False when another request held the lock for the whole wait and $seed did not run.
	 */
	public static function run( string $name, callable $seed ): bool {
		global $wpdb;

		$token = self::token( $name );

		/**
		 * Seconds to wait for a request that is already seeding the same rows.
		 *
		 * @param int    $seconds Wait, 0–30.
		 * @param string $name    What is being seeded.
		 */
		$wait = max( 0, min( 30, (int) apply_filters( 'doublescale_seed_lock_wait', self::WAIT, $name ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $token, $wait ) );

		// NULL: this database cannot lock at all. Seeding unguarded, as before,
		// beats a site that never gets its default rows.
		if ( null === $got ) {
			$seed();

			return true;
		}

		if ( 1 !== (int) $got ) {
			return false;
		}

		try {
			$seed();
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $token ) );
		}

		return true;
	}

	/**
	 * Lock names are shared by every site on the MySQL server and capped at 64
	 * characters, so the name carries this site's tables and is hashed.
	 *
	 * @param string $name What is seeded.
	 * @return string
	 */
	public static function token( string $name ): string {
		global $wpdb;

		return 'ds_seed_' . md5( DB_NAME . '|' . $wpdb->prefix . '|' . $name );
	}
}
