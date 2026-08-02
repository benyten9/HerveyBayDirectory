<?php

namespace SEOPressPro\JsonSchemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Helpers\RichSnippetType;
use SEOPress\Models\GetJsonData;
use SEOPressPro\Models\JsonSchemaValue;

class SoftwareApp extends JsonSchemaValue implements GetJsonData {

	const NAME = 'softwareapp';

	protected function getName() {
		return self::NAME;
	}

	/**
	 * @since 4.6.0
	 *
	 * @return array
	 *
	 * @param array $schemaManual
	 */
	protected function getVariablesForManualSnippet( $schemaManual ) {
		$keys      = array(
			'type'                => '_seopress_pro_rich_snippets_type',
			'name'                => '_seopress_pro_rich_snippets_softwareapp_name',
			'operatingSystem'     => '_seopress_pro_rich_snippets_softwareapp_os',
			'applicationCategory' => '_seopress_pro_rich_snippets_softwareapp_cat',
			'price'               => '_seopress_pro_rich_snippets_softwareapp_price',
			'priceCurrency'       => '_seopress_pro_rich_snippets_softwareapp_currency',
			'ratingValue'         => '_seopress_pro_rich_snippets_softwareapp_rating',
			'bestRating'          => '_seopress_pro_rich_snippets_softwareapp_max_rating',
		);
		$variables = array();

		foreach ( $keys as $key => $value ) {
			$variables[ $key ] = isset( $schemaManual[ $value ] ) ? $schemaManual[ $value ] : '';
		}

		return $variables;
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

		$typeSchema = isset( $context['type'] ) ? $context['type'] : RichSnippetType::MANUAL;

		$variables = array();

		switch ( $typeSchema ) {
			case RichSnippetType::MANUAL:
				$schemaManual = $this->getCurrentSchemaManual( $context );

				if ( null === $schemaManual ) {
					return $data;
				}

				$variables = $this->getVariablesForManualSnippet( $schemaManual );
				break;
		}

		if ( isset( $variables['ratingValue'] ) ) {
			$contextWithVariables              = $context;
			$contextWithVariables['variables'] = array(
				'ratingValue'  => $variables['ratingValue'],
				'bestRating'   => $variables['bestRating'],
				'worstRating'  => empty( $variables['bestRating'] ) ? '' : 1,
				'ratingAuthor' => '%%post_author%%',
			);
			$contextWithVariables['type']      = RichSnippetType::SUB_TYPE;
			$schema                            = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( Review::NAME, $contextWithVariables, array( 'remove_empty' => true ) );
			if ( count( $schema ) > 1 ) {
				$data['review'] = $schema;
			}
		}

		if ( isset( $variables['price'], $variables['priceCurrency'] ) ) {
			$contextWithVariables              = $context;
			$contextWithVariables['variables'] = array(
				'price'         => $variables['price'],
				'priceCurrency' => $variables['priceCurrency'],
			);
			$contextWithVariables['type']      = RichSnippetType::SUB_TYPE;
			$schema                            = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( Offer::NAME, $contextWithVariables, array( 'remove_empty' => true ) );
			if ( count( $schema ) > 1 ) {
				$data['offers'] = $schema;
			}
		}

		$data = seopress_get_service( 'VariablesToString' )->replaceDataToString( $data, $variables );

		return apply_filters( 'seopress_pro_get_json_data_software_app', $data, $context );
	}

	/**
	 * @since 4.6.0
	 *
	 * @param  $data
	 *
	 * @return array
	 */
	public function cleanValues( $data ) {
		if ( isset( $data['review'] ) && isset( $data['review']['@context'] ) ) {
			unset( $data['review']['@context'] );
		}

		return parent::cleanValues( $data );
	}
}
