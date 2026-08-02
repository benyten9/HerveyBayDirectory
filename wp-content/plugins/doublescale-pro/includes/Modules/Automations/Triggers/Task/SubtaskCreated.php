<?php
/**
 * Automation trigger: subtask created.
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
 * SubtaskCreated trigger.
 */
class SubtaskCreated extends BaseTaskTrigger {

	/**
	 * @var string
	 */
	public $name = 'Subtask created';

	/**
	 * @var string
	 */
	public $slug = 'subtask_created';

	/**
	 * @var string
	 */
	public $description = 'Fires when a subtask is created.';

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
		add_action( 'doublescale_subtask_created', array( $this, 'handle' ), 10, 1 );
	}

	/**
	 * @param mixed $subtask Subtask model.
	 */
	public function handle( $subtask ): void {
		if ( ! $subtask instanceof SubtaskModel ) {
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
