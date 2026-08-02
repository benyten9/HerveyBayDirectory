<?php
/**
 * Copy legacy per-module attachment tables into the unified store, then drop them.
 *
 * @package DoubleScale\Core\Database\Migrations
 */

namespace DoubleScale\Core\Database\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * MigrateLegacyAttachments migration.
 */
class MigrateLegacyAttachments {

	/**
	 * Rows per INSERT…SELECT chunk.
	 */
	private const CHUNK_SIZE = 500;

	/**
	 * Run until {@see doublescale_attachments_unified} is set. Safe to call on every
	 * install — retries when the table-create migration had not run yet on a prior pass.
	 *
	 * @return void
	 */
	public function run() {
		global $wpdb;

		if ( get_option( 'doublescale_attachments_unified' ) ) {
			return;
		}

		$new      = $wpdb->prefix . 'doublescale_attachments';
		$support  = $wpdb->prefix . 'doublescale_support_attachments';
		$contract = $wpdb->prefix . 'doublescale_sales_contract_attachments';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new ) ) !== $new ) {
			throw new \RuntimeException( 'Unified attachments table does not exist yet.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$support_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $support ) ) === $support;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$contract_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $contract ) ) === $contract;

		if ( ! $support_exists && ! $contract_exists ) {
			update_option( 'doublescale_attachments_unified', 1 );
			return;
		}

		$expected_support  = $support_exists ? $this->table_row_count( $support ) : 0;
		$expected_contract = $contract_exists ? $this->table_row_count( $contract ) : 0;

		$wpdb->query( 'START TRANSACTION' );
		try {
			if ( $support_exists ) {
				$this->copy_support_in_chunks( $support, $new );
			}
			if ( $contract_exists ) {
				$this->copy_contract_in_chunks( $contract, $new );
			}

			$this->assert_db_ok( 'attachment copy' );

			$copied_support = $expected_support > 0
				? $this->count_unified_rows( $new, 'support_ticket' )
				: 0;
			$copied_contract = $expected_contract > 0
				? $this->count_unified_rows( $new, 'sales_contract' )
				: 0;

			if ( $copied_support < $expected_support || $copied_contract < $expected_contract ) {
				throw new \RuntimeException(
					sprintf(
						'Attachment copy incomplete (support %d/%d, contract %d/%d).',
						$copied_support,
						$expected_support,
						$copied_contract,
						$expected_contract
					)
				);
			}

			$wpdb->query( 'COMMIT' );
			$this->assert_db_ok( 'attachment copy commit' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Attachment unification failed',
					array(
						'source' => 'migrate-legacy-attachments',
						'error'  => $e->getMessage(),
					)
				);
			}
			throw $e;
		}

		foreach ( array( $support, $contract ) as $old ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ) === $old ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DROP TABLE {$old}" );
				$this->assert_db_ok( 'drop legacy attachment table' );
			}
		}

		update_option( 'doublescale_attachments_unified', 1 );
	}

	/**
	 * @param string $table Table name.
	 * @return int
	 */
	private function table_row_count( string $table ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * @param string $table           Unified table.
	 * @param string $attachable_type Owner type.
	 * @return int
	 */
	private function count_unified_rows( string $table, string $attachable_type ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE attachable_type = %s",
				$attachable_type
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

	/**
	 * @param string $source Source table (fully qualified).
	 * @param string $dest   Destination table (fully qualified).
	 * @return void
	 */
	private function copy_support_in_chunks( string $source, string $dest ): void {
		global $wpdb;

		$last_id = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$dest}
						( attachable_type, attachable_id, activity_id, user_id, contact_id,
						  file_name, file_path, file_type, file_size, file_hash, content_id,
						  driver, status, created_at, updated_at )
					SELECT
						'support_ticket', ticket_id, activity_id, user_id, contact_id,
						file_name, file_path, file_type, file_size, file_hash, content_id,
						driver, status, created_at, updated_at
					FROM {$source}
					WHERE id > %d
					ORDER BY id ASC
					LIMIT %d",
					$last_id,
					self::CHUNK_SIZE
				)
			);
			$this->assert_db_ok( 'copy support attachments' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$max = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(id) FROM {$source} WHERE id > %d",
					$last_id
				)
			);

			if ( $max <= $last_id ) {
				break;
			}
			$last_id = $max;
		} while ( true );
	}

	/**
	 * @param string $source Source table (fully qualified).
	 * @param string $dest   Destination table (fully qualified).
	 * @return void
	 */
	private function copy_contract_in_chunks( string $source, string $dest ): void {
		global $wpdb;

		$last_id = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$dest}
						( attachable_type, attachable_id, user_id, contact_id,
						  file_name, file_path, file_type, file_size, file_hash,
						  driver, status, created_at, updated_at )
					SELECT
						'sales_contract', contract_id, user_id, contact_id,
						file_name, file_path, file_type, file_size, file_hash,
						'local', 'active', created_at, updated_at
					FROM {$source}
					WHERE id > %d
					ORDER BY id ASC
					LIMIT %d",
					$last_id,
					self::CHUNK_SIZE
				)
			);
			$this->assert_db_ok( 'copy contract attachments' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$max = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(id) FROM {$source} WHERE id > %d",
					$last_id
				)
			);

			if ( $max <= $last_id ) {
				break;
			}
			$last_id = $max;
		} while ( true );
	}
}
