<?php
/**
 * Rule: task kanban status.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Task;

use DoubleScale\Pro\Modules\Tasks\Models\TaskStatusModel;

defined( 'ABSPATH' ) || exit;

class TaskKanbanStatus extends BaseTaskRule {

	public $name = 'Task Kanban Status';
	public $slug = 'task_kanban_status';
	public $type = 'select';

	public function get_operators() {
		return $this->is_is_not_operators();
	}

	public function get_options() {
		if ( ! TaskRuleRegistration::storage_ready() ) {
			return array();
		}

		$options = array();
		foreach ( TaskStatusModel::orderBy( 'sort_order', 'asc' )->get() as $stage ) {
			$options[ $stage->id ] = $stage->name;
		}
		return $options;
	}

	public function get_value( $automation_contact ) {
		$task = $this->resolve_task( $automation_contact );
		return $task ? ( $task->status_id ?? '' ) : '';
	}
}

TaskRuleRegistration::register( new TaskKanbanStatus() );
