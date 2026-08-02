<?php
/**
 * Drop the legacy activities.contact_id column once associations are the source of truth.
 *
 * Plain class (not a Migration subclass) — Migration::run() skips existing tables,
 * so schema changes to existing tables must be invoked from Install.
 *
 * @package DoubleScale\Modules\Activities\Migrations
 */

namespace DoubleScale\Modules\Activities\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;

/**
 * DropActivityContactIdColumn migration.
 */
class DropActivityContactIdColumn {

	/**
	 * Option flag set when the column has been dropped.
	 */
	private const OPTION_FLAG = 'doublescale_activity_contact_id_dropped';

	/**
	 * Prerequisite: contact associations backfill must have completed.
	 */
	private const BACKFILL_FLAG = 'doublescale_activity_contact_assoc_migrated';

	/**
	 * Run until {@see doublescale_activity_contact_id_dropped} is set.
	 * Safe to call on every install — no-ops once the column is gone.
	 *
	 * @return void
	 */
	public function run() {
		global $wpdb;

		if ( get_option( self::OPTION_FLAG ) ) {
			return;
		}

		$activities_table   = $wpdb->prefix . 'doublescale_activities';
		$associations_table = $wpdb->prefix . 'doublescale_activity_associations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $activities_table ) ) !== $activities_table ) {
			throw new \RuntimeException( 'Activities table does not exist yet.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_column = $wpdb->get_var( "SHOW COLUMNS FROM `{$activities_table}` LIKE 'contact_id'" );
		if ( ! $has_column ) {
			update_option( self::OPTION_FLAG, 1 );
			delete_option( 'doublescale_activity_contact_id_drop_deferred' );
			return;
		}

		if ( ! get_option( self::BACKFILL_FLAG ) ) {
			throw new \RuntimeException( 'Contact association backfill must complete before dropping contact_id.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $associations_table ) ) !== $associations_table ) {
			throw new \RuntimeException( 'Activity associations table does not exist yet.' );
		}

		if ( ! EnsureActivityAssociationUniqueIndex::has_unique_index( $associations_table ) ) {
			throw new \RuntimeException(
				'Cannot drop contact_id: unique_activity_entity index is not present on activity_associations.'
			);
		}

		$contact_type = ActivityAssociationModel::ENTITY_TYPE_CONTACT;

		// Refuse to drop while any activity still lacks its contact association.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$missing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$activities_table} a
				WHERE a.contact_id IS NOT NULL
				AND a.contact_id > 0
				AND NOT EXISTS (
					SELECT 1 FROM {$associations_table} aa
					WHERE aa.activity_id = a.id
					AND aa.entity_type = %d
					AND aa.entity_id = a.contact_id
				)",
				$contact_type
			)
		);

		if ( $missing > 0 ) {
			throw new \RuntimeException(
				sprintf(
					'Cannot drop contact_id: %d activities still missing contact associations.',
					$missing
				)
			);
		}

		$indexes_to_drop = array(
			'contact_id',
			'composite_contact_activity_date',
			'composite_contact_type',
		);

		foreach ( $indexes_to_drop as $index_name ) {
			$this->drop_index_if_exists( $activities_table, $index_name );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE `{$activities_table}` DROP COLUMN `contact_id`" );
		$this->assert_db_ok( 'drop activities.contact_id' );

		update_option( self::OPTION_FLAG, 1 );
		delete_option( 'doublescale_activity_contact_id_drop_deferred' );
	}

	/**
	 * @param string $table      Fully qualified table name.
	 * @param string $index_name Index name.
	 * @return void
	 */
	private function drop_index_if_exists( string $table, string $index_name ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_results(
			$wpdb->prepare(
				"SHOW INDEX FROM `{$table}` WHERE Key_name = %s",
				$index_name
			)
		);

		if ( empty( $exists ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `{$index_name}`" );
		$this->assert_db_ok( 'drop index ' . $index_name );
	}

	/**
	 * @param string $context Error context label.
	 * @return void
	 */
	private function assert_db_ok( string $context ): void {
		global $wpdb;
		if ( property_exists( $wpdb, 'last_error' ) && '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException( $context . ': ' . (string) $wpdb->last_error );
		}
	}
}
