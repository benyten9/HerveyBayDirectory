<?php
/**
 * Rule: project budget.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Project;

defined( 'ABSPATH' ) || exit;

class ProjectBudget extends BaseProjectRule {

	public $name = 'Project Budget';
	public $slug = 'project_budget';
	public $type = 'number';

	public function get_operators() {
		return array(
			'is'           => __( 'Is', 'doublescale' ),
			'is_not'       => __( 'Is not', 'doublescale' ),
			'greater_than' => __( 'Greater than', 'doublescale' ),
			'lower_than'   => __( 'Less than', 'doublescale' ),
			'is_empty'     => __( 'Is empty', 'doublescale' ),
			'is_not_empty' => __( 'Is not empty', 'doublescale' ),
		);
	}

	public function get_value( $automation_contact ) {
		$project = $this->resolve_project( $automation_contact );
		return $project ? ( $project->budget ?? '' ) : '';
	}
}

ProjectRuleRegistration::register( new ProjectBudget() );