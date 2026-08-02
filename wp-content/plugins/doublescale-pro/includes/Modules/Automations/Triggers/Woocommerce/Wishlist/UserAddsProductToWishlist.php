<?php

/**
 * WooCommerce User Adds Product To Wishlist Trigger
 * This trigger will be fired when a user adds a product to their wishlist.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Woocommerce\Wishlist;

use DoubleScale\Modules\Automations\Abstracts\Trigger;

/**
 * User Adds Product To Wishlist Trigger
 */
class UserAddsProductToWishlist extends Trigger
{



	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'User Adds Product To Wishlist';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'wc_user_adds_product_to_wishlist';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a user adds a product to their wishlist.';

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
		add_action('woocommerce_wishlist_add_item', array($this, 'product_added_to_wishlist'), 10, 7);
	}

	/**
	 * Product Added to Wishlist
	 *
	 * @since 1.0.0
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param int    $product_id Product ID.
	 * @param int    $quantity Quantity.
	 * @param int    $variation_id Variation ID.
	 * @param array  $variation Variation data.
	 * @param array  $cart_item_data Cart item data.
	 * @param mixed  $wishlist_id Wishlist ID.
	 * @return void
	 */
	public function product_added_to_wishlist($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data, $wishlist_id)
	{
		// Skip session wishlists
		if ($wishlist_id === 'session') {
			return;
		}

		// Get product
		$product = wc_get_product($product_id);
		if (! $product) {
			return;
		}

		// Get wishlist details
		$wishlist = get_post($wishlist_id);
		if (! $wishlist) {
			return;
		}

		// Get wishlist owner email
		$owner_email      = get_post_meta($wishlist_id, '_wishlist_owner_email', true);
		$owner_first_name = get_post_meta($wishlist_id, '_wishlist_first_name', true);
		$owner_last_name  = get_post_meta($wishlist_id, '_wishlist_last_name', true);

		// If no email from wishlist meta, try to get from current user
		if (empty($owner_email) && is_user_logged_in()) {
			$current_user     = wp_get_current_user();
			$owner_email      = $current_user->user_email;
			$owner_first_name = $owner_first_name ?: $current_user->first_name;
			$owner_last_name  = $owner_last_name ?: $current_user->last_name;
		}

		// Skip if no email available
		if (empty($owner_email)) {
			return;
		}

		$data = array(
			'first_name' => $owner_first_name,
			'last_name'  => $owner_last_name,
			'email'      => $owner_email,
			'data'       => array(
				'wishlist_id'    => $wishlist_id,
				'wishlist_title' => $wishlist->post_title,
				'product_id'     => $product_id,
				'product_name'   => $product->get_name(),
				'product_sku'    => $product->get_sku(),
				'product_price'  => $product->get_price(),
				'variation_id'   => $variation_id,
				'quantity'       => $quantity,
				'cart_item_key'  => $cart_item_key,
			),
		);

		$this->process($data);
	}
}
