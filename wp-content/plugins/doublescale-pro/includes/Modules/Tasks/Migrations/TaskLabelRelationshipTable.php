<?php
/**
 * Task ↔ label pivot (many-to-many assignment).
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Modules\Tasks\Migrations
 */

namespace DoubleScale\Pro\Modules\Tasks\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * TaskLabelRelationshipTable class
 */
class TaskLabelRelationshipTable extends Migration {

	/**
	 * Table name
	 *
	 * @var string
	 */
	public $table_name = 'task_label_relationship';

	/**
	 * Column definitions for dbDelta.
	 *
	 * @return string
	 */
	public function get_query() {
		return "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			task_id BIGINT(20) UNSIGNED NOT NULL,
			label_id BIGINT(20) UNSIGNED NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY task_label (task_id, label_id),
			KEY task_id (task_id),
			KEY label_id (label_id)";
	}
}
