<?php
/**
 * Add per-membership subscription status on contact↔list pivot rows.
 *
 * @package DoubleScale\Modules\Contacts\Migrations
 */

namespace DoubleScale\Modules\Contacts\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * ContactTaxonomyRelationshipTableStatusColumn migration.
 */
class ContactTaxonomyRelationshipTableStatusColumn {

	/**
	 * @return void
	 */
	public function run() {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_contact_taxonomy_relationship';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'status'", ARRAY_A );
		if ( ! empty( $column ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"ALTER TABLE `{$table}` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'subscribed'"
		);
	}
}
