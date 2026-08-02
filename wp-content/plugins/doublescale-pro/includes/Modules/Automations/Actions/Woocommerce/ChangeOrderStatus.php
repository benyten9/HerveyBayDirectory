<?php

/**
 * Change Order Status Action
 *
 * This action will change the order status.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Woocommerce;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\Constants\OrderStatus;

/**
 * Change Order Status Action
 */
class ChangeOrderStatus extends Action
{
	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Change Order Status';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'change_order_status';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will change the order status.';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'woocommerce';

	/**
	 * Tigger Group
	 *
	 * @var string
	 */
	public $group = 'order';

	/**
	 * Required triggers for this action to be enabled
	 *
	 * @var array
	 */
	public $required_triggers = array('wc_order_created', 'wc_order_completed', 'wc_order_status_changed', 'wc_order_refunded');


	/**
	 * Process Action
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel         $automation Automation Model.
	 * @param AutomationStepModel    $step Automation Step Model.
	 * @param AutomationContactModel $contact Contact Model.
	 *
	 * @return bool
	 */
	public function process_action(AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact)
	{
		$order_id = $automation_contact->get_data('order_id', null);
		if (! $order_id) {
			doublescale_get_logger()->info(
				__('Order ID not found in automation contact data', 'doublescale'),
				array(
					'code'          => 'woocommerce_order_id_missing',
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		// Check if WooCommerce function exists
		if (! function_exists('wc_get_order')) {
			doublescale_get_logger()->error(
				__('WooCommerce plugin is not active. Cannot change order status.', 'doublescale'),
				array(
					'code'          => 'woocommerce_plugin_inactive',
					'order_id'      => $order_id,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		$order = wc_get_order($order_id);
		if (! $order) {
			doublescale_get_logger()->info(
				__('WooCommerce order not found', 'doublescale'),
				array(
					'code'          => 'woocommerce_order_not_found',
					'order_id'      => $order_id,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		$status = $step->get_setting('status', '');
		if (! $status) {
			doublescale_get_logger()->info(
				__('Order status not configured for WooCommerce action', 'doublescale'),
				array(
					'code'          => 'woocommerce_status_missing',
					'order_id'      => $order_id,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		// Execute the action
		$order->update_status($status);

		doublescale_get_logger()->info(
			__('WooCommerce order status updated successfully', 'doublescale'),
			array(
				'code'          => 'woocommerce_order_status_changed',
				'order_id'      => $order_id,
				'new_status'    => $status,
				'automation_id' => $automation->id,
				'step_id'       => $step->id,
			)
		);

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
			'status' => array(
				'type'    => 'select',
				'label'   => __('Order Status', 'doublescale'),
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
