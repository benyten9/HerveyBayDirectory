<?php
/**
 * Add customer-response timestamps to proposals.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * SalesProposalTableResponseColumns migration.
 */
class SalesProposalTableResponseColumns {

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
			'sent_at'        => "ADD `sent_at` DATETIME NULL AFTER `allow_comments`",
			'accepted_at'    => "ADD `accepted_at` DATETIME NULL AFTER `sent_at`",
			'declined_at'    => "ADD `declined_at` DATETIME NULL AFTER `accepted_at`",
			'decline_reason' => "ADD `decline_reason` TEXT NULL AFTER `declined_at`",
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
