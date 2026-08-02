<?php
/**
 * Rule: task title.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Task;

defined( 'ABSPATH' ) || exit;

class TaskTitle extends BaseTaskRule {

	public $name = 'Task Title';
	public $slug = 'task_title';
	public $type = 'text';

	public function get_operators() {
		return array(
			'is'               => __( 'Is', 'doublescale' ),
			'is_not'           => __( 'Is not', 'doublescale' ),
			'contains'         => __( 'Contains', 'doublescale' ),
			'does_not_contain' => __( 'Does not contain', 'doublescale' ),
			'is_empty'         => __( 'Is empty', 'doublescale' ),
			'is_not_empty'     => __( 'Is not empty', 'doublescale' ),
		);
	}

	public function get_value( $automation_contact ) {
		$task = $this->resolve_task( $automation_contact );
		return $task ? ( $task->title ?? '' ) : '';
	}
}

TaskRuleRegistration::register( new TaskTitle() );
