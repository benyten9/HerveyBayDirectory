<?php

/**
 * PMPro Trigger for Checkout Completed
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Pmpro;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use WP_User;

/**
 * Checkout Completed Trigger
 */
class CheckoutCompleted extends Trigger
{

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Checkout Completed';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'pmpro_checkout_completed';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a user completes checkout in Paid Memberships Pro.';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'pmpro';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'pmpro';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks()
	{
		add_action('pmpro_after_checkout', array($this, 'checkout_completed'), 10, 2);
	}

	/**
	 * Checkout Completed
	 *
	 * @param int    $user_id The user ID.
	 * @param object $morder  MemberOrder object.
	 */
	public function checkout_completed($user_id, $morder)
	{
		$user = get_user_by('ID', $user_id);
		if (! $user instanceof WP_User) {
			return;
		}

		$level = $morder->getMembershipLevel();

		$membership_id   = $level ? $level->id : $morder->membership_id;
		$membership_name = $level ? $level->name : '';

		if (empty($membership_name) && ! empty($membership_id) && function_exists('pmpro_getLevel')) {
			$level_obj = pmpro_getLevel($membership_id);
			$membership_name = $level_obj ? $level_obj->name : '';
		}

		$data = array(
			'email' => $user->user_email,
			'data'  => array(
				'membership_id'   => $membership_id,
				'membership_name' => $membership_name,
				'order_id'        => $morder->id,
				'order_code'      => $morder->code,
				'subtotal'        => $morder->subtotal,
				'tax'             => $morder->tax,
				'total'           => $morder->total,
				'payment_type'    => $morder->payment_type,
				'gateway'         => $morder->gateway,
				'status'          => $morder->status,
				'user_id'         => $user_id,
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
				'label'   => __('Membership Levels (leave empty for all)', 'doublescale'),
				'options' => $this->get_membership_levels(),
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
	 * Get PMPro membership levels
	 *
	 * @return array
	 */
	private function get_membership_levels()
	{
		if (! defined('PMPRO_VERSION') || ! function_exists('pmpro_getAllLevels')) {
			return array();
		}

		$levels  = pmpro_getAllLevels(true, true);
		$options = array();

		foreach ($levels as $level) {
			$options[ $level->id ] = $level->name;
		}

		return $options;
	}
}
