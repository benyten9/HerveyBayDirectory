<?php
/**
 * Widen a document `currency` column to NULL and restore inherit-on-draft.
 *
 * Existing rows defaulted to 'USD', but drafts were *displayed* as the global
 * currency. Setting drafts to NULL restores that meaning. Idempotent: gated on
 * SHOW COLUMNS Null=YES so a later boot cannot re-blank an explicit draft.
 *
 * @package DoubleScale\Core\Database
 */

namespace DoubleScale\Core\Database;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;

/**
 * NullableCurrencyColumn helper.
 */
final class NullableCurrencyColumn {

	/**
	 * @param string $logical_table Table name without wp_ / doublescale_ prefix
	 *                               (e.g. `sales_invoices`).
	 * @return void
	 */
	public static function ensure( string $logical_table ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_' . $logical_table;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'currency'", ARRAY_A );
		if ( empty( $column ) ) {
			return;
		}

		$nullable = isset( $column[0]['Null'] ) && 'YES' === strtoupper( (string) $column[0]['Null'] );
		if ( $nullable ) {
			return;
		}

		// Step 1 must precede the NULL writes or MySQL strict mode rejects them.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE `{$table}` MODIFY `currency` VARCHAR(10) NULL DEFAULT NULL" );

		// Drafts were displaying the global; NULL makes them keep displaying it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE `{$table}` SET `currency` = NULL WHERE `sent_at` IS NULL" );

		$global = Settings::get_currency();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET `currency` = %s WHERE `sent_at` IS NOT NULL AND (`currency` IS NULL OR `currency` = '')",
				$global
			)
		);
	}
}
