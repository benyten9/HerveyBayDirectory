<?php
/**
 * Sales taxes table migration.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;
use DoubleScale\Modules\Sales\Models\TaxModel;

/**
 * SalesTaxesTable migration.
 */
class SalesTaxesTable extends Migration {

	/**
	 * @var string
	 */
	public $table_name = 'sales_taxes';

	/**
	 * @return string
	 */
	public function get_query() {
		return "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(100) NOT NULL,
			rate DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY name (name)";
	}

	/**
	 * @return void
	 */
	public function run() {
		parent::run();
		$this->seed_defaults();
	}

	/**
	 * @return void
	 */
	private function seed_defaults(): void {
		if ( TaxModel::query()->count() > 0 ) {
			return;
		}

		$defaults = array(
			array( 'name' => 'TAX1', 'rate' => 18.0 ),
			array( 'name' => 'TAX2', 'rate' => 10.0 ),
			array( 'name' => 'TAX3', 'rate' => 5.0 ),
		);

		foreach ( $defaults as $tax ) {
			TaxModel::query()->create( $tax );
		}
	}
}
