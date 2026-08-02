<?php
/**
 * Backfill contact_id values into polymorphic activity_associations rows.
 *
 * Plain class (not a Migration subclass) — Migration::run() skips existing tables,
 * so data backfills must be invoked explicitly from Install.
 *
 * @package DoubleScale\Modules\Activities\Migrations
 */

namespace DoubleScale\Modules\Activities\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;

/**
 * BackfillContactActivityAssociations migration.
 */
class BackfillContactActivityAssociations {

	/**
	 * Rows per INSERT…SELECT chunk.
	 */
	private const CHUNK_SIZE = 500;

	/**
	 * Option flag set when backfill completes successfully.
	 */
	private const OPTION_FLAG = 'doublescale_activity_contact_assoc_migrated';

	/**
	 * Run until {@see doublescale_activity_contact_assoc_migrated} is set.
	 * Safe to call on every install — retries until the flag is set.
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

		// Column already dropped (cleanup ran first on a fresh/partial state) — nothing to backfill.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_column = $wpdb->get_var( "SHOW COLUMNS FROM `{$activities_table}` LIKE 'contact_id'" );
		if ( ! $has_column ) {
			EnsureActivityAssociationUniqueIndex::ensure_on_table( $associations_table );
			update_option( self::OPTION_FLAG, 1 );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $associations_table ) ) !== $associations_table ) {
			throw new \RuntimeException( 'Activity associations table does not exist yet.' );
		}

		$contact_type = ActivityAssociationModel::ENTITY_TYPE_CONTACT;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$expected = (int) $wpdb->get_var(
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

		$wpdb->query( 'START TRANSACTION' );
		try {
			$this->backfill_contact_associations_in_chunks( $activities_table, $associations_table, $contact_type );
			$this->assert_db_ok( 'contact association backfill' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$remaining = (int) $wpdb->get_var(
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

			if ( $remaining > 0 ) {
				throw new \RuntimeException(
					sprintf(
						'Contact association backfill incomplete (%d remaining of %d expected).',
						$remaining,
						$expected
					)
				);
			}

			$wpdb->query( 'COMMIT' );
			$this->assert_db_ok( 'contact association backfill commit' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Contact activity association backfill failed',
					array(
						'source' => 'backfill-contact-activity-associations',
						'error'  => $e->getMessage(),
					)
				);
			}
			throw $e;
		}

		EnsureActivityAssociationUniqueIndex::ensure_on_table( $associations_table );
		$this->log_json_only_proposal_invoice_counts( $activities_table );

		update_option( self::OPTION_FLAG, 1 );
	}

	/**
	 * Keyset-chunked INSERT…SELECT of contact associations.
	 *
	 * @param string $activities_table   Fully qualified activities table.
	 * @param string $associations_table Fully qualified associations table.
	 * @param int    $contact_type       ENTITY_TYPE_CONTACT constant.
	 *
	 * @return void
	 */
	private function backfill_contact_associations_in_chunks( string $activities_table, string $associations_table, int $contact_type ): void {
		global $wpdb;

		$last_id = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$associations_table}
						( activity_id, entity_type, entity_id, created_at, updated_at )
					SELECT
						a.id, %d, a.contact_id, a.created_at, a.updated_at
					FROM {$activities_table} a
					WHERE a.id > %d
					AND a.contact_id IS NOT NULL
					AND a.contact_id > 0
					AND NOT EXISTS (
						SELECT 1 FROM {$associations_table} aa
						WHERE aa.activity_id = a.id
						AND aa.entity_type = %d
						AND aa.entity_id = a.contact_id
					)
					ORDER BY a.id ASC
					LIMIT %d",
					$contact_type,
					$last_id,
					$contact_type,
					self::CHUNK_SIZE
				)
			);
			$this->assert_db_ok( 'copy contact associations' );

			// Advance keyset by the max id among candidates in this window,
			// including rows that already had associations (NOT EXISTS filtered them out).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$max = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(id) FROM (
						SELECT a.id FROM {$activities_table} a
						WHERE a.id > %d
						AND a.contact_id IS NOT NULL
						AND a.contact_id > 0
						ORDER BY a.id ASC
						LIMIT %d
					) chunk",
					$last_id,
					self::CHUNK_SIZE
				)
			);

			if ( $max <= $last_id ) {
				break;
			}
			$last_id = $max;
		} while ( true );
	}

	/**
	 * Log a count of activities that still only store proposal/invoice ids in JSON.
	 * No writes — informational for the follow-up morph wiring.
	 *
	 * @param string $activities_table Fully qualified activities table.
	 *
	 * @return void
	 */
	private function log_json_only_proposal_invoice_counts( string $activities_table ): void {
		global $wpdb;

		if ( ! function_exists( 'doublescale_get_logger' ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$proposal_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$activities_table}
			WHERE JSON_EXTRACT(data, '$.proposal_id') IS NOT NULL"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$invoice_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$activities_table}
			WHERE JSON_EXTRACT(data, '$.invoice_id') IS NOT NULL"
		);

		doublescale_get_logger()->info(
			'Activities still storing proposal/invoice ids in JSON only',
			array(
				'source'               => 'backfill-contact-activity-associations',
				'proposal_json_count'  => $proposal_count,
				'invoice_json_count'   => $invoice_count,
			)
		);
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
