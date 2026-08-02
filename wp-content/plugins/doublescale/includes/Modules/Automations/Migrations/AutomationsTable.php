<?php
/**
 * Class AutomationsTable
 * This class is responsible for handling the Automations table
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;
/**
 * Automations Table class
 */
class AutomationsTable extends Migration {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $table_name = 'automations';

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
		 * name: Name of the automation
		 * trigger_name: Trigger of the automation
		 * status: Status of the automation
		 * settings: Settings of the automation
		 * created_at: Created at timestamp
		 * updated_at: Updated at timestamp
		 */
		$query = 'id BIGINT(20) NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            `trigger` VARCHAR(255) NOT NULL,
            status VARCHAR(255) NOT NULL,
            settings TEXT,
            created_by BIGINT(20) UNSIGNED DEFAULT NULL COMMENT "WordPress user ID who created this automation",
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_created_by (created_by)';

		return $query;
	}
}
