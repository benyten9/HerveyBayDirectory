<?php

namespace SEOPressPro\JsonSchemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Helpers\RichSnippetType;
use SEOPress\Models\GetJsonData;
use SEOPressPro\Models\JsonSchemaValue;

class Service extends JsonSchemaValue implements GetJsonData {
	const NAME = 'service';

	const ALIAS = array( 'services' );

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
		$keys = array(
			'type'               => '_seopress_pro_rich_snippets_type',
			'name'               => array(
				'value'   => '_seopress_pro_rich_snippets_service_name',
				'default' => '%%post_title%%',
			),
			'id'                 => array(
				'default' => '%%schema_article_canonical%%',
			),
			'serviceType'        => '_seopress_pro_rich_snippets_service_type',
			'description'        => array(
				'value'   => '_seopress_pro_rich_snippets_service_description',
				'default' => '%%post_excerpt%%',
			),
			'image'              => array(
				'value'   => '_seopress_pro_rich_snippets_service_img',
				'default' => '%%post_thumbnail_url%%',
			),
			'areaServed'         => '_seopress_pro_rich_snippets_service_area',
			'providerName'       => '_seopress_pro_rich_snippets_service_provider_name',
			'localBusinessImage' => '_seopress_pro_rich_snippets_service_lb_img',
			'providerMobility'   => '_seopress_pro_rich_snippets_service_provider_mobility',
			'slogan'             => '_seopress_pro_rich_snippets_service_slogan',
			'streetAddress'      => '_seopress_pro_rich_snippets_service_street_addr',
			'addressLocality'    => '_seopress_pro_rich_snippets_service_city',
			'addressRegion'      => '_seopress_pro_rich_snippets_service_state',
			'postalCode'         => '_seopress_pro_rich_snippets_service_pc',
			'addressCountry'     => '_seopress_pro_rich_snippets_service_country',
			'latitude'           => '_seopress_pro_rich_snippets_service_lat',
			'longitude'          => '_seopress_pro_rich_snippets_service_lon',
			'telephone'          => '_seopress_pro_rich_snippets_service_tel',
			'priceRange'         => '_seopress_pro_rich_snippets_service_price',
		);

		$variables = array();

		foreach ( $keys as $key => $item ) {
			if ( is_string( $item ) ) {
				$variables[ $key ] = isset( $schemaManual[ $item ] ) ? $schemaManual[ $item ] : '';
			} elseif ( is_array( $item ) && isset( $item['value'] ) ) {
				$variables[ $key ] = isset( $schemaManual[ $item['value'] ] ) && ! empty( $schemaManual[ $item['value'] ] ) ? $schemaManual[ $item['value'] ] : $item['default'];
			}
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
		$variables  = array();
		switch ( $typeSchema ) {
			case RichSnippetType::MANUAL:
				$schemaManual = $this->getCurrentSchemaManual( $context );

				if ( null === $schemaManual ) {
					return $data;
				}

				$variables = $this->getVariablesForManualSnippet( $schemaManual );
				break;
		}

		if ( isset( $variables['image'] ) && ! empty( $variables['image'] ) ) {
			$contextWithVariables              = $context;
			$contextWithVariables['variables'] = array(
				'type'            => 'LocalBusiness',
				'name'            => isset( $variables['providerName'] ) ? $variables['providerName'] : '',
				'telephone'       => isset( $variables['telephone'] ) ? $variables['telephone'] : '',
				'image'           => $variables['image'],
				'priceRange'      => isset( $variables['priceRange'] ) ? $variables['priceRange'] : '',
				'streetAddress'   => isset( $variables['streetAddress'] ) ? $variables['streetAddress'] : '',
				'addressLocality' => isset( $variables['addressLocality'] ) ? $variables['addressLocality'] : '',
				'addressRegion'   => isset( $variables['addressRegion'] ) ? $variables['addressRegion'] : '',
				'postalCode'      => isset( $variables['postalCode'] ) ? $variables['postalCode'] : '',
				'addressCountry'  => isset( $variables['addressCountry'] ) ? $variables['addressCountry'] : '',
				'latitude'        => isset( $variables['latitude'] ) ? $variables['latitude'] : '',
				'longitude'       => isset( $variables['longitude'] ) ? $variables['longitude'] : '',
			);
			$contextWithVariables['type']      = RichSnippetType::SUB_TYPE;
			$schema                            = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( LocalBusiness::NAME, $contextWithVariables, array( 'remove_empty' => true ) );
			if ( count( $schema ) > 1 ) {
				$data['provider'] = $schema;
			}
		}

		if ( isset( $context['product'] ) && null !== $context['product'] && isset( $context['post']->ID ) && comments_open( $context['post']->ID ) ) {
			$args = array(
				'meta_key'    => 'rating',
				'number'      => 1,
				'status'      => 'approve',
				'post_status' => 'publish',
				'parent'      => 0,
				'orderby'     => 'meta_value_num',
				'order'       => 'DESC',
				'post_id'     => $context['post']->ID,
				'post_type'   => 'product',
			);

			$comments = get_comments( $args );

			if ( ! empty( $comments ) ) {
				$contextWithVariables              = $context;
				$contextWithVariables['variables'] = array(
					'ratingValue'  => get_comment_meta( $comments[0]->comment_ID, 'rating', true ),
					'ratingAuthor' => get_comment_author( $comments[0]->comment_ID ),
				);
				$contextWithVariables['type']      = RichSnippetType::SUB_TYPE;
				$schema                            = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( Review::NAME, $contextWithVariables, array( 'remove_empty' => true ) );
				if ( count( $schema ) > 1 ) {
					$data['review'] = $schema;
				}
			}

			if ( function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $context['post']->ID );

				if ( method_exists( $product, 'get_review_count' ) && $product->get_review_count() >= 1 ) {
					$contextWithVariables              = $context;
					$contextWithVariables['variables'] = array(
						'ratingValue' => $product->get_average_rating(),
						'reviewCount' => $product->get_review_count(),
					);
					$contextWithVariables['type']      = RichSnippetType::SUB_TYPE;
					$schema                            = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( AggregateRating::NAME, $contextWithVariables, array( 'remove_empty' => true ) );
					if ( count( $schema ) > 1 ) {
						$data['aggregateRating'] = $schema;
					}
				}
			}
		}

		$data = seopress_get_service( 'VariablesToString' )->replaceDataToString( $data, $variables );

		return apply_filters( 'seopress_pro_get_json_data_service', $data, $context );
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
