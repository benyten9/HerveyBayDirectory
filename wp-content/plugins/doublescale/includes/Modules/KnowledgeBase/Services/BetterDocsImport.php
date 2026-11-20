<?php
/**
 * Copy a BetterDocs knowledge base into the help center.
 *
 * Reads BetterDocs' own rows straight from the database (posts of type
 * `docs`, `doc_category` terms and their meta, the analytics table), so it
 * works with BetterDocs on or already deactivated, and changes nothing of
 * BetterDocs: the owner can compare both before turning it off.
 *
 *   docs (published, drafts)        → articles: title, slug, body, excerpt,
 *                                     author, dates (private: staff only)
 *   doc categories, parents, order  → categories; a third level or deeper
 *                                     sits at the second, under its top one
 *   order of docs in a category     → article positions
 *   views, 😊 / 😞 reactions        → views, helpful yes / no
 *   a doc in several categories     → its first category
 *   tags, 😐 reactions              → not kept
 *
 * Already here: a category with the same address or name is used as it is
 * (never a second "Billing"). A doc whose article already exists (same
 * address or title) is skipped by default, its old addresses leading to the
 * existing article; the admin may import such docs as copies instead.
 *
 * Runs in batches (the Articles screen calls again until nothing is left).
 * What was copied is remembered by BetterDocs id, so running it again copies
 * only what is new and never makes a second copy. The same record answers
 * BetterDocs' old addresses with a 301 once they end in "page not found"
 * ({@see self::redirect_target()}, called by {@see KbAddresses}).
 *
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\KnowledgeBase\Models\KbArticleModel;
use DoubleScale\Modules\KnowledgeBase\Models\KbCategoryModel;
use WP_Error;

/**
 * BetterDocsImport service.
 */
final class BetterDocsImport {

	/**
	 * What was copied, and BetterDocs' addresses.
	 */
	public const MAP_OPTION = 'doublescale_kb_betterdocs_import';

	/**
	 * Docs copied per request.
	 */
	public const BATCH = 20;

	/**
	 * Statuses copied (trash, auto drafts and revisions are not).
	 */
	private const STATUSES = array( 'publish', 'draft', 'pending', 'future', 'private' );

	/**
	 * A doc whose article is already here: link to it (default) or copy it.
	 */
	public const SKIP = 'skip';
	public const COPY = 'copy';

	/**
	 * What there is to copy.
	 *
	 * @return array<string, mixed>
	 */
	public static function scan(): array {
		$docs      = self::docs();
		$map       = self::map();
		$copied    = self::copied_ids( $map, array_keys( $docs ) );
		$terms     = self::terms();
		$doc_terms = self::doc_terms();

		$published  = 0;
		$multi      = 0;
		$restricted = self::restricted_ids( array_keys( $docs ) );
		$protected  = 0;
		foreach ( $docs as $id => $doc ) {
			if ( 'publish' === $doc->post_status ) {
				++$published;
			}
			if ( self::is_protected( $doc, $restricted ) ) {
				++$protected;
			}
			if ( count( $doc_terms[ $id ] ?? array() ) > 1 ) {
				++$multi;
			}
		}

		$deep = 0;
		foreach ( $terms as $term ) {
			if ( self::depth( (int) $term->term_id, $terms ) >= 2 ) {
				++$deep;
			}
		}

		// Not copied yet, but already here.
		$index    = self::existing_articles( $map );
		$used     = array();
		$existing = 0;
		foreach ( $docs as $id => $doc ) {
			if ( ! isset( $copied[ $id ] ) && self::match_doc( $doc, $index, $used ) > 0 ) {
				++$existing;
			}
		}
		$taken           = array_values( $map['terms'] );
		$existing_groups = 0;
		foreach ( $terms as $term_id => $term ) {
			$mapped = (int) ( $map['terms'][ $term_id ] ?? 0 );
			if ( ( $mapped <= 0 || ! KbCategoryModel::find( $mapped ) ) && self::match_category( $term, $taken ) > 0 ) {
				++$existing_groups;
			}
		}

		return array(
			'available'           => count( $docs ) > 0,
			'active'              => defined( 'BETTERDOCS_VERSION' ),
			'docs'                => count( $docs ),
			'published'           => $published,
			'drafts'              => count( $docs ) - $published,
			'categories'          => count( $terms ),
			'copied'              => count( $copied ),
			'linked'              => count( array_intersect_key( $copied, array_flip( $map['linked'] ) ) ),
			'remaining'           => count( $docs ) - count( $copied ),
			'existing'            => $existing,
			'existing_categories' => $existing_groups,
			'multi_category'      => $multi,
			'deep'                => $deep,
			'tags'                => self::tag_count(),
			// Private, password-protected or restricted docs: copied as internal.
			'protected'           => $protected,
		);
	}

	/**
	 * Copy the categories, then up to `$limit` docs not copied yet. A doc
	 * whose article is already here is linked to it (`skip`, not counted in
	 * `$limit`) or copied like the rest (`copy`).
	 *
	 * @param int    $limit      Docs copied this time.
	 * @param string $duplicates {@see self::SKIP} or {@see self::COPY}.
	 * @return array<string, mixed>|WP_Error {@see self::scan()} plus `copied_now`, `linked_now`.
	 */
	public static function run( int $limit = self::BATCH, string $duplicates = self::SKIP ) {
		$docs = self::docs();
		if ( ! $docs ) {
			return new WP_Error( 'kb_betterdocs_nothing', __( 'No BetterDocs docs were found on this site.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$map = self::map();
		$map = self::copy_categories( $map );
		$map = self::remember_bases( $map );
		update_option( self::MAP_OPTION, $map, false );

		$copied    = self::copied_ids( $map, array_keys( $docs ) );
		$doc_terms = self::doc_terms();
		$positions = self::positions( $docs, $doc_terms );
		$stats     = self::stats();
		$service   = new ArticleService();
		$now       = 0;
		$linked    = 0;
		$index     = self::COPY === $duplicates ? array() : self::existing_articles( $map );
		$used      = array();

		$kept_internal = 0;
		$restricted    = self::restricted_ids( array_keys( $docs ) );

		foreach ( $docs as $id => $doc ) {
			if ( $now >= max( 1, $limit ) ) {
				break;
			}
			if ( isset( $copied[ $id ] ) ) {
				continue;
			}

			$existing = $index ? self::match_doc( $doc, $index, $used ) : 0;
			if ( $existing > 0 ) {
				// Already here: the existing article stays as it is, and the
				// doc's old addresses lead to it.
				$map['docs'][ $id ] = $existing;
				$map['linked'][]    = $id;
				$map['doc_slugs'][ rawurldecode( (string) $doc->post_name ) ] = $id;

				$path = self::old_path( 'doc', $id );
				if ( '' !== $path ) {
					$map['paths'][ $path ] = array( 'doc', $id );
				}
				++$linked;
				update_option( self::MAP_OPTION, $map, false );
				continue;
			}

			$term_id     = (int) ( $doc_terms[ $id ][0] ?? 0 );
			$category_id = (int) ( $map['terms'][ $term_id ] ?? 0 );

			$protected = self::is_protected( $doc, $restricted );
			if ( $protected ) {
				++$kept_internal;
			}

			$title = trim( html_entity_decode( (string) $doc->post_title, ENT_QUOTES, 'UTF-8' ) );
			$data  = array(
				'title'                => '' !== $title ? $title : __( 'Untitled', 'doublescale' ),
				'slug'                 => '' !== (string) $doc->post_name ? rawurldecode( (string) $doc->post_name ) : $title,
				'content'              => (string) $doc->post_content,
				'excerpt'              => (string) $doc->post_excerpt,
				'status'               => in_array( $doc->post_status, array( 'publish', 'private' ), true ) ? KbArticleModel::STATUS_PUBLISHED : KbArticleModel::STATUS_DRAFT,
				// A private, password-protected or restricted doc was not for the
				// public: it stays internal, out of the assistant, never public.
				'visibility'           => $protected ? KbArticleModel::VISIBILITY_INTERNAL : KbArticleModel::VISIBILITY_PUBLIC,
				'category_id'          => $category_id > 0 ? $category_id : null,
				'position'             => (int) ( $positions[ $id ] ?? 0 ),
				'include_in_assistant' => ! $protected,
			);

			$article = $service->create( $data );
			if ( is_wp_error( $article ) ) {
				return $article;
			}

			$views = max( (int) ( $stats[ $id ]['meta_views'] ?? 0 ), (int) ( $stats[ $id ]['impressions'] ?? 0 ) );
			$keep  = array(
				'views'       => $views,
				'helpful_yes' => (int) ( $stats[ $id ]['happy'] ?? 0 ),
				'helpful_no'  => (int) ( $stats[ $id ]['sad'] ?? 0 ),
				'author_id'   => (int) $doc->post_author > 0 ? (int) $doc->post_author : null,
			);
			if ( self::real_date( (string) $doc->post_date_gmt ) ) {
				$keep['created_at'] = (string) $doc->post_date_gmt;
				if ( KbArticleModel::STATUS_PUBLISHED === $article->status ) {
					$keep['published_at'] = (string) $doc->post_date_gmt;
				}
			}
			KbArticleModel::query()->where( 'id', (int) $article->id )->update( $keep );

			$map['docs'][ $id ] = (int) $article->id;
			$map['linked']      = array_values( array_diff( $map['linked'], array( $id ) ) );
			$map['doc_slugs'][ rawurldecode( (string) $doc->post_name ) ] = $id;

			$path = self::old_path( 'doc', $id );
			if ( '' !== $path ) {
				$map['paths'][ $path ] = array( 'doc', $id );
			}
			++$now;

			// Saved as it goes: a request cut short loses nothing copied.
			update_option( self::MAP_OPTION, $map, false );
		}

		/**
		 * A BetterDocs import batch finished.
		 *
		 * @param int $copied_now Docs copied in this batch.
		 */
		do_action( 'doublescale_kb_betterdocs_imported', $now );

		return array_merge(
			self::scan(),
			array(
				'copied_now'    => $now,
				'linked_now'    => $linked,
				// Copied as internal (not public) because the doc was private,
				// password-protected or restricted in BetterDocs.
				'kept_internal' => $kept_internal,
			)
		);
	}

	/**
	 * Was the doc kept from the public in BetterDocs: private, password
	 * protected, or restricted ({@see self::restricted_ids()})?
	 *
	 * @param object $doc        Doc row.
	 * @param int[]  $restricted Ids of restricted docs.
	 * @return bool
	 */
	private static function is_protected( $doc, array $restricted ): bool {
		return 'private' === $doc->post_status
			|| '' !== (string) ( $doc->post_password ?? '' )
			|| in_array( (int) $doc->ID, $restricted, true );
	}

	/**
	 * Docs BetterDocs Pro restricts (content restriction / internal knowledge
	 * base). Its meta keys are not documented, so this reads any post meta whose
	 * name has "restrict" or "internal" in it and whose value is on. Unsure is
	 * safe here: such a doc is copied as internal, never public.
	 *
	 * @param int[] $ids Doc ids.
	 * @return int[]
	 */
	private static function restricted_ids( array $ids ): array {
		global $wpdb;

		if ( array() === $ids ) {
			return array();
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- BetterDocs rows; placeholders built above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT post_id, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($in) AND (meta_key LIKE %s OR meta_key LIKE %s)",
				array_merge( $ids, array( '%' . $wpdb->esc_like( 'restrict' ) . '%', '%' . $wpdb->esc_like( 'internal' ) . '%' ) )
			)
		);
		// phpcs:enable

		$out = array();
		foreach ( (array) $rows as $row ) {
			$value = strtolower( trim( (string) $row->meta_value ) );
			if ( ! in_array( $value, array( '', '0', 'off', 'no', 'false', 'a:0:{}', 'n;' ), true ) ) {
				$out[] = (int) $row->post_id;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Where an old BetterDocs address goes now, or ''.
	 *
	 * @param string               $path  Request path ({@see KbAddresses::request_path()}).
	 * @param array<string, mixed> $query Query arguments.
	 * @return string
	 */
	public static function redirect_target( string $path, array $query = array() ): string {
		$map = self::map();
		if ( ! $map['docs'] && ! $map['terms'] ) {
			return '';
		}

		// Plain links: a doc by slug or id, a category by slug.
		if ( isset( $query['docs'] ) && is_scalar( $query['docs'] ) ) {
			$doc = (int) ( $map['doc_slugs'][ (string) $query['docs'] ] ?? 0 );
			return $doc > 0 ? self::doc_target( $map, $doc ) : '';
		}
		if ( isset( $query['p'] ) && is_scalar( $query['p'] ) && isset( $map['docs'][ (int) $query['p'] ] ) ) {
			return self::doc_target( $map, (int) $query['p'] );
		}
		if ( isset( $query['doc_category'] ) && is_scalar( $query['doc_category'] ) ) {
			$term = (int) ( $map['term_slugs'][ (string) $query['doc_category'] ] ?? 0 );
			return $term > 0 ? self::term_target( $map, $term ) : '';
		}
		if ( '' === $path && isset( $query['post_type'] ) && 'docs' === $query['post_type'] ) {
			return KbPublicArticles::page_url();
		}

		if ( '' === $path ) {
			return '';
		}

		// The exact address BetterDocs gave, when it was on at the import.
		if ( isset( $map['paths'][ $path ] ) ) {
			list( $kind, $id ) = $map['paths'][ $path ];
			return 'doc' === $kind ? self::doc_target( $map, (int) $id ) : self::term_target( $map, (int) $id );
		}

		// Otherwise any address under one of its bases, by its last part.
		foreach ( $map['bases'] as $base ) {
			$base = trim( (string) $base, '/' );
			if ( '' === $base ) {
				continue;
			}
			if ( $path === $base ) {
				return KbPublicArticles::page_url();
			}
			if ( 0 !== strpos( $path, $base . '/' ) ) {
				continue;
			}

			$parts = explode( '/', $path );
			$last  = (string) end( $parts );
			$doc   = (int) ( $map['doc_slugs'][ $last ] ?? 0 );
			if ( $doc > 0 ) {
				return self::doc_target( $map, $doc );
			}
			$term = (int) ( $map['term_slugs'][ $last ] ?? 0 );
			if ( $term > 0 ) {
				return self::term_target( $map, $term );
			}
		}

		return '';
	}

	/**
	 * What was copied: BetterDocs id → our id, slugs, addresses, bases.
	 *
	 * @return array{docs: array<int,int>, terms: array<int,int>, doc_slugs: array<string,int>, term_slugs: array<string,int>, paths: array<string,array{0:string,1:int}>, bases: string[], linked: int[]}
	 */
	public static function map(): array {
		$map = get_option( self::MAP_OPTION, array() );
		$map = is_array( $map ) ? $map : array();

		return array(
			'docs'       => array_map( 'intval', (array) ( $map['docs'] ?? array() ) ),
			'terms'      => array_map( 'intval', (array) ( $map['terms'] ?? array() ) ),
			'doc_slugs'  => array_map( 'intval', (array) ( $map['doc_slugs'] ?? array() ) ),
			'term_slugs' => array_map( 'intval', (array) ( $map['term_slugs'] ?? array() ) ),
			'paths'      => (array) ( $map['paths'] ?? array() ),
			'bases'      => array_values( array_map( 'strval', (array) ( $map['bases'] ?? array() ) ) ),
			// Docs whose article was already here (not copied).
			'linked'     => array_values( array_map( 'intval', (array) ( $map['linked'] ?? array() ) ) ),
		);
	}

	// ------------------------------------------------------------------ copy

	/**
	 * Every category not copied yet (or whose copy was deleted), parents
	 * first, in BetterDocs' tree order after the categories already here.
	 *
	 * @param array $map {@see self::map()}.
	 * @return array
	 */
	private static function copy_categories( array $map ): array {
		$terms = self::terms();
		if ( ! $terms ) {
			return $map;
		}

		$order = self::tree_order( $terms );
		$next  = (int) KbCategoryModel::query()->max( 'position' ) + 1;

		foreach ( $order as $term_id ) {
			$term   = $terms[ $term_id ];
			$mapped = (int) ( $map['terms'][ $term_id ] ?? 0 );
			if ( $mapped > 0 && KbCategoryModel::find( $mapped ) ) {
				continue;
			}

			// The same category is already here: use it as it is.
			$same = self::match_category( $term, array_values( $map['terms'] ) );
			if ( $same > 0 ) {
				$map['terms'][ $term_id ]                                  = $same;
				$map['term_slugs'][ rawurldecode( (string) $term->slug ) ] = $term_id;

				$path = self::old_path( 'term', $term_id );
				if ( '' !== $path ) {
					$map['paths'][ $path ] = array( 'term', $term_id );
				}
				continue;
			}

			// Two levels here: a deeper category sits under its top category.
			$parent = self::top_two( $term_id, $terms );

			$category              = new KbCategoryModel();
			$category->name        = mb_substr( trim( html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ) ), 0, 191, 'UTF-8' );
			$category->slug        = self::unique_category_slug( rawurldecode( (string) $term->slug ) );
			$category->description = trim( sanitize_textarea_field( (string) $term->description ) );
			$category->parent_id   = $parent > 0 && ! empty( $map['terms'][ $parent ] ) ? (int) $map['terms'][ $parent ] : null;
			$category->position    = $next++;
			$category->save();

			if ( empty( $category->id ) ) {
				continue;
			}

			$map['terms'][ $term_id ]                                  = (int) $category->id;
			$map['term_slugs'][ rawurldecode( (string) $term->slug ) ] = $term_id;

			$path = self::old_path( 'term', $term_id );
			if ( '' !== $path ) {
				$map['paths'][ $path ] = array( 'term', $term_id );
			}
		}

		return $map;
	}

	/**
	 * BetterDocs' address bases: its docs page, the docs, category and tag
	 * slugs, and the fixed start of its docs address pattern.
	 *
	 * @param array $map {@see self::map()}.
	 * @return array
	 */
	private static function remember_bases( array $map ): array {
		$settings = get_option( 'betterdocs_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		$bases = array(
			(string) ( $settings['docs_slug'] ?? 'docs' ),
			(string) ( $settings['category_slug'] ?? 'docs-category' ),
			(string) ( $settings['tag_slug'] ?? 'docs-tag' ),
		);

		$pattern = trim( (string) ( $settings['permalink_structure'] ?? '' ), '/' );
		if ( '' !== $pattern ) {
			$fixed = array();
			foreach ( explode( '/', $pattern ) as $part ) {
				if ( false !== strpos( $part, '%' ) ) {
					break;
				}
				$fixed[] = $part;
			}
			if ( $fixed ) {
				$bases[] = implode( '/', $fixed );
			}
		}

		$page = (int) ( $settings['docs_page'] ?? 0 );
		if ( $page > 0 && empty( $settings['builtin_doc_page'] ) ) {
			$uri = (string) get_page_uri( $page );
			if ( '' !== $uri ) {
				$bases[] = $uri;
			}
		}

		$bases = array_map(
			static function ( $base ) {
				return trim( rawurldecode( $base ), '/' );
			},
			array_merge( $map['bases'], $bases )
		);
		// The help center's own address is never an old BetterDocs one.
		$ours         = trim( ArticlePages::base(), '/' );
		$map['bases'] = array_values( array_unique( array_filter( $bases, static fn( $base ) => '' !== $base && $base !== $ours ) ) );

		return $map;
	}

	/**
	 * The address BetterDocs gives a doc or category right now (only while it
	 * is on: its rules make them), as a request path; '' otherwise.
	 *
	 * @param string $kind 'doc' or 'term'.
	 * @param int    $id   BetterDocs id.
	 * @return string
	 */
	private static function old_path( string $kind, int $id ): string {
		if ( 'doc' === $kind ) {
			if ( ! post_type_exists( 'docs' ) || 'publish' !== get_post_status( $id ) ) {
				return '';
			}
			$url = get_permalink( $id );
		} else {
			if ( ! taxonomy_exists( 'doc_category' ) ) {
				return '';
			}
			$url = get_term_link( $id, 'doc_category' );
		}

		if ( ! is_string( $url ) || '' === $url || false !== strpos( $url, '?' ) ) {
			return '';
		}

		$path = KbAddresses::request_path( $url );
		// Our own address must never be taken as an old one.
		$ours = trim( ArticlePages::base(), '/' );

		return ( '' === $path || $path === $ours || 0 === strpos( $path, $ours . '/' ) ) ? '' : $path;
	}

	/**
	 * @param array $map {@see self::map()}.
	 * @param int   $doc BetterDocs doc id.
	 * @return string
	 */
	private static function doc_target( array $map, int $doc ): string {
		$article = KbArticleModel::find( (int) ( $map['docs'][ $doc ] ?? 0 ) );
		if ( ! $article instanceof KbArticleModel || KbArticleModel::STATUS_PUBLISHED !== $article->status ) {
			return '';
		}

		return KbPublicArticles::article_url( (string) $article->slug );
	}

	/**
	 * @param array $map  {@see self::map()}.
	 * @param int   $term BetterDocs term id.
	 * @return string
	 */
	private static function term_target( array $map, int $term ): string {
		$category = KbCategoryModel::find( (int) ( $map['terms'][ $term ] ?? 0 ) );

		return $category instanceof KbCategoryModel ? KbPublicArticles::category_url( (string) $category->slug ) : '';
	}

	// ------------------------------------------------------------------ read

	/**
	 * BetterDocs docs, by id, in BetterDocs' own order.
	 *
	 * @return array<int, object>
	 */
	private static function docs(): array {
		global $wpdb;

		$in   = implode( ',', array_fill( 0, count( self::STATUSES ), '%s' ) );
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- BetterDocs rows, read whether or not BetterDocs is on.
			$wpdb->prepare(
				"SELECT ID, post_title, post_name, post_content, post_excerpt, post_status, post_password, post_author, post_date_gmt, menu_order FROM {$wpdb->posts} WHERE post_type = 'docs' AND post_status IN ($in) ORDER BY menu_order ASC, ID ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above.
				self::STATUSES
			)
		);

		$docs = array();
		foreach ( (array) $rows as $row ) {
			$docs[ (int) $row->ID ] = $row;
		}

		return $docs;
	}

	/**
	 * `doc_category` terms by id.
	 *
	 * @return array<int, object>
	 */
	private static function terms(): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- BetterDocs rows, read whether or not BetterDocs is on.
			"SELECT t.term_id, t.name, t.slug, tt.parent, tt.description, CAST(COALESCE(m.meta_value, '0') AS SIGNED) AS sort
			FROM {$wpdb->terms} t
			JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = 'doc_category'
			LEFT JOIN {$wpdb->termmeta} m ON m.term_id = t.term_id AND m.meta_key = 'doc_category_order'"
		);

		$terms = array();
		foreach ( (array) $rows as $row ) {
			$terms[ (int) $row->term_id ] = $row;
		}

		return $terms;
	}

	/**
	 * Each doc's categories, first one first: by name, as BetterDocs' own
	 * "first category" (get_the_terms(), used for its breadcrumbs).
	 *
	 * @return array<int, int[]>
	 */
	private static function doc_terms(): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- BetterDocs rows.
			"SELECT tr.object_id, tt.term_id FROM {$wpdb->term_relationships} tr
			JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'doc_category'
			JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			ORDER BY tr.object_id ASC, t.name ASC, tt.term_id ASC"
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->object_id ][] = (int) $row->term_id;
		}

		return $out;
	}

	/**
	 * Each doc's place in its (first) category, in the order BetterDocs shows
	 * them: its "Order Docs By" setting, by default the order set by
	 * dragging (`_docs_order`; docs never dragged go after, by menu order).
	 *
	 * @param array<int, object> $docs      Docs by id.
	 * @param array<int, int[]>  $doc_terms Each doc's categories.
	 * @return array<int, int>
	 */
	private static function positions( array $docs, array $doc_terms ): array {
		$settings = get_option( 'betterdocs_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$by       = (string) ( $settings['alphabetically_order_post'] ?? 'betterdocs_order' );
		$desc     = 'DESC' === strtoupper( (string) ( $settings['docs_order'] ?? 'ASC' ) );
		$orders   = self::doc_orders();

		$groups = array();
		foreach ( array_keys( $docs ) as $id ) {
			$groups[ (int) ( $doc_terms[ $id ][0] ?? 0 ) ][] = $id;
		}

		$out = array();
		foreach ( $groups as $term_id => $ids ) {
			$dragged = array_values( array_intersect( $orders[ $term_id ] ?? array(), $ids ) );
			$rank    = array_flip( $dragged );

			usort(
				$ids,
				static function ( $a, $b ) use ( $by, $desc, $docs, $rank ) {
					if ( 'title' === $by ) {
						$cmp = strnatcasecmp( (string) $docs[ $a ]->post_title, (string) $docs[ $b ]->post_title );
					} elseif ( in_array( $by, array( 'date', 'modified' ), true ) ) {
						$cmp = strcmp( (string) $docs[ $a ]->post_date_gmt, (string) $docs[ $b ]->post_date_gmt );
					} else {
						$cmp = array( $rank[ $a ] ?? PHP_INT_MAX, (int) $docs[ $a ]->menu_order, $a ) <=> array( $rank[ $b ] ?? PHP_INT_MAX, (int) $docs[ $b ]->menu_order, $b );
						return $cmp;
					}
					return $desc ? -$cmp : $cmp;
				}
			);

			foreach ( $ids as $index => $id ) {
				$out[ $id ] = $index;
			}
		}

		return $out;
	}

	/**
	 * The order BetterDocs keeps for the docs of each category (`_docs_order`).
	 *
	 * @return array<int, int[]>
	 */
	private static function doc_orders(): array {
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = '_docs_order'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- BetterDocs rows.

		$out = array();
		foreach ( (array) $rows as $row ) {
			$ids = array_values( array_filter( array_map( 'intval', explode( ',', (string) $row->meta_value ) ) ) );
			if ( $ids ) {
				$out[ (int) $row->term_id ] = $ids;
			}
		}

		return $out;
	}

	/**
	 * Views and reactions per doc: the views meta and the analytics table.
	 *
	 * @return array<int, array<string, int>>
	 */
	private static function stats(): array {
		global $wpdb;

		$out  = array();
		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_betterdocs_meta_views'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- BetterDocs rows.
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->post_id ]['meta_views'] = (int) $row->meta_value;
		}

		// Read with errors quiet: the table is missing where BetterDocs never
		// counted (or was removed with its data).
		$table      = $wpdb->prefix . 'betterdocs_analytics';
		$suppressed = $wpdb->suppress_errors( true );
		$rows       = $wpdb->get_results( "SELECT post_id, SUM(impressions) AS impressions, SUM(happy) AS happy, SUM(sad) AS sad FROM `{$table}` GROUP BY post_id" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- BetterDocs table, name from $wpdb->prefix.
		$wpdb->suppress_errors( $suppressed );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$id                        = (int) $row->post_id;
				$out[ $id ]['impressions'] = (int) $row->impressions;
				$out[ $id ]['happy']       = (int) $row->happy;
				$out[ $id ]['sad']         = (int) $row->sad;
			}
		}

		return $out;
	}

	/**
	 * Tags used on docs (not copied: the help center has none).
	 *
	 * @return int
	 */
	private static function tag_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'doc_tag' AND count > 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- BetterDocs rows.
	}

	// ------------------------------------------------------------- helpers

	/**
	 * Docs whose copy still exists, as id => true.
	 *
	 * @param array $map {@see self::map()}.
	 * @param int[] $ids BetterDocs doc ids.
	 * @return array<int, bool>
	 */
	private static function copied_ids( array $map, array $ids ): array {
		$mapped = array_intersect_key( $map['docs'], array_flip( $ids ) );
		if ( ! $mapped ) {
			return array();
		}

		$alive = array_flip( array_map( 'intval', KbArticleModel::query()->whereIn( 'id', array_values( $mapped ) )->pluck( 'id' )->all() ) );
		$out   = array();
		foreach ( $mapped as $doc => $article ) {
			if ( isset( $alive[ $article ] ) ) {
				$out[ (int) $doc ] = true;
			}
		}

		return $out;
	}

	/**
	 * Articles already here that a doc may be: not ones this import made or
	 * linked, by slug and by title (lower case).
	 *
	 * @param array $map {@see self::map()}.
	 * @return array{slug: array<string,int>, title: array<string,int>}
	 */
	private static function existing_articles( array $map ): array {
		$taken = array_flip( array_values( $map['docs'] ) );
		$index = array(
			'slug'  => array(),
			'title' => array(),
		);
		foreach ( KbArticleModel::query()->orderBy( 'id', 'asc' )->get( array( 'id', 'slug', 'title' ) ) as $article ) {
			$id = (int) $article->id;
			if ( isset( $taken[ $id ] ) ) {
				continue;
			}
			$index['slug'][ (string) $article->slug ] = $index['slug'][ (string) $article->slug ] ?? $id;
			$title                                    = self::title_key( (string) $article->title );
			if ( '' !== $title ) {
				$index['title'][ $title ] = $index['title'][ $title ] ?? $id;
			}
		}

		return $index;
	}

	/**
	 * The article already here for a doc (same address, else same title), or
	 * 0. One article answers for one doc only.
	 *
	 * @param object                                                   $doc   Doc row.
	 * @param array{slug: array<string,int>, title: array<string,int>} $index {@see self::existing_articles()}.
	 * @param array<int, bool>                                         $used  Articles matched already (updated).
	 * @return int
	 */
	private static function match_doc( $doc, array $index, array &$used ): int {
		$slug  = sanitize_title( rawurldecode( (string) $doc->post_name ) );
		$title = self::title_key( (string) $doc->post_title );

		foreach ( array( (int) ( $index['slug'][ $slug ] ?? 0 ), (int) ( $index['title'][ $title ] ?? 0 ) ) as $id ) {
			if ( $id > 0 && ! isset( $used[ $id ] ) ) {
				$used[ $id ] = true;
				return $id;
			}
		}

		return 0;
	}

	/**
	 * A category already here with the same address or name, not yet taken
	 * by another BetterDocs category, or 0.
	 *
	 * @param object $term  Term row.
	 * @param int[]  $taken Categories other terms use.
	 * @return int
	 */
	private static function match_category( $term, array $taken ): int {
		$slug = sanitize_title( rawurldecode( (string) $term->slug ) );
		$name = self::title_key( (string) $term->name );

		foreach ( KbCategoryModel::query()->get( array( 'id', 'slug', 'name' ) ) as $category ) {
			if ( in_array( (int) $category->id, $taken, true ) ) {
				continue;
			}
			if ( (string) $category->slug === $slug || ( '' !== $name && self::title_key( (string) $category->name ) === $name ) ) {
				return (int) $category->id;
			}
		}

		return 0;
	}

	/**
	 * @param string $title Title or name, maybe with entities.
	 * @return string Lower case, single spaces.
	 */
	private static function title_key( string $title ): string {
		$title = html_entity_decode( $title, ENT_QUOTES, 'UTF-8' );

		return mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', $title ) ), 'UTF-8' );
	}

	/**
	 * Term ids in tree order: each category, then its children, by
	 * BetterDocs' order then name.
	 *
	 * @param array<int, object> $terms Terms.
	 * @return int[]
	 */
	private static function tree_order( array $terms ): array {
		$children = array();
		foreach ( $terms as $id => $term ) {
			$parent                = isset( $terms[ (int) $term->parent ] ) ? (int) $term->parent : 0;
			$children[ $parent ][] = $id;
		}

		$sort = static function ( array $ids ) use ( $terms ): array {
			usort(
				$ids,
				static function ( $a, $b ) use ( $terms ) {
					return array( (int) $terms[ $a ]->sort, (string) $terms[ $a ]->name ) <=> array( (int) $terms[ $b ]->sort, (string) $terms[ $b ]->name );
				}
			);
			return $ids;
		};

		$out  = array();
		$walk = static function ( int $parent_id ) use ( &$walk, &$out, $children, $sort ) {
			foreach ( $sort( $children[ $parent_id ] ?? array() ) as $id ) {
				$out[] = $id;
				$walk( $id );
			}
		};
		$walk( 0 );

		return $out;
	}

	/**
	 * Levels above a term (0 = top).
	 *
	 * @param int                $term_id Term.
	 * @param array<int, object> $terms   Terms.
	 * @return int
	 */
	private static function depth( int $term_id, array $terms ): int {
		$depth = 0;
		$seen  = array();
		while ( isset( $terms[ $term_id ] ) && isset( $terms[ (int) $terms[ $term_id ]->parent ] ) && ! isset( $seen[ $term_id ] ) ) {
			$seen[ $term_id ] = true;
			$term_id          = (int) $terms[ $term_id ]->parent;
			++$depth;
		}

		return $depth;
	}

	/**
	 * The parent a term gets here: its top-level ancestor, 0 for a top one.
	 *
	 * @param int                $term_id Term.
	 * @param array<int, object> $terms   Terms.
	 * @return int
	 */
	private static function top_two( int $term_id, array $terms ): int {
		$parent = (int) $terms[ $term_id ]->parent;
		if ( ! isset( $terms[ $parent ] ) ) {
			return 0;
		}

		$seen = array( $term_id => true );
		while ( isset( $terms[ (int) $terms[ $parent ]->parent ] ) && ! isset( $seen[ $parent ] ) ) {
			$seen[ $parent ] = true;
			$parent          = (int) $terms[ $parent ]->parent;
		}

		return $parent;
	}

	/**
	 * @param string $slug BetterDocs slug (decoded).
	 * @return string A slug no category uses yet.
	 */
	private static function unique_category_slug( string $slug ): string {
		// Shortened a letter at a time: cutting the encoded slug could split one.
		$base   = sanitize_title( $slug );
		$length = strlen( $base );
		while ( $length > 180 && '' !== $slug ) {
			$slug   = mb_substr( $slug, 0, -1, 'UTF-8' );
			$base   = sanitize_title( $slug );
			$length = strlen( $base );
		}
		$base = rtrim( $base, '-' );
		if ( '' === $base ) {
			$base = 'category';
		}

		$candidate = $base;
		$suffix    = 2;
		while ( KbCategoryModel::query()->where( 'slug', $candidate )->exists() ) {
			$candidate = $base . '-' . $suffix;
			++$suffix;
		}

		return $candidate;
	}

	/**
	 * @param string $date GMT date.
	 * @return bool
	 */
	private static function real_date( string $date ): bool {
		return '' !== $date && 0 !== strpos( $date, '0000-00-00' );
	}
}
