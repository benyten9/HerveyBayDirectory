<?php
/**
 * Action Scheduler runner tuning for DoubleScale's queue load.
 *
 * Automations, campaigns and mailbox polls all run through Action Scheduler.
 * Its defaults (25 actions per batch, one batch at a time, 31-day history)
 * are sized for occasional jobs; an automation firing for thousands of
 * contacts queues thousands of small actions, and the runner became the
 * bottleneck — a 5-step automation for 5,000 contacts took hours on a
 * WP-Cron-only site.
 *
 * What changes, and why each is safe:
 *  - batch size 25 → 100: our actions are small (one enrolment or one step);
 *    a bigger claim means fewer claim/unclaim round-trips per tick.
 *  - concurrent batches 1 → 2, only when a real server cron is configured
 *    (DISABLE_WP_CRON): page-triggered WP-Cron stays serial so a busy queue
 *    never competes with visitors. Enrolments dedupe per contact, so two
 *    runners cannot double-enrol.
 *  - per-request time budget: 15 s under WP-Cron (shared with page loads),
 *    25 s under a server cron.
 *  - retention 31 → 7 days for completed/cancelled actions: the history
 *    tables are what the runner scans for the next due action.
 *
 * Every value is filterable; hosts with different constraints can override.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Action Scheduler filters.
 */
final class SchedulerTuning {

	/**
	 * Whether a real (server) cron drives WP-Cron.
	 *
	 * @return bool
	 */
	public static function has_server_cron(): bool {
		return (bool) apply_filters( 'doublescale_has_server_cron', defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
	}

	/**
	 * Actions claimed per runner batch.
	 *
	 * @param int $default Action Scheduler's value.
	 * @return int
	 */
	public static function batch_size( $default = 25 ): int {
		$size = (int) apply_filters( 'doublescale_scheduler_batch_size', 100, $default );
		return max( 1, min( 500, $size ) );
	}

	/**
	 * Runner batches allowed to run at once.
	 *
	 * @param int $default Action Scheduler's value.
	 * @return int
	 */
	public static function concurrent_batches( $default = 1 ): int {
		$batches = self::has_server_cron() ? 2 : 1;
		$batches = (int) apply_filters( 'doublescale_scheduler_concurrent_batches', $batches, $default );
		return max( 1, min( 5, $batches ) );
	}

	/**
	 * Seconds one runner request may spend processing.
	 *
	 * @param int $default Action Scheduler's value (30).
	 * @return int
	 */
	public static function time_limit( $default = 30 ): int {
		$cap   = self::has_server_cron() ? 25 : 15;
		$cap   = (int) apply_filters( 'doublescale_scheduler_time_limit', $cap, $default );
		$limit = min( (int) $default, $cap );
		return max( 5, $limit );
	}

	/**
	 * Seconds completed/cancelled actions are kept before cleanup.
	 *
	 * @param int $default Action Scheduler's value (31 days).
	 * @return int
	 */
	public static function retention_period( $default = MONTH_IN_SECONDS ): int {
		$days = (int) apply_filters( 'doublescale_scheduler_retention_days', 7, $default );
		return max( 1, $days ) * DAY_IN_SECONDS;
	}

	/**
	 * Hook everything up.
	 */
	public static function register(): void {
		add_filter( 'action_scheduler_queue_runner_batch_size', array( self::class, 'batch_size' ) );
		add_filter( 'action_scheduler_queue_runner_concurrent_batches', array( self::class, 'concurrent_batches' ) );
		add_filter( 'action_scheduler_queue_runner_time_limit', array( self::class, 'time_limit' ) );
		add_filter( 'action_scheduler_retention_period', array( self::class, 'retention_period' ) );
	}
}
