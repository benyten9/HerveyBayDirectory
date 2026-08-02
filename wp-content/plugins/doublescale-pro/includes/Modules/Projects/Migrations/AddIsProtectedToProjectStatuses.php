<?php
/**
 * Add is_protected flag to project statuses for mandatory Open/Closed columns.
 *
 * @package DoubleScale\Pro\Modules\Projects\Migrations
 */

namespace DoubleScale\Pro\Modules\Projects\Migrations;

use DoubleScale\Pro\Modules\Projects\Services\ProjectManager;

defined( 'ABSPATH' ) || exit;

/**
 * AddIsProtectedToProjectStatuses migration.
 */
class AddIsProtectedToProjectStatuses {

	/**
	 * Ensure is_protected exists on project_statuses table (safe on every boot).
	 *
	 * @return void
	 */
	public static function ensure(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_project_statuses';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'is_protected'", ARRAY_A );
		if ( ! empty( $column ) ) {
			ProjectManager::instance()->ensure_protected_statuses();
			return;
		}

		( new self() )->run();
	}

	/**
	 * @return void
	 */
	public function run() {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_project_statuses';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"ALTER TABLE `{$table}` ADD COLUMN `is_protected` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_completed`"
		);

		$manager = ProjectManager::instance();
		$manager->seed_default_statuses();
		$manager->ensure_protected_statuses();
	}
}
