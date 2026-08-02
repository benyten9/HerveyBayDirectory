<?php

/**
 * WooCommerce Subscription Created Trigger
 * This trigger will be fired when a new subscription is created.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Woocommerce\Subscription;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use WC_Subscription;

/**
 * Subscription Created Trigger
 */
class SubscriptionCreated extends Trigger
{
	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Subscription Created';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'wc_subscription_created';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a new subscription is created in WooCommerce.';

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
		add_action('woocommerce_checkout_subscription_created', array($this, 'subscription_created'), 20, 2);
		add_action('wcs_api_subscription_created', array($this, 'subscription_created'), 20, 1);
		add_action('woocommerce_admin_created_subscription', array($this, 'subscription_created'), 20, 1);
		add_action('woocommerce_new_subscription', array($this, 'subscription_created'), 20, 1);
	}

	/**
	 * Subscription Created
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 * @return void
	 */
	public function subscription_created($subscription)
	{
		if (! $subscription instanceof WC_Subscription) {
			return;
		}

		$data = array(
			'subscription_id' => $subscription->get_id(),
			'subscription'    => $subscription,
			'customer_id'     => $subscription->get_customer_id(),
			'customer_email'  => $subscription->get_billing_email(),
			'status'          => $subscription->get_status(),
			'total'           => $subscription->get_total(),
			'currency'        => $subscription->get_currency(),
			'start_date'      => $subscription->get_date('start'),
			'next_payment'    => $subscription->get_date('next_payment'),
			'end_date'        => $subscription->get_date('end'),
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
