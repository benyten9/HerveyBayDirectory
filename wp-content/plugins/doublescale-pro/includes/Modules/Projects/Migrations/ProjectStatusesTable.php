<?php
/**
 * Project statuses table migration.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\Migrations;

use DoubleScale\Core\Database\Migration;

class ProjectStatusesTable extends Migration {

	/**
	 * @var string
	 */
	public $table_name = 'project_statuses';

	/**
	 * @return string
	 */
	public function get_query() {
		return 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(100) NOT NULL,
			color VARCHAR(20) DEFAULT "#8775EC",
			bg_color VARCHAR(20) DEFAULT "#F4F2FE",
			position INT DEFAULT 0,
			is_completed TINYINT(1) DEFAULT 0,
			is_protected TINYINT(1) NOT NULL DEFAULT 0,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			INDEX idx_position (position)';
	}
}
