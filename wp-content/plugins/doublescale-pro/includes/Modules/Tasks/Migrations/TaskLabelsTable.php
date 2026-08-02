<?php
/**
 * Global workspace task labels (colored tags assignable to any task).
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Modules\Tasks\Migrations
 */

namespace DoubleScale\Pro\Modules\Tasks\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * TaskLabelsTable class
 */
class TaskLabelsTable extends Migration {

	/**
	 * Table name
	 *
	 * @var string
	 */
	public $table_name = 'task_labels';

	/**
	 * Column definitions for dbDelta.
	 *
	 * @return string
	 */
	public function get_query() {
		return "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(255) DEFAULT NULL,
			color VARCHAR(7) NOT NULL DEFAULT '#6d78d8',
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id)";
	}
}
