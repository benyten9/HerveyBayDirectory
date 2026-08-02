<?php
/**
 * Store subtask due dates with time (DATETIME), not date-only.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * AlterSubtasksDueDateToDatetime migration.
 */
class AlterSubtasksDueDateToDatetime {

	/**
	 * Upgrade legacy DATE columns on boot when the migration ledger already ran.
	 *
	 * @return void
	 */
	public static function ensure(): void {
		( new self() )->run();
	}

	/**
	 * @return void
	 */
	public function run() {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_task_subtasks';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column = $wpdb->get_row( "SHOW COLUMNS FROM `{$table}` LIKE 'due_date'", ARRAY_A );
		if ( empty( $column ) ) {
			return;
		}

		$type = strtolower( (string) ( $column['Type'] ?? '' ) );
		if ( false !== strpos( $type, 'datetime' ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"UPDATE `{$table}` SET `due_date` = CONCAT(DATE(`due_date`), ' 09:00:00') WHERE `due_date` IS NOT NULL"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN `due_date` DATETIME NULL DEFAULT NULL" );
	}
}
