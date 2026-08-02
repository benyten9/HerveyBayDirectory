<?php

namespace SEOPressPro\Actions\Thirds\WooCommerce;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

use SEOPress\Core\Hooks\ExecuteHooks;

class MetaBoxVariation implements ExecuteHooks {
	public function hooks() {
		if ( function_exists( 'seopress_get_toggle_option' ) && '1' !== seopress_get_toggle_option( 'woocommerce' ) ) {
			return;
		}

		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'variationSettingsFields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'saveVariationSettingsFields' ), 10, 2 );
		add_filter( 'woocommerce_available_variation', array( $this, 'loadVariationSettingsFields' ) );
	}

	/**
	 * @since 4.4.0
	 *
	 * @param array $variation
	 *
	 * @return array
	 */
	public function loadVariationSettingsFields( $variation ) {
		$variation['seopress_global_ids'] = get_post_meta( $variation['variation_id'], 'seopress_global_ids', true );
		$variation['seopress_barcode']    = get_post_meta( $variation['variation_id'], 'seopress_barcode', true );

		return $variation;
	}

	/**
	 * @since 4.4.0
	 *
	 * @param int    $variation_id
	 * @param string $loop
	 *
	 * @return void
	 */
	public function saveVariationSettingsFields( $variation_id, $loop ) {
		$globalIds = sanitize_text_field( wp_unslash( $_POST['seopress_global_ids'][ $loop ] ) );

		if ( ! empty( $globalIds ) ) {
			update_post_meta( $variation_id, 'seopress_global_ids', esc_attr( $globalIds ) );
		} else {
			delete_post_meta( $variation_id, 'seopress_global_ids' );
		}

		$barCode = sanitize_text_field( wp_unslash( $_POST['seopress_barcode'][ $loop ] ) );

		if ( ! empty( $barCode ) ) {
			update_post_meta( $variation_id, 'seopress_barcode', esc_attr( $barCode ) );
		} else {
			delete_post_meta( $variation_id, 'seopress_barcode' );
		}
	}

	/**
	 * @since 4.4.0
	 *
	 * @param string $loop
	 * @param array  $variation_data
	 * @param object $variation
	 *
	 * @return void
	 */
	public function variationSettingsFields( $loop, $variation_data, $variation ) {
		woocommerce_wp_select(
			array(
				'id'            => "seopress_global_ids{$loop}",
				'name'          => "seopress_global_ids[{$loop}]",
				'value'         => get_post_meta( $variation->ID, 'seopress_global_ids', true ),
				'label'         => __( 'Product Global Identifiers type', 'wp-seopress-pro' ),
				'desc_tip'      => false,
				'description'   => '',
				'options'       => array(
					'none'   => __( 'None', 'wp-seopress-pro' ),
					'gtin8'  => __( 'gtin8 (ean8)', 'wp-seopress-pro' ),
					'gtin12' => __( 'gtin12 (ean12)', 'wp-seopress-pro' ),
					'gtin13' => __( 'gtin13 (ean13)', 'wp-seopress-pro' ),
					'gtin14' => __( 'gtin14 (ean14)', 'wp-seopress-pro' ),
					'mpn'    => __( 'mpn', 'wp-seopress-pro' ),
					'isbn'   => __( 'isbn', 'wp-seopress-pro' ),
				),
				'wrapper_class' => 'form-row form-row-full',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'            => "seopress_barcode{$loop}",
				'name'          => "seopress_barcode[{$loop}]",
				'value'         => get_post_meta( $variation->ID, 'seopress_barcode', true ),
				'label'         => __( 'Product Global Identifiers', 'wp-seopress-pro' ),
				'desc_tip'      => false,
				'description'   => '',
				'wrapper_class' => 'form-row form-row-full',
			)
		);
	}
}
