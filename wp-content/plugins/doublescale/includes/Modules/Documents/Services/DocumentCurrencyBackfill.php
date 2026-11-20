<?php
/**
 * Fix, once, the currency of documents that left draft without it.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;

/**
 * Before every save out of draft fixed a document's currency, an invoice or
 * proposal the team moved on by hand kept following the store's currency.
 * This writes the store's current currency on those, once, as sending would
 * have (the models now do it on every save).
 */
final class DocumentCurrencyBackfill {

	/** Set when the fix has run. */
	public const OPTION = 'doublescale_document_currency_backfill_v1';

	/**
	 * @return void
	 */
	public static function ensure(): void {
		if ( get_option( self::OPTION ) ) {
			return;
		}

		global $wpdb;
		$currency = (string) Settings::get_currency();
		foreach ( array( 'doublescale_sales_invoices', 'doublescale_sales_proposals' ) as $table ) {
			$name = $wpdb->prefix . $table;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $name !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) ) {
				return;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE `{$name}` SET currency = %s WHERE status <> 'draft' AND ( currency IS NULL OR currency = '' )", $currency ) );
		}

		update_option( self::OPTION, time(), true );
	}
}
