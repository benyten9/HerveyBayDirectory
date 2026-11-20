<?php
/**
 * Create the help assistant's tables on sites that installed Pro before they
 * existed (Pro runs migrations only when its version changes).
 *
 * One autoloaded option read on steady-state requests.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\HelpAssistant\Migrations\HelpConversationsTable;
use DoubleScale\Pro\Modules\HelpAssistant\Migrations\HelpMessagesTable;
use DoubleScale\Pro\Modules\HelpAssistant\Migrations\KbChunksTable;

/**
 * Schema class.
 */
final class Schema {

	/**
	 * Bump when a table is added or a column list changes.
	 */
	// 3: help_messages.author; 4: .attachments; 5: .author_id; 6: .activity_id
	// and help_conversations.team_seen_id / visitor_seen_id; 7:
	// help_conversations.satisfaction*; 8: .satisfaction_note_id (added to an
	// existing table by ensure_columns()); 9: help_conversations typing marks
	// and visitor_context, help_messages.page_url / page_title, kb_chunks
	// source / post_id (+ idx_source_post), one bump for all of them.
	public const VERSION = '9';

	private const READY_OPTION = 'doublescale_help_schema';

	/**
	 * @return void
	 */
	public static function ensure(): void {
		if ( self::VERSION === get_option( self::READY_OPTION ) ) {
			return;
		}

		KbChunksTable::ensure();

		global $wpdb;
		$ok = true;
		foreach ( array( new HelpConversationsTable(), new HelpMessagesTable() ) as $migration ) {
			// The constructor made table_name the full prefixed name; run()
			// skips a table that already exists.
			$table = (string) $migration->table_name;
			$migration->run();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = $ok && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		}

		if ( $ok ) {
			update_option( self::READY_OPTION, self::VERSION, true );
		}
	}
}
