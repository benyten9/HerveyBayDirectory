<?php
/**
 * Product groups table migration.
 *
 * @package DoubleScale\Pro\Modules\ProductCatalog
 */

namespace DoubleScale\Pro\Modules\ProductCatalog\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * ProductGroupsTable migration.
 *
 * Groups are an organizational label only — they never affect document totals.
 */
class ProductGroupsTable extends Migration {

	/**
	 * @var string
	 */
	public $table_name = 'product_groups';

	/**
	 * @return string
	 */
	public function get_query() {
		return "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_name (name)";
	}
}
