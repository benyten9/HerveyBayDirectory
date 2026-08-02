<?php
/**
 * Class NotificationsTable
 * Table for storing user notifications (critical errors, system alerts)
 *
 * @since 1.2.0
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Notifications\Migrations;

use DoubleScale\Core\Database\Migration;
/**
 * Notifications Table class
 */
class NotificationsTable extends Migration {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.2.0
	 */
	public $table_name = 'notifications';

	/**
	 * Get query
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_query() {
		$query = 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			subcategory VARCHAR(50) NOT NULL COMMENT "Notification subcategory - category derived in code",
			data JSON NOT NULL COMMENT "JSON: title, message, links (web/mobile), metadata",
			is_read TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_user_unread (user_id, is_read, created_at),
			KEY idx_subcategory (subcategory)';

		return $query;
	}
}
