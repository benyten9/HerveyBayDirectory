<?php
/**
 * Keeps the assistant's passages (`kb_chunks`) in step with free's articles.
 *
 * Listens to free's ArticleService hooks:
 *   - saved, still published and "Use in the help assistant" on → passages
 *     rebuilt NOW (skipped when the body is unchanged), meaning fingerprints
 *     added in the background (Action Scheduler)
 *   - saved as a draft, or taken out of the assistant → passages removed NOW
 *   - deleted → passages removed NOW
 * Removal is never queued: a passage must stop being found the moment its
 * article stops being readable. (Visibility needs no reindex at all: the
 * Retriever reads it from the live article row.)
 *
 * The first boot after install queues every article once (backfill).
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Tasks;
use DoubleScale\Modules\KnowledgeBase\Models\KbArticleModel;
use DoubleScale\Modules\KnowledgeBase\Services\TextNormalizer;
use DoubleScale\Pro\Modules\HelpAssistant\Migrations\KbChunksTable;

/**
 * Indexer class.
 */
final class Indexer {

	/**
	 * Action Scheduler group.
	 */
	public const GROUP = 'doublescale_help';

	/**
	 * Task hook (without the group prefix).
	 */
	public const HOOK = 'reindex_article';

	/**
	 * Bump to queue every article again (chunking rules changed).
	 */
	public const INDEX_VERSION = '1';

	/**
	 * Option holding the index version this site was backfilled to.
	 */
	private const BACKFILL_OPTION = 'doublescale_help_index_version';

	/**
	 * Wire the hooks. Called from the module's boot().
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'doublescale_kb_article_saved', array( self::class, 'on_saved' ), 10, 2 );
		add_action( 'doublescale_kb_article_deleted', array( self::class, 'on_deleted' ), 10, 1 );
		add_action( 'init', array( self::class, 'register_task' ) );
		add_action( 'admin_init', array( self::class, 'maybe_backfill' ), 20 );
	}

	/**
	 * The background task's callback, on every request (the queue runner may
	 * run on any of them).
	 *
	 * @return void
	 */
	public static function register_task(): void {
		( new Tasks( self::GROUP ) )->register_callback( self::HOOK, array( self::class, 'reindex_article' ), true );
	}

	/**
	 * @param KbArticleModel $article Saved article.
	 * @param array|null     $before  Attributes before the change (null on create).
	 * @return void
	 */
	public static function on_saved( $article, $before = null ): void {
		if ( ! $article instanceof KbArticleModel ) {
			return;
		}

		if ( ! self::indexable( $article ) ) {
			self::delete_chunks( (int) $article->id );
			return;
		}

		// The passages now, so the next question is answered from the text just
		// saved; only the fingerprints (a network call) wait for the background.
		self::reindex_article( (int) $article->id, false );
		self::queue( (int) $article->id );
	}

	/**
	 * @param int $article_id Deleted article id.
	 * @return void
	 */
	public static function on_deleted( $article_id ): void {
		self::delete_chunks( (int) $article_id );
	}

	/**
	 * Queue one article's reindex; runs inline when Action Scheduler is absent.
	 *
	 * @param int $article_id Article id.
	 * @return void
	 */
	public static function queue( int $article_id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$queued = ( new Tasks( self::GROUP ) )->enqueue_async( self::HOOK, $article_id );
			if ( $queued ) {
				return;
			}
		}

		// No background tasks: index now, but leave the fingerprints (a
		// network call) to the catch-up instead of this page load.
		self::reindex_article( $article_id, false );
	}

	/**
	 * Rebuild one article's passages from its current body.
	 *
	 * @param int  $article_id Article id.
	 * @param bool $embed      Also fingerprint the passages (a network call).
	 * @return int Passages stored.
	 */
	public static function reindex_article( $article_id, bool $embed = true ): int {
		KbChunksTable::ensure();

		$article_id = (int) $article_id;
		$article    = KbArticleModel::find( $article_id );
		if ( ! $article instanceof KbArticleModel || ! self::indexable( $article ) ) {
			self::delete_chunks( $article_id );
			return 0;
		}

		$hash = (string) $article->content_hash;
		if ( '' !== $hash && $hash === self::stored_hash( $article_id ) ) {
			// Same text: only passages still missing a fingerprint get one.
			if ( $embed ) {
				Embeddings::index_article( $article_id );
			}
			return self::count_chunks( $article_id );
		}

		$title    = (string) $article->title;
		$passages = Chunker::split( (string) $article->content );
		if ( array() === $passages ) {
			// A title-only article is still a (short) answer.
			$passages = array(
				array(
					'heading' => '',
					'text'    => $title,
				),
			);
		}

		global $wpdb;
		$table = self::table();
		self::delete_chunks( $article_id );

		foreach ( $passages as $index => $passage ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$table,
				array(
					'article_id'   => $article_id,
					'chunk_index'  => $index,
					'heading'      => mb_substr( $passage['heading'], 0, 255, 'UTF-8' ),
					'content'      => $passage['text'],
					// The title and heading are part of what a passage is about.
					'search_text'  => TextNormalizer::search_text( $title . ' ' . $passage['heading'] . ' ' . $passage['text'] ),
					'word_count'   => count( preg_split( '/\s+/u', $passage['text'], -1, PREG_SPLIT_NO_EMPTY ) ?: array() ),
					// Every passage carries the article hash it was built from.
					'content_hash' => $hash,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
			);
		}

		// Answers kept before these passages existed were built from the old ones.
		AnswerCache::flush();

		// Meaning fingerprints; a provider without them (or failing) leaves the
		// passages to the word search and the background catch-up.
		if ( $embed ) {
			Embeddings::index_article( $article_id );
		}

		return count( $passages );
	}

	/**
	 * Queue every indexable article once per INDEX_VERSION.
	 *
	 * @return void
	 */
	public static function maybe_backfill(): void {
		if ( self::INDEX_VERSION === get_option( self::BACKFILL_OPTION ) ) {
			return;
		}

		KbChunksTable::ensure();
		self::backfill();
		update_option( self::BACKFILL_OPTION, self::INDEX_VERSION, false );
	}

	/**
	 * Queue every indexable article (also the admin "Rebuild index" action).
	 *
	 * @return int Articles queued.
	 */
	public static function backfill(): int {
		$ids = KbArticleModel::query()
			->where( 'status', KbArticleModel::STATUS_PUBLISHED )
			->where( 'include_in_assistant', 1 )
			->pluck( 'id' )
			->all();

		foreach ( $ids as $id ) {
			self::queue( (int) $id );
		}

		return count( $ids );
	}

	/**
	 * Published and allowed in the assistant (visibility is checked at question time).
	 *
	 * @param KbArticleModel $article Article.
	 * @return bool
	 */
	public static function indexable( KbArticleModel $article ): bool {
		return KbArticleModel::STATUS_PUBLISHED === (string) $article->status
			&& (bool) $article->include_in_assistant;
	}

	/**
	 * @param int $article_id Article id.
	 * @return void
	 */
	public static function delete_chunks( int $article_id ): void {
		if ( $article_id <= 0 || ! self::table_exists() ) {
			return;
		}

		global $wpdb;
		$wpdb->delete( self::table(), array( 'article_id' => $article_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * @param int $article_id Article id.
	 * @return int
	 */
	public static function count_chunks( int $article_id ): int {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE article_id = %d", $article_id ) );
	}

	/**
	 * @param int $article_id Article id.
	 * @return string Hash the stored passages were built from, '' when none.
	 */
	private static function stored_hash( int $article_id ): string {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT content_hash FROM {$table} WHERE article_id = %d LIMIT 1", $article_id ) );
	}

	/**
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'doublescale_kb_chunks';
	}

	/**
	 * @return bool
	 */
	private static function table_exists(): bool {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}
}
