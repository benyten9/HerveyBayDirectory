<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Options Rewrite.
 *
 * @package SEOPress PRO
 * @subpackage Options
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

if ( ! empty( seopress_pro_get_service( 'AdvancedOptionPro' )->getRewriteSearch() ) ) {
	/**
	 * Search results rewrite.
	 *
	 * @return void
	 */
	function seopress_search_url_rewrite() {
		if ( is_search() && ! empty( $_GET['s'] ) ) {
			wp_redirect( home_url( '/' . seopress_pro_get_service( 'AdvancedOptionPro' )->getRewriteSearch() . '/' ) . urlencode( get_query_var( 's' ) ) );
			exit();
		}
	}
	add_action( 'template_redirect', 'seopress_search_url_rewrite' );

	/**
	 * Search results rewrite slug.
	 *
	 * @return void
	 */
	function seopress_rewrite_search_slug() {
		add_rewrite_rule(
			seopress_pro_get_service( 'AdvancedOptionPro' )->getRewriteSearch() . '(/([^/]+))?(/([^/]+))?(/([^/]+))?/?',
			'index.php?s=$matches[2]&paged=$matches[6]',
			'top'
		);
	}
	add_action( 'init', 'seopress_rewrite_search_slug' );

	/**
	 * Search results rewrite breadcrumbs.
	 *
	 * @param string $link The link.
	 * @param string $search The search.
	 *
	 * @return string $link
	 */
	function seopress_rewrite_breadcrumbs( $link, $search ) {
		$link = home_url( '/' . seopress_pro_get_service( 'AdvancedOptionPro' )->getRewriteSearch() . '/' ) . urlencode( get_query_var( 's' ) );
		return $link;
	}
	add_filter( 'search_link', 'seopress_rewrite_breadcrumbs', 10, 2 );
}
