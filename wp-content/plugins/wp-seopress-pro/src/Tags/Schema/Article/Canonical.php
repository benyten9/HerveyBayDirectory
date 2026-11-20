<?php

namespace SEOPressPro\Tags\Schema\Article;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Models\GetTagValue;

class Canonical implements GetTagValue {
	const NAME = 'schema_article_canonical';

	/**
	 * @since 4.6.0
	 *
	 * @param array $args
	 *
	 * @return string
	 */
	public function getValue( $args = null ) {
		$context = isset( $args[0] ) ? $args[0] : null;

		global $wp;
		$value = '';

		if ( isset( $context['post']->ID ) ) {
			$value = get_post_meta( $context['post']->ID, '_seopress_robots_canonical', true );
		}

		// With plain permalinks the request path is always empty, so the
		// rebuilt URL was the home page on every page, and two businesses on
		// two pages shared one identity. The post's permalink carries the
		// query ID that identifies it.
		if ( empty( $value ) && isset( $context['post']->ID ) && empty( $wp->request ) && is_singular() ) {
			$value = get_permalink( $context['post']->ID );
		}

		if ( empty( $value ) ) {
			$value = user_trailingslashit( home_url( add_query_arg( array(), $wp->request ) ) );
		}

		return apply_filters( 'seopress_pro_get_tag_schema_article_canonical', $value, $context );
	}
}
