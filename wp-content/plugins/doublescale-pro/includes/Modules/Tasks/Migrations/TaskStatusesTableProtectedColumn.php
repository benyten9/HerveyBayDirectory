<?php
/**
 * Add is_protected flag to task statuses for mandatory Open/Closed statuses.
 *
 * @package DoubleScale\Pro\Modules\Tasks\Migrations
 */

namespace DoubleScale\Pro\Modules\Tasks\Migrations;

use DoubleScale\Pro\Modules\Tasks\Services\TaskStatusManager;

defined( 'ABSPATH' ) || exit;

/**
 * TaskStatusesTableProtectedColumn migration.
 */
class TaskStatusesTableProtectedColumn {

	/**
	 * Ensure is_protected exists on task_statuses table (safe on every boot).
	 *
	 * @return void
	 */
	public static function ensure(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_task_statuses';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'is_protected'", ARRAY_A );
		if ( ! empty( $column ) ) {
			return;
		}

		( new self() )->run();
	}

	/**
	 * @return void
	 */
	public function run() {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_task_statuses';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"ALTER TABLE `{$table}` ADD COLUMN `is_protected` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`"
		);

		$manager = TaskStatusManager::instance();
		$manager->ensure_default_stages();
		$manager->ensure_protected_stages();
	}
}
