<?php

/**
 * Class LeadScoringRulesTable
 *
 * This class is responsible for handling the LeadScoringRulesTable table
 *
 * @since 1.0.0
 *
 * @package Plugin	
 */

namespace DoubleScale\Pro\Modules\LeadScoring\Migrations;

use DoubleScale\Core\Database\Migration;

/**
 * LeadScoringRulesTable Table class
 */
class LeadScoringRulesTable extends Migration {



	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $table_name = 'lead_scoring_rules';

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
		  * title: Title
		  * status: Status
		  * points: Points
		  * is_adding: Is adding
		  * settings: Settings
		  * created_at: Created at timestamp
		  * updated_at: Updated at timestamp
		  */
		$query = 'id BIGINT(20) NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL,
            points INT UNSIGNED NOT NULL,
            is_adding TINYINT UNSIGNED NOT NULL DEFAULT 1,
            settings TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)';

		return $query;
	}
}
