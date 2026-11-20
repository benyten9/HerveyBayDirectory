<?php
/**
 * Find the passages that answer a question, for one reader.
 *
 * Who may read is checked on the LIVE article row in the same query (JOIN):
 * published, "Use in the help assistant" on, and a visibility the caller
 * passes ({@see \DoubleScale\Modules\KnowledgeBase\Services\KbAudience::current()}
 * for customers). Nothing about readability is stored on a passage, so an
 * article made team-only or unpublished stops being found immediately.
 *
 * Ranking is a light BM25 over the normalised stems in PHP — enough for a few
 * thousand passages — with a bonus when a question word is in the article
 * title, and at most MAX_PER_ARTICLE passages from one article so answers can
 * draw on several.
 *
 * `coverage` (share of the question's words found in the best passage) is the
 * signal for "no answer here": raw scores cannot be compared between
 * questions, coverage can. With meaning fingerprints (Embeddings) the best
 * cosine `similarity` is a second such signal, for questions asked in other
 * words or another language than the article.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\KnowledgeBase\Models\KbArticleModel;
use DoubleScale\Modules\KnowledgeBase\Services\KbPublicArticles;
use DoubleScale\Modules\KnowledgeBase\Services\TextNormalizer;

/**
 * Retriever class.
 */
final class Retriever {

	/**
	 * Candidate passages read per question before ranking.
	 */
	private const CANDIDATES = 300;

	/**
	 * Passages one article may contribute.
	 */
	private const MAX_PER_ARTICLE = 2;

	/**
	 * Fingerprinted passages compared per question, newest first (meaning
	 * search reads every fingerprint, so it is bounded; filter
	 * `doublescale_help_meaning_scan`).
	 */
	private const MEANING_SCAN = 5000;

	/**
	 * Closest passages taken into the fusion.
	 */
	private const MEANING_TOP = 20;

	/**
	 * Reciprocal rank fusion constant.
	 */
	private const FUSION_K = 60;

	/**
	 * Article titles looked at to tell the articles' writing system.
	 */
	private const LANGUAGE_SAMPLE = 50;

	/**
	 * Share of the sampled titles another writing system needs before a
	 * question in the site's main one is translated into it.
	 */
	private const LANGUAGE_SHARE = 0.2;

	/**
	 * BM25 term-frequency saturation and length normalisation.
	 */
	private const K1 = 1.2;
	private const B  = 0.75;

	/**
	 * Question words that say nothing about the topic, after normalisation
	 * (so Arabic forms are written without ال, with ا / ه / ي unified). They are
	 * left out of the search and of `coverage`; a question made only of them
	 * keeps them.
	 *
	 * @var string[]
	 */
	private const STOPWORDS = array(
		'how', 'do', 'doe', 'did', 'can', 'could', 'my', 'me', 'the', 'an', 'to', 'of', 'in', 'on', 'for', 'is', 'are', 'what', 'where', 'when', 'why', 'which', 'with', 'and', 'or', 'you', 'your', 'we', 'our', 'it', 'this', 'that', 'there', 'please', 'want', 'need', 'about', 'many', 'much', 'have', 'ha', 'had', 'get', 'got', 'ask', 'tell', 'know', 'should', 'would', 'will', 'any', 'some', 'am', 'be', 'wa', 'were', 'if', 'not', 'no', 'yes', 'all', 'still', 'way', 'make', 'also', 'too', 'just',
		'كيف', 'ازاي', 'ايه', 'ماذا', 'ما', 'هل', 'في', 'من', 'علي', 'عن', 'الي', 'انا', 'انت', 'هو', 'هي', 'هذا', 'هذه', 'ده', 'دي', 'اللي', 'التي', 'الذي', 'لو', 'مع', 'او', 'عايز', 'اريد', 'ممكن', 'لما', 'فين', 'امتي', 'ليه',
	);

	/**
	 * Passages for $question that a reader with $visibilities may see.
	 *
	 * With the question's meaning fingerprint ($query, from
	 * Embeddings::query()) the passages closest in meaning are found too —
	 * under the very same live filter — and both rankings are fused by
	 * reciprocal rank. `similarity` is the best meaning match (0 without one).
	 *
	 * @param string                                     $question     The question, any language.
	 * @param string[]                                   $visibilities Allowed visibilities (public, portal, internal).
	 * @param int                                        $limit        Passages to return.
	 * @param array{vector: float[], model: string}|null $query        The question's fingerprint.
	 * @return array{hits: array<int, array<string, mixed>>, coverage: float, similarity: float, terms: string[]}
	 */
	public static function search( string $question, array $visibilities, int $limit = 6, ?array $query = null ): array {
		$terms = self::terms( $question );
		$empty = array(
			'hits'       => array(),
			'coverage'   => 0.0,
			'similarity' => 0.0,
			'terms'      => $terms,
		);

		$allowed = array_values( array_intersect( $visibilities, KbArticleModel::visibilities() ) );
		if ( array() === $allowed ) {
			return $empty;
		}

		// The site's own pages (SitePages) are for everyone: any reader may get them.
		$pages   = in_array( KbArticleModel::VISIBILITY_PUBLIC, $allowed, true ) && SitePages::enabled() ? SitePages::settings()['post_types'] : array();
		$rows    = array() !== $terms ? array_merge( self::candidates( $terms, $allowed ), self::page_candidates( $terms, $pages ) ) : array();
		$words   = array() !== $rows ? self::rank( $rows, $terms ) : array();
		$meaning = null !== $query ? self::by_meaning( $query, $allowed, $pages ) : array();
		if ( array() === $words && array() === $meaning ) {
			return $empty;
		}

		$hits      = array();
		$per_title = array();
		$open      = array();
		foreach ( self::fuse( $words, $meaning ) as $hit ) {
			// A page is read again now: closed since it was learned (a password,
			// a membership rule, left out) means never used.
			if ( 'page' === $hit['source'] ) {
				$open[ $hit['post_id'] ] = $open[ $hit['post_id'] ] ?? SitePages::open( (int) $hit['post_id'] );
				if ( ! $open[ $hit['post_id'] ] ) {
					continue;
				}
			}
			$article_id = $hit['key'];
			if ( ( $per_title[ $article_id ] ?? 0 ) >= self::MAX_PER_ARTICLE ) {
				continue;
			}
			$per_title[ $article_id ] = ( $per_title[ $article_id ] ?? 0 ) + 1;
			$hits[]                   = $hit;
			if ( count( $hits ) >= $limit ) {
				break;
			}
		}

		return array(
			'hits'       => $hits,
			'coverage'   => array() === $hits ? 0.0 : (float) max( array_column( $hits, 'coverage' ) ),
			'similarity' => array() === $hits ? 0.0 : (float) max( array_column( $hits, 'similarity' ) ),
			'terms'      => $terms,
		);
	}

	/**
	 * Passages closest in meaning to the question: the same live filter as
	 * the word search, only fingerprints made by the question's model.
	 *
	 * @param array{vector: float[], model: string} $query   Question fingerprint.
	 * @param string[]                              $allowed Visibilities.
	 * @return array<int, array<string, mixed>> Most similar first.
	 */
	private static function by_meaning( array $query, array $allowed, array $page_types = array() ): array {
		global $wpdb;
		$chunks   = Indexer::table();
		$articles = $wpdb->prefix . 'doublescale_kb_articles';
		$in       = implode( ',', array_fill( 0, count( $allowed ), '%s' ) );
		$live     = "a.status = %s AND a.include_in_assistant = 1 AND a.visibility IN ({$in})";
		$live_arg = array_merge( array( KbArticleModel::STATUS_PUBLISHED ), $allowed );
		$scan     = max( 1, (int) apply_filters( 'doublescale_help_meaning_scan', self::MEANING_SCAN ) );

		// Only fingerprints first; newest first, so past the cap it is the
		// oldest passages that are left out, not the ones just written.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.embedding FROM {$chunks} c INNER JOIN {$articles} a ON a.id = c.article_id
				WHERE {$live} AND c.embedding IS NOT NULL AND c.embedding_model = %s
				ORDER BY c.id DESC LIMIT %d",
				array_merge( $live_arg, array( (string) $query['model'], $scan ) )
			)
		);

		$scores = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$scores[ (int) $row->id ] = Embeddings::similarity( $query['vector'], Embeddings::unpack( (string) $row->embedding ) );
		}
		unset( $rows );
		if ( array() !== $page_types ) {
			$page_live = self::page_live( $page_types );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$page_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT c.id, c.embedding FROM {$chunks} c INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id
					WHERE {$page_live['sql']} AND c.embedding IS NOT NULL AND c.embedding_model = %s
					ORDER BY c.id DESC LIMIT %d",
					array_merge( $page_live['args'], array( (string) $query['model'], $scan ) )
				)
			);
			foreach ( is_array( $page_rows ) ? $page_rows : array() as $row ) {
				$scores[ (int) $row->id ] = Embeddings::similarity( $query['vector'], Embeddings::unpack( (string) $row->embedding ) );
			}
			unset( $page_rows );
		}
		if ( array() === $scores ) {
			return array();
		}
		arsort( $scores );
		$scores = array_slice( $scores, 0, self::MEANING_TOP, true );

		// Then the text of the closest ones (the live filter again).
		$ids  = array_keys( $scores );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.article_id, c.heading, c.content, a.title, a.slug, a.visibility
				FROM {$chunks} c INNER JOIN {$articles} a ON a.id = c.article_id
				WHERE {$live} AND c.id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
				array_merge( $live_arg, $ids )
			)
		);
		if ( array() !== $page_types ) {
			$page_live = self::page_live( $page_types );
			$rows      = array_merge(
				is_array( $rows ) ? $rows : array(),
				(array) $wpdb->get_results(
					$wpdb->prepare(
						"SELECT c.id, c.article_id, c.post_id, c.source, c.heading, c.content, p.post_title AS title
						FROM {$chunks} c INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id
						WHERE {$page_live['sql']} AND c.id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
						array_merge( $page_live['args'], $ids )
					)
				)
			);
		}
		// phpcs:enable

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$hit               = self::hit( $row, 0.0, 0.0 );
			$hit['similarity'] = round( $scores[ (int) $row->id ], 4 );
			$out[]             = $hit;
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['similarity'] <=> $a['similarity'] ?: $a['chunk_id'] <=> $b['chunk_id'];
			}
		);

		return $out;
	}

	/**
	 * When $question is written in another script than help articles the
	 * reader may use (an Arabic question, English articles; or the reverse),
	 * the name of those articles' language, so the question can be put into
	 * it and searched again. Null when they share the script, or when the
	 * question's script has articles and the other is under LANGUAGE_SHARE.
	 *
	 * The language is named, not shown by example: small models keep the
	 * question's language when only given sample titles.
	 *
	 * @param string   $question     Question.
	 * @param string[] $visibilities Allowed visibilities.
	 * @return string|null
	 */
	public static function other_language( string $question, array $visibilities ): ?string {
		$script  = self::script( $question );
		$allowed = array_values( array_intersect( $visibilities, KbArticleModel::visibilities() ) );
		if ( null === $script || array() === $allowed ) {
			return null;
		}

		global $wpdb;
		$chunks   = Indexer::table();
		$articles = $wpdb->prefix . 'doublescale_kb_articles';
		$in       = implode( ',', array_fill( 0, count( $allowed ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
		$titles = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT a.title FROM {$chunks} c INNER JOIN {$articles} a ON a.id = c.article_id
				WHERE a.status = %s AND a.include_in_assistant = 1 AND a.visibility IN ({$in})
				ORDER BY a.id DESC LIMIT %d",
				array_merge( array( KbArticleModel::STATUS_PUBLISHED ), $allowed, array( self::LANGUAGE_SAMPLE ) )
			)
		);
		$counts = array();
		foreach ( (array) $titles as $title ) {
			$found = self::script( (string) $title );
			if ( null !== $found ) {
				$counts[ $found ] = ( $counts[ $found ] ?? 0 ) + 1;
			}
		}
		$same = $counts[ $script ] ?? 0;
		unset( $counts[ $script ] );
		if ( array() === $counts ) {
			return null;
		}
		arsort( $counts );
		$other = (string) array_key_first( $counts );

		// One stray article in another language is no reason to translate
		// every question that finds nothing: it must be a real share.
		if ( 0 < $same && $counts[ $other ] < self::LANGUAGE_SHARE * array_sum( $counts + array( $script => $same ) ) ) {
			return null;
		}

		return self::language( $other );
	}

	/**
	 * The language written in $script: the site's own when it uses Latin
	 * letters, else English.
	 *
	 * @param string $script Script name.
	 * @return string
	 */
	private static function language( string $script ): string {
		$names = array(
			'Arabic'     => 'Arabic',
			'Cyrillic'   => 'Russian',
			'Greek'      => 'Greek',
			'Hebrew'     => 'Hebrew',
			'Han'        => 'Chinese',
			'Hangul'     => 'Korean',
			'Hiragana'   => 'Japanese',
			'Katakana'   => 'Japanese',
			'Thai'       => 'Thai',
			'Devanagari' => 'Hindi',
		);
		if ( isset( $names[ $script ] ) ) {
			return $names[ $script ];
		}

		$latin = array(
			'de' => 'German',
			'fr' => 'French',
			'es' => 'Spanish',
			'it' => 'Italian',
			'pt' => 'Portuguese',
			'nl' => 'Dutch',
			'tr' => 'Turkish',
			'pl' => 'Polish',
			'sv' => 'Swedish',
			'id' => 'Indonesian',
		);

		return $latin[ substr( (string) get_locale(), 0, 2 ) ] ?? 'English';
	}

	/**
	 * The writing system most of $text's letters belong to.
	 *
	 * @param string $text Text.
	 * @return string|null
	 */
	private static function script( string $text ): ?string {
		$best  = null;
		$count = 0;
		foreach ( array( 'Latin', 'Arabic', 'Cyrillic', 'Greek', 'Hebrew', 'Han', 'Hangul', 'Hiragana', 'Katakana', 'Thai', 'Devanagari' ) as $name ) {
			$n = (int) preg_match_all( '/\\p{' . $name . '}/u', $text );
			if ( $n > $count ) {
				$best  = $name;
				$count = $n;
			}
		}

		return $best;
	}

	/**
	 * Reciprocal rank fusion of the word and meaning rankings. A passage found
	 * by both keeps its word coverage and its similarity.
	 *
	 * @param array<int, array<string, mixed>> $words   Word ranking.
	 * @param array<int, array<string, mixed>> $meaning Meaning ranking.
	 * @return array<int, array<string, mixed>>
	 */
	private static function fuse( array $words, array $meaning ): array {
		if ( array() === $meaning ) {
			return $words;
		}

		$hits = array();
		foreach ( array( $words, $meaning ) as $ranking ) {
			foreach ( array_values( $ranking ) as $rank => $hit ) {
				$id     = $hit['chunk_id'];
				$points = 1.0 / ( self::FUSION_K + $rank + 1 );
				if ( ! isset( $hits[ $id ] ) ) {
					$hits[ $id ]          = $hit;
					$hits[ $id ]['score'] = $points;
					continue;
				}
				$hits[ $id ]['score']     += $points;
				$hits[ $id ]['coverage']   = max( $hits[ $id ]['coverage'], $hit['coverage'] );
				$hits[ $id ]['similarity'] = max( $hits[ $id ]['similarity'], $hit['similarity'] );
			}
		}

		$hits = array_values( $hits );
		usort(
			$hits,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'] ?: $a['chunk_id'] <=> $b['chunk_id'];
			}
		);

		return array_map(
			static function ( $hit ) {
				$hit['score'] = round( $hit['score'], 6 );
				return $hit;
			},
			$hits
		);
	}

	/**
	 * The question's topic words: normalised stems without stopwords, and
	 * without two-letter stems while longer ones exist (a two-letter LIKE
	 * matches inside unrelated words). Falls back step by step, so a question
	 * never ends up with no words at all.
	 *
	 * @param string $question Question.
	 * @return string[]
	 */
	public static function terms( string $question ): array {
		$all     = array_values( array_unique( TextNormalizer::tokens( $question ) ) );
		$content = array_values( array_diff( $all, self::STOPWORDS ) );
		if ( array() === $content ) {
			return $all;
		}

		$long = array_values(
			array_filter(
				$content,
				static fn( string $term ): bool => mb_strlen( $term, 'UTF-8' ) >= 3
			)
		);

		return array() !== $long ? $long : $content;
	}

	/**
	 * Passages containing any question word, readable by $allowed, live-checked.
	 *
	 * @param string[] $terms   Normalised question words.
	 * @param string[] $allowed Visibilities.
	 * @return array<int, object>
	 */
	private static function candidates( array $terms, array $allowed ): array {
		global $wpdb;
		$chunks   = Indexer::table();
		$articles = $wpdb->prefix . 'doublescale_kb_articles';

		$like = array();
		$args = array( KbArticleModel::STATUS_PUBLISHED );
		foreach ( $allowed as $visibility ) {
			$args[] = $visibility;
		}
		foreach ( $terms as $term ) {
			$like[] = 'c.search_text LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $term ) . '%';
		}
		// Passages matching more question words first, so the cap below never
		// drops the best ones when a common word matches hundreds of passages.
		$score = array();
		foreach ( $terms as $term ) {
			$score[] = '(c.search_text LIKE %s)';
			$args[]  = '%' . $wpdb->esc_like( $term ) . '%';
		}
		$args[] = max( 1, (int) apply_filters( 'doublescale_help_retriever_candidates', self::CANDIDATES ) );

		$in  = implode( ',', array_fill( 0, count( $allowed ), '%s' ) );
		$sql = "SELECT c.id, c.article_id, c.heading, c.content, c.search_text, c.word_count,
				a.title, a.slug, a.visibility
			FROM {$chunks} c
			INNER JOIN {$articles} a ON a.id = c.article_id
			WHERE a.status = %s
				AND a.include_in_assistant = 1
				AND a.visibility IN ({$in})
				AND (" . implode( ' OR ', $like ) . ')
			ORDER BY (' . implode( ' + ', $score ) . ') DESC, c.id ASC
			LIMIT %d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Passages of the site's pages holding a question word, under the live
	 * filter (published, no password, a chosen type; SitePages::open() runs
	 * again on each hit in search()).
	 *
	 * @param string[] $terms Question words.
	 * @param string[] $types Post types the owner chose ([] when off).
	 * @return array<int, object>
	 */
	private static function page_candidates( array $terms, array $types ): array {
		if ( array() === $types || array() === $terms ) {
			return array();
		}
		global $wpdb;
		$chunks = Indexer::table();
		$live   = self::page_live( $types );
		$like   = array();
		$args   = $live['args'];
		foreach ( $terms as $term ) {
			$like[] = 'c.search_text LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $term ) . '%';
		}
		$score = array();
		foreach ( $terms as $term ) {
			$score[] = '(c.search_text LIKE %s)';
			$args[]  = '%' . $wpdb->esc_like( $term ) . '%';
		}
		$args[] = max( 1, (int) apply_filters( 'doublescale_help_retriever_candidates', self::CANDIDATES ) );

		$sql = "SELECT c.id, c.article_id, c.post_id, c.source, c.heading, c.content, c.search_text, c.word_count, p.post_title AS title
			FROM {$chunks} c INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id
			WHERE {$live['sql']} AND (" . implode( ' OR ', $like ) . ')
			ORDER BY (' . implode( ' + ', $score ) . ') DESC, c.id ASC
			LIMIT %d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The live filter on a site page passage (alias c) joined with its post (p).
	 *
	 * @param string[] $types Post types.
	 * @return array{sql: string, args: array<int, string>}
	 */
	private static function page_live( array $types ): array {
		return array(
			'sql'  => "c.source = %s AND p.post_status = 'publish' AND p.post_password = '' AND p.post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')',
			'args' => array_merge( array( SitePages::SOURCE ), array_values( $types ) ),
		);
	}

	/**
	 * Score and sort candidates.
	 *
	 * @param array<int, object> $rows  Candidate rows.
	 * @param string[]           $terms Question words.
	 * @return array<int, array<string, mixed>>
	 */
	private static function rank( array $rows, array $terms ): array {
		$docs = array();
		foreach ( $rows as $row ) {
			$tokens = preg_split( '/\s+/u', (string) $row->search_text, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
			$docs[] = array(
				'row'    => $row,
				'tokens' => $tokens,
				'title'  => TextNormalizer::tokens( (string) $row->title ),
			);
		}

		$count  = count( $docs );
		$avglen = max( 1.0, array_sum( array_map( static fn( $d ) => count( $d['tokens'] ), $docs ) ) / $count );

		// Document frequency of each question word among the candidates.
		$df = array();
		foreach ( $terms as $term ) {
			$df[ $term ] = 0;
			foreach ( $docs as $doc ) {
				if ( self::contains( $doc['tokens'], $term ) ) {
					++$df[ $term ];
				}
			}
		}

		$out = array();
		foreach ( $docs as $doc ) {
			$score   = 0.0;
			$matched = 0;
			$len     = count( $doc['tokens'] );
			foreach ( $terms as $term ) {
				$tf = self::frequency( $doc['tokens'], $term );
				if ( 0 === $tf ) {
					continue;
				}
				++$matched;
				$idf    = log( 1 + ( $count - $df[ $term ] + 0.5 ) / ( $df[ $term ] + 0.5 ) );
				$score += $idf * ( $tf * ( self::K1 + 1 ) ) / ( $tf + self::K1 * ( 1 - self::B + self::B * $len / $avglen ) );
				if ( self::contains( $doc['title'], $term ) ) {
					$score += $idf * 0.5;
				}
			}

			$out[] = self::hit( $doc['row'], $score, $matched / count( $terms ) );
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'] ?: $a['chunk_id'] <=> $b['chunk_id'];
			}
		);

		return $out;
	}

	/**
	 * One passage as the engine receives it.
	 *
	 * @param object $row      Chunk joined with its article.
	 * @param float  $score    Ranking score.
	 * @param float  $coverage Share of the question's words found.
	 * @return array<string, mixed>
	 */
	private static function hit( $row, float $score, float $coverage ): array {
		if ( 'page' === ( $row->source ?? 'article' ) ) {
			$post_id = (int) $row->post_id;

			return array(
				'chunk_id'   => (int) $row->id,
				'article_id' => 0,
				'source'     => 'page',
				'post_id'    => $post_id,
				// One key per page, so pages never share one in "at most two per title".
				'key'        => 'page-' . $post_id,
				'title'      => (string) $row->title,
				'heading'    => (string) $row->heading,
				'text'       => (string) $row->content,
				'visibility' => KbArticleModel::VISIBILITY_PUBLIC,
				'url'        => (string) get_permalink( $post_id ),
				'score'      => round( $score, 4 ),
				'coverage'   => round( $coverage, 4 ),
				'similarity' => 0.0,
			);
		}
		$visibility = (string) $row->visibility;

		return array(
			'chunk_id'   => (int) $row->id,
			'article_id' => (int) $row->article_id,
			'source'     => 'article',
			'post_id'    => 0,
			'key'        => 'article-' . (int) $row->article_id,
			'title'      => (string) $row->title,
			'heading'    => (string) $row->heading,
			'text'       => (string) $row->content,
			'visibility' => $visibility,
			// Built on the server: the model never writes a link.
			'url'        => KbArticleModel::VISIBILITY_INTERNAL === $visibility ? null : ( KbPublicArticles::article_url( (string) $row->slug ) ?: null ),
			'score'      => round( $score, 4 ),
			'coverage'   => round( $coverage, 4 ),
			'similarity' => 0.0,
		);
	}

	/**
	 * Occurrences of $term among a passage's stems (substring match, like the SQL LIKE).
	 *
	 * @param string[] $tokens Passage stems.
	 * @param string   $term   Question stem.
	 * @return int
	 */
	private static function frequency( array $tokens, string $term ): int {
		$n = 0;
		foreach ( $tokens as $token ) {
			if ( false !== strpos( $token, $term ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * @param string[] $tokens Stems.
	 * @param string   $term   Stem.
	 * @return bool
	 */
	private static function contains( array $tokens, string $term ): bool {
		foreach ( $tokens as $token ) {
			if ( false !== strpos( $token, $term ) ) {
				return true;
			}
		}
		return false;
	}
}
