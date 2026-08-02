<?php
/**
 * Task recurrences table — schedule rules that spawn new task copies on a cadence.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\Tasks\Migrations
 */

namespace DoubleScale\Pro\Modules\Tasks\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * TaskRecurrencesTable class
 */
class TaskRecurrencesTable extends Migration {

	/**
	 * Table name (without prefix).
	 *
	 * @var string
	 */
	public $table_name = 'task_recurrences';

	/**
	 * Column definitions for dbDelta.
	 *
	 * @return string
	 */
	public function get_query() {
		return "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			template_task_id BIGINT(20) UNSIGNED NOT NULL,
			frequency VARCHAR(10) NOT NULL DEFAULT 'day',
			interval_count INT UNSIGNED NOT NULL DEFAULT 1,
			weekdays VARCHAR(32) DEFAULT NULL,
			month_day TINYINT UNSIGNED DEFAULT NULL,
			time TIME DEFAULT NULL,
			timezone VARCHAR(64) DEFAULT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			last_run_at DATETIME DEFAULT NULL,
			next_run_at DATETIME NOT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY next_run_at (next_run_at, is_active),
			KEY template_task_id (template_task_id)";
	}
}
