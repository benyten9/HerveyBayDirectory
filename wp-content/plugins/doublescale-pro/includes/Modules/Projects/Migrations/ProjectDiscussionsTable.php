<?php
/**
 * Project discussions table migration.
 *
 * @package DoubleScale\Pro\Modules\Projects\Migrations
 */

namespace DoubleScale\Pro\Modules\Projects\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * ProjectDiscussionsTable migration.
 */
class ProjectDiscussionsTable extends Migration {

	/**
	 * @var string
	 */
	public $table_name = 'project_discussions';

	/**
	 * @return string
	 */
	public function get_query() {
		return 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT(20) UNSIGNED NOT NULL,
			parent_id BIGINT(20) UNSIGNED NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			body LONGTEXT NOT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			INDEX idx_project_id (project_id),
			INDEX idx_parent_id (parent_id),
			INDEX idx_user_id (user_id)';
	}
}
