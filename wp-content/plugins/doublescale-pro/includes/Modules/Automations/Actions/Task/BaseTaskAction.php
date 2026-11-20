<?php
/**
 * Shared base for task automation actions.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Task;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Pro\Modules\Automations\Support\AutomationModuleStorage;
use DoubleScale\Pro\Modules\Automations\Support\TaskContactResolver;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskStatusModel;
use DoubleScale\Pro\Modules\Tasks\Services\TaskAssigneeCandidates;
use DoubleScale\Pro\Modules\Tasks\Services\TaskAssigneeRule;
use DoubleScale\Core\UserRoles\UserRoles;

defined( 'ABSPATH' ) || exit;

/**
 * BaseTaskAction
 */
abstract class BaseTaskAction extends Action {

	/**
	 * Source.
	 *
	 * @var string
	 */
	public $source = 'tasks';

	/**
	 * Group.
	 *
	 * @var string
	 */
	public $group = 'task';

	/**
	 * Triggers that put a task in scope for mutation actions.
	 *
	 * @var array
	 */
	public static $task_trigger_slugs = array(
		'task_created',
		'task_completed',
		'task_assigned',
		'task_status_changed',
		'task_overdue',
		'task_due_soon',
		'subtask_created',
		'subtask_completed',
	);

	/**
	 * Translate helper.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	protected function t( $text ) {
		if ( function_exists( '\\__' ) ) {
			return call_user_func( '\\__', $text, 'doublescale' );
		}
		return $text;
	}

	/**
	 * Whether tasks storage is safe to query.
	 */
	protected function tasks_storage_ready(): bool {
		return AutomationModuleStorage::is_ready( 'tasks', TaskModel::class );
	}

	/**
	 * Resolve merge tags within free text.
	 *
	 * @param string                 $text               Raw text.
	 * @param AutomationContactModel $automation_contact Contact.
	 * @return string
	 */
	protected function parse_text( $text, AutomationContactModel $automation_contact ) {
		if ( empty( $text ) ) {
			return '';
		}
		if ( preg_match( '/{{.*?:.*?}}/', $text ) ) {
			return \DoubleScale\Core\MergeTags\MergeTagsManager::instance()->process_merge_tags( $text, $automation_contact );
		}
		return $text;
	}

	/**
	 * Resolve the triggering task from enrollment data.
	 *
	 * @param AutomationContactModel $automation_contact Contact.
	 * @return TaskModel|null
	 */
	protected function resolve_task( AutomationContactModel $automation_contact ): ?TaskModel {
		return TaskContactResolver::resolve_from_automation_contact( $automation_contact );
	}

	/**
	 * CRM user select options.
	 *
	 * @return array
	 */
	protected function get_users_options(): array {
		// get_fields() is evaluated eagerly at registration; do not query users
		// outside a full WordPress bootstrap.
		if ( ! function_exists( 'get_users' ) ) {
			return array();
		}

		$users = get_users(
			array(
				'role__in' => array(
					UserRoles::CRM_MANAGER,
					UserRoles::SALES_REP,
					UserRoles::ADMINISTRATOR,
				),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);

		$options = array();
		foreach ( $users as $user ) {
			$options[ $user->ID ] = $user->display_name;
		}
		return $options;
	}

	/**
	 * Task type options.
	 *
	 * @return array
	 */
	protected function get_task_type_options(): array {
		if ( ! class_exists( \DoubleScale\Core\Constants\TaskType::class ) ) {
			return array();
		}
		return \DoubleScale\Core\Constants\TaskType::get_all();
	}

	/**
	 * Priority options.
	 *
	 * @return array
	 */
	protected function get_priority_options(): array {
		if ( ! class_exists( \DoubleScale\Core\Constants\TaskPriority::class ) ) {
			return array();
		}
		return \DoubleScale\Core\Constants\TaskPriority::get_all();
	}

	/**
	 * Kanban status options.
	 *
	 * @return array
	 */
	protected function get_kanban_status_options(): array {
		if ( ! $this->tasks_storage_ready() ) {
			return array();
		}

		$options = array();
		foreach ( TaskStatusModel::orderBy( 'sort_order', 'asc' )->get() as $stage ) {
			$options[ $stage->id ] = $stage->name;
		}
		return $options;
	}

	/**
	 * Label options.
	 *
	 * @return array
	 */
	protected function get_label_options(): array {
		if ( ! $this->tasks_storage_ready() ) {
			return array();
		}

		if ( ! doublescale_module_data_ready( 'tasks' ) ) {
			return array();
		}

		$options = array();
		foreach ( \DoubleScale\Pro\Modules\Tasks\Models\TaskLabelModel::orderBy( 'title', 'asc' )->get() as $label ) {
			$options[ $label->id ] = $label->title ?: ( '#' . $label->color );
		}
		return $options;
	}

	/**
	 * Shared infinite-scroll assignee field config (same endpoint as the Tasks UI).
	 *
	 * @param bool $required Whether the field is required.
	 * @return array
	 */
	protected function get_assignee_field( bool $required = false ): array {
		$field = array(
			'label'      => $this->t( 'Assignee' ),
			'type'       => 'infinite_scroll_select',
			// "Owner of the linked record" first, then the team members who open Tasks.
			'endpoint'   => '/doublescale/v1/tasks/automation-assignees',
			'placeholder'=> $this->t( 'Search and select assignee…' ),
			'tooltip'    => __( 'Someone who cannot open the deal, project or contact of a run gets no task: it goes to that record\'s owner, and the run log says so.', 'doublescale' ),
			'settings'   => array(
				'dataPath'        => 'users',
				'totalPath'       => 'pagination.total',
				'searchParamName' => 'search',
				'perPage'         => 20,
			),
		);

		if ( $required ) {
			$field['required'] = true;
		}

		return $field;
	}

	/**
	 * Who a step gives a task on this record to: its pick when they can open
	 * the record, else the record's owner ("Owner of the linked record" asks
	 * for the owner outright). Nobody can be asked to pick again while the
	 * automation runs, so the step says what it did instead.
	 *
	 * @param int $picked      The step's assignee setting.
	 * @param int $entity_type Task entity type.
	 * @param int $entity_id   Record id.
	 * @return array{0:int, 1:string} The assignee (0 = nobody can take it) and
	 *                                a note for the run log ('' = the pick).
	 */
	protected function resolve_assignee( int $picked, int $entity_type, int $entity_id ): array {
		if ( TaskAssigneeCandidates::RECORD_OWNER !== $picked && null === TaskAssigneeRule::error( $entity_type, $entity_id, $picked ) ) {
			return array( $picked, '' );
		}

		$owner = TaskAssigneeRule::allowed_or_owner( $entity_type, $entity_id, 0 );

		if ( TaskAssigneeCandidates::RECORD_OWNER === $picked ) {
			return array( $owner, $owner > 0 ? '' : __( 'The linked record has no owner who can take the task.', 'doublescale' ) );
		}

		if ( $owner > 0 ) {
			return array(
				$owner,
				sprintf(
					/* translators: 1: person picked in the step, 2: the record's owner, who got the task */
					__( '%1$s cannot work on this record, so the task went to its owner, %2$s.', 'doublescale' ),
					TaskAssigneeRule::name_of( $picked ),
					TaskAssigneeRule::name_of( $owner )
				),
			);
		}

		$error = TaskAssigneeRule::error( $entity_type, $entity_id, $picked );
		return array( 0, $error ? $error->get_error_message() : __( 'The linked record has no owner who can take the task.', 'doublescale' ) );
	}
}
