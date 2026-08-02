<?php

namespace SEOPressPro\JsonSchemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Models\GetJsonData;
use SEOPressPro\Models\JsonSchemaValue;

class MainEntity extends JsonSchemaValue implements GetJsonData {
	const NAME = 'main-entity';

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

		return apply_filters( 'seopress_pro_get_json_data_main_entity', $data, $context );
	}
}
