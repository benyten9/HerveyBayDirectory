<?php

namespace SEOPressPro\JsonSchemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Helpers\RichSnippetType;
use SEOPress\JsonSchemas\Image;
use SEOPress\Models\GetJsonData;
use SEOPressPro\Models\JsonSchemaValue;

class HowToStep extends JsonSchemaValue implements GetJsonData {
	const NAME = 'how-to-step';

	protected function getName() {
		return self::NAME;
	}

	/**
	 * @since 4.5.0
	 *
	 * @param array $context
	 *
	 * @return array
	 */
	public function getJsonData( $context = null ) {
		$data = $this->getArrayJson();

		$typeSchema = isset( $context['type'] ) ? $context['type'] : RichSnippetType::MANUAL;

		$variables = $this->getVariablesByType( $typeSchema, $context );

		if ( isset( $variables['image_url'] ) && ! empty( $variables['image_url'] ) ) {
			$variablesContext = array(
				'url'    => $variables['image_url'],
				'width'  => isset( $variables['image_width'] ) ? $variables['image_width'] : '',
				'height' => isset( $variables['image_height'] ) ? $variables['image_height'] : '',
			);

			$contextWithVariables              = $context;
			$contextWithVariables['variables'] = $variablesContext;
			$contextWithVariables['type']      = RichSnippetType::SUB_TYPE;
			$schema                            = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( Image::NAME, $contextWithVariables, array( 'remove_empty' => true ) );

			if ( count( $schema ) > 1 ) {
				$data['image'] = $schema;
			}
		}

		return apply_filters( 'seopress_pro_get_json_data_how_to_step', $data, $context );
	}
}
