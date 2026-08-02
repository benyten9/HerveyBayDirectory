<?php
/**
 * Projects table migration.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\Migrations;

use DoubleScale\Core\Database\Migration;

class ProjectsTable extends Migration {

	/**
	 * @var string
	 */
	public $table_name = 'projects';

	/**
	 * @return string
	 */
	public function get_query() {
		return 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(255) NOT NULL,
			hash VARCHAR(32) NULL,
			description LONGTEXT NULL,
			status_id BIGINT(20) UNSIGNED NOT NULL,
			contact_id BIGINT(20) UNSIGNED NULL,
			deal_id BIGINT(20) UNSIGNED NULL,
			budget DECIMAL(15,2) NULL,
			start_date DATE NULL,
			due_date DATE NULL,
			owner_id BIGINT(20) UNSIGNED NULL,
			position INT DEFAULT 0,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY hash (hash),
			INDEX idx_contact_id (contact_id),
			INDEX idx_deal_id (deal_id),
			INDEX idx_status_id (status_id),
			INDEX idx_owner_id (owner_id),
			INDEX idx_status_position (status_id, position)';
	}
}
