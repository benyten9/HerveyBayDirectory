<?php

namespace SEOPressPro\JsonSchemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Helpers\RichSnippetType;
use SEOPress\Models\GetJsonData;
use SEOPressPro\Models\JsonSchemaValue;

class Geo extends JsonSchemaValue implements GetJsonData {
	const NAME = 'geo';

	protected function getName() {
		return self::NAME;
	}

	/**
	 * @since 4.6.0
	 *
	 * @return array
	 */
	protected function getVariablesForOptionLocalBusiness() {
		return array(
			'latitude'  => '%%local_business_latitude%%',
			'longitude' => '%%local_business_longitude%%',
		);
	}

	/**
	 * @since 4.6.0
	 *
	 * @return array
	 *
	 * @param array $schemaManual
	 */
	protected function getVariablesForManualSnippet( $schemaManual ) {
		$variables = array();
		if ( isset(
			$schemaManual['_seopress_pro_rich_snippets_lb_lat'],
			$schemaManual['_seopress_pro_rich_snippets_lb_lon']
		) ) {
			$variables = array(
				'latitude'  => $schemaManual['_seopress_pro_rich_snippets_lb_lat'],
				'longitude' => $schemaManual['_seopress_pro_rich_snippets_lb_lon'],
			);
		}

		return $variables;
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

		$typeSchema = isset( $context['type'] ) ? $context['type'] : RichSnippetType::OPTION_LOCAL_BUSINESS;

		switch ( $typeSchema ) {
			case RichSnippetType::OPTION_LOCAL_BUSINESS:
			default:
				$variables = $this->getVariablesForOptionLocalBusiness();
				break;
			case RichSnippetType::MANUAL:
				$schemaManual = $this->getCurrentSchemaManual( $context );

				if ( null === $schemaManual ) {
					return $data;
				}

				$variables = $this->getVariablesForManualSnippet( $schemaManual );
				break;
			case RichSnippetType::SUB_TYPE:
				$variables = isset( $context['variables'] ) ? $context['variables'] : array();
				break;
		}

		$data = seopress_get_service( 'VariablesToString' )->replaceDataToString( $data, $variables );

		return apply_filters( 'seopress_pro_get_json_data_geo', $data, $context );
	}
}
