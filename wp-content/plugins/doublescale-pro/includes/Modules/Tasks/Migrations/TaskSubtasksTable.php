<?php
/**
 * Class TaskSubtasksTable
 * This class is responsible for handling the task subtasks table migration.
 *
 * Subtasks are a simple ordered checklist attached to a parent task
 * (title + completion flag + position). They intentionally do NOT reuse
 * the heavy TaskModel validation, so they live in a dedicated table.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * TaskSubtasksTable class
 */
class TaskSubtasksTable extends Migration {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $table_name = 'task_subtasks';

	/**
	 * Column definitions for dbDelta. Avoid SQL COMMENT clauses — dbDelta splits on
	 * semicolons and misparses comment text that contains `;` or `|`.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_query() {
		return "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			task_id BIGINT(20) UNSIGNED NOT NULL,
			title VARCHAR(255) NOT NULL,
			is_completed TINYINT(1) NOT NULL DEFAULT 0,
			position INT UNSIGNED NOT NULL DEFAULT 0,
			completed_at DATETIME DEFAULT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY task_id (task_id),
			KEY position (position)";
	}
}
