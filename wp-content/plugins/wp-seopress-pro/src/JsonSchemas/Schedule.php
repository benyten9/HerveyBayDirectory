<?php

namespace SEOPressPro\JsonSchemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Models\GetJsonData;
use SEOPressPro\Models\JsonSchemaValue;

class Schedule extends JsonSchemaValue implements GetJsonData {
	const NAME = 'schedule';

	protected function getName() {
		return self::NAME;
	}

	/**
	 * @since 7.5.0
	 *
	 * @param array $context
	 *
	 * @return array
	 */
	public function getJsonData( $context = null ) {
		$data             = $this->getArrayJson();
		$variables        = $context['variables'] ?? array();
		$data             = seopress_get_service( 'VariablesToString' )->replaceDataToString( $data, $variables );
		$data['duration'] = sprintf( 'PT%dH', (int) $data['duration'] );
		return apply_filters( 'seopress_pro_get_json_data_schedule', $data, $context );
	}
}
