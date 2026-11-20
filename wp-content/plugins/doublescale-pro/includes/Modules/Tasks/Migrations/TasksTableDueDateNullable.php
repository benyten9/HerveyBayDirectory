<?php
/**
 * A task needs no due date: make tasks.due_date nullable.
 *
 * @package DoubleScale\Pro\Modules\Tasks\Migrations
 */

namespace DoubleScale\Pro\Modules\Tasks\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * TasksTableDueDateNullable migration.
 */
class TasksTableDueDateNullable {

	/**
	 * Upgrade older tables (due_date DATE NOT NULL) on boot; safe to repeat.
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

		$table = $wpdb->prefix . 'doublescale_tasks';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column = $wpdb->get_row( "SHOW COLUMNS FROM `{$table}` LIKE 'due_date'", ARRAY_A );
		if ( empty( $column ) || 'YES' === strtoupper( (string) ( $column['Null'] ?? '' ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN `due_date` DATE NULL DEFAULT NULL" );
	}
}
