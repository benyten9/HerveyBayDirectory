<?php
/**
 * Add the ticket columns that came after the tickets table: first_response_at,
 * resolved_at, satisfaction*, merged_into_id, and their indexes.
 *
 * Safe on every boot and idempotent. Called from the support module's boot
 * (once per schema revision, {@see doublescale_ensure_once()}) as well as the
 * migration ledger: a site whose ledger is already past this module never
 * runs `run()` again.
 *
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\SchemaColumns;

/**
 * SupportTicketsTableTimingColumns migration.
 */
class SupportTicketsTableTimingColumns {

	/**
	 * Index name => column list.
	 */
	private const INDEXES = array(
		'idx_first_response' => '(first_response_at)',
		'idx_resolved'       => '(resolved_at)',
		'idx_merged'         => '(merged_into_id)',
	);

	/**
	 * @return void
	 */
	public static function ensure(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_support_tickets';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		// Every column of the CREATE definition the table lacks, no AFTER.
		SchemaColumns::ensure_from_query( $table, ( new SupportTicketsTable() )->get_query() );

		// Adding columns never adds keys.
		foreach ( self::INDEXES as $name => $columns ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $name ) ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `{$name}` {$columns}" );
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
