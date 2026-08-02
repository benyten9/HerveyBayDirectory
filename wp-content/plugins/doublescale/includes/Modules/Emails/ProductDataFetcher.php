<?php
/**
 * Product Data Fetcher
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches live WooCommerce product data for email rendering
 */
class ProductDataFetcher {
	/**
	 * Get product data from WooCommerce
	 *
	 * @param int $product_id WooCommerce product ID
	 * @return array|null Product data array or null if not found
	 */
	public static function get_product_data( $product_id ) {
		// Validate product ID
		if ( ! $product_id || ! is_numeric( $product_id ) ) {
			return null;
		}

		// Check if WooCommerce is active
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		// Get product
		$product = wc_get_product( $product_id );

		// Check if product exists and is published
		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return null;
		}

		// Only show published products
		if ( $product->get_status() !== 'publish' ) {
			return null;
		}

		// Get product image
		$image_id = $product->get_image_id();
		$image    = $image_id ? wp_get_attachment_image_src( $image_id, 'full' ) : false;

		// Get description (short description first, fallback to full description)
		$description = $product->get_short_description();
		if ( empty( $description ) ) {
			$description = $product->get_description();
		}
		// Strip HTML tags and limit length
		$description = wp_strip_all_tags( $description );
		if ( strlen( $description ) > 200 ) {
			$description = substr( $description, 0, 200 ) . '...';
		}

		return array(
			'id'          => $product->get_id(),
			'title'       => $product->get_name(),
			'description' => $description,
			'price'       => $product->get_price_html(),
			'imageSrc'    => $image ? $image[0] : '',
			'imageAlt'    => $product->get_name(),
			'buttonLink'  => $product->get_permalink(),
		);
	}
}
