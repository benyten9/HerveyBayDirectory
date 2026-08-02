<?php
/**
 * Add public visibility flag for lists on the subscription preference page.
 *
 * Filename sorts after TermsTable so MigrationRunner creates the table first
 * on fresh installs. The old TermsPublicColumn name sorted before TermsTable,
 * returned early when the table was missing, and was still recorded as ran —
 * so `is_public` never got added. TermsSchemaRepair also ensures this column
 * on every Install::install() for sites that already recorded the bad run.
 *
 * @package DoubleScale\Modules\Contacts\Migrations
 */

namespace DoubleScale\Modules\Contacts\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * TermsTablePublicColumn migration.
 */
class TermsTablePublicColumn {

	/**
	 * @return void
	 */
	public function run() {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_terms';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'is_public'", ARRAY_A );
		if ( ! empty( $column ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"ALTER TABLE `{$table}` ADD COLUMN `is_public` TINYINT(1) NOT NULL DEFAULT 1"
		);
	}
}
