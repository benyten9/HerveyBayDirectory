<?php

/**
 * Class Lead_Scoring_Rules_Levels
 *
 * This class is responsible for handling the Lead_Scoring_Rules_Levels table
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\LeadScoring\Migrations;

use DoubleScale\Core\Database\Migration;

/**
 * Lead_Scoring_Rules_Levels Table class
 */
class LeadScoringRulesLevelsTable extends Migration {


	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $table_name = 'lead_scoring_rules_levels';

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
		  * name: Name
		  * slug: Slug
		  * points: Points
		  * created_at: Created at timestamp
		  * updated_at: Updated at timestamp
		  */
		$query = 'id BIGINT(20) NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(100) NOT NULL,
            points INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)';

		return $query;
	}
}
