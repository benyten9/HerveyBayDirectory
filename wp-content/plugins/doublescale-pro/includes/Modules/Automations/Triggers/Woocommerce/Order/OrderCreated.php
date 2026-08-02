<?php

/**
 * WooCommerce Order Created Trigger
 * This trigger will be fired when an order is created.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Woocommerce\Order;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use WC_Order;
use DoubleScale\Core\Constants\OrderStatus;

/**
 * Order Created Trigger
 */
class OrderCreated extends Trigger
{


	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Order Created';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'wc_order_created';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a new order is created in WooCommerce.';

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
		add_action('woocommerce_order_status_changed', array($this, 'order_status_changed'), 10, 3);
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

		/**
		 * WooCommerce Order Statuses
		 *
		 * @link https://docs.woocommerce.com/document/managing-orders/
		 *
		 * 1. wc-pending - Pending payment
		 * 2. wc-processing - Processing
		 * 3. wc-on-hold - On hold
		 * 4. wc-completed - Completed
		 * 5. wc-cancelled - Cancelled
		 * 6. wc-refunded - Refunded
		 * 7. wc-failed - Failed
		 * 8. wc-checkout-draft - Checkout Draft
		 */
		$status = 'wc-' . $order->get_status(); // Add 'wc-' prefix to the status
		$data   = array(
			'first_name' => $order->get_billing_first_name(),
			'last_name'  => $order->get_billing_last_name(),
			'email'      => $order->get_billing_email(),
			'phone'      => $this->resolve_order_phone($order),
			'country'    => $this->resolve_order_country($order),
			'data'       => array(
				'order_id' => $order->get_id(),
				'status'   => $status,
			),
		);

		$this->process($data);
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
	 * Order Status Changed
	 *
	 * @since 1.0.0
	 *
	 * @param int    $order_id Order ID.
	 * @param string $old_status Old Status.
	 * @param string $new_status New Status.
	 * @return void
	 */
	public function order_status_changed($order_id, $old_status, $new_status)
	{
		$order = \wc_get_order($order_id);
		if (! $order instanceof WC_Order) {
			return;
		}
		$new_status = 'wc-' . $new_status; // Add 'wc-' prefix to the status

		$data = array(
			'first_name' => $order->get_billing_first_name(),
			'last_name'  => $order->get_billing_last_name(),
			'email'      => $order->get_billing_email(),
			'phone'      => $this->resolve_order_phone($order),
			'country'    => $this->resolve_order_country($order),
			'data'       => array(
				'order_id' => $order->get_id(),
				'status'   => $new_status,
			),
		);

		$this->process($data);
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

		$product_ids = $automation->get_setting('product_ids', array());
		if (empty($product_ids)) {
			return true;
		}

		$order_id = isset($args['data']['order_id']) ? absint($args['data']['order_id']) : 0;
		if (! $order_id || ! function_exists('wc_get_order')) {
			return false;
		}

		$order = \wc_get_order($order_id);
		if (! $order instanceof WC_Order) {
			return false;
		}

		$allowed = array_filter(array_unique(array_map('absint', (array) $product_ids)));
		if (empty($allowed)) {
			return true;
		}

		$order_product_ids = array();
		foreach ($order->get_items() as $item) {
			$product = $item->get_product();
			if (! $product) {
				continue;
			}
			$order_product_ids[] = (int) $product->get_id();
			$parent_id             = (int) $product->get_parent_id();
			if ($parent_id) {
				$order_product_ids[] = $parent_id;
			}
		}
		$order_product_ids = array_unique(array_filter($order_product_ids));

		return ! empty(array_intersect($allowed, $order_product_ids));
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
			'statuses'    => array(
				'type'    => 'multiselect',
				'label'   => __('Order Statuses', 'doublescale'),
				'options' => OrderStatus::get_all(),
			),
			'product_ids' => array(
				'type'       => 'infinite_scroll_multiselect',
				'label'      => __('Products', 'doublescale'),
				'endpoint'   => '/wc/v3/products',
				'helperText' => __('Optional: only when the order contains at least one of these products. Leave empty for any product.', 'doublescale'),
				'settings'   => array(
					'rootArrayResponse' => true,
					'perPage'          => 20,
					'searchParamName'  => 'search',
					'apiParams'        => array(
						'status' => 'publish',
					),
				),
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
				'statuses'    => array(
					'type'  => 'array',
					'items' => array(
						'type' => 'string',
					),
				),
				'product_ids' => array(
					'type'  => 'array',
					'items' => array(
						'type' => 'integer',
					),
				),
			),
		);
	}
}
