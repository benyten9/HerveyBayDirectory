<?php

/**
 * MemberPress Trigger for Membership Enrolled
 *
 * This trigger will be fired when a user's transaction is completed
 * and they gain access to a membership.
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
 * Membership Enrolled Trigger
 */
class MembershipEnrolled extends Trigger
{

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Membership Enrolled';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'memberpress_membership_enrolled';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a user enrolls in a MemberPress membership.';

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
		add_action('mepr-txn-status-complete', array($this, 'transaction_completed'));
	}

	/**
	 * Transaction completed - user enrolled in membership
	 *
	 * @param object $txn MemberPress Transaction object.
	 */
	public function transaction_completed($txn)
	{
		$user = get_user_by('ID', $txn->user_id);
		if (! $user instanceof WP_User) {
			return;
		}

		$membership = $txn->product();
		if (! $membership) {
			return;
		}

		$subscription    = $txn->subscription();
		$subscription_id = $subscription ? $subscription->id : 0;

		$data = array(
			'email' => $user->user_email,
			'data'  => array(
				'membership_id'   => $membership->ID,
				'membership_name' => $membership->post_title,
				'transaction_id'  => $txn->id,
				'subscription_id' => $subscription_id,
				'amount'          => $txn->amount,
				'user_id'         => $txn->user_id,
				'status'          => $txn->status,
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
