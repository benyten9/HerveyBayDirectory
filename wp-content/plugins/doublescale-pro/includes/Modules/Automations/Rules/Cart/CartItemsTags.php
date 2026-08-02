<?php

namespace DoubleScale\Modules\Automations\Rules\Cart;


defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AbandonedCartModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Cart Items Tags class
 */
class CartItemsTags extends Rule
{

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Cart Items Tags';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'cart_items_tags';

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
		$tags = get_terms(
			array(
				'taxonomy'   => 'product_tag',
				'hide_empty' => false,
			)
		);

		$options = array();
		if (! is_wp_error($tags)) {
			foreach ($tags as $tag) {
				$options[$tag->term_id] = $tag->name;
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

		$tag_ids = array();
		$items   = $abandoned_cart->items;

		foreach ($items as $item) {
			$product_id = isset($item['product_id']) ? $item['product_id'] : 0;
			if ($product_id) {
				$product_tags = wp_get_post_terms($product_id, 'product_tag', array('fields' => 'ids'));
				if (! is_wp_error($product_tags)) {
					$tag_ids = array_merge($tag_ids, $product_tags);
				}
			}
		}

		// Remove duplicates and return unique tag IDs
		return array_unique($tag_ids);
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
		$value      = $this->get_value($automation_contact); // Array of tag IDs
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
				// Check if any of the rule tags are in the cart tags
				return ! empty(array_intersect($value, $rule_value));

			case 'not_includes_in':
				// Check if none of the rule tags are in the cart tags
				return empty(array_intersect($value, $rule_value));

			case 'includes_all':
				// Check if all rule tags are in the cart tags
				return empty(array_diff($rule_value, $value));

			case 'not_includes_all':
				// Check if not all rule tags are in the cart tags (includes none)
				return empty(array_intersect($value, $rule_value));

			default:
				return false;
		}
	}
}

add_action(
	'init',
	function () {
		if (class_exists('WooCommerce') && taxonomy_exists('product_tag')) {
			RulesManager::instance()->register(new CartItemsTags());
		} else {
			add_action(
				'woocommerce_after_register_taxonomy',
				function () {
					RulesManager::instance()->register(new CartItemsTags());
				}
			);
		}
	},
	99
);
