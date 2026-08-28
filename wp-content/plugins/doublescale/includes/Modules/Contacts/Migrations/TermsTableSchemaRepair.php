<?php
/**
 * Repair doublescale_terms schema when the table exists but is missing critical columns
 * or AUTO_INCREMENT on `id` (Migration::run skips dbDelta when the table already exists).
 *
 * @package DoubleScale\Modules\Contacts\Migrations
 */

namespace DoubleScale\Modules\Contacts\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * TermsTableSchemaRepair migration.
 */
class TermsTableSchemaRepair {

	/**
	 * Idempotent schema repair — safe to run on every install.
	 *
	 * @return void
	 */
	public function run(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_terms';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		$this->ensure_id_auto_increment( $table );
		$this->ensure_column(
			$table,
			'type',
			"ADD COLUMN `type` VARCHAR(20) NOT NULL DEFAULT 'list' COMMENT 'list or tag' AFTER `id`"
		);
		$this->ensure_column(
			$table,
			'slug',
			"ADD COLUMN `slug` VARCHAR(191) NOT NULL DEFAULT '' AFTER `name`"
		);
		$this->ensure_column(
			$table,
			'is_public',
			'ADD COLUMN `is_public` TINYINT(1) NOT NULL DEFAULT 1'
		);
		$this->backfill_empty_slugs( $table );
		$this->ensure_type_slug_unique_index( $table );
	}

	/**
	 * Ensure `id` is BIGINT AUTO_INCREMENT PRIMARY KEY.
	 *
	 * @param string $table Fully qualified table name.
	 *
	 * @return void
	 */
	private function ensure_id_auto_increment( string $table ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column = $wpdb->get_row( "SHOW COLUMNS FROM `{$table}` LIKE 'id'", ARRAY_A );
		if ( empty( $column ) ) {
			return;
		}

		$extra = isset( $column['Extra'] ) ? strtolower( (string) $column['Extra'] ) : '';
		if ( str_contains( $extra, 'auto_increment' ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"ALTER TABLE `{$table}` MODIFY COLUMN `id` BIGINT(20) NOT NULL AUTO_INCREMENT"
		);
	}

	/**
	 * Add a column when it is missing.
	 *
	 * @param string $table   Fully qualified table name.
	 * @param string $column  Column name.
	 * @param string $alter   ALTER TABLE fragment (ADD COLUMN …).
	 *
	 * @return void
	 */
	private function ensure_column( string $table, string $column, string $alter ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'", ARRAY_A );
		if ( ! empty( $exists ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE `{$table}` {$alter}" );
	}

	/**
	 * Backfill empty slugs from name for legacy rows.
	 *
	 * @param string $table Fully qualified table name.
	 *
	 * @return void
	 */
	private function backfill_empty_slugs( string $table ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT id, name, type, slug FROM `{$table}` WHERE slug = '' OR slug IS NULL",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$id   = (int) ( $row['id'] ?? 0 );
			$name = (string) ( $row['name'] ?? '' );
			if ( $id <= 0 || '' === $name ) {
				continue;
			}

			$base_slug = sanitize_title( $name );
			if ( '' === $base_slug ) {
				$base_slug = 'term-' . $id;
			}

			$slug  = $base_slug;
			$count = 1;
			$type  = (string) ( $row['type'] ?? 'list' );

			while ( $this->slug_taken( $table, $type, $slug, $id ) ) {
				$slug = $base_slug . '-' . $count++;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array( 'slug' => $slug ),
				array( 'id' => $id ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Whether a slug is already used for the same type.
	 *
	 * @param string $table      Fully qualified table name.
	 * @param string $type       Taxonomy type.
	 * @param string $slug       Candidate slug.
	 * @param int    $exclude_id Row ID to exclude.
	 *
	 * @return bool
	 */
	private function slug_taken( string $table, string $type, string $slug, int $exclude_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM `{$table}` WHERE type = %s AND slug = %s AND id != %d LIMIT 1",
				$type,
				$slug,
				$exclude_id
			)
		);

		return null !== $found;
	}

	/**
	 * Ensure UNIQUE KEY type_slug (type, slug) exists.
	 *
	 * @param string $table Fully qualified table name.
	 *
	 * @return void
	 */
	private function ensure_type_slug_unique_index( string $table ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_results(
			"SHOW INDEX FROM `{$table}` WHERE Key_name = 'type_slug'"
		);

		if ( ! empty( $existing ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"ALTER TABLE `{$table}` ADD UNIQUE KEY `type_slug` (`type`, `slug`)"
		);
	}
}
