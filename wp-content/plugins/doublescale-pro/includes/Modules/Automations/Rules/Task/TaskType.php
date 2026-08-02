<?php
/**
 * Rule: task type.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Task;

use DoubleScale\Core\Constants\TaskType as TaskTypeConst;

defined( 'ABSPATH' ) || exit;

class TaskType extends BaseTaskRule {

	public $name = 'Task Type';
	public $slug = 'task_type';
	public $type = 'select';

	public function get_operators() {
		return $this->is_is_not_operators();
	}

	public function get_options() {
		return TaskTypeConst::get_all();
	}

	public function get_value( $automation_contact ) {
		$task = $this->resolve_task( $automation_contact );
		return $task ? ( $task->task_type ?? '' ) : '';
	}
}

TaskRuleRegistration::register( new TaskType() );
