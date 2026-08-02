<?php

/**
 * Add Order Note Action
 *
 * This action will add a note to the order.
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


/**
 * Add Order Note Action
 */
class AddOrderNote extends Action
{
	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Add Order Note';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'add_order_note';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will add a note to the order.';

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
	 * @param AutomationModel         $automation
	 * @param AutomationStepModel    $step
	 * @param AutomationContactModel $contact
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
				__('WooCommerce plugin is not active. Cannot add order note.', 'doublescale'),
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

		$note = $step->get_setting('note', '');
		if (empty($note)) {
			doublescale_get_logger()->info(
				__('Order note is empty', 'doublescale'),
				array(
					'code'          => 'woocommerce_note_empty',
					'order_id'      => $order_id,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		// Execute the action
		$order->add_order_note($note);

		doublescale_get_logger()->info(
			__('Order note added successfully', 'doublescale'),
			array(
				'code'          => 'woocommerce_note_added',
				'order_id'      => $order_id,
				'automation_id' => $automation->id,
				'step_id'       => $step->id,
			)
		);

		return true;
	}

	/**
	 * Get fields.
	 *
	 * @return array
	 */
	public function get_fields()
	{
		return array(
			array(
				'name'    => 'note',
				'label'   => __('Note', 'doublescale'),
				'type'    => 'textarea',
				'default' => '',
			),
		);
	}
}
