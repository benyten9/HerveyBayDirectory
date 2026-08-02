<?php
/**
 * Add customer-facing timestamps to invoices.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * SalesInvoiceTableCustomerColumns migration.
 */
class SalesInvoiceTableCustomerColumns {

	/**
	 * @return void
	 */
	public function run() {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_sales_invoices';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		$columns = array(
			'sent_at'   => "ADD `sent_at` DATETIME NULL AFTER `terms`",
			'viewed_at' => "ADD `viewed_at` DATETIME NULL AFTER `sent_at`",
		);

		foreach ( $columns as $name => $ddl ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$has_column = $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE '{$name}'" );
			if ( $has_column ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` {$ddl}" );
		}
	}
}
