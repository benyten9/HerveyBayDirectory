<?php
/**
 * Help-center reader feedback: why an article did not help.
 *
 * A reader who answers "No" to "Was this article helpful?" may say what was
 * missing. One row per reason; the yes/no counts stay on the article row
 * (`helpful_yes`, `helpful_no`). `user_id` and `contact_id` are set when the
 * reader was signed in, so the team can follow up; visitors stay anonymous.
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
 * KbFeedbackTable migration.
 */
class KbFeedbackTable extends Migration {

	/**
	 * Logical table name; prefixed in the base constructor.
	 *
	 * @var string
	 */
	public $table_name = 'kb_feedback';

	/**
	 * Column list.
	 *
	 * @return string
	 */
	public function get_query() {
		return "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			article_id BIGINT(20) UNSIGNED NOT NULL,
			helpful TINYINT(1) NOT NULL DEFAULT 0,
			comment TEXT NOT NULL COMMENT 'What the reader said was missing',
			user_id BIGINT(20) UNSIGNED NULL,
			contact_id BIGINT(20) UNSIGNED NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_article_created (article_id,created_at)";
	}
}
