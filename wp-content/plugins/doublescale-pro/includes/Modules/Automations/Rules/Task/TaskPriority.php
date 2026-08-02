<?php
/**
 * Rule: task priority.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Task;

use DoubleScale\Core\Constants\TaskPriority as TaskPriorityConst;

defined( 'ABSPATH' ) || exit;

class TaskPriority extends BaseTaskRule {

	public $name = 'Task Priority';
	public $slug = 'task_priority';
	public $type = 'select';

	public function get_operators() {
		return $this->is_is_not_operators();
	}

	public function get_options() {
		return TaskPriorityConst::get_all();
	}

	public function get_value( $automation_contact ) {
		$task = $this->resolve_task( $automation_contact );
		return $task ? ( $task->priority ?? '' ) : '';
	}
}

TaskRuleRegistration::register( new TaskPriority() );
