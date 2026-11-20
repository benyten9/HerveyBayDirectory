<?php
/**
 * Help assistant conversations: one row per chat.
 *
 * `channel` is where the chat happened (admin, portal, widget, help_page; voice
 * and phone are reserved for phase 2). `hash` is the conversation's only
 * handle outside the admin. `ip_hash` is a salted hash, never the address.
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
 * HelpConversationsTable migration.
 */
class HelpConversationsTable extends Migration {

	/**
	 * Logical table name; prefixed in the base constructor.
	 *
	 * @var string
	 */
	public $table_name = 'help_conversations';

	/**
	 * Column list.
	 *
	 * @return string
	 */
	public function get_query() {
		return "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			hash VARCHAR(64) NOT NULL,
			channel VARCHAR(20) NOT NULL DEFAULT 'admin',
			audience VARCHAR(20) NOT NULL DEFAULT 'public' COMMENT 'Visibility tier the answers were drawn from',
			user_id BIGINT(20) UNSIGNED NULL,
			contact_id BIGINT(20) UNSIGNED NULL,
			visitor_email VARCHAR(191) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			ticket_id BIGINT(20) UNSIGNED NULL,
			rating TINYINT(1) NULL,
			ip_hash VARCHAR(64) NULL,
			message_count INT(11) NOT NULL DEFAULT 0,
			tokens_in INT(11) UNSIGNED NOT NULL DEFAULT 0,
			tokens_out INT(11) UNSIGNED NOT NULL DEFAULT 0,
			team_seen_id BIGINT(20) UNSIGNED NULL COMMENT 'last message the team had on screen (the visitor sees Seen)',
			visitor_seen_id BIGINT(20) UNSIGNED NULL COMMENT 'last team reply the visitor had on screen',
			satisfaction TINYINT(1) NULL COMMENT 'the visitor rated the team 1 to 5 once it was solved',
			satisfaction_comment TEXT NULL,
			satisfaction_at DATETIME NULL,
			satisfaction_note_id BIGINT(20) UNSIGNED NULL COMMENT 'the ticket note that holds the rating (kept up to date)',
			visitor_typing_at DATETIME NULL COMMENT 'the visitor was typing at this time (live chat only)',
			team_typing_at DATETIME NULL COMMENT 'a team member was typing a reply at this time',
			team_typing_by BIGINT(20) UNSIGNED NULL COMMENT 'the team member who was typing',
			visitor_context LONGTEXT NULL COMMENT 'JSON: what the visitor had in their cart (names and totals only)',
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY hash (hash),
			KEY idx_channel_created (channel,created_at)";
	}
}
