<?php

namespace SEOPressPro\JsonSchemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Helpers\RichSnippetType;
use SEOPress\JsonSchemas\Image;
use SEOPress\JsonSchemas\Organization;
use SEOPress\Models\GetJsonData;
use SEOPressPro\Models\JsonSchemaValue;

class Video extends JsonSchemaValue implements GetJsonData {
	const NAME = 'video';

	const ALIAS = array( 'videos' );

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
			'type'           => '_seopress_pro_rich_snippets_type',
			'name'           => '_seopress_pro_rich_snippets_videos_name',
			'description'    => '_seopress_pro_rich_snippets_videos_description',
			'thumbnailUrl'   => '_seopress_pro_rich_snippets_videos_img',
			'imgWidth'       => '_seopress_pro_rich_snippets_videos_img_width',
			'imgHeight'      => '_seopress_pro_rich_snippets_videos_img_height',
			'duration'       => '_seopress_pro_rich_snippets_videos_duration',
			'url'            => '_seopress_pro_rich_snippets_videos_url',
			'published_date' => '_seopress_pro_rich_snippets_videos_date_posted',
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

		if ( isset( $variables['published_date'] ) && ! empty( $variables['published_date'] ) ) {
			$variables['uploadDate'] = date( 'c', strtotime( $variables['published_date'] ) );
		} elseif ( isset( $context['post']->ID ) ) {
			$variables['uploadDate'] = get_the_date( 'c', $context['post']->ID );
		}

		if ( isset( $variables['duration'] ) && ! empty( $variables['duration'] ) ) {
			$time = explode( ':', $variables['duration'] );
			$sec  = isset( $time[2] ) ? intval( $time[2] ) : 00;
			$min  = isset( $time[0] ) && isset( $time[1] ) ? intval( $time[0] ) * 60.0 + intval( $time[1] ) * 1.0 : '00:00';

			$variables['duration'] = sprintf( 'PT%sM%sS', $min, $sec );
		}

		if ( isset( $variables['url'] ) ) {
			$variables['contentUrl'] = $variables['embedUrl'] = $variables['url'];
		}

		$schema = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( Organization::NAME, $context, array( 'remove_empty' => true ) );
		if ( count( $schema ) > 1 && isset( $schema['@type'] ) && 'none' !== $schema['@type'] ) {
			$data['publisher'] = $schema;

			$logo = seopress_pro_get_service( 'OptionPro' )->getArticlesPublisherLogo();
			if ( ! empty( $logo ) ) {
				$variablesContext                  = array(
					'url'    => $logo,
					'width'  => seopress_pro_get_service( 'OptionPro' )->getArticlesPublisherLogoWidth(),
					'height' => seopress_pro_get_service( 'OptionPro' )->getArticlesPublisherLogoHeight(),
				);
				$contextWithVariables              = $context;
				$contextWithVariables['variables'] = $variablesContext;

				$schema = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( Image::NAME, $contextWithVariables, array( 'remove_empty' => true ) );
				if ( count( $schema ) > 1 ) {
					$data['publisher']['logo'] = $schema;
				}
			}
		}

		$data = seopress_get_service( 'VariablesToString' )->replaceDataToString( $data, $variables );

		return apply_filters( 'seopress_pro_get_json_data_video', $data, $context );
	}

	public function cleanValues( $data ) {
		if ( isset( $data['publisher']['@context'] ) ) {
			unset( $data['publisher']['@context'] );
		}

		if ( isset( $data['publisher']['@type'] ) && $data['publisher']['@type'] !== 'Organization' ) {
			if ( isset( $data['publisher']['logo'] ) ) {
				unset( $data['publisher']['logo'] );
			}
		}

		return parent::cleanValues( $data );
	}
}
