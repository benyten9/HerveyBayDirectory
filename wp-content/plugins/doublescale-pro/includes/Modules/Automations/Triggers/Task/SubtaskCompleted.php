<?php
/**
 * Automation trigger: subtask completed.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Task;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Pro\Modules\Automations\Support\TaskContactResolver;
use DoubleScale\Pro\Modules\Tasks\Models\SubtaskModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;

defined( 'ABSPATH' ) || exit;

/**
 * SubtaskCompleted trigger.
 */
class SubtaskCompleted extends BaseTaskTrigger {

	/**
	 * @var string
	 */
	public $name = 'Subtask completed';

	/**
	 * @var string
	 */
	public $slug = 'subtask_completed';

	/**
	 * @var string
	 */
	public $description = 'Fires when a subtask is marked as completed.';

	/**
	 * @var string
	 */
	public $group = 'subtask';

	/**
	 * @var array
	 */
	public $attributes = array();

	/**
	 * {@inheritdoc}
	 */
	public function load_hooks(): void {
		add_action( 'doublescale_subtask_updated', array( $this, 'handle' ), 10, 2 );
	}

	/**
	 * @param mixed $subtask Subtask model.
	 * @param array $changes Changed attributes.
	 */
	public function handle( $subtask, $changes = array() ): void {
		if ( ! $subtask instanceof SubtaskModel ) {
			return;
		}
		if ( ! is_array( $changes ) || ! array_key_exists( 'is_completed', $changes ) ) {
			return;
		}

		// Only fire when flipping to completed.
		if ( ! $changes['is_completed'] ) {
			return;
		}
		if ( $subtask->getOriginal( 'is_completed' ) ) {
			return;
		}

		$task = TaskContactResolver::find_task( (int) $subtask->task_id );
		if ( ! $task ) {
			return;
		}

		$this->enroll(
			$task,
			array(
				'subtask_id' => (int) $subtask->id,
			)
		);
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
