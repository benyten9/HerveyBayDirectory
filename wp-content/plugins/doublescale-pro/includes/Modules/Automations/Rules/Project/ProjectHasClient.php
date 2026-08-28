<?php
/**
 * Rule: project has client.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Project;

defined( 'ABSPATH' ) || exit;

class ProjectHasClient extends BaseProjectRule {

	public $name = 'Project Has Client';
	public $slug = 'project_has_client';
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
		return ! empty( $project->contact_id ) ? 'yes' : 'no';
	}
}

ProjectRuleRegistration::register( new ProjectHasClient() );