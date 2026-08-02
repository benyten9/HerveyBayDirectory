<?php

/**
 * WooCommerce Wishlist Reminder Trigger
 * This trigger will be fired to remind users about items in their wishlist.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Woocommerce\Wishlist;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;

/**
 * Wishlist Reminder Trigger
 */
class WishlistReminder extends Trigger
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
	public $name = 'Wishlist Reminder';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'wc_wishlist_reminder';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired to remind users about items in their wishlist after a specified period.';

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
	public $group = 'wishlist';

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct()
	{
		parent::__construct();
		$this->tasks = new \DoubleScale\Core\Tasks('doublescale_wishlist_reminder');
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
		// Register the cron job callbacks
		$this->tasks->register_callback('check_wishlist_reminders', array($this, 'check_wishlist_reminders'));
		$this->tasks->register_callback('process_wishlist_reminder', array($this, 'process_wishlist_reminder'));

		// Schedule the recurring cron job to check wishlists daily
		\add_action('init', array($this, 'schedule_reminder_checker'));
	}

	/**
	 * Schedule the recurring cron job to check wishlist reminders
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function schedule_reminder_checker()
	{
		// Check if the recurring job is already scheduled
		if ($this->tasks->get_next_timestamp('check_wishlist_reminders') === false) {
			$this->tasks->schedule_recurring(time(), DAY_IN_SECONDS, 'check_wishlist_reminders');
		}
	}

	/**
	 * Check wishlist reminders and schedule individual triggers
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function check_wishlist_reminders()
	{
		// Get all active automations for this trigger
		$automations = AutomationModel::get_automations_by_trigger($this->slug);

		if (0 === $automations->count()) {
			return;
		}

		// Process wishlists in batches to handle large datasets efficiently
		$batch_size      = 100; // Process 100 wishlists at a time
		$offset          = 0;
		$processed_count = 0;

		do {
			$wishlists = $this->get_wishlists_with_items($batch_size, $offset);

			foreach ($wishlists as $wishlist) {
				// Schedule triggers for each automation
				foreach ($automations as $automation) {
					$this->schedule_trigger_for_wishlist($wishlist, $automation);
				}
				$processed_count++;
			}

			$offset += $batch_size;

			// Add a small delay between batches to prevent overwhelming the server
			if (count($wishlists) === $batch_size) {
				usleep(100000); // 0.1 second delay
			}
		} while (count($wishlists) === $batch_size);
	}

	/**
	 * Get wishlists with items
	 *
	 * @since 1.0.0
	 *
	 * @param int $limit Number of wishlists to retrieve (default: -1 for all)
	 * @param int $offset Offset for pagination (default: 0)
	 *
	 * @return array
	 */
	private function get_wishlists_with_items($limit = -1, $offset = 0)
	{
		global $wpdb;

		// Build the base query
		$query = "SELECT p.ID, p.post_title, p.post_modified,
					pm_email.meta_value as owner_email,
					pm_first.meta_value as first_name,
					pm_last.meta_value as last_name,
					pm_items.meta_value as items
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm_email ON p.ID = pm_email.post_id AND pm_email.meta_key = '_wishlist_owner_email'
			LEFT JOIN {$wpdb->postmeta} pm_first ON p.ID = pm_first.post_id AND pm_first.meta_key = '_wishlist_first_name'
			LEFT JOIN {$wpdb->postmeta} pm_last ON p.ID = pm_last.post_id AND pm_last.meta_key = '_wishlist_last_name'
			LEFT JOIN {$wpdb->postmeta} pm_items ON p.ID = pm_items.post_id AND pm_items.meta_key = '_wishlist_items'
			WHERE p.post_type = 'wishlist'
			AND p.post_status = 'publish'
			AND pm_email.meta_value IS NOT NULL
			AND pm_email.meta_value != ''
			AND pm_items.meta_value IS NOT NULL
			AND pm_items.meta_value != ''
			ORDER BY p.ID ASC";

		// Add LIMIT and OFFSET for pagination if specified
		if ($limit > 0) {
			$query .= $wpdb->prepare(' LIMIT %d OFFSET %d', $limit, $offset);
		}

		$wishlists = $wpdb->get_results($query);

		return $wishlists;
	}

	/**
	 * Schedule trigger for a specific wishlist and automation
	 *
	 * @since 1.0.0
	 *
	 * @param object           $wishlist Wishlist object from database
	 * @param AutomationModel $automation Automation model
	 *
	 * @return void
	 */
	private function schedule_trigger_for_wishlist($wishlist, $automation)
	{
		$hours   = (int) $automation->get_setting('hours', 0);
		$minutes = (int) $automation->get_setting('minutes', 0);

		// Calculate today's trigger time based on hours and minutes
		$today = new \DateTime('today', wp_timezone());
		$today->setTime($hours, $minutes);
		$trigger_time = $today->getTimestamp();

		// If the time has already passed today, schedule for tomorrow
		if ($trigger_time <= time()) {
			$trigger_time += DAY_IN_SECONDS;
		}

		// Create unique hook name for this wishlist and automation
		$hook_suffix = $wishlist->ID . '_' . $automation->id;

		// Check if already scheduled for today
		$existing_timestamp = $this->tasks->get_next_timestamp('process_wishlist_reminder', array($hook_suffix));
		if ($existing_timestamp !== false) {
			// If scheduled for a different time, reschedule
			$existing_date = date('Y-m-d', $existing_timestamp);
			$trigger_date  = date('Y-m-d', $trigger_time);

			if ($existing_date === $trigger_date) {
				return; // Already scheduled for today
			}
		}

		// Schedule the individual trigger
		$this->tasks->schedule_single($trigger_time, 'process_wishlist_reminder', $wishlist->ID, $automation->id);
	}

	/**
	 * Process individual wishlist reminder trigger
	 *
	 * @since 1.0.0
	 *
	 * @param int $wishlist_id Wishlist ID
	 * @param int $automation_id Automation ID
	 *
	 * @return void
	 */
	public function process_wishlist_reminder($wishlist_id, $automation_id)
	{
		// Get wishlist
		$wishlist = get_post($wishlist_id);
		if (! $wishlist || $wishlist->post_status !== 'publish') {
			return;
		}

		// Get wishlist items
		$items = get_post_meta($wishlist_id, '_wishlist_items', true);
		if (empty($items) || ! is_array($items)) {
			return;
		}

		// Get wishlist owner details
		$owner_email      = get_post_meta($wishlist_id, '_wishlist_owner_email', true);
		$owner_first_name = get_post_meta($wishlist_id, '_wishlist_first_name', true);
		$owner_last_name  = get_post_meta($wishlist_id, '_wishlist_last_name', true);

		// Skip if no email
		if (empty($owner_email)) {
			return;
		}

		// Count items and calculate total value
		$item_count      = count($items);
		$total_value     = 0;
		$available_items = 0;
		$on_sale_items   = 0;

		foreach ($items as $item) {
			if (isset($item['product_id'])) {
				$product = wc_get_product($item['product_id']);
				if ($product && $product->is_in_stock()) {
					$available_items++;
					$quantity     = isset($item['quantity']) ? (int) $item['quantity'] : 1;
					$total_value += $product->get_price() * $quantity;

					if ($product->is_on_sale()) {
						$on_sale_items++;
					}
				}
			}
		}

		// Only send reminder if there are available items
		if ($available_items === 0) {
			return;
		}

		// Log successful processing

		$data = array(
			'first_name' => $owner_first_name,
			'last_name'  => $owner_last_name,
			'email'      => $owner_email,
			'data'       => array(
				'wishlist_id'       => $wishlist_id,
				'wishlist_title'    => $wishlist->post_title,
				'item_count'        => $item_count,
				'available_items'   => $available_items,
				'on_sale_items'     => $on_sale_items,
				'total_value'       => $total_value,
				'currency'          => get_woocommerce_currency(),
				'wishlist_url'      => get_permalink($wishlist_id),
				'days_since_update' => $this->get_days_since_update($wishlist_id),
				'automation_id'     => $automation_id,
			),
		);

		$this->process($data);
	}

	/**
	 * Get Days Since Update
	 *
	 * @since 1.0.0
	 *
	 * @param int $wishlist_id Wishlist ID.
	 * @return int Days since last update.
	 */
	private function get_days_since_update($wishlist_id)
	{
		$wishlist = get_post($wishlist_id);
		if (! $wishlist) {
			return 0;
		}

		$last_modified = strtotime($wishlist->post_modified);
		$days_since    = floor((time() - $last_modified) / DAY_IN_SECONDS);

		return max(0, $days_since);
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
			'label'   => array(
				'type'  => 'label',
				'label' => __('Schedule this automation to run everyday at: ', 'doublescale'),
			),
			'hours'   => array(
				'type'        => 'number',
				'label'       => __('Hours', 'doublescale'),
				'placeholder' => 'HH',
				'required'    => true,
				'default'     => 9,
				'min'         => 0,
				'max'         => 23,
				'description' => __('24-hour format (0-23)', 'doublescale'),
			),
			'minutes' => array(
				'type'        => 'number',
				'label'       => __('Minutes', 'doublescale'),
				'placeholder' => 'MM',
				'required'    => true,
				'default'     => 0,
				'min'         => 0,
				'max'         => 59,
				'description' => __('Minutes (0-59)', 'doublescale'),
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
