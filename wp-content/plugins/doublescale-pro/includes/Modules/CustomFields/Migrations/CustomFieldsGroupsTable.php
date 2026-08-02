<?php
/**
 * Custom fields groups table.
 *
 * @package DoubleScale\Pro\Modules\CustomFields\Migrations
 */

namespace DoubleScale\Pro\Modules\CustomFields\Migrations;

use DoubleScale\Core\Database\Migration;

/**
 * Custom fields groups table migration.
 */
class CustomFieldsGroupsTable extends Migration {

	/**
	 * Logical table name (see {@see Migration::$table_name}).
	 *
	 * @var string
	 */
	public $table_name = 'custom_fields_groups';

	/**
	 * Column definitions.
	 */
	public function get_query() {
		return 'id BIGINT(20) NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			scope VARCHAR(255) NOT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY slug (slug)';
	}
}
