<?php

/**
 * PMPro Trigger for Membership Expired
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
 * Membership Expired Trigger
 */
class MembershipExpired extends Trigger
{

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Membership Expired';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'pmpro_membership_expired';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a user\'s membership expires in Paid Memberships Pro.';

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
		add_action('pmpro_membership_post_membership_expiry', array($this, 'membership_expired'), 10, 2);
	}

	/**
	 * Membership Expired
	 *
	 * @param int $user_id       User ID.
	 * @param int $membership_id Membership level ID that expired.
	 */
	public function membership_expired($user_id, $membership_id)
	{
		$user = get_user_by('ID', $user_id);
		if (! $user instanceof WP_User) {
			return;
		}

		$level_name = '';
		if (function_exists('pmpro_getLevel')) {
			$level = pmpro_getLevel($membership_id);
			$level_name = $level ? $level->name : '';
		}

		$data = array(
			'email' => $user->user_email,
			'data'  => array(
				'membership_id'   => $membership_id,
				'membership_name' => $level_name,
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
