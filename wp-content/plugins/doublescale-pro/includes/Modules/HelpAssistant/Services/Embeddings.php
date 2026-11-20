<?php
/**
 * Meaning fingerprints (embeddings) of help passages and questions.
 *
 * With a provider that offers them (OpenAI, Gemini), every passage is stored
 * with a unit-length fingerprint (packed float32 in kb_chunks.embedding) and
 * the name of the model that made it. A question's fingerprint is compared
 * with the passages the reader may see (Retriever), so "money back" finds a
 * "refund" article and an Arabic question finds an English one.
 *
 * Without such a provider (Anthropic, OpenAI-compatible servers) nothing is
 * stored and the word search works as before. A failed request never breaks
 * an answer or an index: the passage keeps no fingerprint and the background
 * catch-up (backfill) tries again, also when the model name changes.
 *
 * The provider is the one the chat uses: the `doublescale_help_chat_provider`
 * filter (tests), else AiProvider::from_settings().
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Tasks;
use DoubleScale\Pro\Modules\Ai\AiProvider;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.

/**
 * Embeddings class.
 */
final class Embeddings {

	private const HOOK = 'embed_backlog';

	/**
	 * Transient holding the model the catch-up was last checked for.
	 */
	private const CHECKED = 'doublescale_help_embed_checked';

	/**
	 * Transient set while the provider keeps failing: no fingerprint is asked
	 * for, so customers do not wait on it question after question.
	 */
	private const DOWN = 'doublescale_help_embed_down';

	/**
	 * Transient counting recent failures.
	 */
	private const FAILURES = 'doublescale_help_embed_failures';

	/**
	 * Failures within DOWN_SECONDS that pause requests (one blip only costs
	 * that question its meaning search).
	 */
	private const FAILURES_TO_PAUSE = 3;

	/**
	 * Seconds failures are counted over, and the pause lasts.
	 */
	private const DOWN_SECONDS = 60;

	/**
	 * Longest wait for a question's fingerprint (a customer is waiting; the
	 * word search answers without it).
	 */
	private const QUESTION_TIMEOUT = 5;

	/**
	 * Articles the assistant may use (alias a), whose passages are caught up.
	 */
	private const USABLE = "a.status = 'published' AND a.include_in_assistant = 1";

	/**
	 * Passages sent per request.
	 */
	private const BATCH = 64;

	/**
	 * Characters of a passage sent to be fingerprinted.
	 */
	private const TEXT_CHARS = 4000;

	/**
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_task' ) );
		add_action( 'admin_init', array( self::class, 'maybe_queue_backfill' ), 40 );
	}

	/**
	 * @return void
	 */
	public static function register_task(): void {
		( new Tasks( Indexer::GROUP ) )->register_callback( self::HOOK, array( self::class, 'run_backfill' ), true );
	}

	/**
	 * The provider when it gives fingerprints, else null.
	 *
	 * @param object|null $provider A provider in hand (null: the filter, then settings).
	 * @return object|null
	 */
	public static function provider( $provider = null ) {
		if ( null === $provider ) {
			$provider = apply_filters( 'doublescale_help_chat_provider', null );
		}
		if ( null === $provider && class_exists( AiProvider::class ) ) {
			$provider = AiProvider::from_settings();
		}
		if ( ! is_object( $provider ) || ! method_exists( $provider, 'embed' ) || ! method_exists( $provider, 'embedding_model' ) ) {
			return null;
		}
		if ( method_exists( $provider, 'supports_embeddings' ) && ! $provider->supports_embeddings() ) {
			return null;
		}

		return $provider;
	}

	/**
	 * Fingerprint one question; null when there is no provider or it failed.
	 *
	 * @param string      $text     Question.
	 * @param object|null $provider The chat's provider (null: resolve it).
	 * @return array{vector: float[], model: string}|null
	 */
	public static function query( string $text, $provider = null ): ?array {
		$provider = self::provider( $provider );
		if ( null === $provider || '' === trim( $text ) || get_transient( self::DOWN ) ) {
			return null;
		}

		$vectors = $provider->embed( array( mb_substr( $text, 0, self::TEXT_CHARS, 'UTF-8' ) ), array( 'timeout' => self::QUESTION_TIMEOUT ) );
		if ( is_wp_error( $vectors ) || ! isset( $vectors[0] ) ) {
			self::failed();
			return null;
		}

		$vector = self::unit( (array) $vectors[0] );
		if ( null === $vector ) {
			return null;
		}

		return array(
			'vector' => $vector,
			'model'  => (string) $provider->embedding_model(),
		);
	}

	/**
	 * Fingerprint the passages of one article that have none (or an old one).
	 *
	 * @param int $article_id Article id.
	 * @return int Passages fingerprinted.
	 */
	public static function index_article( int $article_id ): int {
		return self::fill( 'c.article_id = ' . (int) $article_id );
	}

	/**
	 * Fingerprint the passages of one site page that have none (or an old one).
	 *
	 * @param int $post_id Post.
	 * @return int Passages fingerprinted.
	 */
	public static function index_page( int $post_id ): int {
		return self::fill_pages( 'c.post_id = ' . (int) $post_id );
	}

	/**
	 * Fingerprint one batch of passages missing one, of articles the
	 * assistant may use (published, in the assistant).
	 *
	 * @return int Passages fingerprinted.
	 */
	public static function backfill(): int {
		$done = self::fill( self::USABLE );

		return 0 < $done ? $done : self::fill_pages( '1=1' );
	}

	/**
	 * Background run: one batch, then the next while this one made progress
	 * and some remain (a passage that cannot get one never loops).
	 *
	 * @return void
	 */
	public static function run_backfill(): void {
		$provider = self::provider();
		if ( null === $provider || 0 === self::backfill() || 0 === self::missing( (string) $provider->embedding_model() ) ) {
			return;
		}
		( new Tasks( Indexer::GROUP ) )->enqueue_async( self::HOOK );
	}

	/**
	 * On an admin page: queue the catch-up when passages miss a fingerprint
	 * of the current model. Checked again a day after everything was done,
	 * an hour after work remained (a failed run is retried the same day), and
	 * at once when the model changes (another provider in the AI settings).
	 *
	 * @return void
	 */
	public static function maybe_queue_backfill( bool $now = false ): void {
		$provider = self::provider();
		if ( null === $provider ) {
			return;
		}
		$model = (string) $provider->embedding_model();
		// A page just learned: check now, not tomorrow.
		if ( ! $now && get_transient( self::CHECKED ) === $model ) {
			return;
		}

		$missing = self::missing( $model );
		set_transient( self::CHECKED, $model, 0 === $missing ? DAY_IN_SECONDS : HOUR_IN_SECONDS );
		if ( 0 < $missing && function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( Indexer::GROUP . '_' . self::HOOK, null, Indexer::GROUP ) ) {
			( new Tasks( Indexer::GROUP ) )->enqueue_async( self::HOOK );
		}
	}

	/**
	 * Count a failed request; pause requests after FAILURES_TO_PAUSE.
	 *
	 * @return void
	 */
	private static function failed(): void {
		$count = (int) get_transient( self::FAILURES ) + 1;
		if ( $count >= self::FAILURES_TO_PAUSE ) {
			set_transient( self::DOWN, 1, self::DOWN_SECONDS );
			delete_transient( self::FAILURES );
			return;
		}
		set_transient( self::FAILURES, $count, self::DOWN_SECONDS );
	}

	/**
	 * Pack a unit vector for storage.
	 *
	 * @param float[] $vector Vector.
	 * @return string
	 */
	public static function pack( array $vector ): string {
		return pack( 'g*', ...$vector );
	}

	/**
	 * @param string $blob Stored fingerprint.
	 * @return float[]
	 */
	public static function unpack( string $blob ): array {
		$values = unpack( 'g*', $blob );

		return is_array( $values ) ? array_values( $values ) : array();
	}

	/**
	 * Cosine similarity of two unit vectors (their dot product).
	 *
	 * @param float[] $a Vector.
	 * @param float[] $b Vector.
	 * @return float
	 */
	public static function similarity( array $a, array $b ): float {
		$n   = min( count( $a ), count( $b ) );
		$dot = 0.0;
		for ( $i = 0; $i < $n; $i++ ) {
			$dot += $a[ $i ] * $b[ $i ];
		}

		return $dot;
	}

	/**
	 * @param float[] $vector Vector.
	 * @return float[]|null Unit-length copy, null for a zero vector.
	 */
	private static function unit( array $vector ): ?array {
		$norm = sqrt( array_sum( array_map( static function ( $v ) {
			return (float) $v * (float) $v;
		}, $vector ) ) );
		if ( $norm <= 0.0 ) {
			return null;
		}

		return array_map(
			static function ( $v ) use ( $norm ) {
				return (float) $v / $norm;
			},
			$vector
		);
	}

	/**
	 * @param string $model Current model.
	 * @return int Passages without a fingerprint of $model.
	 */
	private static function missing( string $model ): int {
		global $wpdb;
		$chunks   = Indexer::table();
		$articles = $wpdb->prefix . 'doublescale_kb_articles';

		// The same passages backfill() fills, or the catch-up would be queued
		// again and again for passages it never touches.
		$articles_missing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$chunks} c INNER JOIN {$articles} a ON a.id = c.article_id
				WHERE " . self::USABLE . ' AND ( c.embedding IS NULL OR c.embedding_model IS NULL OR c.embedding_model <> %s )',
				$model
			)
		);
		// Site pages (SitePages): every page passage is one the assistant may use.
		$pages_missing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$chunks} c WHERE c.source = %s AND ( c.embedding IS NULL OR c.embedding_model IS NULL OR c.embedding_model <> %s )",
				SitePages::SOURCE,
				$model
			)
		);

		return $articles_missing + $pages_missing;
	}

	/**
	 * Fingerprint one batch of site page passages matching $where (alias c).
	 *
	 * @param string $where Trusted SQL condition on alias c.
	 * @return int Passages fingerprinted.
	 */
	private static function fill_pages( string $where ): int {
		$provider = self::provider();
		if ( null === $provider || get_transient( self::DOWN ) ) {
			return 0;
		}

		global $wpdb;
		$chunks = Indexer::table();
		$model  = (string) $provider->embedding_model();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.heading, c.content, p.post_title AS title FROM {$chunks} c INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id
				WHERE c.source = %s AND {$where} AND ( c.embedding IS NULL OR c.embedding_model IS NULL OR c.embedding_model <> %s )
				ORDER BY c.id ASC LIMIT %d",
				SitePages::SOURCE,
				$model,
				self::BATCH
			)
		);
		// phpcs:enable

		return self::store( $provider, $model, is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Fingerprint one batch of passages matching $where that lack the current
	 * model's fingerprint.
	 *
	 * @param string $where Trusted SQL condition on alias c.
	 * @return int Passages fingerprinted.
	 */
	private static function fill( string $where ): int {
		$provider = self::provider();
		if ( null === $provider || get_transient( self::DOWN ) ) {
			return 0;
		}

		global $wpdb;
		$chunks   = Indexer::table();
		$articles = $wpdb->prefix . 'doublescale_kb_articles';
		$model    = (string) $provider->embedding_model();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.heading, c.content, a.title FROM {$chunks} c INNER JOIN {$articles} a ON a.id = c.article_id
				WHERE {$where} AND ( c.embedding IS NULL OR c.embedding_model IS NULL OR c.embedding_model <> %s )
				ORDER BY c.id ASC LIMIT %d",
				$model,
				self::BATCH
			)
		);
		return self::store( $provider, $model, is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Fingerprint $rows (id, title, heading, content) and keep the vectors.
	 *
	 * @param object             $provider AI provider.
	 * @param string             $model    Embedding model.
	 * @param array<int, object> $rows     Passages.
	 * @return int Passages fingerprinted.
	 */
	private static function store( $provider, string $model, array $rows ): int {
		global $wpdb;
		$chunks = Indexer::table();
		if ( array() === $rows ) {
			return 0;
		}

		$texts = array();
		foreach ( $rows as $row ) {
			// The title and heading are part of what a passage is about.
			$texts[] = mb_substr( trim( $row->title . "\n" . $row->heading . "\n" . wp_strip_all_tags( (string) $row->content ) ), 0, self::TEXT_CHARS, 'UTF-8' );
		}

		$vectors = $provider->embed( $texts );
		if ( is_wp_error( $vectors ) || count( (array) $vectors ) !== count( $rows ) ) {
			self::failed();
			return 0;
		}

		$done = 0;
		foreach ( array_values( $rows ) as $i => $row ) {
			$vector = self::unit( (array) $vectors[ $i ] );
			if ( null === $vector ) {
				continue;
			}
			$wpdb->update(
				$chunks,
				array(
					'embedding'       => self::pack( $vector ),
					'embedding_model' => $model,
				),
				array( 'id' => (int) $row->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			++$done;
		}

		return $done;
	}
}
