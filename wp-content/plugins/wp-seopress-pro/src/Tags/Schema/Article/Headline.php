<?php

namespace SEOPressPro\Tags\Schema\Article;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Models\GetTagValue;

class Headline implements GetTagValue {
	const NAME = 'schema_article_headline';

	/**
	 * @since 4.6.0
	 *
	 * @param array $args
	 *
	 * @return string
	 */
	public function getValue( $args = null ) {
		$context = isset( $args[0] ) ? $args[0] : null;

		$value = '';

		if ( seopress_get_service( 'CheckContextPage' )->hasSchemaManualValues( $context ) ) {
			$schema = $context['schemas_manual'][ $context['key_get_json_schema'] ];

			if ( array_key_exists( '_seopress_pro_rich_snippets_article_title', $schema ) ) {
				$value = $schema['_seopress_pro_rich_snippets_article_title'];
			}
		}

		if ( empty( $value ) && isset( $context['post']->ID ) ) {
			$value = get_the_title( $context['post']->ID );
		}

		return apply_filters( 'seopress_pro_get_tag_schema_article_headline', $value, $context );
	}
}
