<?php

/**
 * Class Add User To Group
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Learndash;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;


/**
 * Add User To Group
 */
class AddUserToGroup extends Action
{
	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Add User To Group';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'learndash_add_user_to_group';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will add a user to a group.';

	/**
	 * Action Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'lms';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'learndash';

	/**
	 * Process Action
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel         $automation Automation Model.
	 * @param AutomationStepModel    $step Automation Step Model.
	 * @param AutomationContactModel $contact Contact Model.
	 *
	 * @return bool
	 */
	public function process_action(AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact)
	{
		$contact = $automation_contact->contact;
		$user    = get_user_by('email', $contact->email);
		if (! $user) {
			doublescale_get_logger()->info(
				__('User not found for LearnDash group enrollment', 'doublescale'),
				array(
					'code'          => 'learndash_user_not_found',
					'contact_email' => $contact->email,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		$group_id = $step->get_setting('group_id');
		if (! $group_id) {
			doublescale_get_logger()->info(
				__('Group ID not configured for LearnDash enrollment action', 'doublescale'),
				array(
					'code'          => 'learndash_group_id_missing',
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		$group_id = absint($group_id);

		// Check if LearnDash function exists
		if (! function_exists('ld_update_group_access')) {
			doublescale_get_logger()->error(
				__('LearnDash plugin is not active. Cannot add user to group.', 'doublescale'),
				array(
					'code'          => 'learndash_plugin_inactive',
					'user_id'       => $user->ID,
					'group_id'      => $group_id,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}

		// Execute the action
		ld_update_group_access($user->ID, $group_id);

		doublescale_get_logger()->info(
			__('User successfully added to LearnDash group', 'doublescale'),
			array(
				'code'          => 'learndash_group_enrolled',
				'user_id'       => $user->ID,
				'group_id'      => $group_id,
				'automation_id' => $automation->id,
				'step_id'       => $step->id,
			)
		);

		return true;
	}

	/**
	 * Get Groups
	 *
	 * @since 1.0.0
	 */
	public function get_groups()
	{
		if (! function_exists('learndash_get_groups')) {
			return array();
		}
		$groups = learndash_get_groups(array('posts_per_page' => -1));

		$options = array();
		foreach ($groups ?? array() as $group) {
			// Check if not object.
			if (! is_object($group)) {
				continue;
			}

			$options[$group->ID] = $group->post_title;
		}

		return $options;
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
			'group_id' => array(
				'type'    => 'select',
				'label'   => __('Group', 'doublescale'),
				'options' => $this->get_groups(),
			),
		);
	}

	/**
	 * Get attributes schema
	 *
	 * @return array
	 */
	public function get_attributes_schema()
	{
		return array(
			'type'       => 'object',
			'properties' => array(
				'group_id' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		);
	}
}
