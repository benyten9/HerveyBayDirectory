<?php
/**
 * Rule: task is overdue.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Task;

defined( 'ABSPATH' ) || exit;

class TaskIsOverdue extends BaseTaskRule {

	public $name = 'Task Is Overdue';
	public $slug = 'task_is_overdue';
	public $type = 'select';

	public function get_operators() {
		return $this->is_is_not_operators();
	}

	public function get_options() {
		return array(
			'yes' => __( 'Yes', 'doublescale' ),
			'no'  => __( 'No', 'doublescale' ),
		);
	}

	public function get_value( $automation_contact ) {
		$task = $this->resolve_task( $automation_contact );
		if ( ! $task ) {
			return '';
		}
		return $task->is_overdue ? 'yes' : 'no';
	}
}

TaskRuleRegistration::register( new TaskIsOverdue() );
