<?php
/**
 * Make sales_credit_notes.currency nullable (NULL = inherit global).
 *
 * @package DoubleScale\Pro\Modules\CreditNotes\Migrations
 */

namespace DoubleScale\Pro\Modules\CreditNotes\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * SalesCreditNotesTableCurrencyNullable migration.
 */
class SalesCreditNotesTableCurrencyNullable {

	/**
	 * Safe on every boot — gated on SHOW COLUMNS Null=YES.
	 *
	 * Requires free DoubleScale ≥ 1.3.8 (`NullableCurrencyColumn`). When Pro is
	 * updated before free, skip quietly; the admin notice asks them to update free.
	 *
	 * @return void
	 */
	public static function ensure(): void {
		if ( ! class_exists( \DoubleScale\Core\Database\NullableCurrencyColumn::class ) ) {
			return;
		}

		\DoubleScale\Core\Database\NullableCurrencyColumn::ensure( 'sales_credit_notes' );
	}

	/**
	 * @return void
	 */
	public function run() {
		self::ensure();
	}
}
