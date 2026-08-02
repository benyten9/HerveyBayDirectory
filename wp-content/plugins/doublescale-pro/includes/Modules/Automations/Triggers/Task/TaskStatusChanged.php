<?php
/**
 * Automation trigger: task kanban status changed.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Task;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;

defined( 'ABSPATH' ) || exit;

/**
 * TaskStatusChanged trigger.
 */
class TaskStatusChanged extends BaseTaskTrigger {

	/**
	 * @var string
	 */
	public $name = 'Task status changed';

	/**
	 * @var string
	 */
	public $slug = 'task_status_changed';

	/**
	 * @var string
	 */
	public $description = 'Fires when a task kanban status changes.';

	/**
	 * @var array
	 */
	public $attributes = array();

	/**
	 * {@inheritdoc}
	 */
	public function load_hooks(): void {
		add_action( 'doublescale_task_updated', array( $this, 'handle' ), 10, 2 );
	}

	/**
	 * @param mixed $task    Task model.
	 * @param array $changes Changed attributes.
	 */
	public function handle( $task, $changes = array() ): void {
		if ( ! $task instanceof TaskModel ) {
			return;
		}
		if ( ! is_array( $changes ) || ! array_key_exists( 'status_id', $changes ) ) {
			return;
		}

		$old_status_id = $task->getOriginal( 'status_id' );
		$new_status_id = $changes['status_id'];

		if ( (string) $old_status_id === (string) $new_status_id ) {
			return;
		}

		$this->enroll(
			$task,
			array(
				'old_status_id' => $old_status_id,
				'new_status_id' => $new_status_id,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$task = $args['task'] ?? null;
		if ( ! $task instanceof TaskModel ) {
			return false;
		}

		$old = $automation->get_setting( 'old_status', 'any-status' );
		$new = $automation->get_setting( 'new_status', 'any-status' );

		$old_actual = $args['data']['old_status_id'] ?? null;
		$new_actual = $args['data']['new_status_id'] ?? null;

		return $this->matches_any_or_value( $old, $old_actual, 'any-status' )
			&& $this->matches_any_or_value( $new, $new_actual, 'any-status' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_fields() {
		$options = $this->get_kanban_status_options();

		return array(
			'old_status' => array(
				'label'         => $this->t( 'Old status' ),
				'type'          => 'select',
				'options'       => $options,
				'default-value' => 'any-status',
			),
			'new_status' => array(
				'label'         => $this->t( 'New status' ),
				'type'          => 'select',
				'options'       => $options,
				'default-value' => 'any-status',
			),
		);
	}
}
