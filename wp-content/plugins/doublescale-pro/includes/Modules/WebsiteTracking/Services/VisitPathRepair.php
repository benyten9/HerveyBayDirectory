<?php
/**
 * Repairs visits stored with the site's folder twice.
 *
 * Website::track_anonymous_visits_after_signup() rebuilt each earlier page
 * with home_url( path ) while the browser keeps the whole path, so a site in
 * a folder stored "/wordpress/wordpress/pricing/" (fixed at the source in
 * Pro 1.5.x). Filters and automation rules on "visited a page" compare the
 * stored path, so those visits never matched.
 *
 * Runs once per site (doublescale_ensure_once), in the background, a batch at
 * a time. Only the path changes: no row is removed and the visit keeps its
 * times. Only on a site in a folder, only paths that start with it twice.
 *
 * @package DoubleScale\Pro\Modules\WebsiteTracking
 */

namespace DoubleScale\Pro\Modules\WebsiteTracking\Services;

defined( 'ABSPATH' ) || exit;

/**
 * VisitPathRepair class.
 */
final class VisitPathRepair {

	public const HOOK = 'doublescale_page_visit_path_repair';

	/**
	 * Rows per batch.
	 */
	private const BATCH = 500;

	/**
	 * @return void
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	/**
	 * Start the repair (a site at its root has nothing to repair).
	 *
	 * @return void
	 */
	public static function queue(): void {
		if ( '' === self::folder() ) {
			return;
		}
		// Action Scheduler drops work queued before `init`.
		if ( ! did_action( 'init' ) ) {
			add_action( 'init', array( self::class, 'enqueue_now' ), 20 );
			return;
		}
		self::enqueue_now();
	}

	/**
	 * @return void
	 */
	public static function run(): void {
		if ( self::BATCH === self::run_batch( self::BATCH ) ) {
			self::enqueue_now();
		}
	}

	/**
	 * Repair up to $limit visits.
	 *
	 * @param int $limit Rows.
	 * @return int Rows repaired.
	 */
	public static function run_batch( int $limit ): int {
		$folder = self::folder();
		if ( '' === $folder ) {
			return 0;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'doublescale_page_visits';
		$twice = $folder . $folder;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$done = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET path = SUBSTRING(path, %d), updated_at = updated_at WHERE path = %s OR path LIKE %s LIMIT %d",
				strlen( $folder ) + 1,
				$twice,
				$wpdb->esc_like( $twice . '/' ) . '%',
				max( 1, $limit )
			)
		);

		return false === $done ? 0 : (int) $done;
	}

	/**
	 * The site's folder ("/wordpress"), or '' at the root.
	 *
	 * @return string
	 */
	public static function folder(): string {
		return untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
	}

	/**
	 * @return void
	 */
	public static function enqueue_now(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array(), 'doublescale' );
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::HOOK );
		}
	}
}
