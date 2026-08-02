<?php

/**
 * Class CartCouponText
 *
 * This class is responsible for handling the cart coupon text rule
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
 * Cart Coupon Text class
 */
class CartCouponText extends Rule
{

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Cart Coupon Text';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'cart_coupon_text';

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
	public $type = 'text';

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
			return '';
		}

		$abandoned_cart = AbandonedCartModel::find($abandoned_cart_id);
		if (! $abandoned_cart || empty($abandoned_cart->coupons)) {
			return '';
		}

		// Get all coupon codes and join them with comma
		$coupon_codes = array();
		if (is_array($abandoned_cart->coupons)) {
			$coupon_codes = array_keys($abandoned_cart->coupons);
		}

		return implode(', ', $coupon_codes);
	}
}

add_action(
	'init',
	function () {
		if (class_exists('WooCommerce')) {
			RulesManager::instance()->register(new CartCouponText());
		} else {
			add_action(
				'woocommerce_loaded',
				function () {
					RulesManager::instance()->register(new CartCouponText());
				}
			);
		}
	},
	99
);
