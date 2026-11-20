<?php
/**
 * Who may see a task.
 *
 * The rules the task REST controller applies, in one place other modules
 * (time tracking) can ask instead of copying them.
 *
 * @package DoubleScale\Pro\Modules\Tasks
 */

namespace DoubleScale\Pro\Modules\Tasks\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\TaskEntityType;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Pro\Core\UserRoles\PermissionsCompat;
use DoubleScale\Pro\Modules\Projects\Capabilities;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;
use DoubleScale\Pro\Modules\Tasks\Models\SubtaskModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;

/**
 * Task visibility rules.
 */
final class TaskAccess {

	/** @var array<string, bool> "user:project:level" => allowed, per request */
	private static $memo = array();

	/**
	 * Roles that work on tasks: every team role (the free plugin's
	 * can_access_tasks_api). Outside the task managers they see their own —
	 * {@see is_own_tasks_only()}.
	 *
	 * @return bool
	 */
	public static function has_task_role(): bool {
		return Permissions::can_access_tasks_api()
			|| PermissionsCompat::has_agent_access()
			|| Permissions::is_project_manager()
			|| Permissions::is_project_member();
	}

	/**
	 * Whether the current user works only on their own tasks: anyone but the task managers (Sales and Project Managers).
	 * They see tasks assigned to them (or with a subtask assigned to them) and the tasks of the projects they own or
	 * are on the team of, assign tasks only to themselves, and delete only their own (a project owner also deletes
	 * the tasks of their project).
	 *
	 * @return bool
	 */
	public static function is_own_tasks_only(): bool {
		// One rule with the free plugin: only the task managers see every task.
		if ( method_exists( Permissions::class, 'sees_all_records' ) ) {
			return ! Permissions::sees_all_records( 'tasks' );
		}
		if ( Permissions::is_sales_rep() || PermissionsCompat::is_crm_agent() ) {
			return true;
		}
		return Permissions::is_project_member()
			&& ! Permissions::has_sales_rep_access()
			&& ! Permissions::is_project_manager()
			&& ! Permissions::can_manage_all_projects();
	}

	/**
	 * Limit a task query to what an own-tasks-only user sees: tasks assigned to
	 * them, tasks with a subtask assigned to them, and (when they can open
	 * projects) every task of the projects they own or are on the team of.
	 *
	 * @param \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder $query   Task query.
	 * @param int|null                              $user_id User (null = current user).
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder
	 */
	public static function apply_visibility( $query, ?int $user_id = null ) {
		$user_id      = null === $user_id ? get_current_user_id() : $user_id;
		$via_projects = self::reaches_projects( $user_id );

		return $query->where(
			static function ( $q ) use ( $user_id, $via_projects ) {
				$q->where( 'assigned_to', $user_id )
					->orWhereIn( 'id', SubtaskModel::where( 'assigned_to', $user_id )->select( 'task_id' ) );
				if ( $via_projects ) {
					$q->orWhere(
						static function ( $p ) use ( $user_id ) {
							$p->where( 'entity_type', TaskEntityType::PROJECT )
								->whereIn( 'entity_id', ProjectModel::query()->readableBy( $user_id )->select( 'id' ) );
						}
					);
				}
			}
		);
	}

	/**
	 * Limit a task query to what an own-tasks-only user may change (complete,
	 * reopen, edit): their own tasks and their projects' tasks.
	 *
	 * @param \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder $query   Task query.
	 * @param int|null                              $user_id User (null = current user).
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder
	 */
	public static function apply_editable( $query, ?int $user_id = null ) {
		$user_id      = null === $user_id ? get_current_user_id() : $user_id;
		$via_projects = self::reaches_projects( $user_id );

		return $query->where(
			static function ( $q ) use ( $user_id, $via_projects ) {
				$q->where( 'assigned_to', $user_id );
				if ( $via_projects ) {
					$q->orWhere(
						static function ( $p ) use ( $user_id ) {
							$p->where( 'entity_type', TaskEntityType::PROJECT )
								->whereIn( 'entity_id', ProjectModel::query()->readableBy( $user_id )->select( 'id' ) );
						}
					);
				}
			}
		);
	}

	/**
	 * Limit a task query to what an own-tasks-only user may delete: their own
	 * tasks, and the tasks of projects they own.
	 *
	 * @param \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder $query   Task query.
	 * @param int|null                              $user_id User (null = current user).
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder
	 */
	public static function apply_deletable( $query, ?int $user_id = null ) {
		$user_id      = null === $user_id ? get_current_user_id() : $user_id;
		$via_projects = self::reaches_projects( $user_id ) && user_can( $user_id, 'doublescale_project_manage_own_projects' );

		return $query->where(
			static function ( $q ) use ( $user_id, $via_projects ) {
				$q->where( 'assigned_to', $user_id );
				if ( $via_projects ) {
					$q->orWhere(
						static function ( $p ) use ( $user_id ) {
							$p->where( 'entity_type', TaskEntityType::PROJECT )
								->whereIn( 'entity_id', ProjectModel::query()->where( 'owner_id', $user_id )->select( 'id' ) );
						}
					);
				}
			}
		);
	}

	/**
	 * Whether the current user may open this task.
	 *
	 * @param TaskModel $task Task.
	 * @return bool
	 */
	public static function can_view( TaskModel $task ): bool {
		if ( self::has_task_role() ) {
			return ! self::is_own_tasks_only()
				|| TaskModel::salesRepCanView( $task )
				|| self::project_allows( $task, 'read' );
		}

		if ( ! Permissions::has_project_access() || TaskEntityType::PROJECT !== (int) $task->entity_type ) {
			return false;
		}

		return Capabilities::can_read_project( (int) $task->entity_id );
	}

	/**
	 * Whether the current user may change this task (edit, complete, its
	 * subtasks): their own, or a task of a project they work on.
	 *
	 * @param TaskModel $task Task.
	 * @return bool
	 */
	public static function can_edit( TaskModel $task ): bool {
		return ! self::is_own_tasks_only()
			|| TaskModel::salesRepOwns( $task )
			|| self::project_allows( $task, 'contribute' );
	}

	/**
	 * Whether the current user may delete this task: their own, or a task of
	 * a project they own (team members do not delete each other's work).
	 *
	 * @param TaskModel $task Task.
	 * @return bool
	 */
	public static function can_delete( TaskModel $task ): bool {
		return ! self::is_own_tasks_only()
			|| TaskModel::salesRepOwns( $task )
			|| self::project_allows( $task, 'manage' );
	}

	/**
	 * Whether the current user may change who works on a subtask, or delete
	 * it. Teammates do not take each other off their work: an agent changes
	 * only subtasks that are theirs or nobody's, unless they own the task or
	 * the project.
	 *
	 * @param TaskModel $task    Parent task.
	 * @param object    $subtask Subtask.
	 * @return bool
	 */
	public static function can_reassign_subtask( TaskModel $task, $subtask ): bool {
		if ( ! self::is_own_tasks_only() || TaskModel::salesRepOwns( $task ) ) {
			return true;
		}
		$assignee = (int) ( $subtask->assigned_to ?? 0 );
		if ( 0 === $assignee || get_current_user_id() === $assignee ) {
			return true;
		}
		return self::project_allows( $task, 'manage' );
	}

	/**
	 * Whether the task's project gives the current user this level.
	 *
	 * @param TaskModel $task  Task.
	 * @param string    $level 'read', 'contribute' or 'manage'.
	 * @return bool
	 */
	private static function project_allows( TaskModel $task, string $level ): bool {
		if ( TaskEntityType::PROJECT !== (int) $task->entity_type || ! self::reaches_projects( get_current_user_id() ) ) {
			return false;
		}

		$project_id = (int) $task->entity_id;
		$key        = get_current_user_id() . ':' . $project_id . ':' . $level;
		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}
		self::listen_for_changes();

		if ( 'manage' === $level ) {
			$allowed = Capabilities::can_manage_project( $project_id );
		} elseif ( 'contribute' === $level && method_exists( Capabilities::class, 'can_contribute_project' ) ) {
			$allowed = Capabilities::can_contribute_project( $project_id );
		} else {
			$allowed = Capabilities::can_read_project( $project_id );
		}

		self::$memo[ $key ] = $allowed;
		return $allowed;
	}

	/**
	 * Forget the per-request answers (a team or an owner changed).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$memo = array();
	}

	/**
	 * @return void
	 */
	private static function listen_for_changes(): void {
		static $listening = false;
		if ( $listening ) {
			return;
		}
		$listening = true;
		foreach ( array( 'doublescale_project_member_added', 'doublescale_project_member_removed', 'doublescale_project_updated', 'doublescale_project_deleted' ) as $hook ) {
			add_action( $hook, array( self::class, 'flush' ), 1, 0 );
		}
	}

	/**
	 * Whether projects are on and this user can open them.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	private static function reaches_projects( int $user_id ): bool {
		return $user_id > 0
			&& doublescale_module_data_ready( 'projects' )
			&& ( ! function_exists( 'doublescale_is_module_active' ) || doublescale_is_module_active( 'projects' ) )
			&& PermissionsCompat::has_project_access( $user_id );
	}
}
