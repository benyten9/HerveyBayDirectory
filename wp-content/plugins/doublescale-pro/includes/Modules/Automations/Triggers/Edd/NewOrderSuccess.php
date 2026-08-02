<?php

/**
 * EDD Trigger for New Order Success
 *
 * This trigger will be fired when a new order is successfully placed.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Edd;

use DoubleScale\Modules\Automations\Abstracts\Trigger;

/**
 * New Order Success Trigger
 */
class NewOrderSuccess extends Trigger
{

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'New Order Success';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'edd_new_order_success';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a new order is successfully placed.';

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
	public $source = 'edd';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'order';

	/**
	 * Load hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks()
	{
		add_action('edd_complete_purchase', array($this, 'new_order_success'), 10, 3);
	}

	/**
	 * New Order Success
	 *
	 * @since 1.0.0
	 *
	 * @param int           $payment_id Payment ID.
	 * @param \EDD_Payment  $payment    EDD_Payment object containing all payment data.
	 * @param \EDD_Customer $customer   EDD_Customer object containing all customer data.
	 *
	 * @return void
	 */
	public function new_order_success($payment_id, $payment, $customer)
	{
		$user_id = $customer->user_id;
		$user    = get_user_by('id', $user_id);
		if (! $user instanceof \WP_User) {
			return;
		}

		$data = array(
			'email' => $user->user_email,
			'data'  => array(
				'payment_id'  => $payment_id,
				'customer_id' => $customer->id,
			),
		);

		$this->process($data);
	}
}
