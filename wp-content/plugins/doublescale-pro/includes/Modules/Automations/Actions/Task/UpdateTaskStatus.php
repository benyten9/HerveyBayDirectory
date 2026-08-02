<?php
/**
 * Automation action: update the triggering task's kanban status.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Task;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Pro\Modules\Tasks\Services\TaskStatusManager;

defined( 'ABSPATH' ) || exit;

/**
 * UpdateTaskStatus action.
 */
class UpdateTaskStatus extends BaseTaskAction {

	/**
	 * @var string
	 */
	public $name = 'Update task status';

	/**
	 * @var string
	 */
	public $slug = 'update_task_status';

	/**
	 * @var string
	 */
	public $description = 'This action will update the kanban status of the triggering task.';

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

		$status_id = (int) $step->get_setting( 'status_id' );
		if ( $status_id <= 0 ) {
			return false;
		}

		TaskStatusManager::instance()->apply_status_to_task( $task, $status_id );
		return (bool) $task->save();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_fields() {
		return array(
			'status_id' => array(
				'label'    => $this->t( 'Kanban status' ),
				'type'     => 'select',
				'required' => true,
				'options'  => $this->get_kanban_status_options(),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'status_id' => array(
					'type'     => 'integer',
					'required' => true,
				),
			),
		);
	}
}
