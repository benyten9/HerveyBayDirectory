<?php

/**
 * WooCommerce Order Created Per Product Trigger
 * This trigger will be fired for each product when an order is created.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Woocommerce\Order;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Core\Constants\OrderStatus;
use WC_Order;

/**
 * Order Created Per Product Trigger
 */
class OrderCreatedPerProduct extends Trigger
{
	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Order Created - Per Product';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'wc_order_created_per_product';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired for each product when a new order is created in WooCommerce.';

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
		add_action('woocommerce_new_order', array($this, 'order_created'));
	}

	/**
	 * Order Created
	 *
	 * @since 1.0.0
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function order_created($order_id)
	{
		$order = \wc_get_order($order_id);
		if (! $order instanceof WC_Order) {
			return;
		}

		$items = $order->get_items();

		// Loop through each product in the order
		foreach ($items as $item_id => $item) {
			$product = $item->get_product();
			if (! $product) {
				continue;
			}

			$data = array(
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
				'email'      => $order->get_billing_email(),
				'phone'      => $this->resolve_order_phone($order),
				'country'    => $this->resolve_order_country($order),
				'data'       => array(
					'order_id'      => $order->get_id(),
					'product_id'    => $product->get_id(),
					'product_name'  => $product->get_name(),
					'product_sku'   => $product->get_sku(),
					'quantity'      => $item->get_quantity(),
					'line_total'    => $item->get_total(),
					'line_subtotal' => $item->get_subtotal(),
					'item_id'       => $item_id,
				),
			);

			$this->process($data);
		}
	}

	/**
	 * Resolve the best available phone number from an order.
	 *
	 * Prefers billing phone, then shipping phone (HPOS/legacy safe).
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return string
	 */
	protected function resolve_order_phone($order)
	{
		$billing = trim((string) $order->get_billing_phone());
		if ('' !== $billing) {
			return $billing;
		}

		if (method_exists($order, 'get_shipping_phone')) {
			$shipping = trim((string) $order->get_shipping_phone());
			if ('' !== $shipping) {
				return $shipping;
			}
		}

		return '';
	}

	/**
	 * Resolve the billing/shipping country (ISO alpha-2) from an order.
	 *
	 * Used as the calling-code hint when converting a national phone number into
	 * E.164 for the contact's WhatsApp field.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return string
	 */
	protected function resolve_order_country($order)
	{
		$billing = trim((string) $order->get_billing_country());
		if ('' !== $billing) {
			return $billing;
		}

		return trim((string) $order->get_shipping_country());
	}

	/**
	 * Is Processable
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation
	 * @param array            $args
	 *
	 * @return bool
	 */
	public function is_processable(AutomationModel $automation, $args)
	{
		$status              = $args['data']['status'] ?? '';
		$automation_statuses = $automation->get_setting('statuses', array());

		if (! in_array($status, $automation_statuses, true)) {
			return false;
		}

		return true;
	}



	/**
	 * Get fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fields()
	{
		return array(
			'statuses' => array(
				'type'    => 'multiselect',
				'label'   => __('Order Statuses', 'doublescale'),
				'options' => OrderStatus::get_all(),
			),
		);
	}

	/**
	 * Get attributes schema
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_attributes_schema()
	{
		return array(
			'type'       => 'object',
			'properties' => array(
				'statuses' => array(
					'type'  => 'array',
					'items' => array(
						'type' => 'string',
					),
				),
			),
		);
	}
}
