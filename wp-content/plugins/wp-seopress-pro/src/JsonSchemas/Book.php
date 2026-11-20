<?php

namespace SEOPressPro\JsonSchemas;

defined( 'ABSPATH' ) || exit;

use SEOPress\Models\GetJsonData;
use SEOPressPro\Models\JsonSchemaValue;
use SEOPressPro\Helpers\Schemas\Book as Book_Data;

class Book extends JsonSchemaValue implements GetJsonData {
	const NAME = 'book';

	protected function getName() {
		return self::NAME;
	}

	public function getJsonData( $context = null ) {
		$manual = $this->getCurrentSchemaManual( $context );
		$values = $this->getVariablesByKeysAndData( Book_Data::meta_keys(), (array) $manual );
		return Book_Data::build( $values );
	}

	public function cleanValues( $data ) {
		return parent::cleanValues( apply_filters( 'seopress_pro_get_json_data_book', Book_Data::normalize( $data ) ) );
	}
}
