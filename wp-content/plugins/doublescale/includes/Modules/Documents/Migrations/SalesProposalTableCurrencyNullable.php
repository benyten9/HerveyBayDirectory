<?php
/**
 * Make sales_proposals.currency nullable (NULL = inherit global).
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\NullableCurrencyColumn;

/**
 * SalesProposalTableCurrencyNullable migration.
 */
class SalesProposalTableCurrencyNullable {

	/**
	 * Safe on every boot — gated on SHOW COLUMNS Null=YES.
	 *
	 * @return void
	 */
	public static function ensure(): void {
		NullableCurrencyColumn::ensure( 'sales_proposals' );
	}

	/**
	 * @return void
	 */
	public function run() {
		self::ensure();
	}
}
