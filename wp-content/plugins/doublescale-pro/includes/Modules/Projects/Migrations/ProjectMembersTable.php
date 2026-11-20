<?php
/**
 * Project team members: staff who work on a project without owning it.
 *
 * The owner stays on `projects.owner_id` and never has a row here. Added by
 * hand (the owner or a manager), by an automation, or when a manager assigns
 * one of the project's tasks to an agent. `reason` records why ("manual",
 * "task:12", "automation:4").
 *
 * @package DoubleScale\Pro\Modules\Projects\Migrations
 */

namespace DoubleScale\Pro\Modules\Projects\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * ProjectMembersTable migration.
 */
class ProjectMembersTable extends Migration {

	/**
	 * @var string
	 */
	public $table_name = 'project_members';

	/**
	 * @return string
	 */
	public function get_query() {
		return 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			added_by BIGINT(20) UNSIGNED NULL,
			reason VARCHAR(64) NOT NULL DEFAULT "manual",
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY project_user (project_id, user_id),
			KEY user_id (user_id)';
	}

	/**
	 * Create the table on installs that were set up before it existed (safe on
	 * every boot: one SHOW TABLES once it is there).
	 *
	 * @return void
	 */
	public static function ensure(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_project_members';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			( new self() )->run();
		}
	}
}
