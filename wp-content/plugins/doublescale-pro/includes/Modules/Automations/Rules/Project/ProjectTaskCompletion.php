<?php
/**
 * Rule: project task completion percent.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Project;

defined( 'ABSPATH' ) || exit;

class ProjectTaskCompletion extends BaseProjectRule {

	public $name = 'Project Task Completion';
	public $slug = 'project_task_completion';
	public $type = 'number';

	public function get_operators() {
		return array(
			'is'           => __( 'Is', 'doublescale' ),
			'is_not'       => __( 'Is not', 'doublescale' ),
			'greater_than' => __( 'Greater than', 'doublescale' ),
			'lower_than'   => __( 'Less than', 'doublescale' ),
		);
	}

	public function get_value( $automation_contact ) {
		$project = $this->resolve_project( $automation_contact );
		if ( ! $project ) {
			return '';
		}
		$progress = $project->task_progress;
		return is_array( $progress ) ? (int) ( $progress['percent'] ?? 0 ) : 0;
	}
}

ProjectRuleRegistration::register( new ProjectTaskCompletion() );