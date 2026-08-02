<?php
/**
 * Class SubtaskGroupsTable
 * Named groups for organizing a task's subtask checklist.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * SubtaskGroupsTable class
 */
class SubtaskGroupsTable extends Migration {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $table_name = 'task_subtask_groups';

	/**
	 * Column definitions for dbDelta.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_query() {
		return "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			task_id BIGINT(20) UNSIGNED NOT NULL,
			title VARCHAR(255) NOT NULL,
			position INT UNSIGNED NOT NULL DEFAULT 0,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY task_id (task_id),
			KEY position (position)";
	}
}
