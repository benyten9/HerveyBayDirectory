<?php
/**
 * Class ActivityCommentsTable
 * This class is responsible for handling the Activity Comments table
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Activities\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;
/**
 * Activity_Comments Table class
 */
class ActivityCommentsTable extends Migration {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $table_name = 'activity_comments';

	/**
	 * Get query
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_query() {
		$query = 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			activity_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED NULL,
			content TEXT NOT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			INDEX idx_activity_id (activity_id),
			INDEX idx_user_id (user_id),
			INDEX idx_created_at (created_at)';

		return $query;
	}
}
