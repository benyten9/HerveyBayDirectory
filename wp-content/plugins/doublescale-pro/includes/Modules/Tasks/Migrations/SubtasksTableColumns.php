<?php
/**
 * Add group_id, assigned_to, and due_date columns to the subtasks table.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * SubtasksTableColumns migration.
 */
class SubtasksTableColumns {

	/**
	 * Add missing subtask columns when the ledger ran this migration before the
	 * base table existed (glob order bug). Safe to call on every boot.
	 *
	 * @return void
	 */
	public static function ensure_columns(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_task_subtasks';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'group_id'", ARRAY_A );
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

		$table = $wpdb->prefix . 'doublescale_task_subtasks';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			throw new \RuntimeException(
				sprintf(
					'Cannot add subtask columns: table %s does not exist yet.',
					$table
				)
			);
		}

		$columns = array(
			'group_id'         => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL',
			'assigned_to'      => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL',
			'due_date'         => 'DATETIME NULL DEFAULT NULL',
			'reminder_at'      => 'DATETIME NULL DEFAULT NULL',
			'reminder_sent_at' => 'DATETIME NULL DEFAULT NULL',
		);

		foreach ( $columns as $name => $definition ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$column = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE '{$name}'", ARRAY_A );
			if ( ! empty( $column ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$definition}" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$index = $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'group_id'", ARRAY_A );
		if ( empty( $index ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD KEY group_id (group_id)" );
		}
	}
}
