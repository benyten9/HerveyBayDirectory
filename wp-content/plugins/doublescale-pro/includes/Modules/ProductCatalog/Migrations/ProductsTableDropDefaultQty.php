<?php
/**
 * Drop the default_qty column from products.
 *
 * Inserted lines always start at quantity 1 and are edited in the document, so
 * storing a per-product default added a field without a job. Early installs
 * created the column, hence this drop.
 *
 * @package DoubleScale\Pro\Modules\ProductCatalog
 */

namespace DoubleScale\Pro\Modules\ProductCatalog\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * ProductsTableDropDefaultQty migration.
 */
class ProductsTableDropDefaultQty {

	/**
	 * @return void
	 */
	public function run() {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_products';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_column = $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'default_qty'" );
		if ( ! $has_column ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN `default_qty`" );
	}
}
