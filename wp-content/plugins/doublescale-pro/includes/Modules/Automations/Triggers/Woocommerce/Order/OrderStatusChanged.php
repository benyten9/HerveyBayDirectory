<?php

/**
 * WooCommerce Order Status Changed Trigger
 * This trigger will be fired when an order status is changed.
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
 * Order Status Changed Trigger
 */
class OrderStatusChanged extends Trigger
{
	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Order Status Changed';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'wc_order_status_changed';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when an order status is changed.';

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
		add_action('woocommerce_order_status_changed', array($this, 'order_status_changed'), 10, 3);
	}

	/**
	 * Order Status Changed
	 *
	 * @since 1.0.0
	 *
	 * @param int    $order_id
	 * @param string $old_status
	 * @param string $new_status
	 *
	 * @return void
	 */
	public function order_status_changed($order_id, $old_status, $new_status)
	{
		$order = \wc_get_order($order_id);
		if (! $order instanceof WC_Order) {
			return;
		}

		$new_status = 'wc-' . $new_status; // Add 'wc-' prefix to the status
		$old_status = 'wc-' . $old_status; // Add 'wc-' prefix to the status

		$data = array(
			'first_name' => $order->get_billing_first_name(),
			'last_name'  => $order->get_billing_last_name(),
			'email'      => $order->get_billing_email(),
			'data'       => array(
				'order_id'    => $order->get_id(),
				'from_status' => $old_status,
				'to_status'   => $new_status,
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
		$automation_from_status = $automation->get_setting('from_status', 'any');
		$automation_to_status   = $automation->get_setting('to_status', 'any');
		$status                 = $args['data']['from_status'] ?? '';
		$new_status             = $args['data']['to_status'] ?? '';

		if ($automation_from_status !== 'any' && $automation_from_status !== $status) {
			return false;
		}

		if ($automation_to_status !== 'any' && $automation_to_status !== $new_status) {
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
			'from_status' => array(
				'type'    => 'select',
				'label'   => __('From Status', 'doublescale'),
				'options' => OrderStatus::get_all(),
			),
			'to_status'   => array(
				'type'    => 'select',
				'label'   => __('To Status', 'doublescale'),
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
				'from_status' => array(
					'type' => 'string',
				),
				'to_status'   => array(
					'type' => 'string',
				),
			),
		);
	}
}
