<?php
/**
 * Automation trigger: task overdue.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Task;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;

defined( 'ABSPATH' ) || exit;

/**
 * TaskOverdue trigger.
 */
class TaskOverdue extends BaseTaskTrigger {

	/**
	 * @var string
	 */
	public $name = 'Task overdue';

	/**
	 * @var string
	 */
	public $slug = 'task_overdue';

	/**
	 * @var string
	 */
	public $description = 'Fires when a pending task becomes overdue.';

	/**
	 * @var array
	 */
	public $attributes = array();

	/**
	 * {@inheritdoc}
	 */
	public function load_hooks(): void {
		add_action( 'doublescale_automation_task_overdue', array( $this, 'handle' ), 10, 1 );
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
		return isset( $args['task'] ) && $args['task'] instanceof TaskModel;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_fields() {
		return array();
	}
}
