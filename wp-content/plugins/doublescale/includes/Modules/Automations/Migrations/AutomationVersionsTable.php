<?php
/**
 * Class AutomationVersionsTable
 * This class is responsible for handling the Automation Versions table.
 *
 * Each row is a full snapshot of an automation (its row + all steps) taken
 * after a mutating change, enabling undo / redo (rollback) of the workflow.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * AutomationVersionsTable Table class
 */
class AutomationVersionsTable extends Migration {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $table_name = 'automation_versions';

	/**
	 * Get query
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_query() {
		/**
		 * Fields:
		 *
		 * id: Primary key
		 * automation_id: Automation this snapshot belongs to
		 * version: Monotonically increasing sequence number per automation
		 * label: Short description of the change (e.g. "Added Send Email")
		 * snapshot: JSON snapshot of the automation row + all its steps
		 * created_by: WordPress user ID who triggered the change
		 * created_at: Created at timestamp
		 */
		$query = 'id BIGINT(20) NOT NULL AUTO_INCREMENT,
            automation_id BIGINT(20) UNSIGNED NOT NULL,
            version INT(11) NOT NULL,
            label VARCHAR(255) NOT NULL DEFAULT "",
            snapshot LONGTEXT,
            created_by BIGINT(20) UNSIGNED DEFAULT NULL COMMENT "WordPress user ID who created this version",
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY automation_id (automation_id),
            KEY automation_version (automation_id, version)';

		return $query;
	}
}
