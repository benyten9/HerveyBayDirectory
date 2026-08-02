<?php

/**
 * MemberPress Trigger for Subscription Resumed
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Memberpress;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use WP_User;

/**
 * Subscription Resumed Trigger
 */
class SubscriptionResumed extends Trigger
{

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Subscription Resumed';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'memberpress_subscription_resumed';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a MemberPress subscription is resumed after being paused.';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'memberpress';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'memberpress';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks()
	{
		add_action('mepr_subscription_transition_status', array($this, 'subscription_status_changed'), 10, 3);
	}

	/**
	 * Subscription status changed - detect resume (suspended -> active)
	 *
	 * @param string $old_status Old subscription status.
	 * @param string $new_status New subscription status.
	 * @param object $sub        MemberPress Subscription object.
	 */
	public function subscription_status_changed($old_status, $new_status, $sub)
	{
		if ($old_status !== 'suspended' || $new_status !== 'active') {
			return;
		}

		$user = get_user_by('ID', $sub->user_id);
		if (! $user instanceof WP_User) {
			return;
		}

		$membership = $sub->product();
		if (! $membership) {
			return;
		}

		$data = array(
			'email' => $user->user_email,
			'data'  => array(
				'membership_id'   => $membership->ID,
				'membership_name' => $membership->post_title,
				'subscription_id' => $sub->id,
				'amount'          => $sub->price,
				'status'          => $new_status,
				'user_id'         => $sub->user_id,
			),
		);

		$this->process($data);
	}

	/**
	 * Is Processable
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation Automation Model.
	 * @param array            $args Arguments.
	 *
	 * @return bool
	 */
	public function is_processable(AutomationModel $automation, $args)
	{
		$membership_ids = $automation->get_setting('membership_ids', array());

		if (empty($membership_ids)) {
			return true;
		}

		$membership_id = $args['data']['membership_id'] ?? 0;

		return in_array($membership_id, $membership_ids); // phpcs:ignore
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
			'membership_ids' => array(
				'type'    => 'multiselect',
				'label'   => __('Memberships (leave empty for all)', 'doublescale'),
				'options' => $this->get_memberships(),
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
				'membership_ids' => array(
					'type'  => 'array',
					'items' => array(
						'type' => 'string',
					),
				),
			),
		);
	}

	/**
	 * Get MemberPress memberships
	 *
	 * @return array
	 */
	private function get_memberships()
	{
		if (! defined('MEPR_PLUGIN_NAME')) {
			return array();
		}

		$memberships = get_posts(
			array(
				'post_type'   => 'memberpressproduct',
				'numberposts' => -1,
				'post_status' => 'publish',
			)
		);

		$options = array();
		foreach ($memberships as $membership) {
			$options[ $membership->ID ] = $membership->post_title;
		}

		return $options;
	}
}
