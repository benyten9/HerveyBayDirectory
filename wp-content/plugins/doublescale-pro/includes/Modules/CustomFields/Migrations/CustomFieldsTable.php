<?php
/**
 * Custom fields definitions table.
 *
 * @package DoubleScale\Pro\Modules\CustomFields\Migrations
 */

namespace DoubleScale\Pro\Modules\CustomFields\Migrations;

use DoubleScale\Core\Database\Migration;

/**
 * Custom fields table migration.
 */
class CustomFieldsTable extends Migration {

	/**
	 * Logical table name (see {@see Migration::$table_name}).
	 *
	 * @var string
	 */
	public $table_name = 'custom_fields';

	/**
	 * Column definitions.
	 */
	public function get_query() {
		return 'id BIGINT(20) NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			type VARCHAR(255) NOT NULL,
			attributes TEXT,
			group_id BIGINT(20) NOT NULL,
			scope VARCHAR(255) NOT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY slug (slug)';
	}
}
