<?php

namespace SEOPressPro\Services\Forms\Schemas;

defined( 'ABSPATH' ) || exit;

use SEOPressPro\Core\FormApi;

class FormSchemaService extends FormApi {
	protected function getTypeByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_service_description':
				return 'textarea';
			case '_seopress_pro_rich_snippets_service_img':
			case '_seopress_pro_rich_snippets_service_lb_img':
				return 'upload';
			case '_seopress_pro_rich_snippets_service_name':
			case '_seopress_pro_rich_snippets_service_type':
			case '_seopress_pro_rich_snippets_service_area':
			case '_seopress_pro_rich_snippets_service_provider_name':
			case '_seopress_pro_rich_snippets_service_provider_mobility':
			case '_seopress_pro_rich_snippets_service_slogan':
			case '_seopress_pro_rich_snippets_service_street_addr':
			case '_seopress_pro_rich_snippets_service_city':
			case '_seopress_pro_rich_snippets_service_state':
			case '_seopress_pro_rich_snippets_service_pc':
			case '_seopress_pro_rich_snippets_service_country':
			case '_seopress_pro_rich_snippets_service_lat':
			case '_seopress_pro_rich_snippets_service_lon':
			case '_seopress_pro_rich_snippets_service_tel':
			case '_seopress_pro_rich_snippets_service_price':
				return 'input';
		}
	}

	protected function getLabelByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_service_name':
				return __( 'Service name', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_type':
				return __( 'Service type', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_description':
				return __( 'Service description', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_img':
				return __( 'Thumbnail', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_area':
				return __( 'Area served', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_provider_name':
				return __( 'Provider name', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_lb_img':
				return __( 'Location image', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_provider_mobility':
				return __( 'Provider mobility (static or dynamic)', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_slogan':
				return __( 'Slogan', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_street_addr':
				return __( 'Street Address', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_city':
				return __( 'City', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_state':
				return __( 'State', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_pc':
				return __( 'Postal code', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_country':
				return __( 'Country', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_lat':
				return __( 'Latitude', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_lon':
				return __( 'Longitude', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_tel':
				return __( 'Telephone', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_price':
				return __( 'Price range', 'wp-seopress-pro' );
		}
	}

	protected function getPlaceholderByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_service_name':
				return __( 'The name of your service', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_type':
				return __( 'The type of your service', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_description':
				return __( 'The description of your service', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_img':
				return __( 'Select your image', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_area':
				return __( 'The area served by your service', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_provider_name':
				return __( 'The provider name of your service', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_lb_img':
				return __( 'Select your location image', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_provider_mobility':
				return __( 'The provider mobility of your service', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_slogan':
				return __( 'The slogan of your service', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_street_addr':
				return __( 'The street address of your service', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_city':
				return __( 'The city of your service', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_state':
				return __( 'The state of your service', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_pc':
				return __( 'The postal code of your service', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_country':
				return __( 'The country of your service (ISO format)', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_lat':
				return __( 'The latitude of your service', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_lon':
				return __( 'The longitude of your service', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_tel':
				return __( 'The telephone of your service', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_price':
				return __( 'The price range of your service', 'wp-seopress-pro' );
		}
	}

	protected function getDescriptionByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_service_name':
				return __( 'Default: post title', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_description':
				return __( 'Default: post excerpt', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_service_img':
				return __( 'Default: post thumbnail', 'wp-seopress-pro' );
		}
	}

	protected function getDetails( $postId = null ) {
		return array(
			array(
				'key' => '_seopress_pro_rich_snippets_service_name',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_service_type',
			),
			array(
				'key'   => '_seopress_pro_rich_snippets_service_description',
				'class' => 'seopress-textarea-high-size',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_service_img',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_service_area',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_service_provider_name',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_service_lb_img',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_service_provider_mobility',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_service_slogan',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_service_street_addr',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_service_city',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_service_state',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_service_pc',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_service_country',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_service_lat',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_service_lon',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_service_tel',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_service_price',
			),
		);
	}
}
