<?php
/**
 * Add public-access hash column to projects.
 *
 * @package DoubleScale\Pro\Modules\Projects\Migrations
 */

namespace DoubleScale\Pro\Modules\Projects\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * AddHashToProjects migration.
 */
class AddHashToProjects {

	/**
	 * Ensure hash exists on projects table (safe on every boot).
	 *
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'hash'", ARRAY_A );
		if ( ! empty( $column ) ) {
			self::backfill_missing_hashes( $table );
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'hash'", ARRAY_A );
		if ( ! empty( $column ) ) {
			self::backfill_missing_hashes( $table );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"ALTER TABLE `{$table}` ADD COLUMN `hash` VARCHAR(32) NULL AFTER `title`, ADD UNIQUE KEY hash (hash)"
		);

		self::backfill_missing_hashes( $table );
	}

	/**
	 * @param string $table Full table name.
	 * @return void
	 */
	private static function backfill_missing_hashes( string $table ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col( "SELECT id FROM `{$table}` WHERE hash IS NULL OR hash = ''" );
		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $id ) {
			$hash = self::generate_hash();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array( 'hash' => $hash ),
				array( 'id' => (int) $id ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * @return string
	 */
	private static function generate_hash(): string {
		try {
			return md5( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			return md5( uniqid( (string) wp_rand(), true ) );
		}
	}
}
