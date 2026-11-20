<?php
/**
 * FluentCart Current Order rule: order currency.
 *
 * Reads the order the workflow started from (`order_id` in the automation
 * contact's data), live from FluentCart.
 *
 * @package DoubleScale\Modules\Automations\Rules\FluentcartCurrentOrder
 */

namespace DoubleScale\Modules\Automations\Rules\FluentcartCurrentOrder;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\FluentCartOrders;
use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Order Currency class
 */
class OrderCurrency extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Order Currency';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'fluentcart_order_currency';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'fluentcart_current_order';

	/**
	 * Type
	 *
	 * @var string
	 */
	public $type = 'text';

	/**
	 * Get operators
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'is'     => __( 'Is', 'doublescale' ),
			'is_not' => __( 'Is not', 'doublescale' ),
		);
	}

	/**
	 * Get value
	 *
	 * @param AutomationContactModel $automation_contact Automation contact.
	 * @return string
	 */
	public function get_value( $automation_contact ) {
		$order = $this->order( $automation_contact );

		return $order ? $order['currency'] : '';
	}

	/**
	 * Is met — currency codes compare without case.
	 *
	 * @param AutomationContactModel $automation_contact Automation contact.
	 * @param array                  $rule               Rule.
	 * @return bool
	 */
	public function is_met( AutomationContactModel $automation_contact, $rule = array() ) {
		$value      = strtoupper( trim( (string) $this->get_value( $automation_contact ) ) );
		$rule_value = strtoupper( trim( (string) ( $rule['value'] ?? '' ) ) );

		switch ( $rule['operator'] ?? '' ) {
			case 'is':
				return '' !== $value && $value === $rule_value;
			case 'is_not':
				return $value !== $rule_value;
			default:
				return false;
		}
	}

	/**
	 * The current order, or null.
	 *
	 * @param AutomationContactModel $automation_contact Automation contact.
	 * @return array<string, mixed>|null
	 */
	private function order( $automation_contact ): ?array {
		return FluentCartOrders::find_order( (int) $automation_contact->get_data( 'order_id', 0 ) );
	}
}

add_action(
	'init',
	function () {
		if ( FluentCartOrders::is_active() ) {
			RulesManager::instance()->register( new OrderCurrency() );
		}
	},
	99
);
