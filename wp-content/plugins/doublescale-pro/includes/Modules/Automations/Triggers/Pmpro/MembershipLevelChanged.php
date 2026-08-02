<?php

/**
 * PMPro Trigger for Membership Level Changed
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
 * Membership Level Changed Trigger
 */
class MembershipLevelChanged extends Trigger
{

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Membership Level Changed';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'pmpro_membership_level_changed';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a user\'s membership level is changed in Paid Memberships Pro.';

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
		add_action('pmpro_after_change_membership_level', array($this, 'membership_level_changed'), 10, 3);
	}

	/**
	 * Membership Level Changed
	 *
	 * @param int $level_id  New level ID (0 = cancelled).
	 * @param int $user_id   User ID.
	 * @param int $cancel_level  Level being cancelled (if applicable).
	 */
	public function membership_level_changed($level_id, $user_id, $cancel_level = null)
	{
		$user = get_user_by('ID', $user_id);
		if (! $user instanceof WP_User) {
			return;
		}

		$cancel_level   = ! empty($cancel_level) ? (int) $cancel_level : 0;
		$new_level_name = '';
		$old_level_name = '';

		if ($level_id > 0 && function_exists('pmpro_getLevel')) {
			$new_level = pmpro_getLevel($level_id);
			$new_level_name = $new_level ? $new_level->name : '';
		}

		if ($cancel_level > 0 && function_exists('pmpro_getLevel')) {
			$old_level = pmpro_getLevel($cancel_level);
			$old_level_name = $old_level ? $old_level->name : '';
		}

		$data = array(
			'email' => $user->user_email,
			'data'  => array(
				'membership_id'     => (int) $level_id,
				'membership_name'   => $new_level_name,
				'old_level_id'      => $cancel_level,
				'old_level_name'    => $old_level_name,
				'user_id'           => $user_id,
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
				'label'   => __('New Membership Level (leave empty for all)', 'doublescale'),
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
