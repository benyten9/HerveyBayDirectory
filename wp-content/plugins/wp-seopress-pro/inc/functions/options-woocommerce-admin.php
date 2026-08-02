<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Options WooCommerce Admin.
 *
 * @package SEOPress PRO
 * @subpackage Options
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
	/**
	 * Add barcode type field to Inventory Product metabox (WooCommerce)
	 *
	 * @return void
	 */
	function seopress_wc_barcode_type_field() {
		woocommerce_wp_select(
			array(
				'id'      => 'sp_wc_barcode_type_field',
				'label'   => __( 'Product Global Identifiers type', 'wp-seopress-pro' ),
				'options' => array(
					'none'   => __( 'None', 'wp-seopress-pro' ),
					'gtin8'  => __( 'gtin8 (ean8)', 'wp-seopress-pro' ),
					'gtin12' => __( 'gtin12 (ean12)', 'wp-seopress-pro' ),
					'gtin13' => __( 'gtin13 (ean13)', 'wp-seopress-pro' ),
					'gtin14' => __( 'gtin14 (ean14)', 'wp-seopress-pro' ),
					'mpn'    => __( 'mpn', 'wp-seopress-pro' ),
					'isbn'   => __( 'isbn', 'wp-seopress-pro' ),
				),
			)
		);
	}
	add_action( 'woocommerce_product_options_inventory_product_data', 'seopress_wc_barcode_type_field' );

	/**
	 * Save the barcode type custom field
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	function seopress_save_wc_barcode_type_field( $post_id ) {
		$product            = wc_get_product( $post_id );
		$barcode_type_field = isset( $_POST['sp_wc_barcode_type_field'] ) ? sanitize_text_field( wp_unslash( $_POST['sp_wc_barcode_type_field'] ) ) : '';
		$product->update_meta_data( 'sp_wc_barcode_type_field', esc_attr( $barcode_type_field ) );
		$product->save();
	}
	add_action( 'woocommerce_process_product_meta', 'seopress_save_wc_barcode_type_field' );

	/**
	 * Add barcode field to Inventory Product metabox (WooCommerce)
	 *
	 * @return void
	 */
	function seopress_wc_barcode_field() {
		$args = array(
			'id'          => 'sp_wc_barcode_field',
			'label'       => __( 'Product Global Identifiers', 'wp-seopress-pro' ),
			'class'       => '',
			'desc_tip'    => true,
			'data_type'   => '',
			'description' => __( 'A valid product identifier to be used in the product schema (accepted types: gtin8 (ean8) | gtin12 (ean12) | gtin13 (ean13) | gtin14 (ean14) | mpn | isbn)', 'wp-seopress-pro' ),
		);
		woocommerce_wp_text_input( $args );
	}
	add_action( 'woocommerce_product_options_inventory_product_data', 'seopress_wc_barcode_field' );

	/**
	 * Save the barcode custom field
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	function seopress_save_wc_barcode_field( $post_id ) {
		$product       = wc_get_product( $post_id );
		$barcode_field = isset( $_POST['sp_wc_barcode_field'] ) ? sanitize_text_field( wp_unslash( $_POST['sp_wc_barcode_field'] ) ) : '';
		$product->update_meta_data( 'sp_wc_barcode_field', sanitize_text_field( $barcode_field ) );
		$product->save();
	}
	add_action( 'woocommerce_process_product_meta', 'seopress_save_wc_barcode_field' );
}
