<?php
/**
 * Rule: task status (pending/completed).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Task;

defined( 'ABSPATH' ) || exit;

class TaskStatus extends BaseTaskRule {

	public $name = 'Task Status';
	public $slug = 'task_status';
	public $type = 'select';

	public function get_operators() {
		return $this->is_is_not_operators();
	}

	public function get_options() {
		return array(
			'pending'   => __( 'Pending', 'doublescale' ),
			'completed' => __( 'Completed', 'doublescale' ),
		);
	}

	public function get_value( $automation_contact ) {
		$task = $this->resolve_task( $automation_contact );
		return $task ? ( $task->status ?? '' ) : '';
	}
}

TaskRuleRegistration::register( new TaskStatus() );
