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

		$picked = (int) $step->get_setting( 'assignee' );
		if ( 0 === $picked ) {
			return false;
		}

		list( $assignee, $note ) = $this->resolve_assignee( $picked, (int) $task->entity_type, (int) $task->entity_id );
		if ( $assignee <= 0 ) {
			return array(
				'status'  => 'skipped',
				/* translators: %s: why nobody could take the task */
				'message' => sprintf( __( 'The task was not reassigned: %s', 'doublescale' ), $note ),
			);
		}

		$task->assigned_to = $assignee;
		if ( ! $task->save() ) {
			return false;
		}

		// The run log says when the task went to the record's owner instead.
		return '' === $note ? true : array(
			'success' => true,
			'message' => $note,
		);
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
