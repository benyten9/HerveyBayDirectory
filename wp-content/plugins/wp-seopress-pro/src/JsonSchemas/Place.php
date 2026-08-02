<?php

namespace SEOPressPro\JsonSchemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Helpers\RichSnippetType;
use SEOPress\Models\GetJsonData;
use SEOPressPro\Models\JsonSchemaValue;

class Place extends JsonSchemaValue implements GetJsonData {
	const NAME = 'place';

	protected function getName() {
		return self::NAME;
	}

	/**
	 * @since 4.6.0
	 *
	 * @param array $context
	 *
	 * @return array
	 */
	public function getJsonData( $context = null ) {
		$data = $this->getArrayJson();

		$typeSchema = isset( $context['type'] ) ? $context['type'] : RichSnippetType::DEFAULT_SNIPPET;

		$schema = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( PostalAddress::NAME, $context, array( 'remove_empty' => true ) );
		if ( count( $schema ) > 1 ) {
			$data['address'] = $schema;
		}

		return apply_filters( 'seopress_pro_get_json_data_place', $data, $context );
	}
}
