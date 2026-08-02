<?php

/**
 * Class CartCoupons
 *
 * This class is responsible for handling the cart coupons rule
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
 * Cart Coupons class
 */
class CartCoupons extends Rule
{

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Cart Coupons';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'cart_coupons';

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
		$options = array();

		$coupons = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		if (! empty($coupons)) {
			foreach ($coupons as $coupon_id) {
				$coupon = new \WC_Coupon($coupon_id);
				if ($coupon && $coupon->get_code()) {
					$options[$coupon->get_code()] = $coupon->get_code();
				}
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
		if (! $abandoned_cart || empty($abandoned_cart->coupons)) {
			return array();
		}

		// Get coupon codes from the coupons array
		$coupon_codes = array();
		if (is_array($abandoned_cart->coupons)) {
			$coupon_codes = array_keys($abandoned_cart->coupons);
		}

		return $coupon_codes;
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
		$value      = $this->get_value($automation_contact); // Array of coupon codes
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

		switch ($operator) {
			case 'includes':
				// Check if any of the rule coupons are in the cart coupons
				return ! empty(array_intersect($value, $rule_value));

			case 'not_includes_in':
				// Check if none of the rule coupons are in the cart coupons
				return empty(array_intersect($value, $rule_value));

			case 'includes_all':
				// Check if all rule coupons are in the cart coupons
				return empty(array_diff($rule_value, $value));

			case 'not_includes_all':
				// Check if not all rule coupons are in the cart coupons (includes none)
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
			RulesManager::instance()->register(new CartCoupons());
		} else {
			add_action(
				'woocommerce_loaded',
				function () {
					RulesManager::instance()->register(new CartCoupons());
				}
			);
		}
	},
	99
);
