<?php
/**
 * Shared canonical resolver for the automatic schemas.
 *
 * @package SEOPress PRO
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

if ( ! function_exists( 'seopress_schema_current_canonical' ) ) {
	/**
	 * The URL an automatic schema should publish as its `@id`.
	 *
	 * The per-post canonical wins when the user set one. Otherwise the current
	 * request is rebuilt and normalised to the site's trailing slash setting,
	 * which is what `get_permalink()` and the canonical tag already follow.
	 *
	 * This used to be copied into each schema file. Article normalised, Service
	 * and Local Business did not, so their `@id` disagreed with the canonical on
	 * the same page and with every other node describing it. One copy means the
	 * next schema cannot reintroduce that.
	 *
	 * @since 10.3.0
	 *
	 * @return string The canonical URL, or an empty string when it cannot be resolved.
	 */
	function seopress_schema_current_canonical() {
		$post_id = seopress_pro_get_the_id();

		// Only on a singular page. On an archive `seopress_pro_get_the_id()`
		// answers the first post of the loop, so its per-post canonical would
		// become the identity of the archive: a category page would publish
		// itself as whichever post happens to be listed first. The permalink
		// branch below was already guarded this way; the meta read was not,
		// and now that the breadcrumb list uses this on archives too it would
		// leak there.
		if ( $post_id && is_singular() ) {
			$custom = get_post_meta( $post_id, '_seopress_robots_canonical', true );

			if ( ! empty( $custom ) ) {
				return $custom;
			}
		}

		if ( $post_id && is_singular() ) {
			// The request path is empty with plain permalinks; the query ID is
			// part of the canonical URL and must not collapse to the home URL.
			$permalink = get_permalink( $post_id );
			if ( $permalink ) {
				return $permalink;
			}
		}

		global $wp;

		if ( ! isset( $wp->request ) ) {
			return '';
		}

		return user_trailingslashit( home_url( add_query_arg( array(), $wp->request ) ) );
	}
}
