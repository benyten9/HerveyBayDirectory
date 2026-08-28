<?php
/**
 * Rule: project owner.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Project;

defined( 'ABSPATH' ) || exit;

class ProjectOwner extends BaseProjectRule {

	public $name = 'Project Owner';
	public $slug = 'project_owner';
	public $type = 'infinite_scroll_select';
	public $endpoint = '/doublescale/v1/user-management/users/frontend';
	public $settings = array(
		'apiParams'       => array(
			'filter_crm_users' => 'true',
		),
		'dataPath'        => 'users',
		'totalPath'       => 'pagination.total',
		'searchParamName' => 'search',
		'perPage'         => 20,
	);

	public function get_operators() {
		return $this->is_is_not_operators();
	}

	public function get_options() {
		return array();
	}

	public function get_value( $automation_contact ) {
		$project = $this->resolve_project( $automation_contact );
		return $project ? ( $project->owner_id ?? '' ) : '';
	}
}

ProjectRuleRegistration::register( new ProjectOwner() );