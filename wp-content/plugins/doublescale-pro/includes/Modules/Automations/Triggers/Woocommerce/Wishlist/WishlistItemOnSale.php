<?php

/**
 * WooCommerce Wishlist Item on Sale Trigger
 * This trigger will be fired when a product in a user's wishlist goes on sale.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Woocommerce\Wishlist;

use DoubleScale\Modules\Automations\Abstracts\Trigger;

/**
 * Wishlist Item on Sale Trigger
 */
class WishlistItemOnSale extends Trigger
{
	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Wishlist Item on Sale';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'wc_wishlist_item_on_sale';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a product in a user\'s wishlist goes on sale.';

	/**
	 * Trigger Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'woocommerce';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'wishlist';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks()
	{
		add_action('woocommerce_product_object_updated_props', array($this, 'product_updated'), 10, 2);
	}


	/**
	 * Product Updated
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Product $product Product object.
	 * @param array       $updated_props Updated properties.
	 * @return void
	 */
	public function product_updated($product, $updated_props)
	{
		// Check if sale_price was updated
		if (in_array('sale_price', $updated_props, true) && $product->is_on_sale()) {
			$this->check_product_in_wishlists($product->get_id());
		}
	}

	/**
	 * Check if Product is in Wishlists
	 *
	 * @since 1.0.0
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	private function check_product_in_wishlists($product_id)
	{
		global $wpdb;

		// Get product
		$product = wc_get_product($product_id);
		if (! $product || ! $product->is_on_sale()) {
			return;
		}

		// Find all wishlists containing this product
		$wishlist_items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id as wishlist_id, pm.meta_value as items_data
			FROM {$wpdb->postmeta} pm
			WHERE pm.meta_key = '_wishlist_items'
			AND pm.meta_value LIKE %s",
				'%"product_id";i:' . $product_id . ';%'
			)
		);

		foreach ($wishlist_items as $wishlist_item) {
			$items_data = maybe_unserialize($wishlist_item->items_data);

			if (! is_array($items_data)) {
				continue;
			}

			// Check if this specific product is in the wishlist
			$product_in_wishlist = false;
			foreach ($items_data as $item) {
				if (isset($item['product_id']) && (int) $item['product_id'] === $product_id) {
					$product_in_wishlist = true;
					break;
				}
			}

			if (! $product_in_wishlist) {
				continue;
			}

			// Get wishlist details
			$wishlist = get_post($wishlist_item->wishlist_id);
			if (! $wishlist) {
				continue;
			}

			// Get wishlist owner details
			$owner_email      = get_post_meta($wishlist_item->wishlist_id, '_wishlist_owner_email', true);
			$owner_first_name = get_post_meta($wishlist_item->wishlist_id, '_wishlist_first_name', true);
			$owner_last_name  = get_post_meta($wishlist_item->wishlist_id, '_wishlist_last_name', true);

			// Skip if no email
			if (empty($owner_email)) {
				continue;
			}

			// Calculate discount information
			$regular_price       = $product->get_regular_price();
			$sale_price          = $product->get_sale_price();
			$discount_amount     = $regular_price - $sale_price;
			$discount_percentage = $regular_price > 0 ? round(($discount_amount / $regular_price) * 100, 2) : 0;

			$data = array(
				'first_name' => $owner_first_name,
				'last_name'  => $owner_last_name,
				'email'      => $owner_email,
				'data'       => array(
					'wishlist_id'         => $wishlist_item->wishlist_id,
					'wishlist_title'      => $wishlist->post_title,
					'product_id'          => $product_id,
					'product_name'        => $product->get_name(),
					'product_sku'         => $product->get_sku(),
					'regular_price'       => $regular_price,
					'sale_price'          => $sale_price,
					'discount_amount'     => $discount_amount,
					'discount_percentage' => $discount_percentage,
					'currency'            => get_woocommerce_currency(),
				),
			);

			$this->process($data);
		}
	}
}
