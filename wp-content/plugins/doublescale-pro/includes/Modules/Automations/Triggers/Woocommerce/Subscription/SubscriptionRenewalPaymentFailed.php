<?php

/**
 * WooCommerce Subscription Renewal Payment Failed Trigger
 * This trigger will be fired when a subscription renewal payment fails.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Woocommerce\Subscription;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use WC_Subscription;

/**
 * Subscription Renewal Payment Failed Trigger
 */
class SubscriptionRenewalPaymentFailed extends Trigger
{



	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Subscription Renewal Payment Failed';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'wc_subscription_renewal_payment_failed';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a subscription renewal payment fails.';

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
		add_action('woocommerce_subscription_renewal_payment_failed', array($this, 'subscription_renewal_payment_failed'));
	}

	/**
	 * Subscription Renewal Payment Failed
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 * @param WC_Order        $related_order Related order object.
	 * @return void
	 */
	public function subscription_renewal_payment_failed($subscription, $related_order)
	{
		if (! $subscription instanceof WC_Subscription) {
			return;
		}

		$last_order = $related_order;

		$data = array(
			'subscription_id' => $subscription->get_id(),
			'subscription'    => $subscription,
			'customer_id'     => $subscription->get_customer_id(),
			'customer_email'  => $subscription->get_billing_email(),
			'status'          => $subscription->get_status(),
			'total'           => $subscription->get_total(),
			'currency'        => $subscription->get_currency(),
			'next_payment'    => $subscription->get_date('next_payment'),
			'failed_amount'   => $last_order ? $last_order->get_total() : $subscription->get_total(),
			'payment_method'  => $subscription->get_payment_method_title(),
			'failed_order_id' => $last_order ? $last_order->get_id() : null,
			'failure_reason'  => $last_order ? $last_order->get_status() : '',
		);

		$this->process($data);
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
		return array();
	}
}
