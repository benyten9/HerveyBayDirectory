<?php

/**
 * Update User Meta Action
 *
 * This action will update the user meta.
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
 * Update User Meta Action
 */
class UpdateUserMeta extends Action
{
	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Update User Meta';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'update_user_meta';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will update the user meta.';


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

		$meta = $step->get_setting('meta', array());
		foreach ($meta as $item) {
			update_user_meta($user->ID, $item['key'], $item['value']);
		}

		return true;
	}

	/**
	 * Get fields.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fields()
	{
		return array(
			'meta' => array(
				'type'        => 'repeater',
				'label'       => __('Meta', 'doublescale'),
				'description' => __('User meta to update.', 'doublescale'),
				'fields'      => array(
					'key'   => array(
						'type'        => 'string',
						'label'       => __('Meta Key', 'doublescale'),
						'description' => __('Meta key to update.', 'doublescale'),
					),
					'value' => array(
						'type'        => 'string',
						'label'       => __('Meta Value', 'doublescale'),
						'description' => __('Meta value to update.', 'doublescale'),
					),
				),
			),
		);
	}

	/**
	 * Get Attributes schema.
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
				'meta' => array(
					'type'        => 'array',
					'label'       => 'Meta',
					'description' => 'User meta to update.',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'key'   => array(
								'type'        => 'string',
								'label'       => 'Meta Key',
								'description' => 'Meta key to update.',
							),
							'value' => array(
								'type'        => 'string',
								'label'       => 'Meta Value',
								'description' => 'Meta value to update.',
							),
						),
					),
					'required'    => true,
				),
			),
		);
	}
}
