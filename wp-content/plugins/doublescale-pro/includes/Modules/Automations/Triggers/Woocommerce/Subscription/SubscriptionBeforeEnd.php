<?php

/**
 * WooCommerce Subscription Before End Trigger
 * This trigger will be fired before a subscription reaches its end date.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Woocommerce\Subscription;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Core\PluginKernel;
use WC_Subscription;

/**
 * Subscription Before End Trigger
 */
class SubscriptionBeforeEnd extends Trigger
{
	/**
	 * Tasks instance for scheduling cron jobs
	 *
	 * @var \DoubleScale\Core\Tasks
	 */
	private $tasks;

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Subscription Before End';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'wc_subscription_before_end';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired before a subscription reaches its end date.';

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
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct()
	{
		parent::__construct();
		$this->tasks = new \DoubleScale\Core\Tasks('doublescale_subscription_end');
	}

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks()
	{
		// Register the cron job callback
		$this->tasks->register_callback('check_subscription_ends', array($this, 'check_subscription_ends'));
		$this->tasks->register_callback('process_subscription_before_end', array($this, 'process_subscription_before_end'));

		// Schedule the recurring cron job to check subscriptions every hour
		\add_action('init', array($this, 'schedule_end_checker'));

		// Hook into subscription status changes to reschedule jobs
		\add_action('woocommerce_subscription_status_updated', array($this, 'reschedule_on_status_change'), 10, 3);
	}

	/**
	 * Schedule the recurring cron job to check subscription ends
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function schedule_end_checker()
	{
		// Check if the recurring job is already scheduled
		if ($this->tasks->get_next_timestamp('check_subscription_ends') === false) {
			// Schedule to run every 5 minutes (300 seconds)
			$this->tasks->schedule_recurring(time(), 300, 'check_subscription_ends');
		}
	}

	/**
	 * Check subscription ends and schedule individual triggers
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function check_subscription_ends()
	{
		// Get all active automations for this trigger
		$automations = AutomationModel::get_automations_by_trigger($this->slug);

		if (empty($automations)) {
			return;
		}

		// Get all subscriptions with end dates
		$subscriptions = $this->get_subscriptions_with_end_dates();

		foreach ($subscriptions as $subscription) {
			$end_time = $subscription->get_time('end');

			if (! $end_time || $end_time <= time()) {
				continue;
			}

			// Schedule triggers for each automation
			foreach ($automations as $automation) {
				$this->schedule_trigger_for_subscription($subscription, $automation);
			}
		}
	}

	/**
	 * Get subscriptions with end dates
	 *
	 * @since 1.0.0
	 *
	 * @return WC_Subscription[]
	 */
	private function get_subscriptions_with_end_dates()
	{
		if (! \function_exists('wcs_get_subscriptions')) {
			return array();
		}

		// Get active subscriptions with end dates
		return \wcs_get_subscriptions(
			array(
				'subscription_status'    => array('active', 'pending-cancel'),
				'subscriptions_per_page' => -1,
				'meta_query'             => array(
					array(
						'key'     => '_schedule_end',
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);
	}

	/**
	 * Schedule trigger for a specific subscription and automation
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Subscription  $subscription Subscription object
	 * @param AutomationModel $automation Automation model
	 *
	 * @return void
	 */
	private function schedule_trigger_for_subscription($subscription, $automation)
	{
		$days    = (int) $automation->get_setting('days', 1);
		$hours   = (int) $automation->get_setting('hours', 0);
		$minutes = (int) $automation->get_setting('minutes', 0);

		$end_time = $subscription->get_time('end');

		// Calculate trigger time: days/hours/minutes before end
		$trigger_time = $end_time - ($days * \DAY_IN_SECONDS) - ($hours * \HOUR_IN_SECONDS) - ($minutes * \MINUTE_IN_SECONDS);

		// Only schedule if trigger time is in the future
		if ($trigger_time <= time()) {
			return;
		}

		// Create unique hook name for this subscription and automation
		$hook_suffix = $subscription->get_id() . '_' . $automation->id;

		// Check if already scheduled
		if ($this->tasks->get_next_timestamp('process_subscription_before_end', array($hook_suffix)) !== false) {
			return;
		}

		// Schedule the individual trigger
		$this->tasks->schedule_single($trigger_time, 'process_subscription_before_end', $subscription->get_id(), $automation->id);
	}

	/**
	 * Process individual subscription before end trigger
	 *
	 * @since 1.0.0
	 *
	 * @param int $subscription_id Subscription ID
	 * @param int $automation_id Automation ID
	 *
	 * @return void
	 */
	public function process_subscription_before_end($subscription_id, $automation_id)
	{
		$subscription = \wcs_get_subscription($subscription_id);

		if (! $subscription instanceof WC_Subscription) {
			return;
		}

		// Check if subscription still has an end date
		if (! $subscription->get_time('end')) {
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
			'end_date'        => $subscription->get_date('end'),
			'cancelled_date'  => $subscription->get_date('cancelled'),
			'automation_id'   => $automation_id,
		);

		$this->process($data);
	}

	/**
	 * Reschedule jobs when subscription status changes
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Subscription $subscription Subscription object
	 * @param string          $new_status New status
	 * @param string          $old_status Old status
	 *
	 * @return void
	 */
	public function reschedule_on_status_change($subscription, $new_status, $old_status)
	{
		// If subscription gets an end date, schedule new jobs
		if (in_array($new_status, array('active', 'pending-cancel'), true) && $subscription->get_time('end')) {
			$automations = AutomationModel::get_automations_by_trigger($this->slug);
			foreach ($automations as $automation) {
				$this->schedule_trigger_for_subscription($subscription, $automation);
			}
		}
	}

	/**
	 * Check if trigger should be processed for specific automation
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
		// If automation_id is provided, only process for that specific automation
		if (isset($args['automation_id']) && $automation->id !== $args['automation_id']) {
			return false;
		}

		return parent::is_processable($automation, $args);
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
			'days'    => array(
				'type'     => 'number',
				'label'    => __('Days before subscription end', 'doublescale'),
				'required' => true,
				'default'  => 1,
				'min'      => 0,
				'max'      => 365,
			),
			'label'   => array(
				'type'  => 'label',
				'label' => __('Run at following (Store) Time of a Day: ', 'doublescale'),
			),
			'hours'   => array(
				'type'        => 'number',
				'label'       => __('Hours', 'doublescale'),
				'placeholder' => 'HH',
				'required'    => true,
				'default'     => 0,
				'min'         => 0,
				'max'         => 23,
			),
			'minutes' => array(
				'type'        => 'number',
				'label'       => __('Minutes', 'doublescale'),
				'placeholder' => 'MM',
				'required'    => true,
				'default'     => 0,
				'min'         => 0,
				'max'         => 59,
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
			'days'    => array(
				'label'    => __('Days', 'doublescale'),
				'type'     => 'number',
				'required' => true,
				'default'  => 1,
			),
			'hours'   => array(
				'label'    => __('Hours', 'doublescale'),
				'type'     => 'number',
				'required' => true,
				'default'  => 0,
			),
			'minutes' => array(
				'label'    => __('Minutes', 'doublescale'),
				'type'     => 'number',
				'required' => true,
				'default'  => 0,
			),
		);
	}
}
