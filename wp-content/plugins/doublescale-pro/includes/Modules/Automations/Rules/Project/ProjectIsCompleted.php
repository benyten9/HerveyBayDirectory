<?php
/**
 * Rule: project is completed.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Project;

defined( 'ABSPATH' ) || exit;

class ProjectIsCompleted extends BaseProjectRule {

	public $name = 'Project Is Completed';
	public $slug = 'project_is_completed';
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
		$project = $this->resolve_project( $automation_contact );
		if ( ! $project ) {
			return '';
		}
		$status = $project->status;
		return ( $status && $status->is_completed ) ? 'yes' : 'no';
	}
}

ProjectRuleRegistration::register( new ProjectIsCompleted() );