<?php

/**
 * Class CartItems
 *
 * This class is responsible for handling the cart items rule
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rules\Cart;


defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AbandonedCartModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Cart Items class
 */
class CartItems extends Rule
{

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Cart Items';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'cart_items';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'cart';

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type = 'multiselect';

	/**
	 * Has options
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function has_options()
	{
		return true;
	}

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators()
	{
		return array(
			'includes'         => __('includes', 'doublescale'),
			'not_includes_in'  => __('Does not include (in any)', 'doublescale'),
			'includes_all'     => __('includes all', 'doublescale'),
			'not_includes_all' => __('includes none of (match all)', 'doublescale'),
		);
	}

	/**
	 * Get options
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_options()
	{
		// Check if WooCommerce is available
		if (! function_exists('wc_get_products')) {
			return array();
		}

		$products = wc_get_products(
			array(
				'limit'  => -1,
				'status' => 'publish',
			)
		);

		$options = array();
		if (! empty($products)) {
			foreach ($products as $product) {
				$options[$product->get_id()] = $product->get_name();
			}
		}
		return $options;
	}

	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return array
	 */
	public function get_value($automation_contact)
	{
		$abandoned_cart_id = $automation_contact->get_data('cart_id', 0);
		if (! $abandoned_cart_id) {
			return array();
		}

		$abandoned_cart = AbandonedCartModel::find($abandoned_cart_id);
		if (! $abandoned_cart || empty($abandoned_cart->items)) {
			return array();
		}

		$product_ids = array();
		$items       = $abandoned_cart->items;

		foreach ($items as $item) {
			$product_id = isset($item['product_id']) ? $item['product_id'] : 0;
			if ($product_id) {
				$product = wc_get_product($product_id);
				if ($product) {
					$product_ids[] = $product_id;

					// Also include variation parent ID if this is a variation
					if ($product->is_type('variation')) {
						$parent_id = $product->get_parent_id();
						if ($parent_id) {
							$product_ids[] = $parent_id;
						}
					}
				}
			}
		}

		// Remove duplicates and return unique product IDs
		return array_unique($product_ids);
	}

	/**
	 * Is met
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 * @param array                    $rule Rule.
	 *
	 * @return bool
	 */
	public function is_met(AutomationContactModel $automation_contact, $rule = array())
	{
		$value      = $this->get_value($automation_contact); // Array of product IDs
		$operator   = $rule['operator'];
		$rule_value = $rule['value'] ?? array();

		// Ensure rule_value is an array (for multiselect compatibility)
		if (! is_array($rule_value)) {
			$rule_value = array($rule_value);
		}

		// Ensure value is an array
		if (! is_array($value)) {
			$value = array();
		}

		// Convert to integers for proper comparison
		$value      = array_map('intval', $value);
		$rule_value = array_map('intval', $rule_value);

		switch ($operator) {
			case 'includes':
				// Check if any of the rule products are in the cart products
				return ! empty(array_intersect($value, $rule_value));

			case 'not_includes_in':
				// Check if none of the rule products are in the cart products
				return empty(array_intersect($value, $rule_value));

			case 'includes_all':
				// Check if all rule products are in the cart products
				return empty(array_diff($rule_value, $value));

			case 'not_includes_all':
				// Check if not all rule products are in the cart products (includes none)
				return empty(array_intersect($value, $rule_value));

			default:
				return false;
		}
	}
}

add_action(
	'init',
	function () {
		if (class_exists('WooCommerce')) {
			RulesManager::instance()->register(new CartItems());
		} else {
			add_action(
				'woocommerce_loaded',
				function () {
					RulesManager::instance()->register(new CartItems());
				}
			);
		}
	},
	99
);
