<?php
/**
 * Class LogsTable file.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\Logger\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * Logs Table class
 */
class LogsTable extends Migration {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $table_name = 'logs';

	/**
	 * Get query
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_query() {
		$query = 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            timestamp datetime NOT NULL,
            level smallint(4) NOT NULL,
            source varchar(200) NOT NULL,
            message longtext NOT NULL,
            context longtext NULL,
            PRIMARY KEY (id),
            KEY level (level),
            KEY idx_timestamp (timestamp),
            KEY idx_source (source(50)),
            KEY idx_timestamp_level (timestamp, level)';

		return $query;
	}
}
