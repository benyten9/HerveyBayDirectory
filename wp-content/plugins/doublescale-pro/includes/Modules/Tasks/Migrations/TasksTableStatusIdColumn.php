<?php
/**
 * Add status_id column to tasks for kanban board grouping.
 *
 * @package DoubleScale\Pro\Modules\Tasks\Migrations
 */

namespace DoubleScale\Pro\Modules\Tasks\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * TasksTableStatusIdColumn migration.
 */
class TasksTableStatusIdColumn {

	/**
	 * Ensure status_id exists on tasks table (safe on every boot).
	 *
	 * @return void
	 */
	public static function ensure(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_tasks';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'status_id'", ARRAY_A );
		if ( ! empty( $column ) ) {
			return;
		}

		// Legacy installs: RenameTaskStageIdToStatusId handles stage_id → status_id.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$legacy = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'stage_id'", ARRAY_A );
		if ( ! empty( $legacy ) ) {
			return;
		}

		( new self() )->run();
	}

	/**
	 * @return void
	 */
	public function run() {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_tasks';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"ALTER TABLE `{$table}` ADD COLUMN `status_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL AFTER `status`, ADD INDEX `idx_status_id` (`status_id`)"
		);
	}
}
