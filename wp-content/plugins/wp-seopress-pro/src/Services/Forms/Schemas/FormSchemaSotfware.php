<?php

namespace SEOPressPro\Services\Forms\Schemas;

defined( 'ABSPATH' ) || exit;

use SEOPressPro\Core\FormApi;

class FormSchemaSotfware extends FormApi {
	protected function getTypeByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_softwareapp_cat':
				return 'select';
			case '_seopress_pro_rich_snippets_softwareapp_max_rating':
			case '_seopress_pro_rich_snippets_softwareapp_rating':
				return 'number';
			case '_seopress_pro_rich_snippets_softwareapp_name':
			case '_seopress_pro_rich_snippets_softwareapp_os':
			case '_seopress_pro_rich_snippets_softwareapp_price':
			case '_seopress_pro_rich_snippets_softwareapp_currency':
				return 'input';
		}
	}

	protected function getLabelByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_softwareapp_name':
				return __( 'Software name', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_softwareapp_os':
				return __( 'Operating system', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_softwareapp_cat':
				return __( 'Application category', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_softwareapp_price':
				return __( 'Price of your app', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_softwareapp_currency':
				return __( 'Currency', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_softwareapp_rating':
				return __( 'Your rating', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_softwareapp_max_rating':
				return __( 'Max best rating', 'wp-seopress-pro' );
		}
	}

	protected function getPlaceholderByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_softwareapp_name':
				return __( 'The name of your app', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_softwareapp_os':
				return __( 'The operating system(s) required to use the app', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_softwareapp_price':
				return __( 'The price of your app (set "0" if the app is free of charge)', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_softwareapp_currency':
				return __( 'Currency: USD, EUR...', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_softwareapp_rating':
				return __( 'The item rating', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_softwareapp_max_rating':
				return __( 'Max best rating', 'wp-seopress-pro' );
		}
	}

	protected function getOptions( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_softwareapp_cat':
				return array(
					array(
						'value' => 'GameApplication',
						'label' => __( 'GameApplication', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'SocialNetworkingApplication',
						'label' => __( 'SocialNetworkingApplication', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'TravelApplication',
						'label' => __( 'TravelApplication', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'ShoppingApplication',
						'label' => __( 'ShoppingApplication', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'SportsApplication',
						'label' => __( 'SportsApplication', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'LifestyleApplication',
						'label' => __( 'LifestyleApplication', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'BusinessApplication',
						'label' => __( 'BusinessApplication', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'DesignApplication',
						'label' => __( 'DesignApplication', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'DeveloperApplication',
						'label' => __( 'DeveloperApplication', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'DriverApplication',
						'label' => __( 'DriverApplication', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'EducationalApplication',
						'label' => __( 'EducationalApplication', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'HealthApplication',
						'label' => __( 'HealthApplication', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'FinanceApplication',
						'label' => __( 'FinanceApplication', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'SecurityApplication',
						'label' => __( 'SecurityApplication', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'BrowserApplication',
						'label' => __( 'BrowserApplication', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'CommunicationApplication',
						'label' => __( 'CommunicationApplication', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'DesktopEnhancementApplication',
						'label' => __( 'DesktopEnhancementApplication', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'EntertainmentApplication',
						'label' => __( 'EntertainmentApplication', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'MultimediaApplication',
						'label' => __( 'MultimediaApplication', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'HomeApplication',
						'label' => __( 'HomeApplication', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'UtilitiesApplication',
						'label' => __( 'UtilitiesApplication', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'ReferenceApplication',
						'label' => __( 'ReferenceApplication', 'wp-seopress-pro' ),
					),
				);
		}
	}

	protected function getDetails( $postId = null ) {
		return array(
			array(
				'key' => '_seopress_pro_rich_snippets_softwareapp_name',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_softwareapp_os',
			),
			array(
				'key'   => '_seopress_pro_rich_snippets_softwareapp_cat',
				'value' => 'GameApplication',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_softwareapp_price',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_softwareapp_currency',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_softwareapp_rating',
				'min' => 1,
			),
			array(
				'key' => '_seopress_pro_rich_snippets_softwareapp_max_rating',
			),
		);
	}
}
