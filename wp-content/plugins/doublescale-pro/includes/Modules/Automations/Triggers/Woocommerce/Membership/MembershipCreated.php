<?php

/**
 * WooCommerce Membership Created Trigger
 * This trigger will be fired when a new membership is created.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Woocommerce\Membership;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use WP_User;

/**
 * Membership Created Trigger
 */
class MembershipCreated extends Trigger
{
	/**
	 * Admin membership ID for tracking admin-created memberships
	 *
	 * @var int|null
	 */
	public $admin_membership = null;
	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Membership Created';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'wc_membership_created';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a new membership is created in WooCommerce Memberships.';

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
		add_action('wc_memberships_user_membership_created', array($this, 'membership_created'), 20, 2);
		add_action('wc_memberships_user_membership_saved', array($this, 'membership_created'), 20, 2);

		/** for checking if the membership created via admin */
		if (is_admin()) {
			add_action('transition_post_status', array($this, 'transition_post_status'), 50, 3);
		}
	}

	/**
	 * Membership Created
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Memberships_Membership_Plan $membership_plan The membership plan that user was granted access to.
	 * @param array                           $args            Arguments passed to the membership creation.
	 * @return void
	 */
	public function membership_created($membership_plan, $args)
	{
		if (! $membership_plan || ! isset($args['user_id']) || ! isset($args['user_membership_id'])) {
			return;
		}

		/** check if membership plan available */
		if (! $membership_plan instanceof \WC_Memberships_Membership_Plan) {
			return;
		}

		$user_id = (int) $args['user_id'];
		$user    = get_user_by('ID', $user_id);

		if (! $user instanceof WP_User) {
			return;
		}

		if (false === $args['is_update'] || ! empty($this->admin_membership)) {

			// Get the user membership object
			$user_membership = null;
			if (function_exists('wc_memberships_get_user_membership')) {
				$user_membership = wc_memberships_get_user_membership($args['user_membership_id']);
			}

			$plan_id   = method_exists($membership_plan, 'get_id') ? $membership_plan->get_id() : '';
			$plan_name = method_exists($membership_plan, 'get_name') ? $membership_plan->get_name() : '';

			$data = array(
				'first_name' => $user->first_name,
				'last_name'  => $user->last_name,
				'email'      => $user->user_email,
				'data'       => array(
					'member_name'            => $user->first_name . ' ' . $user->last_name,
					'user_membership_id'     => $args['user_membership_id'],
					'user_id'                => $user_id,
					'plan_id'                => $plan_id,
					'plan_name'              => $plan_name,
					'status'                 => $user_membership && method_exists($user_membership, 'get_status') ? $user_membership->get_status() : '',
					'membership_renewal_url' => method_exists($user_membership, 'get_renew_membership_url') ? $user_membership->get_renew_membership_url() : '',
					'start_date'             => $user_membership && method_exists($user_membership, 'get_start_date') ? $user_membership->get_start_date('Y-m-d H:i:s') : '',
					'end_date'               => $user_membership && method_exists($user_membership, 'get_end_date') ? $user_membership->get_end_date('Y-m-d H:i:s') : '',
					'is_update'              => isset($args['is_update']) ? $args['is_update'] : false,
				),
			);

			$this->process($data);
			$this->admin_membership = null;
		} else {
			return;
		}
	}

	/**
	 * Handle membership created from the admin screen
	 *
	 * @since 1.0.0
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       Post object.
	 * @return void
	 */
	public function transition_post_status($new_status, $old_status, $post)
	{
		if ($old_status === 'auto-draft' && $post->post_type === 'wc_user_membership') {
			// don't trigger now as post transition happens before data is saved
			$this->admin_membership = $post->ID;
		}
	}
}
