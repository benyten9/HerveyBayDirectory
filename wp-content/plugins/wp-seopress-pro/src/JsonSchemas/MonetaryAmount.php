<?php

namespace SEOPressPro\JsonSchemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Helpers\RichSnippetType;
use SEOPress\Models\GetJsonData;
use SEOPressPro\Models\JsonSchemaValue;

class MonetaryAmount extends JsonSchemaValue implements GetJsonData {
	const NAME = 'monetary-amount';

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
		$variables  = array();
		switch ( $typeSchema ) {
			case RichSnippetType::SUB_TYPE:
				$variables = isset( $context['variables'] ) ? $context['variables'] : array();
				break;
		}

		$data = seopress_get_service( 'VariablesToString' )->replaceDataToString( $data, $variables );

		return apply_filters( 'seopress_pro_get_json_data_monetary_amount', $data, $context );
	}
}
