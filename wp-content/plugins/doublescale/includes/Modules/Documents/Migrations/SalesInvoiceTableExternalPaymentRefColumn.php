<?php
/**
 * Add external_payment_ref column to invoices (gateway-agnostic in-progress payment id).
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * SalesInvoiceTableExternalPaymentRefColumn migration.
 */
class SalesInvoiceTableExternalPaymentRefColumn {

	/**
	 * @return void
	 */
	public function run() {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_sales_invoices';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_column = $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'external_payment_ref'" );
		if ( ! $has_column ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				"ALTER TABLE `{$table}` ADD `external_payment_ref` VARCHAR(191) NULL AFTER `amount_paid`"
			);
		}

		// Backfill from legacy stripe_payment_intent_id column.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"UPDATE `{$table}`
			SET `external_payment_ref` = `stripe_payment_intent_id`
			WHERE `stripe_payment_intent_id` IS NOT NULL
				AND `stripe_payment_intent_id` != ''
				AND ( `external_payment_ref` IS NULL OR `external_payment_ref` = '' )"
		);
	}
}
