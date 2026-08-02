<?php

namespace SEOPressPro\JsonSchemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use SEOPress\Helpers\RichSnippetType;
use SEOPress\JsonSchemas\Image;
use SEOPress\Models\GetJsonData;
use SEOPressPro\Models\JsonSchemaValue;

class HowTo extends JsonSchemaValue implements GetJsonData {
	const NAME  = 'how-to';
	const ALIAS = array( 'howto' );

	protected function getName() {
		return self::NAME;
	}

	/**
	 * @since 4.7.0
	 *
	 * @return array
	 *
	 * @param array $schemaManual
	 */
	protected function getVariablesForManualSnippet( $schemaManual ) {
		$keys      = array(
			'step'        => '_seopress_pro_rich_snippets_how_to',
			'name'        => '_seopress_pro_rich_snippets_how_to_name',
			'description' => '_seopress_pro_rich_snippets_how_to_desc',
			'image'       => '_seopress_pro_rich_snippets_how_to_img',
			'width'       => '_seopress_pro_rich_snippets_how_to_img_width',
			'height'      => '_seopress_pro_rich_snippets_how_to_img_height',
			'currency'    => '_seopress_pro_rich_snippets_how_to_currency',
			'cost'        => '_seopress_pro_rich_snippets_how_to_cost',
			'totalTime'   => '_seopress_pro_rich_snippets_how_to_total_time',
		);
		$variables = array();

		foreach ( $keys as $key => $value ) {
			$variables[ $key ] = isset( $schemaManual[ $value ] ) ? $schemaManual[ $value ] : '';
		}
		if ( isset( $variables['totalTime'] ) && ! empty( $variables['totalTime'] ) ) {
			$time = explode( ':', $variables['totalTime'] );
			$sec  = isset( $time[2] ) ? intval( $time[2] ) : 00;
			$min  = isset( $time[0] ) && isset( $time[1] ) ? intval( $time[0] ) * 60.0 + intval( $time[1] ) * 1.0 : $variables['totalTime'];

			$variables['totalTime'] = sprintf( 'PT%sM%sS', $min, $sec );
		}

		return $variables;
	}

	/**
	 * @since 4.7.0
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
		$data = seopress_get_service( 'VariablesToString' )->replaceDataToString( $data, $variables );

		if ( isset( $variables['image'] ) && ! empty( $variables['image'] ) ) {
			$variablesContext = array(
				'url'    => $variables['image'],
				'width'  => isset( $variables['width'] ) ? $variables['width'] : '',
				'height' => isset( $variables['height'] ) ? $variables['height'] : '',
			);

			$contextWithVariables              = $context;
			$contextWithVariables['variables'] = $variablesContext;
			$schema                            = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( Image::NAME, $contextWithVariables, array( 'remove_empty' => true ) );
			if ( count( $schema ) > 1 ) {
				$data['image'] = $schema;
			}
		} else {
			$variablesContext = array(
				'url'    => '%%post_thumbnail_url%%',
				'width'  => '%%post_thumbnail_url_width%%',
				'height' => '%%post_thumbnail_url_height%%',
			);

			$contextWithVariables              = $context;
			$contextWithVariables['variables'] = $variablesContext;
			$schema                            = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( Image::NAME, $contextWithVariables, array( 'remove_empty' => true ) );
			if ( count( $schema ) > 1 ) {
				$data['image'] = $schema;
			}
		}

		if ( isset( $variables['currency'] ) && ! empty( $variables['currency'] ) && isset( $variables['cost'] ) && ! empty( $variables['cost'] ) ) {
			$variablesContext                  = array(
				'currency'       => isset( $variables['currency'] ) ? $variables['currency'] : '',
				'quantity_value' => isset( $variables['cost'] ) ? $variables['cost'] : '',
			);
			$contextWithVariables              = $context;
			$contextWithVariables['variables'] = $variablesContext;
			$contextWithVariables['type']      = RichSnippetType::SUB_TYPE;
			$schema                            = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( MonetaryAmount::NAME, $contextWithVariables, array( 'remove_empty' => true ) );

			if ( count( $schema ) > 1 ) {
				$data['estimatedCost'] = $schema;
			}
		}

		if ( isset( $variables['step'] ) && ! empty( $variables['step'] ) ) {
			foreach ( $variables['step'] as $key => $step ) {
				$variablesContext = array(
					'name'         => isset( $step['name'] ) ? $step['name'] : '',
					'text'         => isset( $step['text'] ) ? $step['text'] : '',
					'image_url'    => isset( $step['image'] ) ? $step['image'] : '',
					'image_width'  => isset( $step['width'] ) ? $step['width'] : '',
					'image_height' => isset( $step['height'] ) ? $step['height'] : '',
				);

				$contextWithVariables              = $context;
				$contextWithVariables['variables'] = $variablesContext;
				$contextWithVariables['type']      = RichSnippetType::SUB_TYPE;

				$schema = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( HowToStep::NAME, $contextWithVariables, array( 'remove_empty' => true ) );

				if ( count( $schema ) > 1 && isset( $schema['name'] ) && ! empty( $schema['name'] ) && isset( $schema['text'] ) && ! empty( $schema['text'] ) ) {
					$data['step'][] = $schema;
				}
			}
		}

		return apply_filters( 'seopress_pro_get_json_data_how_to', $data, $context );
	}
}
