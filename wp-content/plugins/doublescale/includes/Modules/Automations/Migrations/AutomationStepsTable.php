<?php
/**
 * Class AutomationStepsTable
 * This class is responsible for handling the AutomationStepsTable table
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;
/**
 * AutomationStepsTable Table class
 */
class AutomationStepsTable extends Migration {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $table_name = 'automation_steps';

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
		 * parent_id: Parent ID not null and default 0
		 * automation_id: Automation ID
		 * action: Action of the step
		 * type: Type of the step
		 * condition: Condition of the step small text yes or no
		 * status: Status of the step
		 * settings: Settings of the step
		 * order: Order of the step
		 * created_at: Created at timestamp
		 * updated_at: Updated at timestamp
		 */
		$query = 'id BIGINT(20) NOT NULL AUTO_INCREMENT,
			parent_id BIGINT(20) NOT NULL DEFAULT 0,
            automation_id BIGINT(20) UNSIGNED NOT NULL,
            action VARCHAR(255) NOT NULL,
            type VARCHAR(255) NOT NULL,
			`condition` VARCHAR(255) NOT NULL,
            status VARCHAR(255) NOT NULL,
            settings TEXT,
			`order` INT(11) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
			KEY automation_id (automation_id),
			KEY parent_id (parent_id)';

		return $query;
	}
}
