<?php

/**
 * Class FormsTable
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Modules\Forms
 */

namespace DoubleScale\Modules\Forms\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * FormsTable class
 */
class FormsTable extends Migration {


	/**
	 * Table Name
	 *
	 * @var string
	 */
	public $table_name = 'forms';

	/**
	 * Get Query
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
		  * name: Name of the form
		  * form_type: Type of the form
		  * form_id: ID of the form
		  * data: Data of the form
		  * status: Status of the form
		  * created_at: Created at timestamp
		  * updated_at: Updated at timestamp
		  */
		$query = 'id BIGINT(20) NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            form_type VARCHAR(255),
            form_id VARCHAR(255),
            data TEXT,
            status VARCHAR(255) NOT NULL DEFAULT "inactive",
            hash VARCHAR(40) NULL,
            slug VARCHAR(191) NULL,
            layout VARCHAR(20) NULL,
            form_schema LONGTEXT NULL,
            style LONGTEXT NULL,
            settings LONGTEXT NULL,
            version INT(11) NOT NULL DEFAULT 0,
            published_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)';

		return $query;
	}
}
