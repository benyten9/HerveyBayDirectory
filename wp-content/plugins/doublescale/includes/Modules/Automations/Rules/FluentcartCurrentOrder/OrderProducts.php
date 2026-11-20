<?php
/**
 * FluentCart Current Order rule: products in the order.
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
 * Products in Order class
 */
class OrderProducts extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Products in Order';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'fluentcart_order_products';

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
	public $type = 'multiselect';

	/**
	 * Get operators
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'includes'         => __( 'Includes', 'doublescale' ),
			'not_includes_in'  => __( 'Does not include (in any)', 'doublescale' ),
			'includes_all'     => __( 'Includes all', 'doublescale' ),
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
		return FluentCartOrders::product_options();
	}

	/**
	 * Get value
	 *
	 * @param AutomationContactModel $automation_contact Automation contact.
	 * @return int[]
	 */
	public function get_value( $automation_contact ) {
		$order = $this->order( $automation_contact );

		return $order ? $order['product_ids'] : array();
	}

	/**
	 * Is met
	 *
	 * @param AutomationContactModel $automation_contact Automation contact.
	 * @param array                  $rule               Rule.
	 * @return bool
	 */
	public function is_met( AutomationContactModel $automation_contact, $rule = array() ) {
		$value      = array_map( 'intval', (array) $this->get_value( $automation_contact ) );
		$rule_value = array_map( 'intval', (array) ( $rule['value'] ?? array() ) );

		switch ( $rule['operator'] ?? '' ) {
			case 'includes':
				return ! empty( array_intersect( $value, $rule_value ) );
			case 'not_includes_in':
				return empty( array_intersect( $value, $rule_value ) );
			case 'includes_all':
				return ! empty( $rule_value ) && empty( array_diff( $rule_value, $value ) );
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
			RulesManager::instance()->register( new OrderProducts() );
		}
	},
	99
);
