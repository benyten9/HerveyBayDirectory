<?php
/**
 * Help assistant passages: each help-center article split at its headings
 * into passages the assistant searches.
 *
 * Deliberately NO visibility or status column: who may read a passage is
 * decided from the live article row at question time (a JOIN), so an article
 * made team-only, unpublished or deleted stops being found at once, before
 * any background reindex runs.
 *
 * `search_text` holds free's TextNormalizer stems (LIKE search, no FULLTEXT:
 * SchemaColumns would misread a FULLTEXT KEY line as a column). `embedding`
 * is reserved for meaning-based search (plan step 8).
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
 * KbChunksTable migration.
 */
class KbChunksTable extends Migration {

	/**
	 * Bump when the column list changes, so {@see ensure()} runs again.
	 */
	// 2: source / post_id (passages from the site's pages) + idx_source_post.
	public const SCHEMA_VERSION = '2';

	/**
	 * Option remembering the schema version this site has.
	 */
	private const READY_OPTION = 'doublescale_help_chunks_schema';

	/**
	 * Logical table name; prefixed in the base constructor.
	 *
	 * @var string
	 */
	public $table_name = 'kb_chunks';

	/**
	 * Column list.
	 *
	 * @return string
	 */
	public function get_query() {
		return "id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			article_id BIGINT(20) UNSIGNED NOT NULL,
			chunk_index INT(11) NOT NULL DEFAULT 0,
			heading VARCHAR(255) NULL,
			content TEXT NOT NULL COMMENT 'Plain text of the passage',
			search_text LONGTEXT NULL COMMENT 'Normalised stems for search',
			word_count INT(11) NOT NULL DEFAULT 0,
			content_hash VARCHAR(64) NULL,
			embedding LONGBLOB NULL COMMENT 'Reserved for meaning-based search',
			embedding_model VARCHAR(100) NULL,
			source VARCHAR(20) NOT NULL DEFAULT 'article' COMMENT 'article or page (a page of the site)',
			post_id BIGINT(20) UNSIGNED NULL COMMENT 'the site page of a page passage (article_id is 0 then)',
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_article_chunk (article_id,chunk_index),
			KEY idx_source_post (source,post_id)";
	}

	/**
	 * Create the table on a site that installed Pro before it existed.
	 *
	 * Pro runs migrations only when its version changes; this covers every
	 * other site. One autoloaded option read on steady-state requests.
	 *
	 * @return void
	 */
	public static function ensure(): void {
		if ( self::SCHEMA_VERSION === get_option( self::READY_OPTION ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'doublescale_kb_chunks';
		// Creates the table, or adds the columns an older one lacks.
		( new self() )->run();
		self::ensure_index( $table, 'idx_source_post', '(source,post_id)' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			update_option( self::READY_OPTION, self::SCHEMA_VERSION, true );
		}
	}
	/**
	 * Add an index an older table lacks (adding columns never adds keys).
	 *
	 * @param string $table   Full table name.
	 * @param string $name    Index name.
	 * @param string $columns Column list, in parentheses.
	 * @return void
	 */
	private static function ensure_index( string $table, string $name, string $columns ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $name ) ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `{$name}` {$columns}" );
	}
}
