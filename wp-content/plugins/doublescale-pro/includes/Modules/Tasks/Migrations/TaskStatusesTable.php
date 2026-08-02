<?php
/**
 * Task kanban statuses table migration.
 *
 * @package DoubleScale\Pro\Modules\Tasks\Migrations
 */

namespace DoubleScale\Pro\Modules\Tasks\Migrations;

use DoubleScale\Core\Database\Migration;

/**
 * TaskStatusesTable class.
 *
 * Creates `{prefix}doublescale_task_statuses`.
 */
class TaskStatusesTable extends Migration {

	/**
	 * Table name (without prefix).
	 *
	 * @var string
	 */
	public $table_name = 'task_statuses';

	/**
	 * @return string
	 */
	public function get_query() {
		return 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT \'open\' COMMENT \'open|closed\',
			is_protected TINYINT(1) NOT NULL DEFAULT 0,
			color VARCHAR(7) DEFAULT \'#6d78d8\',
			sort_order INT DEFAULT 0,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			INDEX idx_sort_order (sort_order),
			INDEX idx_status (status)';
	}
}
