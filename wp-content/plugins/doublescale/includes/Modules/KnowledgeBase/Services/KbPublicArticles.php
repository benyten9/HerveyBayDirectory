<?php
/**
 * Reading published articles outside the admin: the public help center page,
 * the public REST routes, the client portal, the sitemap and the search
 * ability all go through here, so the "published + allowed visibility" rule
 * lives in one place.
 *
 * An article links to its own page ({@see ArticlePages}), under the help
 * center page's address: `/help-center/<slug>/`. A category links to the help
 * center page (`[doublescale_help_center]`, created on its own by
 * {@see \DoubleScale\Core\Services\ShortcodePageProvisioner}) at
 * `/help-center/category/<slug>/` (`?category=slug` without pretty links).
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\ShortcodePageProvisioner;
use DoubleScale\Modules\KnowledgeBase\Models\KbArticleModel;
use DoubleScale\Modules\KnowledgeBase\Models\KbCategoryModel;

/**
 * KbPublicArticles class.
 */
final class KbPublicArticles {

	/**
	 * Shortcode of the public help center page.
	 */
	public const SHORTCODE = 'doublescale_help_center';

	/**
	 * Default address word of a category on the help center page:
	 * `/help-center/category/<slug>/` (the owner may choose another,
	 * {@see KbAddresses::category_segment()}).
	 */
	public const CATEGORY_SEGMENT = 'category';

	/**
	 * Query variable the category address sets.
	 */
	public const CATEGORY_VAR = 'ds_kb_category';

	/**
	 * Published articles in the given visibilities.
	 *
	 * @param string[] $visibilities Allowed visibilities ({@see KbAudience::current()}).
	 * @return object Eloquent builder.
	 */
	public static function query( array $visibilities ) {
		$allowed = array_values(
			array_intersect(
				$visibilities,
				array( KbArticleModel::VISIBILITY_PUBLIC, KbArticleModel::VISIBILITY_PORTAL )
			)
		);

		return KbArticleModel::query()
			->where( 'status', KbArticleModel::STATUS_PUBLISHED )
			->whereIn( 'visibility', array() !== $allowed ? $allowed : array( KbArticleModel::VISIBILITY_PUBLIC ) );
	}

	/**
	 * One readable article by slug, or null (missing, draft or not allowed).
	 *
	 * @param string   $slug         Article slug.
	 * @param string[] $visibilities Allowed visibilities.
	 * @return KbArticleModel|null
	 */
	public static function find_by_slug( string $slug, array $visibilities ): ?KbArticleModel {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}

		$article = self::query( $visibilities )->with( 'category' )->where( 'slug', $slug )->first();

		return $article instanceof KbArticleModel ? $article : null;
	}

	/**
	 * One readable article by id, or null.
	 *
	 * @param int      $id           Article id.
	 * @param string[] $visibilities Allowed visibilities.
	 * @return KbArticleModel|null
	 */
	public static function find( int $id, array $visibilities ): ?KbArticleModel {
		$article = self::query( $visibilities )->where( 'id', $id )->first();

		return $article instanceof KbArticleModel ? $article : null;
	}

	/**
	 * Categories that hold at least one readable article, in their order, each
	 * with its readable-article count.
	 *
	 * @param string[] $visibilities Allowed visibilities.
	 * @return array<int, array<string, mixed>>
	 */
	public static function categories( array $visibilities ): array {
		// Counted by the database: a public route never loads every article.
		$counts = array();
		foreach ( self::query( $visibilities )->selectRaw( 'category_id, COUNT(*) AS total' )->groupBy( 'category_id' )->get() as $row ) {
			$counts[ (int) $row->category_id ] = (int) $row->total;
		}

		$all = KbCategoryModel::query()->orderBy( 'position', 'asc' )->orderBy( 'name', 'asc' )->get();

		// A parent with no article of its own still shows while one of its
		// sub-categories has some.
		$has_kids = array();
		foreach ( $all as $category ) {
			if ( $category->parent_id && ( $counts[ (int) $category->id ] ?? 0 ) > 0 ) {
				$has_kids[ (int) $category->parent_id ] = true;
			}
		}

		$out = array();
		foreach ( $all as $category ) {
			$count = $counts[ (int) $category->id ] ?? 0;
			if ( $count < 1 && empty( $has_kids[ (int) $category->id ] ) ) {
				continue;
			}
			$out[] = array(
				'id'             => (int) $category->id,
				'name'           => (string) $category->name,
				'slug'           => (string) $category->slug,
				'description'    => (string) $category->description,
				'articles_count' => $count,
				'url'            => self::category_url( (string) $category->slug ),
				'parent_id'      => $category->parent_id ? (int) $category->parent_id : null,
				'icon'           => (string) $category->icon,
			);
		}

		return $out;
	}

	/**
	 * The articles readers opened most, most read first. Only articles that
	 * were read at least once.
	 *
	 * @param string[] $visibilities Allowed visibilities.
	 * @param int      $limit        How many.
	 * @return KbArticleModel[]
	 */
	public static function popular( array $visibilities, int $limit = 6 ): array {
		return self::query( $visibilities )
			->with( 'category' )
			->where( 'views', '>', 0 )
			->orderBy( 'views', 'desc' )
			->orderBy( 'title', 'asc' )
			->limit( max( 1, min( 20, $limit ) ) )
			->get()
			->all();
	}

	/**
	 * Readable articles, optionally searched and narrowed to a category slug.
	 *
	 * @param string[] $visibilities  Allowed visibilities.
	 * @param string   $search        Search text.
	 * @param string   $category_slug Category slug, '' for all.
	 * @param int      $page          1-based page.
	 * @param int      $per_page      Page size (1-50).
	 * @return array{items: KbArticleModel[], total: int}
	 */
	public static function list( array $visibilities, string $search = '', string $category_slug = '', int $page = 1, int $per_page = 20 ): array {
		$query = self::query( $visibilities )->with( 'category' );

		$category_slug = sanitize_title( $category_slug );
		if ( '' !== $category_slug ) {
			$category = KbCategoryModel::query()->where( 'slug', $category_slug )->first();
			if ( ! $category ) {
				return array(
					'items' => array(),
					'total' => 0,
				);
			}
			$query->where( 'category_id', (int) $category->id );
		}

		ArticleSearch::apply( $query, $search );

		$total    = (int) ( clone $query )->count();
		$per_page = max( 1, min( 50, $per_page ) );
		$page     = max( 1, $page );

		$items = $query->orderBy( 'position', 'asc' )
			->orderBy( 'title', 'asc' )
			->offset( ( $page - 1 ) * $per_page )
			->limit( $per_page )
			->get()
			->all();

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Readable articles filed under no category, in their order.
	 *
	 * @param string[] $visibilities Allowed visibilities.
	 * @param int      $limit        Most to return.
	 * @return KbArticleModel[]
	 */
	public static function uncategorised( array $visibilities, int $limit = 100 ): array {
		return self::query( $visibilities )
			->whereNull( 'category_id' )
			->orderBy( 'position', 'asc' )
			->orderBy( 'title', 'asc' )
			->limit( max( 1, $limit ) )
			->get()
			->all();
	}

	/**
	 * Reader payload for one article.
	 *
	 * @param KbArticleModel $article      Article.
	 * @param bool           $with_content Include the HTML body.
	 * @return array<string, mixed>
	 */
	public static function shape( KbArticleModel $article, bool $with_content ): array {
		$category = $article->category;

		$out = array(
			'id'           => (int) $article->id,
			'title'        => (string) $article->title,
			'slug'         => (string) $article->slug,
			'excerpt'      => ArticleService::display_excerpt( $article ),
			// One clean line for lists and search: the author's summary, else
			// the first paragraph (never headings run into the text).
			'summary'      => \DoubleScale\Modules\KnowledgeBase\Renderer\HelpCenterParts::summary( $article ),
			'category'     => $category ? array(
				'name' => (string) $category->name,
				'slug' => (string) $category->slug,
			) : null,
			'url'          => self::article_url( (string) $article->slug ),
			'updated_at'   => (string) $article->updated_at,
			'published_at' => $article->published_at ? (string) $article->published_at : null,
		);

		if ( $with_content ) {
			// Sanitised on save; run again on the way out as a second guard.
			// The body is written in the block editor: render its blocks the
			// way the article's page does.
			$content        = (string) $article->content;
			$out['content'] = wp_kses_post( has_blocks( $content ) ? do_blocks( $content ) : $content );
		}

		return $out;
	}

	/**
	 * Permalink of the help center page, or '' when there is none.
	 *
	 * @return string
	 */
	public static function page_url(): string {
		$page_id = ShortcodePageProvisioner::resolve_page_id( self::SHORTCODE );
		if ( $page_id <= 0 || 'publish' !== get_post_status( $page_id ) ) {
			return '';
		}

		return (string) get_permalink( $page_id );
	}

	/**
	 * The article's page. Slugs come from sanitize_title(), so they are already
	 * URL-safe (a non-Latin title is stored percent-encoded); encoding again
	 * would break it.
	 *
	 * @param string $slug Article slug.
	 * @return string
	 */
	public static function article_url( string $slug ): string {
		return ArticlePages::url_for_slug( $slug );
	}

	/**
	 * A category on the help center page: its own address with pretty links
	 * (rewrite rule in {@see ArticlePages::register_category_rule()}), the
	 * page with `?category=` without.
	 *
	 * @param string $slug Category slug (already URL-safe).
	 * @return string
	 */
	public static function category_url( string $slug ): string {
		$base = self::page_url();
		if ( '' === $base ) {
			return '';
		}

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return add_query_arg( 'category', $slug, $base );
		}

		return trailingslashit( $base ) . user_trailingslashit( KbAddresses::category_segment() . '/' . $slug );
	}
}
