<?php
/**
 * Add progress + calculate_progress columns to projects.
 *
 * @package DoubleScale\Pro\Modules\Projects\Migrations
 */

namespace DoubleScale\Pro\Modules\Projects\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectsTableProgressColumn migration.
 */
class ProjectsTableProgressColumn {

	/**
	 * Ensure progress columns exist on projects table (safe on every boot).
	 *
	 * @return void
	 */
	public static function ensure(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_projects';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		( new self() )->run();
	}

	/**
	 * @return void
	 */
	public function run() {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_projects';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$progress = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'progress'", ARRAY_A );
		if ( empty( $progress ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				"ALTER TABLE `{$table}` ADD COLUMN `progress` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `budget`"
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$calculate = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'calculate_progress'", ARRAY_A );
		if ( empty( $calculate ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				"ALTER TABLE `{$table}` ADD COLUMN `calculate_progress` TINYINT(1) NOT NULL DEFAULT 0 AFTER `progress`"
			);
		}
	}
}
