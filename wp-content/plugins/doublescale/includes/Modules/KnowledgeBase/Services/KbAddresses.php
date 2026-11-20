<?php
/**
 * The help center's addresses, and the old ones that still lead to it.
 *
 *   /help-center/                  the help center page (its slug is the page's own)
 *   /help-center/<article>/        an article ({@see ArticlePages})
 *   /help-center/category/<slug>/  a category; "category" is the owner's word
 *
 * The owner changes the first part by renaming the page and the category
 * word in the Articles screen. Links already shared must keep working, so
 * every address given up is remembered and answered with a 301 when it ends
 * in "page not found": WordPress keeps no old slugs for pages, and the
 * article and category rules follow the page's current address only.
 * Addresses of an imported help center (BetterDocs) are answered the same
 * way, by {@see BetterDocsImport::redirect_target()}.
 *
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\ShortcodePageProvisioner;
use DoubleScale\Modules\KnowledgeBase\Models\KbArticleModel;
use DoubleScale\Modules\KnowledgeBase\Models\KbCategoryModel;
use WP_Error;
use WP_Post;

/**
 * KbAddresses service.
 */
final class KbAddresses {

	/**
	 * The word before a category's slug.
	 */
	public const CATEGORY_OPTION = 'doublescale_kb_category_segment';

	/**
	 * Addresses given up: page addresses ('bases') and category words ('segments').
	 */
	public const OLD_OPTION = 'doublescale_kb_old_addresses';

	/**
	 * Old addresses remembered of each kind, newest first.
	 */
	private const KEEP = 20;

	/**
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'post_updated', array( self::class, 'remember_page_address' ), 10, 3 );
		// Before redirect_canonical() (10), whose guess could send an old
		// address to some other post with the same slug.
		add_action( 'template_redirect', array( self::class, 'maybe_redirect' ), 0 );
	}

	/**
	 * The word in category addresses: `category` unless the owner chose another.
	 *
	 * @return string
	 */
	public static function category_segment(): string {
		$word = sanitize_title( (string) get_option( self::CATEGORY_OPTION, '' ) );

		return '' !== $word ? $word : KbPublicArticles::CATEGORY_SEGMENT;
	}

	/**
	 * Change the category word; the old one keeps leading to the categories.
	 *
	 * @param string $word Requested word.
	 * @return string|WP_Error The word saved.
	 */
	public static function set_category_segment( string $word ) {
		$word = sanitize_title( $word );
		if ( '' === $word || strlen( $word ) > 40 || is_numeric( $word ) ) {
			return new WP_Error(
				'kb_invalid_category_word',
				__( 'Use a short word made of letters, numbers and dashes, like “topics”.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$before = self::category_segment();
		if ( $word === $before ) {
			return $word;
		}

		self::remember( 'segments', $before, $word );
		update_option( self::CATEGORY_OPTION, $word, false );

		return $word;
	}

	/**
	 * Give the help center page a new slug. WordPress may add a number when
	 * another page has it; the slug actually saved comes back.
	 *
	 * @param int    $page_id Help center page.
	 * @param string $slug    Requested slug.
	 * @return string|WP_Error
	 */
	public static function rename_page( int $page_id, string $slug ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug || is_numeric( $slug ) ) {
			return new WP_Error(
				'kb_invalid_page_slug',
				__( 'Use letters, numbers and dashes, like “help”.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$page = get_post( $page_id );
		if ( ! $page instanceof WP_Post ) {
			return new WP_Error( 'kb_page_missing', __( 'The help center page was not found.', 'doublescale' ), array( 'status' => 404 ) );
		}
		if ( $slug === $page->post_name ) {
			return $slug;
		}

		$result = wp_update_post(
			array(
				'ID'        => $page_id,
				'post_name' => $slug,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 500 ) );
			return $result;
		}

		return (string) get_post_field( 'post_name', $page_id );
	}

	/**
	 * `post_updated`: the help center page moved (renamed here, in the page
	 * editor or Quick Edit, or under another parent), so its old address and
	 * everything under it is remembered.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $after   The post now.
	 * @param WP_Post $before  The post before.
	 * @return void
	 */
	public static function remember_page_address( $post_id, $after, $before ): void {
		if ( ! $after instanceof WP_Post || ! $before instanceof WP_Post || 'page' !== $after->post_type ) {
			return;
		}
		if ( 'publish' !== $before->post_status || '' === (string) $before->post_name ) {
			return;
		}
		if ( (int) $post_id !== ShortcodePageProvisioner::resolve_page_id( KbPublicArticles::SHORTCODE ) ) {
			return;
		}

		$old = trim( (string) get_page_uri( $before ), '/' );
		$now = trim( (string) get_page_uri( $after ), '/' );
		if ( '' !== $old && $old !== $now ) {
			self::remember( 'bases', $old, $now );
		}
	}

	/**
	 * `template_redirect`: an address that is gone leads to where it went.
	 *
	 * @return void
	 */
	public static function maybe_redirect(): void {
		if ( is_admin() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect lookup.
		$query  = is_array( $_GET ) ? (array) wp_unslash( $_GET ) : array();
		$path   = self::request_path();
		$target = '';

		// Lost: "page not found", or an address that fell through to the
		// home page — BetterDocs' rules outlive it until the rules are
		// rebuilt, and WordPress drops the query variables it no longer knows.
		if ( is_404() || ( '' !== $path && ( is_home() || is_front_page() ) ) ) {
			$target = self::target_for( $path );
			if ( '' === $target ) {
				$target = BetterDocsImport::redirect_target( $path, $query );
			}
		} elseif ( '' === $path && ! defined( 'BETTERDOCS_VERSION' ) && ( isset( $query['docs'] ) || isset( $query['doc_category'] ) ) ) {
			// BetterDocs' plain links (?docs=slug) show the home page once it is off.
			$target = BetterDocsImport::redirect_target( '', $query );
		}

		if ( '' !== $target && wp_safe_redirect( $target, 301, 'DoubleScale' ) ) {
			exit;
		}
	}

	/**
	 * Where an old help center address goes now, or ''.
	 *
	 * @param string $path Request path ({@see self::request_path()}).
	 * @return string
	 */
	public static function target_for( string $path ): string {
		$old = self::old();
		if ( '' === $path || ( ! $old['bases'] && ! $old['segments'] ) ) {
			return '';
		}

		$current  = trim( ArticlePages::base(), '/' );
		$segments = array_merge( array( self::category_segment() ), $old['segments'] );

		foreach ( array_merge( $old['bases'], array( $current ) ) as $base ) {
			$base = trim( rawurldecode( (string) $base ), '/' );
			if ( '' === $base ) {
				continue;
			}
			if ( $path === $base ) {
				return $base === $current ? '' : KbPublicArticles::page_url();
			}
			if ( 0 !== strpos( $path, $base . '/' ) ) {
				continue;
			}

			$parts = explode( '/', substr( $path, strlen( $base ) + 1 ) );
			if ( 1 === count( $parts ) && $base !== $current ) {
				return self::article_target( $parts[0] );
			}
			if ( 2 === count( $parts ) && in_array( $parts[0], $segments, true ) ) {
				return self::category_target( $parts[1] );
			}
		}

		return '';
	}

	/**
	 * A published article's address, by slug, or ''.
	 *
	 * @param string $slug Slug as typed (decoded).
	 * @return string
	 */
	public static function article_target( string $slug ): string {
		$article = KbArticleModel::query()
			->where( 'slug', sanitize_title( $slug ) )
			->where( 'status', KbArticleModel::STATUS_PUBLISHED )
			->first();

		return $article instanceof KbArticleModel ? KbPublicArticles::article_url( (string) $article->slug ) : '';
	}

	/**
	 * A category's address, by slug, or ''.
	 *
	 * @param string $slug Slug as typed (decoded).
	 * @return string
	 */
	public static function category_target( string $slug ): string {
		$category = KbCategoryModel::query()->where( 'slug', sanitize_title( $slug ) )->first();

		return $category instanceof KbCategoryModel ? KbPublicArticles::category_url( (string) $category->slug ) : '';
	}

	/**
	 * The site-relative path of a URL (default: this request), decoded, with
	 * no slashes around it and no `index.php/` ("PATHINFO" permalinks):
	 * `https://site.com/blog/index.php/docs/a/` on a site at /blog → `docs/a`.
	 *
	 * @param string|null $url Full URL or request URI; null for this request.
	 * @return string
	 */
	public static function request_path( ?string $url = null ): string {
		if ( null === $url ) {
			$url = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only parsed and compared.
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$path = trim( rawurldecode( $path ), '/' );

		if ( '' !== $home && ( $path === $home || 0 === strpos( $path, $home . '/' ) ) ) {
			$path = trim( substr( $path, strlen( $home ) ), '/' );
		}
		if ( 'index.php' === $path || 0 === strpos( $path, 'index.php/' ) ) {
			$path = trim( substr( $path, strlen( 'index.php' ) ), '/' );
		}

		return $path;
	}

	/**
	 * @return array{bases: string[], segments: string[]}
	 */
	public static function old(): array {
		$old = get_option( self::OLD_OPTION, array() );
		$old = is_array( $old ) ? $old : array();

		return array(
			'bases'    => array_values( array_filter( array_map( 'strval', (array) ( $old['bases'] ?? array() ) ) ) ),
			'segments' => array_values( array_filter( array_map( 'strval', (array) ( $old['segments'] ?? array() ) ) ) ),
		);
	}

	/**
	 * @param string $kind  'bases' or 'segments'.
	 * @param string $value Address given up.
	 * @param string $now   Address in use now (no longer an old one).
	 * @return void
	 */
	private static function remember( string $kind, string $value, string $now ): void {
		$old          = self::old();
		$list         = array_values( array_diff( $old[ $kind ], array( $value, $now ) ) );
		$old[ $kind ] = array_slice( array_merge( array( $value ), $list ), 0, self::KEEP );
		update_option( self::OLD_OPTION, $old, false );
	}
}
