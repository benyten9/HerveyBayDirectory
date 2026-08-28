<?php
/**
 * Add group_id column to products.
 *
 * Separate from ProductsTable so installs that already created the products
 * table pick the column up. Migrations run in sorted filename order, so this
 * runs after ProductsTable.
 *
 * @package DoubleScale\Pro\Modules\ProductCatalog
 */

namespace DoubleScale\Pro\Modules\ProductCatalog\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * ProductsTableGroupColumn migration.
 */
class ProductsTableGroupColumn {

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
		$has_column = $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'group_id'" );
		if ( $has_column ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE `{$table}` ADD `group_id` BIGINT(20) UNSIGNED NULL AFTER `unit`" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `idx_group_id` (`group_id`)" );
	}
}
