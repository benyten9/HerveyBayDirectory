<?php
/**
 * Rule: task assignee.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Task;

defined( 'ABSPATH' ) || exit;

class TaskAssignee extends BaseTaskRule {

	public $name = 'Task Assignee';

	public $slug = 'task_assignee';

	/**
	 * Searchable infinite-scroll user picker (same UX as Tasks "Assigned to").
	 *
	 * @var string
	 */
	public $type = 'infinite_scroll_select';

	/**
	 * REST endpoint for InfiniteScrollSelect.
	 *
	 * @var string
	 */
	public $endpoint = '/doublescale/v1/user-management/users/frontend';

	/**
	 * InfiniteScrollSelect settings.
	 *
	 * @var array
	 */
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

	/**
	 * Options are loaded via the infinite-scroll endpoint — never dump all users at register time.
	 *
	 * @return array
	 */
	public function get_options() {
		return array();
	}

	public function get_value( $automation_contact ) {
		$task = $this->resolve_task( $automation_contact );
		return $task ? ( $task->assigned_to ?? '' ) : '';
	}
}

TaskRuleRegistration::register( new TaskAssignee() );
