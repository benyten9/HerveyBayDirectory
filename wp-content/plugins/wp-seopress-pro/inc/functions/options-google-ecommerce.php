<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Options Google Ecommerce.
 *
 * @package SEOPress PRO
 * @subpackage Options
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

add_filter( 'seopress_gtag_before_closing_script', 'seopress_pro_ga4_send_purchases' );

/**
 * Send purchases to Google Analytics 4.
 *
 * @param string $ga4_js The Google Analytics 4 JavaScript.
 *
 * @return string $ga4_js
 */
function seopress_pro_ga4_send_purchases( $ga4_js ) {
	if ( is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
		// Measure purchases.
		$purchases_options = seopress_get_service( 'GoogleAnalyticsOption' )->getPurchases();
		if ( ! $purchases_options ) {
			return $ga4_js;
		}

		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			global $wp;
			$order_id = isset( $wp->query_vars['order-received'] ) ? $wp->query_vars['order-received'] : 0;

			if ( 0 < $order_id ) {
				$order = wc_get_order( $order_id );

				// Check it's a real order.
				if ( is_bool( $order ) ) {
					return $ga4_js;
				}

				// Skip if this order has already been tracked.
				// Read the flag from the order itself so it works with WooCommerce HPOS.
				if ( 1 == $order->get_meta( '_seopress_ga_tracked' ) ) {
					return $ga4_js;
				}

				// Check order status.
				$status = array( 'completed', 'processing' );
				$status = apply_filters( 'seopress_gtag_ec_status', $status );

				if ( method_exists( $order, 'get_status' ) && ( in_array( $order->get_status(), $status, true ) ) ) {
					$final = array();
					foreach ( $order->get_items() as $item ) {
						// Get product object.
						$_product = wc_get_product( $item->get_product_id() );

						if ( ! is_a( $_product, 'WC_Product' ) ) {
							continue;
						}

						// Reset line item data on each iteration so variant and
						// category values from the previous product do not leak.
						$items_purchased = array();

						// Initialize variables.
						$item_id = $_product->get_sku() ? $_product->get_sku() : $_product->get_id();

						/**
						 * Filter the product identifier sent to Google Analytics 4.
						 *
						 * @param string|int $item_id  The product identifier (SKU or product ID by default).
						 * @param WC_Product $_product The product object.
						 * @param WC_Order_Item $item  The order item.
						 * @param WC_Order   $order    The order object.
						 */
						$item_id = apply_filters( 'seopress_gtag_ec_item_id', $item_id, $_product, $item, $order );
						$variation_id   = 0;
						$variation_data = null;
						$categories_js  = null;
						$categories_out = array();
						$variant_js     = null;

						// Set data.
						$items_purchased['item_id']   = esc_js( $item_id );
						$items_purchased['item_name'] = esc_js( $item->get_name() );
						$items_purchased['quantity']  = (float) esc_js( $item->get_quantity() );
						$items_purchased['price']     = (float) esc_js( $order->get_item_total( $item ) );

						// Categories and variations.
						$categories = get_the_terms( $item_id, 'product_cat' );
						if ( $item->get_variation_id() ) {
							$variation_id   = $item->get_variation_id();
							$variation_data = wc_get_product_variation_attributes( $variation_id );
						}

						// Variations.
						if ( is_array( $variation_data ) && ! empty( $variation_data ) ) {
							$variant_js = esc_js( wc_get_formatted_variation( $variation_data, true ) );
							$categories = get_the_terms( $item_id, 'product_cat' );
							$item_id    = $variation_id;

							$items_purchased['variant'] = esc_js( $variant_js );
						}

						$items_purchased = array_merge( $items_purchased, seopress_get_service( 'WooCommerceAnalyticsService' )->getProductCategories( $_product ) );

						$final[] = $items_purchased;
					}

					$global_purchase = array(
						'transaction_id' => esc_js( $order_id ),
						'affiliation'    => esc_js( get_bloginfo( 'name' ) ),
						'value'          => (float) esc_js( $order->get_total() ),
						'currency'       => esc_js( $order->get_currency() ),
						'tax'            => (float) esc_js( $order->get_total_tax() ),
						'shipping'       => (float) esc_js( $order->get_shipping_total() ),
						'items'          => $final,
					);

					$seopress_google_analytics_click_event['purchase_tracking']  = 'gtag(\'event\', \'purchase\',';
					$seopress_google_analytics_click_event['purchase_tracking'] .= wp_json_encode( $global_purchase );
					$seopress_google_analytics_click_event['purchase_tracking'] .= ');';
					$seopress_google_analytics_click_event['purchase_tracking']  = apply_filters( 'seopress_gtag_ec_purchases_ev', $seopress_google_analytics_click_event['purchase_tracking'] );

					// Mark the order as tracked on the order itself (HPOS-compatible).
					// Persist only the meta, not the whole order, to avoid firing the
					// full order-save hooks on the front-end thank-you page.
					$order->update_meta_data( '_seopress_ga_tracked', '1' );
					$order->save_meta_data();

					$ga4_js = $seopress_google_analytics_click_event['purchase_tracking'];

					return $ga4_js;
				}
			}
		}
	}

	return $ga4_js;
}
