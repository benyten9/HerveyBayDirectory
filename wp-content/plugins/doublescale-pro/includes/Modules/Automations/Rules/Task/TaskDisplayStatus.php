<?php
/**
 * Rule: task display status.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Task;

defined( 'ABSPATH' ) || exit;

class TaskDisplayStatus extends BaseTaskRule {

	public $name = 'Task Display Status';
	public $slug = 'task_display_status';
	public $type = 'select';

	public function get_operators() {
		return $this->is_is_not_operators();
	}

	public function get_options() {
		return array(
			'overdue'   => __( 'Overdue', 'doublescale' ),
			'due_today' => __( 'Due today', 'doublescale' ),
			'upcoming'  => __( 'Upcoming', 'doublescale' ),
			'completed' => __( 'Completed', 'doublescale' ),
		);
	}

	public function get_value( $automation_contact ) {
		$task = $this->resolve_task( $automation_contact );
		return $task ? ( $task->display_status ?? '' ) : '';
	}
}

TaskRuleRegistration::register( new TaskDisplayStatus() );
