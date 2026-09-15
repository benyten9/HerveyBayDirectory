<?php
/**
 * Add optional company and legal/tax identification columns to contacts.
 *
 * @package DoubleScale\Modules\Contacts\Migrations
 */

namespace DoubleScale\Modules\Contacts\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * ContactsTableLegalTaxColumns migration.
 */
class ContactsTableLegalTaxColumns {

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

		$columns = array(
			'company_name'                => 'ADD `company_name` VARCHAR(255) NULL',
			'company_registration_number' => 'ADD `company_registration_number` VARCHAR(191) NULL',
			'tax_vat_number'              => 'ADD `tax_vat_number` VARCHAR(191) NULL',
		);

		foreach ( $columns as $name => $ddl ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$has_column = $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE '{$name}'" );
			if ( $has_column ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` {$ddl}" );
		}
	}
}
