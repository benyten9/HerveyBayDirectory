<?php
/**
 * Add content sections field to invoices.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * SalesInvoiceTableContentColumns migration.
 */
class SalesInvoiceTableContentColumns {

	/**
	 * Safe on every boot, and idempotent.
	 *
	 * Called from the module boot as well as the migration ledger, because a
	 * site whose ledger already advanced past this version never re-runs
	 * `run()` — so a table that missed the ALTER (a multisite subsite created
	 * out of order, or a run where the statement failed) stays permanently
	 * without `sections`, and every invoice INSERT fails against it. The
	 * proposal twin was hardened the same way for the same reason.
	 *
	 * @return void
	 */
	public static function ensure(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_sales_invoices';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_column = $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'sections'" );
		if ( $has_column ) {
			return;
		}

		// No AFTER — do not depend on any later column existing.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `{$table}` ADD `sections` JSON NULL" );
	}

	/**
	 * Migration-ledger entry point.
	 *
	 * @return void
	 */
	public function run() {
		self::ensure();
	}
}
