<?php
/**
 * Help-center articles table.
 *
 * `visibility` decides who may read an article, and every reader (public
 * help center, portal, Pro's assistant) filters on it at query time:
 *   - public   — anyone, including logged-out visitors
 *   - portal   — signed-in client-portal contacts
 *   - internal — staff with helpdesk access only
 *
 * `search_text` holds {@see \DoubleScale\Modules\KnowledgeBase\Services\TextNormalizer}
 * stems of the title and body. Search uses LIKE on it, never a FULLTEXT index:
 * SchemaColumns::parse_columns() would read a FULLTEXT KEY line as a column.
 *
 * `post_id` links the article to its page ({@see \DoubleScale\Modules\KnowledgeBase\Services\ArticlePages}):
 * the page gives it an address and a body written in the block editor.
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
 * KbArticlesTable migration.
 */
class KbArticlesTable extends Migration {

	/**
	 * Logical table name; prefixed in the base constructor.
	 *
	 * @var string
	 */
	public $table_name = 'kb_articles';

	/**
	 * Column list.
	 *
	 * @return string
	 */
	public function get_query() {
		return "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			category_id BIGINT(20) UNSIGNED NULL,
			title VARCHAR(255) NOT NULL,
			slug VARCHAR(191) NOT NULL COMMENT 'URL-friendly identifier',
			content LONGTEXT NULL COMMENT 'Sanitised HTML body',
			excerpt TEXT NULL,
			search_text LONGTEXT NULL COMMENT 'Normalised stems of title and body for search',
			status VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft or published',
			visibility VARCHAR(20) NOT NULL DEFAULT 'public' COMMENT 'public or portal or internal',
			include_in_assistant TINYINT(1) NOT NULL DEFAULT 1,
			author_id BIGINT(20) UNSIGNED NULL,
			position INT(11) NOT NULL DEFAULT 0,
			views BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			helpful_yes INT(11) UNSIGNED NOT NULL DEFAULT 0,
			helpful_no INT(11) UNSIGNED NOT NULL DEFAULT 0,
			content_hash VARCHAR(64) NULL COMMENT 'sha256 of title and body',
			published_at DATETIME NULL,
			post_id BIGINT(20) UNSIGNED NULL COMMENT 'The article page (ds_kb_article post)',
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY idx_category_position (category_id,position),
			KEY idx_status_visibility (status,visibility),
			KEY post_id (post_id)";
	}
}
