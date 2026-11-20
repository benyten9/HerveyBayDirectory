<?php
/**
 * Add the optional project link to support tickets on existing sites.
 *
 * Fresh installs get `project_id` and its index from SupportTicketsTable.
 * Sites created before have the table already, so this adds the column (if
 * the declared-columns sync has not yet) and the index the project's Tickets
 * tab filters on. Runs once (migration ledger); safe to run again.
 *
 * @package DoubleScale\Modules\Support\Migrations
 */

namespace DoubleScale\Modules\Support\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * SupportTicketsTableProjectColumn migration.
 */
class SupportTicketsTableProjectColumn {

	/**
	 * @return void
	 */
	public function run() {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_support_tickets';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'project_id'" ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` ADD `project_id` BIGINT(20) UNSIGNED NULL" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $wpdb->get_var( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_project'" ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` ADD KEY `idx_project` (`project_id`)" );
		}
	}
}
