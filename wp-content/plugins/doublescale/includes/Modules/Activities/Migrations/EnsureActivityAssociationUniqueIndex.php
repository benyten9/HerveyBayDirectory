<?php
/**
 * Ensure activity_associations has a unique (activity_id, entity_type, entity_id) index.
 *
 * Plain class (not a Migration subclass) — existing tables skip dbDelta, so this
 * idempotent ALTER runs from Install on every upgrade until the index exists.
 *
 * @package DoubleScale\Modules\Activities\Migrations
 */

namespace DoubleScale\Modules\Activities\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * EnsureActivityAssociationUniqueIndex migration.
 */
class EnsureActivityAssociationUniqueIndex {

	/**
	 * Index name enforced on activity_associations.
	 */
	public const INDEX_NAME = 'unique_activity_entity';

	/**
	 * Legacy non-unique index superseded by {@see INDEX_NAME}.
	 */
	public const REDUNDANT_INDEX = 'composite_activity_entity';

	/**
	 * Add the unique index when the associations table exists and lacks it.
	 *
	 * Safe to call on every install — no option flag.
	 *
	 * @return void
	 */
	public function run(): void {
		global $wpdb;

		$associations_table = $wpdb->prefix . 'doublescale_activity_associations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $associations_table ) ) !== $associations_table ) {
			return;
		}

		self::ensure_on_table( $associations_table );
	}

	/**
	 * Add the unique index to a fully qualified associations table name.
	 *
	 * @param string $associations_table Fully qualified associations table.
	 *
	 * @return void
	 */
	public static function ensure_on_table( string $associations_table ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $associations_table ) ) !== $associations_table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_results(
			"SHOW INDEX FROM `{$associations_table}` WHERE Key_name = '" . self::INDEX_NAME . "'"
		);

		if ( empty( $existing ) ) {
			if ( ! self::dedupe_activity_entity_tuples( $associations_table ) ) {
				return;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				"ALTER TABLE `{$associations_table}`
				ADD UNIQUE KEY `" . self::INDEX_NAME . "` ( activity_id, entity_type, entity_id )"
			);

			if ( property_exists( $wpdb, 'last_error' ) && '' !== (string) $wpdb->last_error ) {
				if ( function_exists( 'doublescale_get_logger' ) ) {
					doublescale_get_logger()->error(
						'Could not add unique_activity_entity index after deduplication',
						array(
							'source' => 'ensure-activity-association-unique-index',
							'error'  => (string) $wpdb->last_error,
						)
					);
				}
				$wpdb->last_error = '';
				return;
			}
		}

		self::drop_redundant_composite_index( $associations_table );
	}

	/**
	 * Whether the unique activity/entity index exists on the associations table.
	 *
	 * @param string $associations_table Fully qualified associations table.
	 *
	 * @return bool
	 */
	public static function has_unique_index( string $associations_table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $associations_table ) ) !== $associations_table ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_results(
			"SHOW INDEX FROM `{$associations_table}` WHERE Key_name = '" . self::INDEX_NAME . "'"
		);

		return ! empty( $existing );
	}

	/**
	 * Delete duplicate morph rows, keeping the lowest id per (activity_id, entity_type, entity_id).
	 *
	 * @param string $associations_table Fully qualified associations table.
	 *
	 * @return bool True when deduplication succeeded (including zero rows to delete).
	 */
	private static function dedupe_activity_entity_tuples( string $associations_table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			"DELETE aa FROM `{$associations_table}` aa
			INNER JOIN (
				SELECT activity_id, entity_type, entity_id, MIN(id) AS keep_id
				FROM `{$associations_table}`
				GROUP BY activity_id, entity_type, entity_id
				HAVING COUNT(*) > 1
			) d ON aa.activity_id = d.activity_id
				AND aa.entity_type = d.entity_type
				AND aa.entity_id = d.entity_id
				AND aa.id <> d.keep_id"
		);

		if ( false === $deleted ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Could not deduplicate activity association rows',
					array(
						'source' => 'ensure-activity-association-unique-index',
						'error'  => (string) $wpdb->last_error,
					)
				);
			}
			$wpdb->last_error = '';
			return false;
		}

		if ( $deleted > 0 && function_exists( 'doublescale_get_logger' ) ) {
			doublescale_get_logger()->info(
				'Removed duplicate activity association rows before unique index',
				array(
					'source'  => 'ensure-activity-association-unique-index',
					'deleted' => (int) $deleted,
				)
			);
		}

		return true;
	}

	/**
	 * Drop the legacy non-unique composite index when the unique index is in place.
	 *
	 * @param string $associations_table Fully qualified associations table.
	 *
	 * @return void
	 */
	private static function drop_redundant_composite_index( string $associations_table ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_results(
			"SHOW INDEX FROM `{$associations_table}` WHERE Key_name = '" . self::REDUNDANT_INDEX . "'"
		);

		if ( empty( $existing ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"ALTER TABLE `{$associations_table}` DROP INDEX `" . self::REDUNDANT_INDEX . "`"
		);

		if ( property_exists( $wpdb, 'last_error' ) && '' !== (string) $wpdb->last_error ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->warning(
					'Could not drop redundant composite_activity_entity index',
					array(
						'source' => 'ensure-activity-association-unique-index',
						'error'  => (string) $wpdb->last_error,
					)
				);
			}
			$wpdb->last_error = '';
		}
	}
}
