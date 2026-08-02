<?php

namespace SEOPressPro\Helpers\Schemas;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

abstract class Currencies {
	public static function getOptions() {
		return apply_filters(
			'seopress_get_options_schema_currencies',
			array(
				array(
					'value' => 'none',
					'label' => __( 'Select a Currency', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'USD',
					'label' => __( 'U.S. Dollar', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'GBP',
					'label' => __( 'Pound Sterling', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'EUR',
					'label' => __( 'Euro', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'ARS',
					'label' => __( 'Argentina Peso', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'AUD',
					'label' => __( 'Australian Dollar', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'BRL',
					'label' => __( 'Brazilian Real', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'BGN',
					'label' => __( 'Bulgarian lev', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'CAD',
					'label' => __( 'Canadian Dollar', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'CLP',
					'label' => __( 'Chilean Peso', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'CZK',
					'label' => __( 'Czech Koruna', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'DKK',
					'label' => __( 'Danish Krone', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'HKD',
					'label' => __( 'Hong Kong Dollar', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'HUF',
					'label' => __( 'Hungarian Forint', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'INR',
					'label' => __( 'Indian rupee', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'ILS',
					'label' => __( 'Israeli New Sheqel', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'JPY',
					'label' => __( 'Japanese Yen', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'MYR',
					'label' => __( 'Malaysian Ringgit', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'MXN',
					'label' => __( 'Mexican Peso', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'NOK',
					'label' => __( 'Norwegian Krone', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'NZD',
					'label' => __( 'New Zealand Dollar', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'PHP',
					'label' => __( 'Philippine Peso', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'PLN',
					'label' => __( 'Polish Zloty', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'IDR',
					'label' => __( 'Indonesian rupiah', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'RUB',
					'label' => __( 'Russian Ruble', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'SGD',
					'label' => __( 'Singapore Dollar', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'PEN',
					'label' => __( 'Sol', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'ZAR',
					'label' => __( 'South African Rand', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'SEK',
					'label' => __( 'Swedish Krona', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'CHF',
					'label' => __( 'Swiss Franc', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'TWD',
					'label' => __( 'Taiwan New Dollar', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'THB',
					'label' => __( 'Thai Baht', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'UAH',
					'label' => __( 'Ukrainian hryvnia', 'wp-seopress-pro' ),
				),
				array(
					'value' => 'VND',
					'label' => __( 'Vietnamese đồng', 'wp-seopress-pro' ),
				),
			)
		);
	}
}
