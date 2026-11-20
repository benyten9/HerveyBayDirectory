<?php
/**
 * FluentCart Current Order rule: order status.
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
 * Order Status class
 */
class OrderStatus extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Order Status';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'fluentcart_order_status';

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
	public $type = 'select';

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
	 * Has options
	 *
	 * @return bool
	 */
	public function has_options() {
		return true;
	}

	/**
	 * Get options
	 *
	 * @return array
	 */
	public function get_options() {
		return FluentCartOrders::order_statuses();
	}

	/**
	 * Get value
	 *
	 * @param AutomationContactModel $automation_contact Automation contact.
	 * @return string
	 */
	public function get_value( $automation_contact ) {
		$order = $this->order( $automation_contact );

		return $order ? $order['status'] : '';
	}

	/**
	 * Is met
	 *
	 * @param AutomationContactModel $automation_contact Automation contact.
	 * @param array                  $rule               Rule.
	 * @return bool
	 */
	public function is_met( AutomationContactModel $automation_contact, $rule = array() ) {
		$value      = (string) $this->get_value( $automation_contact );
		$rule_value = (string) ( $rule['value'] ?? '' );

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
			RulesManager::instance()->register( new OrderStatus() );
		}
	},
	99
);
