<?php
/**
 * Rule: project status.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Project;

use DoubleScale\Pro\Modules\Projects\Models\ProjectStatusModel;

defined( 'ABSPATH' ) || exit;

class ProjectStatus extends BaseProjectRule {

	public $name = 'Project Status';
	public $slug = 'project_status';
	public $type = 'select';

	public function get_operators() {
		return $this->is_is_not_operators();
	}

	public function get_options() {
		if ( ! ProjectRuleRegistration::storage_ready() ) {
			return array();
		}

		$options = array();
		foreach ( ProjectStatusModel::orderBy( 'position', 'asc' )->get() as $status ) {
			$options[ $status->id ] = $status->name;
		}
		return $options;
	}

	public function get_value( $automation_contact ) {
		$project = $this->resolve_project( $automation_contact );
		return $project ? ( $project->status_id ?? '' ) : '';
	}
}

ProjectRuleRegistration::register( new ProjectStatus() );