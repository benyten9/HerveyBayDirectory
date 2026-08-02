<?php

/**
 * Delay Until Datetime Action
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Delays;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\PluginKernel;

/**
 * Delay Until Datetime Action
 */
class DelayUntilDatetime extends Action
{
	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Delay Until Datetime';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'delay-until-datetime';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will delay the automation until a specified datetime.';

	/**
	 * Action Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Auto enqueue step
	 *
	 * @var bool
	 */
	public $auto_enqueue = false;

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'crm';

	/**
	 * Tigger Group
	 *
	 * @var string
	 */
	public $group = 'delay';

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
		$next_step = $automation->get_next_step($step);
		if (! $next_step) {
			return false;
		}
		// Schedule the next step after 2 minutes
		$datetime = $step->get_setting('datetime');
		// convert datetime to timestamp
		$timestamp = (new \DateTime($datetime))->getTimestamp();

		PluginKernel::instance()->automations_tasks->schedule_single($timestamp, 'process_automation_step', $automation->id, $step->id, $next_step->id, $automation_contact->id);
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
			'datetime' => array(
				'type'  => 'datetime',
				'label' => __('Datetime', 'doublescale'),
			),
		);
	}

	/**
	 * Get Attributes Schema
	 */
	public function get_attributes_schema()
	{
		return array(
			'type'       => 'object',
			'properties' => array(
				'datetime' => array(
					'type'    => 'string',
					'title'   => 'Datetime (YYYY-MM-DD HH:MM:SS)',
					'default' => 'now',
				),
			),
		);
	}
}
