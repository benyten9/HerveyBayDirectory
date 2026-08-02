<?php

/**
 * Update User Role Action
 * This action will update the user role.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Wordpress;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;


/**
 * Update User Role Action
 */
class UpdateUserRole extends Action
{
	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Update User Role';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'update_user_role';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will update the user role.';

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
	public $source = 'wp';

	/**
	 * Tigger Group
	 *
	 * @var string
	 */
	public $group = 'user';

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
			return false;
		}

		$role    = $step->get_setting('role');
		$replace = $step->get_setting('replace');

		if ($replace) {
			$user->set_role($role);
		} else {
			$user->add_role($role);
		}

		return true;
	}

	/**
	 * Get options
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_options()
	{
		// Get all WordPress roles
		global $wp_roles;

		if (! isset($wp_roles)) {
			$wp_roles = new \WP_Roles();
		}

		$roles   = $wp_roles->roles;
		$options = array();

		foreach ($roles as $role => $role_data) {
			$options[$role] = $role_data['name'];
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
			'role'    => array(
				'type'     => 'select',
				'label'    => __('Role', 'doublescale'),
				'required' => true,
				'options'  => $this->get_options(),
			),
			'replace' => array(
				'type'    => 'boolean',
				'label'   => __('Replace the existing roles', 'doublescale'),
				'default' => true,
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
				'role'    => array(
					'type'        => 'string',
					'title'       => 'Role',
					'description' => 'Enter the role.',
					'required'    => true,
				),
				'replace' => array(
					'type'        => 'boolean',
					'title'       => 'Replace',
					'description' => 'Replace the existing roles.',
					'default'     => true,
				),
			),
		);
	}
}
