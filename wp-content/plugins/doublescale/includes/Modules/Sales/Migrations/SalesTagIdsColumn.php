<?php
/**
 * Rename sales `tags` JSON column to `tag_ids` (system tag IDs).
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\TagModel;

/**
 * SalesTagIdsColumn migration.
 */
class SalesTagIdsColumn {

	/**
	 * @return void
	 */
	public function run() {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'doublescale_sales_proposals',
			$wpdb->prefix . 'doublescale_sales_invoices',
		);

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$has_tag_ids = $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'tag_ids'" );
			if ( $has_tag_ids ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$has_tags = $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'tags'" );
			if ( $has_tags ) {
				$this->convert_legacy_tags_column( $table );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE `{$table}` CHANGE `tags` `tag_ids` JSON NULL" );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE `{$table}` ADD `tag_ids` JSON NULL" );
			}
		}
	}

	/**
	 * @param string $table Full table name.
	 * @return void
	 */
	private function convert_legacy_tags_column( string $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT id, tags FROM `{$table}` WHERE tags IS NOT NULL AND tags != '' AND tags != '[]'",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$decoded = json_decode( (string) $row['tags'], true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			$ids = array();
			foreach ( $decoded as $item ) {
				if ( is_numeric( $item ) ) {
					$ids[] = (int) $item;
					continue;
				}
				if ( ! is_string( $item ) || '' === trim( $item ) ) {
					continue;
				}
				$name = sanitize_text_field( $item );
				$tag  = TagModel::query()
					->where( 'name', $name )
					->orWhere( 'slug', sanitize_title( $name ) )
					->first();
				if ( $tag ) {
					$ids[] = (int) $tag->id;
				}
			}

			$ids = array_values( array_unique( array_filter( $ids ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array( 'tags' => wp_json_encode( $ids ) ),
				array( 'id' => (int) $row['id'] ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}
}
