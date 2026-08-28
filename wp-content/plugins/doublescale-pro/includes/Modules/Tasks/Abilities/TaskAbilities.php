<?php
/**
 * Read-only task abilities.
 *
 * @package DoubleScale\Pro\Modules\Tasks
 */

namespace DoubleScale\Pro\Modules\Tasks\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\AbilityBulk;
use DoubleScale\Core\Abilities\AbilityCategories;
use DoubleScale\Core\Abilities\AbilityInput;
use DoubleScale\Core\Abilities\AbilityResult;
use DoubleScale\Core\Abilities\AbilityScope;
use DoubleScale\Core\Constants\TaskEntityType;
use DoubleScale\Core\Constants\TaskPriority;
use DoubleScale\Core\Constants\TaskStatus;
use DoubleScale\Core\Constants\TaskType;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Contacts\Abilities\ContactAbilities;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskStatusModel;

/**
 * Tasks are the module an agent is asked about most after deals.
 *
 * Gate 3 keys on `assigned_to`. Entity linkage is polymorphic — `entity_type`
 * is an integer from {@see TaskEntityType} and `entity_id` points at a contact,
 * deal, or project — so the shaper resolves it by type rather than assuming.
 */
final class TaskAbilities {

	/**
	 * Ability definitions.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$permission = array( Permissions::class, 'can_access_tasks_api' );

		$definitions = array(
			'doublescale/list-tasks'         => array(
				'module_slug'      => 'tasks',
				'label'            => __( 'List tasks', 'doublescale' ),
				'description'      => __( 'List tasks with title, status, priority, due date, and what they are linked to. Unless you can manage all tasks you see only tasks assigned to you.', 'doublescale' ),
				'category'         => AbilityCategories::TASKS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'status'       => array(
							'type'        => 'string',
							'description' => 'Filter by task status.',
							'enum'        => TaskStatus::all(),
						),
						'priority'     => array(
							'type'        => 'string',
							'description' => 'Filter by priority.',
							'enum'        => array( TaskPriority::LOW, TaskPriority::MEDIUM, TaskPriority::HIGH ),
						),
						'overdue_only' => array(
							'type'        => 'boolean',
							'description' => 'Only tasks whose due date has passed and are not complete.',
						),
						// Tasks attach polymorphically, so "tasks for contact 5"
						// is entity_type + entity_id. Naming the three links
						// directly keeps this consistent with list-invoices,
						// list-deals, list-tickets, and list-bookings, which all
						// take a plain contact_id.
						'contact_id'   => array(
							'type'        => 'integer',
							'description' => 'Only tasks linked to this contact.',
						),
						'deal_id'      => array(
							'type'        => 'integer',
							'description' => 'Only tasks linked to this deal.',
						),
						'project_id'   => array(
							'type'        => 'integer',
							'description' => 'Only tasks linked to this project.',
						),
						'search'       => array(
							'type'        => 'string',
							'description' => 'Match on task title.',
						),
						'limit'        => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'offset'       => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
				),
				'execute_callback' => array( self::class, 'list_tasks' ),
			),

			'doublescale/get-task'           => array(
				'module_slug'      => 'tasks',
				'label'            => __( 'Get task', 'doublescale' ),
				'description'      => __( 'One task with its description, status, priority, due date, assignee, subtasks, and linked record.', 'doublescale' ),
				'category'         => AbilityCategories::TASKS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'Task id.',
						),
					),
					'required'   => array( 'id' ),
				),
				'execute_callback' => array( self::class, 'get_task' ),
			),

			'doublescale/list-task-statuses' => array(
				'module_slug'      => 'tasks',
				'label'            => __( 'List task statuses', 'doublescale' ),
				'description'      => __( 'The task status vocabulary plus any custom kanban statuses configured on this site. Call this before filtering tasks by status.', 'doublescale' ),
				'category'         => AbilityCategories::TASKS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
				'execute_callback' => array( self::class, 'list_task_statuses' ),
			),

			'doublescale/get-task-summary'   => array(
				'module_slug'      => 'tasks',
				'label'            => __( 'Get task summary', 'doublescale' ),
				'description'      => __( 'Task counts grouped by status and priority, plus how many are overdue. Scoped to what you can see.', 'doublescale' ),
				'category'         => AbilityCategories::TASKS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
				'execute_callback' => array( self::class, 'get_task_summary' ),
			),

			'doublescale/create-task'        => array(
				'module_slug'      => 'tasks',
				'label'            => __( 'Create a task', 'doublescale' ),
				'description'      => __( 'Create a task attached to a contact, deal, or project — every task must belong to one. Creating a task notifies the assignee and can start an automation, so only create one when the user has asked for it.', 'doublescale' ),
				'category'         => AbilityCategories::TASKS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'title'       => array(
							'type'        => 'string',
							'description' => 'What needs doing.',
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'Longer detail, optional.',
						),
						'due_date'    => array(
							'type'        => 'string',
							'description' => 'Due date as YYYY-MM-DD. Every task needs one.',
						),
						'priority'    => array(
							'type'        => 'string',
							'description' => 'Task priority.',
							'enum'        => array( TaskPriority::LOW, TaskPriority::MEDIUM, TaskPriority::HIGH ),
						),
						'task_type'   => array(
							'type'        => 'string',
							'description' => 'What kind of task this is. Defaults to a to-do.',
							'enum'        => TaskType::get_all(),
						),
						'assigned_to' => array(
							'type'        => 'integer',
							'description' => 'WordPress user id to assign to. Defaults to you.',
						),
						'entity_type' => array(
							'type'        => 'string',
							'description' => 'What the task belongs to. Every task must be attached to something.',
							'enum'        => array( 'contact', 'deal', 'project' ),
						),
						'entity_id'   => array(
							'type'        => 'integer',
							'description' => 'Id of the contact, deal, or project this task belongs to.',
						),
					),
					// Mirrors TaskModel::$rules exactly. Every one of these is
					// `required` there and the model THROWS when one is absent —
					// which the ability wrapper turns into an opaque 500. Declaring
					// them here means the agent is told which field it forgot.
					'required'   => array( 'title', 'entity_type', 'entity_id', 'due_date' ),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						'destructive'   => false,
						'idempotent'    => false,
						// Fires a notification to the assignee AND the
						// Task/TaskCreated automation trigger, which can send
						// email. Effects reach outside the record.
						'openWorldHint' => true,
					),
				),
				'execute_callback' => array( self::class, 'create_task' ),
			),

			'doublescale/update-task'        => array(
				'module_slug'      => 'tasks',
				'label'            => __( 'Update a task', 'doublescale' ),
				'description'      => __( 'Change a task\'s title, status, priority, due date, or assignee. Updating a task can start an automation and notify the assignee. You can only update tasks assigned to you unless you manage all tasks.', 'doublescale' ),
				'category'         => AbilityCategories::TASKS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array(
							'type'        => 'integer',
							'description' => 'Task id.',
						),
						'title'       => array(
							'type'        => 'string',
							'description' => 'New title.',
						),
						'status'      => array(
							'type'        => 'string',
							'description' => 'New status.',
							'enum'        => TaskStatus::all(),
						),
						'priority'    => array(
							'type'        => 'string',
							'description' => 'New priority.',
							'enum'        => array( TaskPriority::LOW, TaskPriority::MEDIUM, TaskPriority::HIGH ),
						),
						'due_date'    => array(
							'type'        => 'string',
							'description' => 'New due date as YYYY-MM-DD.',
						),
						'assigned_to' => array(
							'type'        => 'integer',
							'description' => 'WordPress user id to reassign to.',
						),
					),
					'required'   => array( 'id' ),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						// Overwrites existing values, but the record survives.
						'destructive'   => false,
						'idempotent'    => true,
						'openWorldHint' => true,
					),
				),
				'execute_callback' => array( self::class, 'update_task' ),
			)
		);


		// Bulk write helpers live in free (AbilityBulk). Omit when free is outdated.
		if ( class_exists( AbilityBulk::class ) ) {
			$definitions = array_merge(
				$definitions,
				array(
				'doublescale/create-tasks-bulk'  => array(
					'module_slug'      => 'tasks',
					'label'            => __( 'Create tasks in bulk', 'doublescale' ),
					'description'      => __( 'Create many tasks in one call. Provide exactly one of: tasks (per-row objects, each attached to a contact, deal, or project), contact_ids, or a contact filter. With contact_ids or filter, one task is created per matched contact using the shared title and due_date. Set dry_run to preview without writing. Each created task notifies the assignee and can start an automation. Rows are processed independently — some may succeed while others fail. Check errors before reporting success.', 'doublescale' ),
					'category'         => AbilityCategories::TASKS,
					'permission'       => $permission,
					'input_schema'     => array(
						'type'       => 'object',
						'properties' => array(
							'tasks'       => array(
								'type'        => 'array',
								'minItems'    => 1,
								'maxItems'    => AbilityBulk::max_items( 'doublescale/create-tasks-bulk' ),
								'description' => 'One object per task. Each accepts: title (required), entity_type '
									. '(contact|deal|project, required), entity_id (required), due_date '
									. '(YYYY-MM-DD, required), description, priority, task_type, assigned_to. '
									. 'Mutually exclusive with contact_ids and filter.',
							),
							'contact_ids' => AbilityBulk::ids_property(
								'doublescale/create-tasks-bulk',
								'Create one task per contact id. Mutually exclusive with tasks and filter. Requires title and due_date.'
							),
							'filter'      => AbilityBulk::filter_property(
								'Same criteria as list-contacts. One task is created per matched contact. Mutually exclusive with tasks and contact_ids. An empty filter is refused.',
								ContactAbilities::filter_schema_properties()
							),
							'title'       => array(
								'type'        => 'string',
								'description' => 'Task title applied to every matched contact (contact_ids or filter).',
							),
							'description' => array(
								'type'        => 'string',
								'description' => 'Task description applied to every matched contact (contact_ids or filter).',
							),
							'due_date'    => array(
								'type'        => 'string',
								'description' => 'Due date as YYYY-MM-DD, applied to every matched contact (contact_ids or filter).',
							),
							'priority'    => array(
								'type'        => 'string',
								'description' => 'Priority applied to every matched contact (contact_ids or filter).',
								'enum'        => array( TaskPriority::LOW, TaskPriority::MEDIUM, TaskPriority::HIGH ),
							),
							'task_type'   => array(
								'type'        => 'string',
								'description' => 'Task type applied to every matched contact (contact_ids or filter).',
								'enum'        => TaskType::get_all(),
							),
							'assigned_to' => array(
								'type'        => 'integer',
								'description' => 'Assignee applied to every matched contact (contact_ids or filter). Defaults to you.',
							),
							'dry_run'     => AbilityBulk::dry_run_property(),
						),
					),
					'meta'             => array(
						'annotations' => array(
							'readonly'      => false,
							'destructive'   => false,
							'idempotent'    => false,
							// Per-row create_task notifies the assignee and can
							// fire the Task/TaskCreated automation trigger.
							'openWorldHint' => true,
							'bulk'          => true,
						),
					),
					'execute_callback' => array( self::class, 'create_tasks_bulk' ),
				),

				'doublescale/update-tasks-bulk'  => array(
					'module_slug'      => 'tasks',
					'label'            => __( 'Update tasks in bulk', 'doublescale' ),
					'description'      => __( 'Change status, priority, due date, or assignee on many tasks in one call. Provide exactly one of: tasks (per-row objects), task_ids, or filter. With task_ids or filter, the patch fields apply to every match. The filter is scoped to tasks you can see — a sales rep cannot update someone else\'s tasks this way. Set dry_run to preview without writing. Updating a task can notify the assignee and start an automation. Rows are processed independently — some may succeed while others fail. Check errors before reporting success.', 'doublescale' ),
					'category'         => AbilityCategories::TASKS,
					'permission'       => $permission,
					'input_schema'     => array(
						'type'       => 'object',
						'properties' => array(
							'tasks'       => array(
								'type'        => 'array',
								'minItems'    => 1,
								'maxItems'    => AbilityBulk::max_items( 'doublescale/update-tasks-bulk' ),
								'description' => 'One object per task. Each accepts: id (required), title, '
									. 'status, priority, due_date (YYYY-MM-DD), assigned_to. Mutually exclusive '
									. 'with task_ids and filter.',
								// NO 'items' key — WP validates items before the callback
								// runs, so one bad row would reject the whole batch.
							),
							'task_ids'    => AbilityBulk::ids_property(
								'doublescale/update-tasks-bulk',
								'Task ids to apply the same patch to. Mutually exclusive with tasks and filter. Each id is still checked against what you may edit.'
							),
							'filter'      => AbilityBulk::filter_property(
								'Same criteria as list-tasks, including owner scoping. Mutually exclusive with tasks and task_ids. An empty filter is refused.',
								self::filter_schema_properties()
							),
							'title'       => array(
								'type'        => 'string',
								'description' => 'Patch: new title for every matched task (task_ids or filter).',
							),
							'status'      => array(
								'type'        => 'string',
								'description' => 'Patch: new status for every matched task (task_ids or filter).',
								'enum'        => TaskStatus::all(),
							),
							'priority'    => array(
								'type'        => 'string',
								'description' => 'Patch: new priority for every matched task (task_ids or filter).',
								'enum'        => array( TaskPriority::LOW, TaskPriority::MEDIUM, TaskPriority::HIGH ),
							),
							'due_date'    => array(
								'type'        => 'string',
								'description' => 'Patch: new due date as YYYY-MM-DD for every matched task (task_ids or filter).',
							),
							'assigned_to' => array(
								'type'        => 'integer',
								'description' => 'Patch: reassign every matched task (task_ids or filter). Reassigning to someone else needs permission.',
							),
							'dry_run'     => AbilityBulk::dry_run_property(),
						),
					),
					'meta'             => array(
						'annotations' => array(
							'readonly'      => false,
							// Overwrites values, but the task survives.
							'destructive'   => false,
							'idempotent'    => true,
							// Per-row update_task can notify the assignee and fire
							// the Task automation triggers.
							'openWorldHint' => true,
							'bulk'          => true,
						),
					),
					'execute_callback' => array( self::class, 'update_tasks_bulk' ),
				),
				)
			);
		}

		return $definitions;
	}

	/**
	 * Whether the caller sees every task or only their own.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private static function sees_all(): bool {
		return Permissions::can_assign_task_assignee();
	}

	/**
	 * List tasks.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_tasks( array $input ): array {
		$limit  = AbilityResult::limit( $input );
		$offset = AbilityResult::offset( $input );

		$query    = self::query_for_filter( $input )->with( array( 'assignedUser', 'kanbanStatus' ) );
		$sees_all = self::sees_all();

		$total = (int) $query->count();

		$rows = $query->orderBy( 'created_at', 'desc' )
			->limit( $limit )
			->offset( $offset )
			->get();

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::shape_task( $row );
		}

		return AbilityResult::collection(
			$items,
			$total,
			$limit,
			$offset,
			array( 'scope' => AbilityScope::label( $sees_all ) )
		);
	}

	/**
	 * Get one task.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_task( array $input ) {
		$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
		if ( $id <= 0 ) {
			return AbilityResult::not_found( __( 'Provide a valid task id.', 'doublescale' ) );
		}

		$task = TaskModel::query()
			->with( array( 'assignedUser', 'kanbanStatus', 'subtasks' ) )
			->where( 'id', $id )
			->first();

		if ( ! $task ) {
			return AbilityResult::not_found( __( 'No task found with that id.', 'doublescale' ) );
		}

		$forbidden = AbilityScope::assert_owns(
			$task,
			'assigned_to',
			self::sees_all(),
			__( 'This task is not assigned to you.', 'doublescale' )
		);
		if ( $forbidden ) {
			return $forbidden;
		}

		$data = self::shape_task( $task );

		$body                = AbilityResult::truncate( (string) ( $task->description ?? '' ) );
		$data['description'] = $body['text'];
		$data['truncated']   = $body['truncated'];

		$subtasks = array();
		foreach ( ( $task->subtasks ?? array() ) as $subtask ) {
			if ( ! is_object( $subtask ) ) {
				continue;
			}
			$subtasks[] = array(
				'id'        => (int) $subtask->id,
				'title'     => $subtask->title,
				'completed' => (bool) ( $subtask->is_completed ?? $subtask->completed ?? false ),
			);
		}
		$data['subtasks'] = $subtasks;

		return $data;
	}

	/**
	 * The status vocabulary, plus site-configured kanban statuses.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_task_statuses( array $input ): array {
		unset( $input );

		$kanban = array();
		foreach ( TaskStatusModel::query()->get() as $status ) {
			$kanban[] = array(
				'id'   => (int) $status->id,
				'name' => $status->name ?? $status->title ?? '',
			);
		}

		return array(
			'statuses'        => TaskStatus::all(),
			'priorities'      => array( TaskPriority::LOW, TaskPriority::MEDIUM, TaskPriority::HIGH ),
			'kanban_statuses' => $kanban,
			'entity_types'    => array(
				'contact' => TaskEntityType::CONTACT,
				'deal'    => TaskEntityType::DEAL,
				'project' => TaskEntityType::PROJECT,
			),
		);
	}

	/**
	 * Task counts by status and priority.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function get_task_summary( array $input ): array {
		unset( $input );

		$sees_all = self::sees_all();
		$query    = TaskModel::query();
		AbilityScope::apply( $query, 'assigned_to', $sees_all );

		$by_status   = array();
		$by_priority = array();
		$overdue     = 0;
		$total       = 0;
		$today       = gmdate( 'Y-m-d' );

		foreach ( $query->get() as $task ) {
			$status   = (string) $task->status;
			$priority = (string) $task->priority;

			$by_status[ $status ]     = ( $by_status[ $status ] ?? 0 ) + 1;
			$by_priority[ $priority ] = ( $by_priority[ $priority ] ?? 0 ) + 1;
			++$total;

			if ( ! empty( $task->due_date ) && $task->due_date < $today && empty( $task->completed_at ) ) {
				++$overdue;
			}
		}

		return array(
			'total'       => $total,
			'overdue'     => $overdue,
			'by_status'   => $by_status,
			'by_priority' => $by_priority,
			'scope'       => AbilityScope::label( $sees_all ),
		);
	}

	/**
	 * Entity type name => the integer the column stores.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, int>
	 */
	private static function entity_types(): array {
		return array(
			'contact' => TaskEntityType::CONTACT,
			'deal'    => TaskEntityType::DEAL,
			'project' => TaskEntityType::PROJECT,
		);
	}

	/**
	 * Confirm the record a task is being attached to actually exists.
	 *
	 * The model validates that entity_type/entity_id are PRESENT, not that they
	 * point at anything real, so an agent that mistypes an id would create a
	 * task hanging off nothing — visible in no list and reachable from no
	 * record.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type Entity type name.
	 * @param int    $id   Entity id.
	 * @return \WP_Error|null Null when the record exists.
	 */
	private static function assert_entity_exists( string $type, int $id ): ?\WP_Error {
		$models = array(
			'contact' => '\DoubleScale\Modules\Contacts\Models\ContactModel',
			'deal'    => '\DoubleScale\Pro\Modules\Deals\Models\DealModel',
			'project' => '\DoubleScale\Pro\Modules\Projects\Models\ProjectModel',
		);

		$model = $models[ $type ] ?? null;
		if ( null === $model || ! class_exists( $model ) ) {
			// The owning module is not loaded, so we cannot verify and must not
			// guess — refuse rather than create a link we cannot check.
			return AbilityResult::not_found(
				sprintf(
					/* translators: %s: entity type name */
					__( 'Tasks cannot be attached to a %s on this site.', 'doublescale' ),
					$type
				)
			);
		}

		if ( $model::query()->where( 'id', $id )->count() > 0 ) {
			return null;
		}

		return AbilityResult::not_found(
			sprintf(
				/* translators: 1: entity type name, 2: record id */
				__( 'No %1$s exists with id %2$d.', 'doublescale' ),
				$type,
				$id
			)
		);
	}

	/**
	 * Create a task.
	 *
	 * Everything is validated here rather than left to the model: TaskModel
	 * validates inside its saving() hook and THROWS, which the ability wrapper
	 * turns into an opaque 500 with a correlation id — useless to an agent that
	 * just needs to be told which field was wrong.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function create_task( array $input ) {
		$types = self::entity_types();

		$invalid = AbilityInput::first_error(
			array(
				AbilityInput::required( $input, array( 'title', 'entity_type', 'entity_id', 'due_date' ) ),
				AbilityInput::date( $input['due_date'] ?? null, 'due_date' ),
				AbilityInput::enum(
					$input['priority'] ?? null,
					array( TaskPriority::LOW, TaskPriority::MEDIUM, TaskPriority::HIGH ),
					'priority'
				),
				AbilityInput::enum( $input['entity_type'] ?? null, array_keys( $types ), 'entity_type' ),
				AbilityInput::enum( $input['task_type'] ?? null, TaskType::get_all(), 'task_type' ),
				AbilityInput::id( $input['entity_id'] ?? null, 'entity_id' ),
				AbilityInput::id( $input['assigned_to'] ?? null, 'assigned_to' ),
			)
		);
		if ( $invalid ) {
			return $invalid;
		}

		$entity_missing = self::assert_entity_exists(
			(string) $input['entity_type'],
			(int) $input['entity_id']
		);
		if ( $entity_missing ) {
			return $entity_missing;
		}

		$assigned_to = isset( $input['assigned_to'] ) ? (int) $input['assigned_to'] : get_current_user_id();

		// Reassigning to someone else is a separate permission from creating.
		if ( $assigned_to !== (int) get_current_user_id() && ! Permissions::can_assign_task_assignee() ) {
			return AbilityResult::forbidden(
				__( 'You can only assign tasks to yourself.', 'doublescale' )
			);
		}

		if ( AbilityBulk::is_preview( $input ) ) {
			return array(
				'created'      => false,
				'would_create' => true,
				'title'        => (string) $input['title'],
				'assigned_to'  => $assigned_to,
				'due_date'     => (string) $input['due_date'],
			);
		}

		$data = array(
			'title'       => (string) $input['title'],
			'description' => isset( $input['description'] ) ? (string) $input['description'] : '',
			'status'      => TaskStatus::PENDING,
			'priority'    => isset( $input['priority'] ) ? (string) $input['priority'] : TaskPriority::MEDIUM,
			// Required by the model; TaskType::TODO is its own default.
			'task_type'   => isset( $input['task_type'] ) ? (string) $input['task_type'] : TaskType::get_default(),
			'assigned_to' => $assigned_to,
		);

		$data['due_date']    = (string) $input['due_date'];
		$data['entity_type'] = $types[ (string) $input['entity_type'] ];
		$data['entity_id']   = (int) $input['entity_id'];

		$task = TaskModel::create( $data );

		if ( ! $task ) {
			return new \WP_Error(
				'doublescale_task_not_created',
				__( 'The task could not be created.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		// NOTE: do NOT fire doublescale_task_created here. TaskModel fires it
		// itself (TaskModel.php:901), so firing again sends the assignee two
		// notifications and runs the automation trigger twice.

		return array(
			'created'     => true,
			'task_id'     => (int) $task->id,
			'title'       => $task->title,
			'assigned_to' => $assigned_to,
			'due_date'    => $task->due_date,
		);
	}

	/**
	 * Create many tasks.
	 *
	 * Loops {@see create_task()} per row so the per-row assignee gate stays
	 * intact. Must not fire doublescale_task_created itself — TaskModel already
	 * does, and firing again would notify twice and run automations twice.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function create_tasks_bulk( array $input ) {
		if ( ! class_exists( AbilityBulk::class ) ) {
			return new \WP_Error(
				'doublescale_ability_unavailable',
				__( 'Bulk task abilities require a newer DoubleScale (free) plugin.', 'doublescale' )
			);
		}

		$using_contacts = array_key_exists( 'contact_ids', $input ) || array_key_exists( 'filter', $input );
		if ( $using_contacts ) {
			$missing = AbilityInput::required( $input, array( 'title', 'due_date' ) );
			if ( $missing ) {
				return $missing;
			}
		}

		$expanded = AbilityBulk::expand(
			$input,
			'doublescale/create-tasks-bulk',
			array(
				'rows_key'       => 'tasks',
				'ids_key'        => 'contact_ids',
				'id_field'       => 'entity_id',
				'patch_keys'     => array( 'title', 'description', 'due_date', 'priority', 'task_type', 'assigned_to' ),
				'patch_required' => $using_contacts,
				'querier'        => array( ContactAbilities::class, 'query_for_filter' ),
			)
		);
		if ( $expanded instanceof \WP_Error ) {
			return $expanded;
		}

		if ( $using_contacts && empty( $expanded['count_only'] ) ) {
			foreach ( array_keys( $expanded['rows'] ) as $index ) {
				$expanded['rows'][ $index ]['entity_type'] = 'contact';
			}
		}

		return AbilityBulk::dispatch(
			$expanded,
			$input,
			'doublescale/create-tasks-bulk',
			static function ( array $row ) {
				return self::create_task( $row );
			},
			'created',
			array(
				'id_key'      => 'task_id',
				'applied_key' => 'applied_task_ids',
			)
		);
	}

	/**
	 * Update many tasks.
	 *
	 * Loops {@see update_task()} per row. That is load-bearing rather than
	 * merely convenient: update_task applies TWO per-row permission checks —
	 * ownership of the task being edited, and a separate reassignment gate that
	 * compares against the loaded task's CURRENT assignee. Neither can be
	 * hoisted out of the loop, so calling the single-record callback is what
	 * keeps them enforced for every row.
	 *
	 * Fires no hooks of its own: TaskModel fires doublescale_task_updated on
	 * save, and firing it here would double every notification and automation.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function update_tasks_bulk( array $input ) {
		if ( ! class_exists( AbilityBulk::class ) ) {
			return new \WP_Error(
				'doublescale_ability_unavailable',
				__( 'Bulk task abilities require a newer DoubleScale (free) plugin.', 'doublescale' )
			);
		}

		return AbilityBulk::run_targeted(
			$input,
			'doublescale/update-tasks-bulk',
			static function ( array $row ) {
				return self::update_task( $row );
			},
			'updated',
			array(
				'rows_key'       => 'tasks',
				'ids_key'        => 'task_ids',
				'id_field'       => 'id',
				'patch_keys'     => array( 'title', 'status', 'priority', 'due_date', 'assigned_to' ),
				'patch_required' => true,
				'querier'        => array( self::class, 'query_for_filter' ),
			),
			array(
				'id_key'      => 'task_id',
				'applied_key' => 'applied_task_ids',
			)
		);
	}

	/**
	 * Update a task.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function update_task( array $input ) {
		$invalid = AbilityInput::first_error(
			array(
				AbilityInput::required( $input, array( 'id' ) ),
				AbilityInput::id( $input['id'] ?? null, 'id' ),
				AbilityInput::date( $input['due_date'] ?? null, 'due_date' ),
				AbilityInput::enum( $input['status'] ?? null, TaskStatus::all(), 'status' ),
				AbilityInput::enum(
					$input['priority'] ?? null,
					array( TaskPriority::LOW, TaskPriority::MEDIUM, TaskPriority::HIGH ),
					'priority'
				),
				AbilityInput::id( $input['assigned_to'] ?? null, 'assigned_to' ),
			)
		);
		if ( $invalid ) {
			return $invalid;
		}

		$task = TaskModel::query()->where( 'id', (int) $input['id'] )->first();
		if ( ! $task ) {
			return AbilityResult::not_found( __( 'No task found with that id.', 'doublescale' ) );
		}

		$forbidden = AbilityScope::assert_owns(
			$task,
			'assigned_to',
			self::sees_all(),
			__( 'This task is not assigned to you.', 'doublescale' )
		);
		if ( $forbidden ) {
			return $forbidden;
		}

		if ( isset( $input['assigned_to'] )
			&& (int) $input['assigned_to'] !== (int) $task->assigned_to
			&& ! Permissions::can_assign_task_assignee() ) {
			return AbilityResult::forbidden(
				__( 'You cannot reassign tasks to another user.', 'doublescale' )
			);
		}

		$changed = array();
		foreach ( array( 'title', 'status', 'priority', 'due_date', 'assigned_to' ) as $field ) {
			if ( ! isset( $input[ $field ] ) ) {
				continue;
			}
			$value = 'assigned_to' === $field ? (int) $input[ $field ] : (string) $input[ $field ];
			if ( $value !== $task->{$field} ) {
				$task->{$field} = $value;
				$changed[]      = $field;
			}
		}

		if ( array() === $changed ) {
			return array(
				'updated' => false,
				'task_id' => (int) $task->id,
				'message' => __( 'Nothing to change — the task already has those values.', 'doublescale' ),
			);
		}

		if ( AbilityBulk::is_preview( $input ) ) {
			return array(
				'updated'      => false,
				'would_update' => true,
				'task_id'      => (int) $task->id,
				'changed'      => $changed,
			);
		}

		// Completing a task is recorded by a timestamp, not the status alone.
		if ( in_array( 'status', $changed, true ) && TaskStatus::COMPLETED === $task->status ) {
			$task->completed_at = current_time( 'mysql', true );
		}

		// TaskModel fires doublescale_task_updated( $task, $changes ) on save
		// (TaskModel.php:810). Firing it here would both duplicate the effects
		// and crash TaskActivityLogger, which requires the second argument.
		$task->save();

		return array(
			'updated' => true,
			'task_id' => (int) $task->id,
			'changed' => $changed,
		);
	}

	/**
	 * Filter fields shared by list-tasks and bulk targeting.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function filter_schema_properties(): array {
		return array(
			'status'       => array(
				'type'        => 'string',
				'description' => 'Filter by task status.',
				'enum'        => TaskStatus::all(),
			),
			'priority'     => array(
				'type'        => 'string',
				'description' => 'Filter by priority.',
				'enum'        => array( TaskPriority::LOW, TaskPriority::MEDIUM, TaskPriority::HIGH ),
			),
			'overdue_only' => array(
				'type'        => 'boolean',
				'description' => 'Only tasks whose due date has passed and are not complete.',
			),
			'contact_id'   => array(
				'type'        => 'integer',
				'description' => 'Only tasks linked to this contact.',
			),
			'deal_id'      => array(
				'type'        => 'integer',
				'description' => 'Only tasks linked to this deal.',
			),
			'project_id'   => array(
				'type'        => 'integer',
				'description' => 'Only tasks linked to this project.',
			),
			'search'       => array(
				'type'        => 'string',
				'description' => 'Match on task title.',
			),
		);
	}

	/**
	 * Task query for list-tasks and bulk filter targeting.
	 *
	 * AbilityScope is applied last so a caller filter cannot widen it.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $filter Filter criteria.
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public static function query_for_filter( array $filter ) {
		$query = TaskModel::query();

		if ( ! empty( $filter['status'] ) ) {
			$query->where( 'status', (string) $filter['status'] );
		}
		if ( ! empty( $filter['priority'] ) ) {
			$query->where( 'priority', (string) $filter['priority'] );
		}
		if ( ! empty( $filter['overdue_only'] ) ) {
			$query->whereNotNull( 'due_date' )
				->where( 'due_date', '<', gmdate( 'Y-m-d' ) )
				->whereNull( 'completed_at' );
		}

		foreach ( array(
			'contact_id' => TaskEntityType::CONTACT,
			'deal_id'    => TaskEntityType::DEAL,
			'project_id' => TaskEntityType::PROJECT,
		) as $field => $entity_type ) {
			if ( ! empty( $filter[ $field ] ) ) {
				$query->where( 'entity_type', $entity_type )
					->where( 'entity_id', (int) $filter[ $field ] );
			}
		}

		$search = isset( $filter['search'] ) ? trim( (string) $filter['search'] ) : '';
		if ( '' !== $search ) {
			$query->where( 'title', 'LIKE', '%' . $search . '%' );
		}

		AbilityScope::apply( $query, 'assigned_to', self::sees_all() );

		return $query;
	}

	/**
	 * Shape a task row.
	 *
	 * @since 1.0.0
	 *
	 * @param object $task Task.
	 * @return array<string, mixed>
	 */
	private static function shape_task( $task ): array {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Eloquent relation accessors defined on TaskModel; renaming them is not ours to do.
		$assignee = $task->assignedUser ?? null;
		$kanban   = $task->kanbanStatus ?? null;
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		return array(
			'id'            => (int) $task->id,
			'title'         => $task->title,
			'status'        => $task->status,
			'priority'      => $task->priority,
			'due_date'      => $task->due_date,
			'completed'     => ! empty( $task->completed_at ),
			'assignee'      => is_object( $assignee )
				? array(
					'id'   => (int) $task->assigned_to,
					'name' => $assignee->display_name,
				)
				: null,
			'kanban_status' => is_object( $kanban ) ? ( $kanban->name ?? $kanban->title ?? null ) : null,
			'linked_to'     => self::shape_link( $task ),
			'created_at'    => $task->created_at,
		);
	}

	/**
	 * Describe the polymorphic entity a task hangs off.
	 *
	 * Returned as a type name plus id rather than a joined record: an agent
	 * that needs the detail can call get-deal or get-contact, and loading three
	 * possible relations per row would be an N+1 for a field most calls ignore.
	 *
	 * @since 1.0.0
	 *
	 * @param object $task Task.
	 * @return array<string, mixed>|null
	 */
	private static function shape_link( $task ): ?array {
		$entity_id = (int) ( $task->entity_id ?? 0 );
		if ( $entity_id <= 0 ) {
			return null;
		}

		switch ( (int) ( $task->entity_type ?? 0 ) ) {
			case TaskEntityType::CONTACT:
				$type = 'contact';
				break;
			case TaskEntityType::DEAL:
				$type = 'deal';
				break;
			case TaskEntityType::PROJECT:
				$type = 'project';
				break;
			default:
				return null;
		}

		return array(
			'type' => $type,
			'id'   => $entity_id,
		);
	}
}
