<?php
/**
 * Rule: project title.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Project;

defined( 'ABSPATH' ) || exit;

class ProjectTitle extends BaseProjectRule {

	public $name = 'Project Title';
	public $slug = 'project_title';
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
		$project = $this->resolve_project( $automation_contact );
		return $project ? ( $project->title ?? '' ) : '';
	}
}

ProjectRuleRegistration::register( new ProjectTitle() );