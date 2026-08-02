<?php

/**
 * Class CartTotal
 *
 * This class is responsible for handling the cart total rule
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
 * Cart Total class
 */
class CartTotal extends Rule
{

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Cart Total';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'cart_total';

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
	public $type = 'number';

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
			'greater_than' => __('Greater than', 'doublescale'),
			'lower_than'   => __('Less than', 'doublescale'),
			'equal'        => __('Equal', 'doublescale'),
			'not_equal'    => __('Does not equal', 'doublescale'),
		);
	}

	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return float
	 */
	public function get_value($automation_contact)
	{
		$abandoned_cart_id = $automation_contact->get_data('cart_id', 0);
		if (! $abandoned_cart_id) {
			return 0;
		}

		$abandoned_cart = AbandonedCartModel::find($abandoned_cart_id);
		if (! $abandoned_cart) {
			return 0;
		}

		return (float) ($abandoned_cart->total ?? 0);
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
		$value      = (float) $this->get_value($automation_contact);
		$operator   = $rule['operator'];
		$rule_value = (float) $rule['value'];

		switch ($operator) {
			case 'equal':
				return $value === $rule_value;
			case 'not_equal':
				return $value !== $rule_value;
			case 'greater_than':
				return $value > $rule_value;
			case 'lower_than':
				return $value < $rule_value;
			default:
				return false;
		}
	}
}

add_action(
	'init',
	function () {
		if (class_exists('WooCommerce')) {
			RulesManager::instance()->register(new CartTotal());
		} else {
			add_action(
				'woocommerce_loaded',
				function () {
					RulesManager::instance()->register(new CartTotal());
				}
			);
		}
	},
	99
);
