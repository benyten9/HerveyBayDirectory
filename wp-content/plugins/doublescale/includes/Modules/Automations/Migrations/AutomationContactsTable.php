<?php
/**
 * Class AutomationContactsTable
 * This class is responsible for handling the AutomationContactsTable table
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;
/**
 * AutomationContactsTable Table class
 */
class AutomationContactsTable extends Migration {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $table_name = 'automation_contacts';

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
		 * id PRIMARY KEY
		 * contact_id BIGINT(20) UNSIGNED NOT NULL
		 * automation_id BIGINT(20) UNSIGNED NOT NULL
		 * current_step BIGINT(20) UNSIGNED NOT NULL
		 * next_step BIGINT(20) UNSIGNED NOT NULL
		 * status VARCHAR(255) NOT NULL
		 * data TEXT
		 * execution_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
		 * created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
		 * updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
		 */
		$query = 'id BIGINT(20) NOT NULL AUTO_INCREMENT,
			contact_id BIGINT(20) UNSIGNED NOT NULL,
			automation_id BIGINT(20) UNSIGNED NOT NULL,
			current_step BIGINT(20) UNSIGNED NOT NULL,
			next_step BIGINT(20) UNSIGNED NOT NULL,
			status VARCHAR(255) NOT NULL,
			data TEXT,
			execution_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY contact_id (contact_id),
			KEY automation_id (automation_id),
			KEY current_step (current_step),
			KEY next_step (next_step)';

		return $query;
	}
}
