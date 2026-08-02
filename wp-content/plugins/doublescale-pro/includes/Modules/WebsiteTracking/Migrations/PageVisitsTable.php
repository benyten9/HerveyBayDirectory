<?php

/**
 * Class PageVisitsTable file.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\WebsiteTracking\Migrations;


use DoubleScale\Core\Database\Migration;
/**
 * Page_Visits Table class
 */
class PageVisitsTable extends Migration
{
	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $table_name = 'page_visits';

	/**
	 * Get query
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_query()
	{
		$query = 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        contact_id BIGINT UNSIGNED NOT NULL,
        path VARCHAR(500) NOT NULL,
        query VARCHAR(500) NOT NULL,
        ip_address VARBINARY(16) NOT NULL,
        user_agent VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY contact_id (contact_id),
        KEY path (path(191)),
        KEY created_at (created_at)
        ';

		return $query;
	}
}
