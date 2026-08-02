<?php
/**
 * Automation trigger: task assigned / reassigned.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Task;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;

defined( 'ABSPATH' ) || exit;

/**
 * TaskAssigned trigger.
 */
class TaskAssigned extends BaseTaskTrigger {

	/**
	 * @var string
	 */
	public $name = 'Task assigned';

	/**
	 * @var string
	 */
	public $slug = 'task_assigned';

	/**
	 * @var string
	 */
	public $description = 'Fires when a task is assigned or reassigned.';

	/**
	 * @var array
	 */
	public $attributes = array();

	/**
	 * {@inheritdoc}
	 */
	public function load_hooks(): void {
		add_action( 'doublescale_task_reassigned', array( $this, 'handle' ), 10, 3 );
	}

	/**
	 * @param mixed $task            Task model.
	 * @param int   $new_assigned_to New assignee.
	 * @param int   $old_assigned_to Previous assignee.
	 */
	public function handle( $task, $new_assigned_to = 0, $old_assigned_to = 0 ): void {
		if ( ! $task instanceof TaskModel ) {
			return;
		}
		$this->enroll(
			$task,
			array(
				'new_assigned_to' => (int) $new_assigned_to,
				'old_assigned_to' => (int) $old_assigned_to,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$task = $args['task'] ?? null;
		if ( ! $task instanceof TaskModel ) {
			return false;
		}

		// Empty / any-user = match every assignee (infinite-scroll select leaves
		// empty when "any" is intended).
		$assignee = $automation->get_setting( 'assignee', '' );
		$actual   = $args['data']['new_assigned_to'] ?? $task->assigned_to;

		return $this->matches_any_or_value( $assignee, $actual, 'any-user' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_fields() {
		return array(
			'assignee' => array(
				'label'       => $this->t( 'Assignee' ),
				'type'        => 'infinite_scroll_select',
				'endpoint'    => '/doublescale/v1/user-management/users/frontend',
				'placeholder' => $this->t( 'Search and select assignee…' ),
				'helperText'  => $this->t( 'Leave empty to match any assignee.' ),
				'settings'    => array(
					'apiParams'       => array(
						'filter_crm_users' => 'true',
					),
					'dataPath'        => 'users',
					'totalPath'       => 'pagination.total',
					'searchParamName' => 'search',
					'perPage'         => 20,
				),
			),
		);
	}
}
