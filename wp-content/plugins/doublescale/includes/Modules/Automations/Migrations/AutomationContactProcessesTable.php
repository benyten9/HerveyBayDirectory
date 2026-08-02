<?php
/**
 * Class AutomationContactProcessesTable
 *
 * This class is responsible for handling the AutomationContactProcessesTable table
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;
/**
 * AutomationContactProcessesTable Table class
 */
class AutomationContactProcessesTable extends Migration {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $table_name = 'automation_contact_processes';

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
		 * step_id: Step ID
		 * contact_id: Contact ID
		 * automation_id: Automation ID
		 * automation_contact_id: Automation Contact ID
		 * status: Status of the process
		 * created_at: Created at timestamp
		 * updated_at: Updated at timestamp
		 */
		$query = 'id BIGINT(20) NOT NULL AUTO_INCREMENT,
            step_id BIGINT(20) UNSIGNED NOT NULL,
            contact_id BIGINT(20) UNSIGNED NOT NULL,
            automation_id BIGINT(20) UNSIGNED NOT NULL,
			automation_contact_id BIGINT(20) UNSIGNED NOT NULL,
            status VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY step_id (step_id),
            KEY contact_id (contact_id),
            KEY automation_id (automation_id),
			KEY automation_contact_id (automation_contact_id)';

		return $query;
	}
}
