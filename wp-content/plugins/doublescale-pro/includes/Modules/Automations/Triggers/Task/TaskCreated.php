<?php
/**
 * Automation trigger: task created.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Task;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;

defined( 'ABSPATH' ) || exit;

/**
 * TaskCreated trigger.
 */
class TaskCreated extends BaseTaskTrigger {

	/**
	 * @var string
	 */
	public $name = 'Task created';

	/**
	 * @var string
	 */
	public $slug = 'task_created';

	/**
	 * @var string
	 */
	public $description = 'Fires when a new task is created.';

	/**
	 * @var array
	 */
	public $attributes = array();

	/**
	 * {@inheritdoc}
	 */
	public function load_hooks(): void {
		add_action( 'doublescale_task_created', array( $this, 'handle' ), 10, 1 );
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

		$type     = $automation->get_setting( 'task_type', 'any-type' );
		$priority = $automation->get_setting( 'priority', 'any-priority' );

		return $this->matches_any_or_value( $type, $task->task_type, 'any-type' )
			&& $this->matches_any_or_value( $priority, $task->priority, 'any-priority' );
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
			'priority'  => array(
				'label'         => $this->t( 'Priority' ),
				'type'          => 'select',
				'options'       => $this->get_priority_options(),
				'default-value' => 'any-priority',
			),
		);
	}
}
