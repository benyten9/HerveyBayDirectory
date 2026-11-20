<?php
/**
 * FluentCart Current Order rule: order total.
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
 * Order Total class
 */
class OrderTotal extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Order Total';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'fluentcart_order_total';

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
	public $type = 'number';

	/**
	 * Get operators
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'is'           => __( 'Is', 'doublescale' ),
			'is_not'       => __( 'Is not', 'doublescale' ),
			'greater_than' => __( 'Greater than', 'doublescale' ),
			'lower_than'   => __( 'Lower than', 'doublescale' ),
		);
	}

	/**
	 * Get value
	 *
	 * @param AutomationContactModel $automation_contact Automation contact.
	 * @return float|string Empty string when the order is gone.
	 */
	public function get_value( $automation_contact ) {
		$order = $this->order( $automation_contact );

		return $order ? $order['total'] : '';
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
			RulesManager::instance()->register( new OrderTotal() );
		}
	},
	99
);
