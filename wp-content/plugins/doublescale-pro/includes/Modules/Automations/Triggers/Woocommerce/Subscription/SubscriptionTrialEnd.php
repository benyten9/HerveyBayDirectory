<?php

/**
 * WooCommerce Subscription Trial End Trigger
 * This trigger will be fired when a subscription's trial period ends.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Woocommerce\Subscription;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use WC_Subscription;

/**
 * Subscription Trial End Trigger
 */
class SubscriptionTrialEnd extends Trigger
{



	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Subscription Trial End';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'wc_subscription_trial_end';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a subscription trial period ends.';

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
		add_action('woocommerce_subscription_trial_ended', array($this, 'subscription_trial_end'));
	}

	/**
	 * Subscription Trial End
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 * @return void
	 */
	public function subscription_trial_end($subscription_id)
	{
		if (! function_exists('wcs_get_subscription')) {
			return;
		}
		$subscription = wcs_get_subscription($subscription_id);
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
			'trial_end_date'  => $subscription->get_date('trial_end'),
			'next_payment'    => $subscription->get_date('next_payment'),
			'trial_period'    => $subscription->get_trial_period(),
		);

		$this->process($data);
	}
}
