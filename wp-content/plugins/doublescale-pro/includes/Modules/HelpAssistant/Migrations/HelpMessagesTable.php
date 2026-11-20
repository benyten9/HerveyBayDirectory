<?php
/**
 * Help assistant messages: the customer's questions and the answers.
 *
 * `answered` is null for a customer message, 1 when the assistant answered
 * from the articles, 0 when it found no answer (those feed "questions with no
 * article"). `citations` is JSON: the articles an answer drew on.
 *
 * NOTE: keep every inline COMMENT comma- and semicolon-free (dbDelta).
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * HelpMessagesTable migration.
 */
class HelpMessagesTable extends Migration {

	/**
	 * Logical table name; prefixed in the base constructor.
	 *
	 * @var string
	 */
	public $table_name = 'help_messages';

	/**
	 * Column list.
	 *
	 * @return string
	 */
	public function get_query() {
		return "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id BIGINT(20) UNSIGNED NOT NULL,
			role VARCHAR(20) NOT NULL COMMENT 'user, assistant, agent (a team reply) or customer (their reply by email or portal)',
			content TEXT NOT NULL,
			author VARCHAR(100) NULL COMMENT 'first name of the team member who wrote it',
			author_id BIGINT(20) UNSIGNED NULL COMMENT 'the team member who wrote it (their photo and last seen)',
			activity_id BIGINT(20) UNSIGNED NULL COMMENT 'the ticket reply it came from (marked seen when the visitor reads it)',
			citations LONGTEXT NULL COMMENT 'JSON list of cited articles',
			attachments LONGTEXT NULL COMMENT 'JSON list of files sent with it (name, size, type, signed url)',
			answered TINYINT(1) NULL,
			tokens_in INT(11) UNSIGNED NOT NULL DEFAULT 0,
			tokens_out INT(11) UNSIGNED NOT NULL DEFAULT 0,
			latency_ms INT(11) UNSIGNED NOT NULL DEFAULT 0,
			feedback TINYINT(1) NULL,
			page_url VARCHAR(255) NULL COMMENT 'the page of this site the visitor wrote it on (no query)',
			page_title VARCHAR(200) NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_conversation (conversation_id,id),
			KEY idx_answered (answered,created_at)";
	}
}
