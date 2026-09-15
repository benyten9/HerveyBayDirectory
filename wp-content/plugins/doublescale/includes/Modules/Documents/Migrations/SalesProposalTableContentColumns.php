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
	 * Safe on every boot. Earlier ALTERs used AFTER signed_ip / sections; those
	 * columns were added in later migrations, so the ALTER failed, the ledger
	 * still advanced, and sites were left without `terms`.
	 *
	 * @return void
	 */
	public static function ensure(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_sales_proposals';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		$columns = array(
			'sections' => 'JSON NULL',
			'terms'    => 'TEXT NULL',
		);

		foreach ( $columns as $name => $definition ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$has_column = $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE '{$name}'" );
			if ( $has_column ) {
				continue;
			}

			// No AFTER — do not depend on signature/response columns existing.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` ADD `{$name}` {$definition}" );
		}
	}

	/**
	 * @return void
	 */
	public function run() {
		self::ensure();
	}
}
