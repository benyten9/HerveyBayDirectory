<?php
/**
 * Rename tasks.stage_id → status_id (and task recurrences column).
 *
 * @package DoubleScale\Pro\Modules\Tasks\Migrations
 */

namespace DoubleScale\Pro\Modules\Tasks\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * RenameTaskStageIdToStatusId migration.
 */
class RenameTaskStageIdToStatusId {

	/**
	 * Idempotent rename on every boot.
	 *
	 * @return void
	 */
	public static function ensure(): void {
		self::rename_tasks_column();
		self::rename_recurrences_column();
	}

	/**
	 * @return void
	 */
	private static function rename_tasks_column(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_tasks';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_status_id = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'status_id'", ARRAY_A );
		if ( ! empty( $has_status_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_stage_id = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'stage_id'", ARRAY_A );
		if ( empty( $has_stage_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"ALTER TABLE `{$table}` CHANGE COLUMN `stage_id` `status_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_old_index = $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_stage_id'", ARRAY_A );
		if ( ! empty( $has_old_index ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `idx_stage_id`" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_new_index = $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_status_id'", ARRAY_A );
		if ( empty( $has_new_index ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `idx_status_id` (`status_id`)" );
		}
	}

	/**
	 * @return void
	 */
	private static function rename_recurrences_column(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_task_recurrences';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_status_id = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'status_id'", ARRAY_A );
		if ( ! empty( $has_status_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_stage_id = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'stage_id'", ARRAY_A );
		if ( empty( $has_stage_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"ALTER TABLE `{$table}` CHANGE COLUMN `stage_id` `status_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL"
		);
	}
}
