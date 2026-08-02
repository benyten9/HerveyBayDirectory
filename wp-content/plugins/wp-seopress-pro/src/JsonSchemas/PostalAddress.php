<?php

namespace SEOPressPro\JsonSchemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Helpers\RichSnippetType;
use SEOPress\Models\GetJsonData;
use SEOPressPro\Models\JsonSchemaValue;

class PostalAddress extends JsonSchemaValue implements GetJsonData {
	const NAME = 'postal-address';

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
			'streetAddress'   => '%%local_business_street_address%%',
			'addressLocality' => '%%local_business_address_locality%%',
			'addressRegion'   => '%%local_business_address_region%%',
			'postalCode'      => '%%local_business_address_postal_code%%',
			'addressCountry'  => '%%local_business_address_country%%',
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
			$schemaManual['_seopress_pro_rich_snippets_lb_street_addr'],
			$schemaManual['_seopress_pro_rich_snippets_lb_city'],
			$schemaManual['_seopress_pro_rich_snippets_lb_state'],
			$schemaManual['_seopress_pro_rich_snippets_lb_pc'],
			$schemaManual['_seopress_pro_rich_snippets_lb_country']
		) ) {
			$variables = array(
				'streetAddress'   => $schemaManual['_seopress_pro_rich_snippets_lb_street_addr'],
				'addressLocality' => $schemaManual['_seopress_pro_rich_snippets_lb_city'],
				'addressRegion'   => $schemaManual['_seopress_pro_rich_snippets_lb_state'],
				'postalCode'      => $schemaManual['_seopress_pro_rich_snippets_lb_pc'],
				'addressCountry'  => $schemaManual['_seopress_pro_rich_snippets_lb_country'],
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

		return apply_filters( 'seopress_pro_get_json_data_postal_address', $data, $context );
	}
}
