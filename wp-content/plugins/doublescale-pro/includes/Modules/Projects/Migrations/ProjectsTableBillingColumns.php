<?php
/**
 * How a project is billed: fee type, hourly rate, tax, estimated hours, and
 * whether its client sees the time worked (and its notes) in the portal.
 *
 * @package DoubleScale\Pro\Modules\Projects
 */

namespace DoubleScale\Pro\Modules\Projects\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the billing columns to the projects table (safe on every boot).
 */
class ProjectsTableBillingColumns {

	/**
	 * Column name => definition. Mirrored in {@see ProjectsTable::get_query()}.
	 *
	 * @var array<string, string>
	 */
	const COLUMNS = array(
		'fee_type'            => 'VARCHAR(20) NULL',
		'hourly_rate'         => 'DECIMAL(15,2) NULL',
		'tax_id'              => 'BIGINT(20) UNSIGNED NULL',
		'estimated_hours_min' => 'DECIMAL(10,2) NULL',
		'estimated_hours_max' => 'DECIMAL(10,2) NULL',
		'portal_show_time'    => 'TINYINT(1) NOT NULL DEFAULT 0',
		'portal_show_notes'   => 'TINYINT(1) NOT NULL DEFAULT 0',
		'portal_show_tasks'   => 'TINYINT(1) NOT NULL DEFAULT 0',
		'portal_show_tickets' => 'TINYINT(1) NOT NULL DEFAULT 0',
	);

	/**
	 * @return void
	 */
	public static function ensure(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_projects';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		( new self() )->run();
	}

	/**
	 * @return void
	 */
	public function run() {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_projects';

		// One query for every column instead of one per column.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$present = (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" );

		foreach ( self::COLUMNS as $column => $definition ) {
			if ( in_array( $column, $present, true ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}" );
		}
	}
}
