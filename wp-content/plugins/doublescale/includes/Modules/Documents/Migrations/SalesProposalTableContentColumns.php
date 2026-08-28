<?php
/**
 * Add content sections and terms fields to proposals.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * SalesProposalTableContentColumns migration.
 */
class SalesProposalTableContentColumns {

	/**
	 * @return void
	 */
	public function run() {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_sales_proposals';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		$columns = array(
			'sections' => "ADD `sections` JSON NULL AFTER `signed_ip`",
			'terms'    => "ADD `terms` TEXT NULL AFTER `sections`",
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
