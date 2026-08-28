<?php
/**
 * Rule: project progress.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Project;

defined( 'ABSPATH' ) || exit;

class ProjectProgress extends BaseProjectRule {

	public $name = 'Project Progress';
	public $slug = 'project_progress';
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
		return $project ? $project->resolveProgress() : '';
	}
}

ProjectRuleRegistration::register( new ProjectProgress() );