<?php
/**
 * Products table migration.
 *
 * @package DoubleScale\Pro\Modules\ProductCatalog
 */

namespace DoubleScale\Pro\Modules\ProductCatalog\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * ProductsTable migration.
 */
class ProductsTable extends Migration {

	/**
	 * @var string
	 */
	public $table_name = 'products';

	/**
	 * Rates are stored in the global currency; documents freeze their own
	 * currency, so the picker warns on mismatch rather than converting.
	 *
	 * @return string
	 */
	public function get_query() {
		return "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			long_description TEXT NULL,
			unit VARCHAR(50) NULL,
			group_id BIGINT(20) UNSIGNED NULL,
			rate DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			tax JSON NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_name (name),
			KEY idx_group_id (group_id),
			KEY idx_created (created_at)";
	}
}
