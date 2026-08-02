<?php
/**
 * Contact (entity) ↔ custom field values pivot table.
 *
 * @package DoubleScale\Pro\Modules\CustomFields\Migrations
 */

namespace DoubleScale\Pro\Modules\CustomFields\Migrations;

use DoubleScale\Core\Database\Migration;

/**
 * Custom field relationship table migration.
 */
class CustomFieldRelationshipTable extends Migration {

	/**
	 * Logical table name (see {@see Migration::$table_name}).
	 *
	 * @var string
	 */
	public $table_name = 'custom_field_relationship';

	/**
	 * Column definitions.
	 */
	public function get_query() {
		return 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_id BIGINT(20) UNSIGNED NOT NULL,
            entity_type VARCHAR(255) NOT NULL,
            custom_field_id BIGINT(20) UNSIGNED NOT NULL,
            value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)';
	}
}
