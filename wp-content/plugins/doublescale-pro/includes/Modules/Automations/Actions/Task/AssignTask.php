<?php
/**
 * Automation action: assign the triggering task.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Task;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;

defined( 'ABSPATH' ) || exit;

/**
 * AssignTask action.
 */
class AssignTask extends BaseTaskAction {

	/**
	 * @var string
	 */
	public $name = 'Assign a task';

	/**
	 * @var string
	 */
	public $slug = 'assign_task';

	/**
	 * @var string
	 */
	public $description = 'This action will assign the triggering task to a user.';

	/**
	 * @var array
	 */
	public $required_triggers = array(
		'task_created',
		'task_completed',
		'task_assigned',
		'task_status_changed',
		'task_overdue',
		'task_due_soon',
		'subtask_created',
		'subtask_completed',
	);

	/**
	 * {@inheritdoc}
	 */
	public function process_action( AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact ) {
		$task = $this->resolve_task( $automation_contact );
		if ( ! $task ) {
			return false;
		}

		$assignee = (int) $step->get_setting( 'assignee' );
		if ( $assignee <= 0 ) {
			return false;
		}

		$task->assigned_to = $assignee;
		return (bool) $task->save();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_fields() {
		return array(
			'assignee' => $this->get_assignee_field( true ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'assignee' => array(
					'type'     => 'integer',
					'required' => true,
				),
			),
		);
	}
}
