<?php
/**
 * Add the service date / period (date of supply, § 14 UStG) columns to invoices.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * SalesInvoiceTableServiceDateColumns migration.
 */
class SalesInvoiceTableServiceDateColumns {

	/**
	 * Safe on every boot, and idempotent.
	 *
	 * Called from the module boot as well as the migration ledger: a site whose
	 * ledger already advanced past this version never re-runs `run()`, and a
	 * missing column would fail every invoice save that carries the fields.
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

		foreach ( array( 'service_date', 'service_date_end' ) as $column ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$has_column = $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'" );
			if ( $has_column ) {
				continue;
			}

			// No AFTER — do not depend on any later column existing.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` ADD `{$column}` DATE NULL" );
		}
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
