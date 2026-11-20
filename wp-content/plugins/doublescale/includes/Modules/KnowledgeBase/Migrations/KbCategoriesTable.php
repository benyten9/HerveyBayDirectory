<?php
/**
 * Help-center categories table.
 *
 * Groups articles on the public help center and in the portal. `parent_id`
 * is reserved for nested categories; the admin UI keeps one level today.
 *
 * NOTE: keep every inline COMMENT comma- and semicolon-free. dbDelta()
 * tokenizes the column list on commas (see SupportTicketsTable).
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * KbCategoriesTable migration.
 */
class KbCategoriesTable extends Migration {

	/**
	 * Logical table name; prefixed in the base constructor.
	 *
	 * @var string
	 */
	public $table_name = 'kb_categories';

	/**
	 * Column list.
	 *
	 * @return string
	 */
	public function get_query() {
		return "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			slug VARCHAR(191) NOT NULL COMMENT 'URL-friendly identifier',
			description TEXT NULL,
			parent_id BIGINT(20) UNSIGNED NULL,
			position INT(11) NOT NULL DEFAULT 0,
			icon VARCHAR(64) NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY idx_parent_position (parent_id,position)";
	}
}
