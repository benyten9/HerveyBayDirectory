<?php
/**
 * Core sitemap (`/wp-sitemap.xml`) entries for public help-center articles.
 *
 * Only published articles anyone may read (`visibility = public`), linked at
 * their own pages; portal-only or internal articles never are. Core's own
 * list of the page type is turned off ({@see \DoubleScale\Modules\KnowledgeBase\Services\ArticlePages}),
 * so each article is listed once, with the date its article last changed.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Renderer;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\KnowledgeBase\Models\KbArticleModel;
use DoubleScale\Modules\KnowledgeBase\Services\KbPublicArticles;

/**
 * KbSitemapProvider class.
 */
class KbSitemapProvider extends \WP_Sitemaps_Provider {

	/**
	 * Provider name, part of the sitemap URL.
	 */
	public const NAME = 'dshelp';

	public function __construct() {
		$this->name        = self::NAME;
		$this->object_type = 'dshelp';
	}

	/**
	 * Article URLs for one sitemap page.
	 *
	 * @param int    $page_num       1-based page.
	 * @param string $object_subtype Unused.
	 * @return array<int, array<string, string>>
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		$per_page = (int) wp_sitemaps_get_max_urls( $this->object_type );
		$rows     = KbPublicArticles::query( array( KbArticleModel::VISIBILITY_PUBLIC ) )
			->whereNotNull( 'post_id' )
			->orderBy( 'id', 'asc' )
			->offset( max( 0, ( (int) $page_num - 1 ) * $per_page ) )
			->limit( $per_page )
			->get( array( 'slug', 'updated_at' ) );

		$urls = array();
		foreach ( $rows as $row ) {
			$urls[] = array(
				'loc'     => KbPublicArticles::article_url( (string) $row->slug ),
				'lastmod' => gmdate( DATE_W3C, (int) strtotime( (string) $row->updated_at . ' UTC' ) ),
			);
		}

		return $urls;
	}

	/**
	 * Number of sitemap pages.
	 *
	 * @param string $object_subtype Unused.
	 * @return int
	 */
	public function get_max_num_pages( $object_subtype = '' ) {
		$total = (int) KbPublicArticles::query( array( KbArticleModel::VISIBILITY_PUBLIC ) )->whereNotNull( 'post_id' )->count();

		return (int) ceil( $total / max( 1, (int) wp_sitemaps_get_max_urls( $this->object_type ) ) );
	}
}
