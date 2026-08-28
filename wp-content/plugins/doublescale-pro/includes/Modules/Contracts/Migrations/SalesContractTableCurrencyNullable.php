<?php
/**
 * Make sales_contracts.currency nullable (NULL = inherit global).
 *
 * @package DoubleScale\Pro\Modules\Contracts\Migrations
 */

namespace DoubleScale\Pro\Modules\Contracts\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * SalesContractTableCurrencyNullable migration.
 */
class SalesContractTableCurrencyNullable {

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

		\DoubleScale\Core\Database\NullableCurrencyColumn::ensure( 'sales_contracts' );
	}

	/**
	 * @return void
	 */
	public function run() {
		self::ensure();
	}
}
