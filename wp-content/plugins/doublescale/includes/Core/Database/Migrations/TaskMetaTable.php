<?php
/**
 * Action Scheduler task argument / heartbeat meta table.
 *
 * @package DoubleScale\Core\Database\Migrations
 */

namespace DoubleScale\Core\Database\Migrations;

use DoubleScale\Core\Database\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Creates {@see Tasks} backing table for hook meta and action_id linkage.
 */
class TaskMetaTable extends Migration {

	/**
	 * Logical name → wp_{prefix}doublescale_task_meta
	 *
	 * @var string
	 */
	public $table_name = 'task_meta';

	/**
	 * Column definitions for dbDelta.
	 *
	 * @return string
	 */
	public function get_query() {
		return 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			hook VARCHAR(255) NOT NULL,
			group_slug VARCHAR(191) NOT NULL,
			value LONGTEXT NULL,
			action_id BIGINT(20) UNSIGNED NULL,
			last_run DATETIME NULL,
			date_created DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY hook_group (hook(191), group_slug),
			KEY action_id (action_id),
			KEY date_created (date_created)';
	}
}
