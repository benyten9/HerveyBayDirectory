<?php
/**
 * Who may be @mentioned in a task comment.
 *
 * @package DoubleScale\Pro\Modules\Tasks
 */

namespace DoubleScale\Pro\Modules\Tasks\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;

/**
 * A mention should only reach someone who can open the task: the task
 * managers (people who see every task) and the task's assignee. So an
 * assignee tags managers, and a manager tags the assignee or another
 * manager; nobody mentions themselves.
 */
class TaskMentions {

	/**
	 * Roles that can hold the team-wide task view; each user is still
	 * checked with Permissions::sees_all_records().
	 *
	 * @var string[]
	 */
	const MANAGER_ROLES = array(
		'administrator',
		'doublescale_crm_manager',
		'doublescale_sales_manager',
		'doublescale_project_manager',
	);

	/**
	 * User ids that may be mentioned on a task.
	 *
	 * @param TaskModel $task   Task.
	 * @param int       $viewer The person writing the comment.
	 * @return int[]
	 */
	public static function allowed_ids( TaskModel $task, int $viewer ): array {
		$ids = array();

		foreach ( get_users( array( 'role__in' => self::MANAGER_ROLES, 'fields' => 'ID' ) ) as $user_id ) {
			if ( Permissions::sees_all_records( 'tasks', (int) $user_id ) ) {
				$ids[] = (int) $user_id;
			}
		}

		$assignee = (int) $task->assigned_to;
		if ( $assignee > 0 ) {
			$ids[] = $assignee;
		}

		$ids = array_values( array_unique( $ids ) );

		return array_values(
			array_filter(
				$ids,
				static function ( $id ) use ( $viewer ) {
					return $id !== $viewer;
				}
			)
		);
	}

	/**
	 * The picker list: id, name and avatar of each mentionable user.
	 *
	 * @param TaskModel $task   Task.
	 * @param int       $viewer The person writing the comment.
	 * @return array<int, array{id:int, name:string, avatar:string}>
	 */
	public static function candidates( TaskModel $task, int $viewer ): array {
		$people = array();
		foreach ( self::allowed_ids( $task, $viewer ) as $user_id ) {
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				continue;
			}
			$people[] = array(
				'id'     => $user_id,
				'name'   => (string) $user->display_name,
				'avatar' => (string) get_avatar_url( $user_id, array( 'size' => 48 ) ),
			);
		}

		usort(
			$people,
			static function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $people;
	}
}
