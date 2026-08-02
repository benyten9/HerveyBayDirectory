<?php

/**
 * Campaign Locker
 *
 * Database-backed distributed locking for campaign processing.
 * Prevents concurrent cron workers from processing the same campaign.
 *
 * Strategy: timestamp written to wp_options.
 * - Acquire: INSERT IGNORE, then conditional UPDATE if timestamp expired.
 * - Release: set value to 0.
 * - Refresh: update timestamp to current time during long runs.
 * - Expiry:  55 seconds – any process that hasn't refreshed is considered dead.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Services;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional CRM/scheduler/campaign DB ops; persistent caching is impractical for write-heavy or per-request lookups (matches WooCommerce/FluentCRM precedent).


defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CampaignLocker class
 */
class CampaignLocker {

	/** Seconds before a lock timestamp is considered stale. */
	const EXPIRY_SECONDS = 55;

	/**
	 * Try to acquire the lock for a campaign.
	 *
	 * Uses INSERT IGNORE followed by a conditional UPDATE so the operation is
	 * atomic at the MySQL level.
	 *
	 * @param string $lock_key      wp_options option_name for this lock.
	 * @param int    $lock_duration Kept for API compatibility; not used internally.
	 * @return bool True if the lock was acquired.
	 */
	public function acquire( $lock_key, $lock_duration ) {
		global $wpdb;

		$current_time     = time();
		$expiry_threshold = $current_time - self::EXPIRY_SECONDS;

		// Check whether a live lock already exists.
		$last_time = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$lock_key
			)
		);

		if ( $last_time && ( $current_time - (int) $last_time ) < self::EXPIRY_SECONDS ) {
			return false;
		}

		// Try to INSERT (first acquisition ever).
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %d, 'no')",
				$lock_key,
				$current_time
			)
		);

		if ( $wpdb->rows_affected === 1 ) {
			return true;
		}

		// Row already exists – try a conditional UPDATE for an expired lock.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options}
				SET option_value = %d
				WHERE option_name = %s
				AND (option_value IS NULL OR CAST(option_value AS UNSIGNED) <= %d)",
				$current_time,
				$lock_key,
				$expiry_threshold
			)
		);

		return $wpdb->rows_affected === 1;
	}

	/**
	 * Release the lock by zeroing the timestamp.
	 *
	 * @param string $lock_key
	 */
	public function release( $lock_key ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->options,
			array( 'option_value' => '0' ),
			array( 'option_name' => $lock_key ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Refresh the lock timestamp to keep it alive during long-running batches.
	 *
	 * @param string $lock_key
	 * @param int    $lock_duration Kept for API compatibility; not used internally.
	 * @return bool True if the row was updated.
	 */
	public function refresh( $lock_key, $lock_duration = 300 ) {
		global $wpdb;

		$updated = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => time() ),
			array( 'option_name' => $lock_key ),
			array( '%d' ),
			array( '%s' )
		);

		return $updated !== false;
	}
}
