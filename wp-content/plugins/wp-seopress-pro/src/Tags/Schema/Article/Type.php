<?php

namespace SEOPressPro\Tags\Schema\Article;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Models\GetTagValue;

class Type implements GetTagValue {
	const NAME = 'schema_article_type';

	/**
	 * @since 4.6.0
	 *
	 * @param array $args
	 *
	 * @return string
	 */
	public function getValue( $args = null ) {
		$context = isset( $args[0] ) ? $args[0] : null;

		$value      = 'NewsArticle';
		$nameFilter = 'seopress_pro_get_tag_schema_article_type';

		if ( ! seopress_get_service( 'CheckContextPage' )->hasSchemaManualValues( $context ) ) {
			return apply_filters( $nameFilter, $value, $context );
		}

		$schema = $context['schemas_manual'][ $context['key_get_json_schema'] ];

		if ( array_key_exists( '_seopress_pro_rich_snippets_article_type', $schema ) ) {
			$value = $schema['_seopress_pro_rich_snippets_article_type'];
		}

		if ( empty( $value ) ) {
			$value = 'NewsArticle';
		}

		return apply_filters( $nameFilter, $value, $context );
	}
}
