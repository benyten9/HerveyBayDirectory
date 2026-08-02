<?php

/**
 * WooCommerce Order Refunded Trigger
 * This trigger will be fired when an order is refunded.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Woocommerce\Order;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use WC_Order;

/**
 * Order Refunded Trigger
 */
class OrderRefunded extends Trigger
{
	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Order Refunded';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'wc_order_refunded';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when an order is refunded.';

	/**
	 * Trigger Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'woocommerce';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'order';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks()
	{
		add_action('woocommerce_order_refunded', array($this, 'order_refunded'), 10, 2);
	}

	/**
	 * Order Refunded
	 *
	 * @since 1.0.0
	 *
	 * @param int $order_id Order ID.
	 * @param int $refund_id Refund ID.
	 * @return void
	 */
	public function order_refunded($order_id, $refund_id)
	{
		$order = \wc_get_order($order_id);
		if (! $order instanceof WC_Order) {
			return;
		}

		$data = array(
			'first_name' => $order->get_billing_first_name(),
			'last_name'  => $order->get_billing_last_name(),
			'email'      => $order->get_billing_email(),
			'data'       => array(
				'order_id'  => $order->get_id(),
				'refund_id' => $refund_id,
			),
		);

		$this->process($data);
	}
}
