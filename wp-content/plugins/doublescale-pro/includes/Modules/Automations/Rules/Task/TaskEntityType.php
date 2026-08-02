<?php
/**
 * Rule: task entity type.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Task;

use DoubleScale\Core\Constants\TaskEntityType as TaskEntityTypeConst;

defined( 'ABSPATH' ) || exit;

class TaskEntityType extends BaseTaskRule {

	public $name = 'Task Entity Type';
	public $slug = 'task_entity_type';
	public $type = 'select';

	public function get_operators() {
		return $this->is_is_not_operators();
	}

	public function get_options() {
		return array(
			TaskEntityTypeConst::CONTACT => __( 'Contact', 'doublescale' ),
			TaskEntityTypeConst::DEAL    => __( 'Deal', 'doublescale' ),
			TaskEntityTypeConst::PROJECT => __( 'Project', 'doublescale' ),
		);
	}

	public function get_value( $automation_contact ) {
		$task = $this->resolve_task( $automation_contact );
		return $task ? (int) $task->entity_type : '';
	}
}

TaskRuleRegistration::register( new TaskEntityType() );
