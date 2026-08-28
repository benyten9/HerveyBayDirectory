<?php
/**
 * Enforce unique phone and WhatsApp numbers on contacts.
 *
 * @package DoubleScale\Modules\Contacts\Migrations
 */

namespace DoubleScale\Modules\Contacts\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * ContactsPhoneUnique migration.
 */
class ContactsTablePhoneUnique {

	/**
	 * @return void
	 */
	public function run() {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_contacts';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE `{$table}` SET phone = NULL WHERE phone = ''" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE `{$table}` SET whatsapp_phone = NULL WHERE whatsapp_phone = ''" );

		$this->dedupe_column( $table, 'phone' );
		$this->dedupe_column( $table, 'whatsapp_phone' );

		$this->ensure_unique_index( $table, 'phone' );
		$this->ensure_unique_index( $table, 'whatsapp_phone' );
	}

	/**
	 * Keep the lowest contact id for each duplicate value; null the rest.
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 * @return void
	 */
	private function dedupe_column( $table, $column ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"UPDATE `{$table}` c
			INNER JOIN (
				SELECT `{$column}` AS value_key, MIN(id) AS keep_id
				FROM `{$table}`
				WHERE `{$column}` IS NOT NULL
				GROUP BY `{$column}`
				HAVING COUNT(*) > 1
			) d ON c.`{$column}` = d.value_key AND c.id != d.keep_id
			SET c.`{$column}` = NULL"
		);
	}

	/**
	 * Replace a non-unique index with a unique index when needed.
	 *
	 * @param string $table      Table name.
	 * @param string $index_name Index / column name.
	 * @return void
	 */
	private function ensure_unique_index( $table, $index_name ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Key_name = '{$index_name}'", ARRAY_A );
		if ( ! empty( $indexes ) ) {
			$is_unique = isset( $indexes[0]['Non_unique'] ) && '0' === (string) $indexes[0]['Non_unique'];
			if ( $is_unique ) {
				return;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `{$index_name}`" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Prefix keeps utf8mb4 unique keys under the 1000-byte compact limit
		// (191*4 = 764). Full VARCHAR(255) unique indexes are 1020 bytes.
		$wpdb->query( "ALTER TABLE `{$table}` ADD UNIQUE KEY `{$index_name}` (`{$index_name}`(191))" );
	}
}
