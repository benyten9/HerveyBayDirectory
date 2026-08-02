<?php

/**
 * WooCommerce Customer Before Card Expiry Trigger
 * This trigger will be fired before a customer's saved payment card expires.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Woocommerce\Subscription;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Core\PluginKernel;
use WC_Customer;

/**
 * Customer Before Card Expiry Trigger
 */
class CustomerBeforeCardExpiry extends Trigger
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
	public $name = 'Customer Before Card Expiry';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'wc_customer_before_card_expiry';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired before a customer\'s saved payment card expires.';

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
		$this->tasks = new \DoubleScale\Core\Tasks('doublescale_card_expiry');
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
		$this->tasks->register_callback('check_card_expiry', array($this, 'check_card_expiry'));
		$this->tasks->register_callback('process_customer_before_card_expiry', array($this, 'process_customer_before_card_expiry'));

		// Schedule the recurring cron job to check card expiry daily
		\add_action('init', array($this, 'schedule_expiry_checker'));
	}

	/**
	 * Schedule the recurring cron job to check card expiry
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function schedule_expiry_checker()
	{
		// Check if the recurring job is already scheduled
		if ($this->tasks->get_next_timestamp('check_card_expiry') === false) {
			// Schedule to run daily (86400 seconds)
			$this->tasks->schedule_recurring(time(), 86400, 'check_card_expiry');
		}
	}

	/**
	 * Check card expiry and schedule individual triggers
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function check_card_expiry()
	{
		// Get all active automations for this trigger
		$automations = AutomationModel::get_automations_by_trigger($this->slug);

		if (empty($automations)) {
			return;
		}

		// Get all customers with active subscriptions and payment methods
		$customers = $this->get_customers_with_payment_methods();

		foreach ($customers as $customer_data) {
			$customer_id = $customer_data['customer_id'];
			$expiry_date = $customer_data['expiry_date'];

			// Schedule triggers for each automation
			foreach ($automations as $automation) {
				$this->schedule_trigger_for_customer($customer_id, $expiry_date, $automation);
			}
		}
	}

	/**
	 * Get customers with payment methods that have expiry dates
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function get_customers_with_payment_methods()
	{
		if (! \function_exists('wcs_get_subscriptions')) {
			return array();
		}

		$customers = array();

		// Get all active subscriptions
		$subscriptions = \wcs_get_subscriptions(
			array(
				'subscription_status'    => array('active', 'on-hold'),
				'subscriptions_per_page' => -1,
			)
		);

		foreach ($subscriptions as $subscription) {
			$customer_id = $subscription->get_customer_id();

			if (! $customer_id) {
				continue;
			}

			// Get payment method expiry date from subscription meta or payment tokens
			$payment_tokens = \WC_Payment_Tokens::get_customer_tokens($customer_id);

			foreach ($payment_tokens as $token) {
				if (method_exists($token, 'get_expiry_month') && method_exists($token, 'get_expiry_year')) {
					$expiry_month = $token->get_expiry_month();
					$expiry_year  = $token->get_expiry_year();

					if ($expiry_month && $expiry_year) {
						// Create expiry date (last day of expiry month)
						$expiry_date = mktime(23, 59, 59, $expiry_month, date('t', mktime(0, 0, 0, $expiry_month, 1, $expiry_year)), $expiry_year);

						$customers[] = array(
							'customer_id' => $customer_id,
							'expiry_date' => $expiry_date,
							'token_id'    => $token->get_id(),
						);
					}
				}
			}
		}

		return $customers;
	}

	/**
	 * Schedule trigger for a specific customer and automation
	 *
	 * @since 1.0.0
	 *
	 * @param int              $customer_id Customer ID
	 * @param int              $expiry_date Card expiry timestamp
	 * @param AutomationModel $automation Automation model
	 *
	 * @return void
	 */
	private function schedule_trigger_for_customer($customer_id, $expiry_date, $automation)
	{
		$days    = (int) $automation->get_setting('days', 30);
		$hours   = (int) $automation->get_setting('hours', 0);
		$minutes = (int) $automation->get_setting('minutes', 0);

		// Calculate trigger time: days/hours/minutes before expiry
		$trigger_time = $expiry_date - ($days * \DAY_IN_SECONDS) - ($hours * \HOUR_IN_SECONDS) - ($minutes * \MINUTE_IN_SECONDS);

		// Only schedule if trigger time is in the future
		if ($trigger_time <= time()) {
			return;
		}

		// Create unique hook name for this customer and automation
		$hook_suffix = $customer_id . '_' . $automation->id;

		// Check if already scheduled
		if ($this->tasks->get_next_timestamp('process_customer_before_card_expiry', array($hook_suffix)) !== false) {
			return;
		}

		// Schedule the individual trigger
		$this->tasks->schedule_single($trigger_time, 'process_customer_before_card_expiry', $customer_id, $automation->id);
	}

	/**
	 * Process individual customer before card expiry trigger
	 *
	 * @since 1.0.0
	 *
	 * @param int $customer_id Customer ID
	 * @param int $automation_id Automation ID
	 *
	 * @return void
	 */
	public function process_customer_before_card_expiry($customer_id, $automation_id)
	{
		$customer = new WC_Customer($customer_id);

		if (! $customer->get_id()) {
			return;
		}

		// Get customer's active subscriptions
		$subscriptions        = \wcs_get_users_subscriptions($customer_id);
		$active_subscriptions = array();

		foreach ($subscriptions as $subscription) {
			if ($subscription->has_status(array('active', 'on-hold'))) {
				$active_subscriptions[] = $subscription->get_id();
			}
		}

		$data = array(
			'customer_id'          => $customer_id,
			'customer'             => $customer,
			'customer_email'       => $customer->get_email(),
			'customer_first_name'  => $customer->get_first_name(),
			'customer_last_name'   => $customer->get_last_name(),
			'active_subscriptions' => $active_subscriptions,
			'subscription_count'   => count($active_subscriptions),
			'automation_id'        => $automation_id,
		);

		$this->process($data);
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
				'label'    => __('Days before card expiry', 'doublescale'),
				'required' => true,
				'default'  => 30,
				'min'      => 1,
				'max'      => 365,
			),
			'label'   => array(
				'type'  => 'label',
				'label' => __('Schedule this automation to run everyday at', 'doublescale'),
			),
			'hours'   => array(
				'type'        => 'number',
				'label'       => __('Hours', 'doublescale'),
				'placeholder' => 'HH',
				'required'    => true,
				'default'     => 9,
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
				'default'  => 30,
			),
			'hours'   => array(
				'label'    => __('Hours', 'doublescale'),
				'type'     => 'number',
				'required' => true,
				'default'  => 9,
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
