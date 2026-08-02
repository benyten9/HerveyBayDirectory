<?php
/**
 * Automation action: complete the triggering task.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Task;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;

defined( 'ABSPATH' ) || exit;

/**
 * CompleteTask action.
 */
class CompleteTask extends BaseTaskAction {

	/**
	 * @var string
	 */
	public $name = 'Complete a task';

	/**
	 * @var string
	 */
	public $slug = 'complete_task';

	/**
	 * @var string
	 */
	public $description = 'This action will mark the triggering task as completed.';

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
		return (bool) $task->markCompleted();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_fields() {
		return array();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}
}
