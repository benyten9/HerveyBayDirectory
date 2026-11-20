<?php
/**
 * Add the `position` column to custom fields, so fields can be put in any
 * order without renumbering them.
 *
 * @package DoubleScale\Pro\Modules\CustomFields\Migrations
 */

namespace DoubleScale\Pro\Modules\CustomFields\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * CustomFieldsTablePositionColumn migration.
 */
class CustomFieldsTablePositionColumn {

	/**
	 * Set once the column is on disk, so ordering queries can rely on it
	 * without probing the schema on every request.
	 */
	const READY_OPTION = 'doublescale_custom_fields_position_ready';

	/**
	 * Ensure the column exists and every field has a position (safe on every boot).
	 *
	 * @return void
	 */
	public static function ensure(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_custom_fields';

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

		$table = $wpdb->prefix . 'doublescale_custom_fields';

		if ( ! self::column_on_disk( $table ) ) {
			// The table's own CREATE definition declares the column; the
			// migration adds whatever it declares that is missing on disk.
			( new CustomFieldsTable() )->ensure_columns();
			if ( ! self::column_on_disk( $table ) ) {
				return;
			}
		}

		update_option( self::READY_OPTION, 'yes', true );
		self::backfill( $table );
	}

	/**
	 * Number the fields of every scope that still has unnumbered (0) fields.
	 *
	 * Fields have always shown in id order, so numbering by (position, id)
	 * keeps every screen exactly as it was: before a drag, all fields are 0
	 * and this is plain id order. A field added in between (already given
	 * the next number) keeps its place after them too.
	 *
	 * @param string $table Full table name.
	 * @return void
	 */
	public static function backfill( string $table ): void {
		global $wpdb;

		// The table name is ours (prefix + fixed name); WordPress 5.8 has no
		// placeholder for identifiers, so it is joined in and values are prepared.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$scopes = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT scope FROM `' . $table . '` WHERE position = %d', 0 ) );
		foreach ( (array) $scopes as $scope ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare( 'SELECT id FROM `' . $table . '` WHERE scope = %s ORDER BY position ASC, id ASC', (string) $scope )
			);
			$position = 0;
			foreach ( (array) $ids as $id ) {
				++$position;
				$wpdb->update( $table, array( 'position' => $position ), array( 'id' => (int) $id ), array( '%d' ), array( '%d' ) );
			}
		}
		// phpcs:enable
	}

	/**
	 * @param string $table Full table name.
	 * @return bool
	 */
	public static function column_on_disk( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Our own table name; no identifier placeholder on WP 5.8.
		$column = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM `' . $table . '` LIKE %s', 'position' ), ARRAY_A );
		return ! empty( $column );
	}
}
