<?php

/**
 * WooCommerce Subscription Status Changed Trigger
 * This trigger will be fired when a subscription's status changes.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Woocommerce\Subscription;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Core\Constants\SubscriptionStatus;
use WC_Subscription;

/**
 * Subscription Status Changed Trigger
 */
class SubscriptionStatusChanged extends Trigger
{

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Subscription Status Changed';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'wc_subscription_status_changed';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a subscription status changes.';

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
	public $group = 'subscription';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks()
	{
		add_action('woocommerce_subscription_status_updated', array($this, 'subscription_status_changed'), 10, 3);
	}

	/**
	 * Subscription Status Changed
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 * @param string          $old_status Old status.
	 * @param string          $new_status New status.
	 * @return void
	 */
	public function subscription_status_changed($subscription, $new_status, $old_status)
	{

		if (! $subscription instanceof WC_Subscription) {
			return;
		}
		// Skip if status hasn't actually changed
		if ($old_status === $new_status) {
			return;
		}

		$data = array(
			'subscription_id' => $subscription->get_id(),
			'subscription'    => $subscription,
			'customer_id'     => $subscription->get_customer_id(),
			'customer_email'  => $subscription->get_billing_email(),
			'old_status'      => $old_status,
			'new_status'      => $new_status,
			'total'           => $subscription->get_total(),
			'currency'        => $subscription->get_currency(),
			'next_payment'    => $subscription->get_date('next_payment'),
			'end_date'        => $subscription->get_date('end'),
		);

		$this->process($data);
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
		$status_options = array('any' => __('Any Status', 'doublescale')) + SubscriptionStatus::get_all();

		return array(
			'from_status' => array(
				'type'        => 'select',
				'label'       => __('From Status', 'doublescale'),
				'description' => __('Select the status the subscription is changing from', 'doublescale'),
				'options'     => $status_options,
			),
			'to_status'   => array(
				'type'        => 'select',
				'label'       => __('To Status', 'doublescale'),
				'description' => __('Select the status the subscription is changing to', 'doublescale'),
				'options'     => $status_options,
			),
		);
	}

	/**
	 * Check if trigger should be processed
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation Automation Model
	 * @param array            $args Arguments
	 *
	 * @return bool
	 */
	public function is_processable(AutomationModel $automation, $args)
	{
		$automation_from_status = $automation->get_setting('from_status', 'any');
		$automation_to_status   = $automation->get_setting('to_status', 'any');
		$from_status            = 'wc-' . $args['old_status'];
		$to_status              = 'wc-' . $args['new_status'];

		if ($automation_from_status !== 'any' && $automation_from_status !== $from_status) {
			return false;
		}

		if ($automation_to_status !== 'any' && $automation_to_status !== $to_status) {
			return false;
		}

		return true;
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
