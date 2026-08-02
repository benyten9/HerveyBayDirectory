<?php

namespace SEOPressPro\JsonSchemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Models\GetJsonData;
use SEOPressPro\Models\JsonSchemaValue;

class ProfilePage extends JsonSchemaValue implements GetJsonData {
	const NAME = 'profile-page';

	/**
	 * Get the schema name
	 *
	 * @return string
	 */
	protected function getName() {
		return self::NAME;
	}

	/**
	 * Get JSON data for ProfilePage schema
	 *
	 * @since 9.6.0
	 *
	 * @param array $context The context.
	 *
	 * @return array
	 */
	public function getJsonData( $context = null ) {
		$data = $this->getArrayJson();

		return apply_filters( 'seopress_pro_get_json_data_profile_page', $data, $context );
	}
}
