<?php

/**
 * WooCommerce Membership Status Changed Trigger
 * This trigger will be fired when a membership status changes.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Woocommerce\Membership;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use WP_User;

/**
 * Membership Status Changed Trigger
 */
class MembershipStatusChanged extends Trigger
{
	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Membership Status Changed';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'wc_membership_status_changed';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a membership status changes in WooCommerce Memberships.';

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
	public $group = 'membership';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks()
	{
		add_action('wc_memberships_user_membership_status_changed', array($this, 'membership_status_changed'), 10, 3);
	}

	/**
	 * Membership Status Changed
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Memberships_User_Membership $user_membership User membership object.
	 * @param string                          $old_status      Previous membership status.
	 * @param string                          $new_status      New membership status.
	 * @return void
	 */
	public function membership_status_changed($user_membership, $old_status, $new_status)
	{
		if (! $user_membership || ! method_exists($user_membership, 'get_user_id')) {
			return;
		}

		// Skip if status hasn't actually changed
		if ($old_status === $new_status) {
			return;
		}

		$user_id = $user_membership->get_user_id();
		$user    = get_user_by('ID', $user_id);

		if (! $user instanceof WP_User) {
			return;
		}

		$plan_id   = method_exists($user_membership, 'get_plan_id') ? $user_membership->get_plan_id() : '';
		$plan_name = '';

		if ($plan_id && function_exists('wc_memberships_get_membership_plan')) {
			$plan = wc_memberships_get_membership_plan($plan_id);
			if ($plan && method_exists($plan, 'get_name')) {
				$plan_name = $plan->get_name();
			}
		}

		$data = array(
			'first_name' => $user->first_name,
			'last_name'  => $user->last_name,
			'email'      => $user->user_email,
			'data'       => array(
				'member_name'            => $user->first_name . ' ' . $user->last_name,
				'membership_id'          => method_exists($user_membership, 'get_id') ? $user_membership->get_id() : '',
				'user_id'                => $user_id,
				'plan_id'                => $plan_id,
				'plan_name'              => $plan_name,
				'old_status'             => $old_status,
				'new_status'             => $new_status,
				'status'                 => $new_status,
				'membership_renewal_url' => method_exists($user_membership, 'get_renew_membership_url') ? $user_membership->get_renew_membership_url() : '',
				'start_date'             => method_exists($user_membership, 'get_start_date') ? $user_membership->get_start_date('Y-m-d H:i:s') : '',
				'end_date'               => method_exists($user_membership, 'get_end_date') ? $user_membership->get_end_date('Y-m-d H:i:s') : '',
			),
		);

		$this->process($data);
	}
}
