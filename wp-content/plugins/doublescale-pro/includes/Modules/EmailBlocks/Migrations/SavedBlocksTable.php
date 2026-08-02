<?php
/**
 * Saved blocks table migration.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Modules\EmailBlocks
 */

namespace DoubleScale\Pro\Modules\EmailBlocks\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * SavedBlocksTable class
 */
class SavedBlocksTable extends Migration {

	/**
	 * Table name
	 *
	 * @var string
	 */
	public $table_name = 'saved_blocks';

	/**
	 * Get query
	 *
	 * @return string
	 */
	public function get_query() {
		$query = 'id BIGINT(20) NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            category VARCHAR(100) NOT NULL DEFAULT "custom",
            content LONGTEXT,
            thumbnail VARCHAR(255) NULL,
            created_by BIGINT(20),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_category (category),
            INDEX idx_created_by (created_by)';

		return $query;
	}
}
