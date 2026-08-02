<?php
/**
 * Automation trigger: task completed.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Task;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;

defined( 'ABSPATH' ) || exit;

/**
 * TaskCompleted trigger.
 */
class TaskCompleted extends BaseTaskTrigger {

	/**
	 * @var string
	 */
	public $name = 'Task completed';

	/**
	 * @var string
	 */
	public $slug = 'task_completed';

	/**
	 * @var string
	 */
	public $description = 'Fires when a task is marked as completed.';

	/**
	 * @var array
	 */
	public $attributes = array();

	/**
	 * {@inheritdoc}
	 */
	public function load_hooks(): void {
		add_action( 'doublescale_task_completed', array( $this, 'handle' ), 10, 1 );
	}

	/**
	 * @param mixed $task Task model.
	 */
	public function handle( $task ): void {
		if ( ! $task instanceof TaskModel ) {
			return;
		}
		$this->enroll( $task );
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$task = $args['task'] ?? null;
		if ( ! $task instanceof TaskModel ) {
			return false;
		}

		$type = $automation->get_setting( 'task_type', 'any-type' );
		return $this->matches_any_or_value( $type, $task->task_type, 'any-type' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_fields() {
		return array(
			'task_type' => array(
				'label'         => $this->t( 'Task type' ),
				'type'          => 'select',
				'options'       => $this->get_task_type_options(),
				'default-value' => 'any-type',
			),
		);
	}
}
