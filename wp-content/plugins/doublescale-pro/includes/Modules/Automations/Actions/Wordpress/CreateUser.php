<?php

/**
 * Create User Action
 * This action will create a new user.
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
 * Create User Action
 */
class CreateUser extends Action
{
	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Create User';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'create_user';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will create a new user.';

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
			$user_id = wp_create_user($contact->email, wp_generate_password(8), $contact->email);
			if (is_wp_error($user_id)) {
				return false;
			}
			$first_name = $contact->first_name ? $contact->first_name : '';
			$last_name  = $contact->last_name ? $contact->last_name : '';
			if (! empty($first_name)) {
				update_user_meta($user_id, 'first_name', $first_name);
			}

			if (! empty($last_name)) {
				update_user_meta($user_id, 'last_name', $last_name);
			}
		}

		return true;
	}
}
