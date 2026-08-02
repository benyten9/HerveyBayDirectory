<?php
/**
 * Unified polymorphic attachments table for all modules.
 *
 * @package DoubleScale\Core\Database\Migrations
 */

namespace DoubleScale\Core\Database\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * AttachmentsTable migration.
 */
class AttachmentsTable extends Migration {

	/**
	 * @var string
	 */
	public $table_name = 'attachments';

	/**
	 * Column definitions for dbDelta. Avoid SQL COMMENT clauses — dbDelta splits on
	 * semicolons and misparses comment text that contains `;` or `|`.
	 *
	 * @return string
	 */
	public function get_query() {
		return "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			attachable_type VARCHAR(50) NOT NULL,
			attachable_id BIGINT(20) UNSIGNED NULL,
			activity_id BIGINT(20) UNSIGNED NULL,
			user_id BIGINT(20) UNSIGNED NULL,
			contact_id BIGINT(20) UNSIGNED NULL,
			file_name VARCHAR(255) NOT NULL,
			file_path VARCHAR(500) NOT NULL,
			file_type VARCHAR(100) NOT NULL DEFAULT '',
			file_size BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			file_hash VARCHAR(64) NOT NULL,
			content_id VARCHAR(255) NULL,
			driver VARCHAR(50) NOT NULL DEFAULT 'local',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			meta LONGTEXT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_file_hash (file_hash),
			KEY idx_attachable (attachable_type, attachable_id),
			KEY idx_activity_status (activity_id, status),
			KEY idx_status (status),
			KEY idx_user (user_id),
			KEY idx_contact (contact_id)";
	}
}
