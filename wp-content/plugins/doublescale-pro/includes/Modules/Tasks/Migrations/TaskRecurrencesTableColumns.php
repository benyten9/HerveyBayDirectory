<?php
/**
 * Add month_mode and year_month columns to task recurrences.
 *
 * @package DoubleScale\Pro\Modules\Tasks\Migrations
 */

namespace DoubleScale\Pro\Modules\Tasks\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * TaskRecurrencesTableColumns migration.
 */
class TaskRecurrencesTableColumns {

	/**
	 * Ensure optional recurrence columns exist (idempotent).
	 *
	 * @return void
	 */
	public static function ensure(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_task_recurrences';
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder -- SHOW TABLES LIKE.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			( new TaskRecurrencesTable() )->run();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_col( "DESC {$table}", 0 );

		if ( ! in_array( 'month_mode', $columns, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `month_mode` VARCHAR(10) DEFAULT NULL AFTER `month_day`" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_col( "DESC `{$table}`", 0 );

		if ( ! in_array( 'year_month', $columns, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `year_month` TINYINT UNSIGNED DEFAULT NULL AFTER `month_mode`" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_col( "DESC `{$table}`", 0 );

		if ( ! in_array( 'repeat_when_completed', $columns, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `repeat_when_completed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `year_month`" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_col( "DESC `{$table}`", 0 );

		if ( ! in_array( 'status_id', $columns, true ) && ! in_array( 'stage_id', $columns, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `status_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL AFTER `repeat_when_completed`" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_col( "DESC `{$table}`", 0 );

		if ( ! in_array( 'create_new_on_repeat', $columns, true ) ) {
			$after_column = in_array( 'status_id', $columns, true ) ? 'status_id' : 'stage_id';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `create_new_on_repeat` TINYINT(1) NOT NULL DEFAULT 1 AFTER `{$after_column}`" );
		}
	}
}
