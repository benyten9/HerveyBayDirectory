<?php
/**
 * FluentCart "Order Paid" trigger.
 *
 * Fires once per paid order, on FluentCart's `fluent_cart/order_paid_done`
 * (the settled half of the payment, after FluentCart's own async work).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Fluentcart;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\FluentCartOrders;
use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;

/**
 * OrderPaid trigger
 */
class OrderPaid extends Trigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Order Paid';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'fluentcart_order_paid';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a FluentCart order is paid.';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'fluentcart';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'order';

	/**
	 * Load Hooks
	 *
	 * @return void
	 */
	public function load_hooks() {
		// FluentCartOrders ships with the free plugin; an older free has none.
		if ( ! class_exists( FluentCartOrders::class ) ) {
			return;
		}
		add_action( 'fluent_cart/order_paid_done', array( $this, 'handle' ), 20, 1 );
	}

	/**
	 * Handle a paid order.
	 *
	 * @param mixed $payload FluentCart payload: order, customer, transaction.
	 * @return void
	 */
	public function handle( $payload ) {
		$order = FluentCartOrders::find_order( FluentCartOrders::order_id_from_payload( $payload ) );
		if ( ! $order || ! is_email( $order['email'] ) ) {
			return;
		}

		$this->process( FluentCartOrders::trigger_args( $order ) );
	}

	/**
	 * Only orders with one of the chosen products, when products are chosen.
	 *
	 * @param AutomationModel $automation Automation.
	 * @param array           $args       Trigger args.
	 * @return bool
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		return ProductFilter::matches( $automation->get_setting( 'product_ids', array() ), $args['data']['product_ids'] ?? array() );
	}

	/**
	 * Get fields
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'product_ids' => ProductFilter::field( __( 'Select products to filter this trigger. Leave empty to trigger for all products.', 'doublescale' ) ),
		);
	}

	/**
	 * Get attributes schema
	 *
	 * @return array
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'product_ids' => ProductFilter::schema(),
			),
		);
	}
}
