<?php

/**
 * Class CartContainsAnyCoupon
 *
 * This class is responsible for handling the cart contains any coupon rule
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
 * Cart Contains Any Coupon class
 */
class CartContainsAnyCoupon extends Rule
{

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Cart Contains Any Coupon';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'cart_contains_any_coupon';

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
	public $type = 'select';

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
			'is'     => __('Is', 'doublescale'),
			'is_not' => __('Is not', 'doublescale'),
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
		return array(
			'yes' => __('Yes', 'doublescale'),
			'no'  => __('No', 'doublescale'),
		);
	}

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
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return string
	 */
	public function get_value($automation_contact)
	{
		$abandoned_cart_id = $automation_contact->get_data('cart_id', 0);
		if (! $abandoned_cart_id) {
			return 'no';
		}

		$abandoned_cart = AbandonedCartModel::find($abandoned_cart_id);
		if (! $abandoned_cart || empty($abandoned_cart->coupons)) {
			return 'no';
		}

		// Check if coupons array has any items
		if (is_array($abandoned_cart->coupons) && ! empty($abandoned_cart->coupons)) {
			return 'yes';
		}

		return 'no';
	}
}

add_action(
	'init',
	function () {
		if (class_exists('WooCommerce')) {
			RulesManager::instance()->register(new CartContainsAnyCoupon());
		} else {
			add_action(
				'woocommerce_loaded',
				function () {
					RulesManager::instance()->register(new CartContainsAnyCoupon());
				}
			);
		}
	},
	99
);
