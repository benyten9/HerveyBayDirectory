<?php
/**
 * Add optional notes column to subtasks.
 *
 * @package DoubleScale\Pro\Modules\Tasks\Migrations
 */

namespace DoubleScale\Pro\Modules\Tasks\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * SubtasksTableNotesColumn migration.
 */
final class SubtasksTableNotesColumn {

	/**
	 * Ensure the notes column exists on boot.
	 *
	 * @return void
	 */
	public static function ensure(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_task_subtasks';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$column = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'notes'", ARRAY_A );
		if ( ! empty( $column ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `notes` TEXT NULL DEFAULT NULL" );
	}
}
