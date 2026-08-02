<?php
/**
 * Shared base for task automation triggers.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Task;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Pro\Modules\Automations\Support\TaskContactResolver;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;

defined( 'ABSPATH' ) || exit;

/**
 * BaseTaskTrigger
 */
abstract class BaseTaskTrigger extends Trigger {

	/**
	 * When true, enroll() is a no-op — used to prevent create_task from
	 * re-entering automations mid-action.
	 *
	 * @var bool
	 */
	private static $suppress_enrollment = false;

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
	 * Suppress task trigger enrollment (e.g. while CreateTask runs).
	 */
	public static function suppress_enrollment( bool $suppress ): void {
		self::$suppress_enrollment = $suppress;
	}

	/**
	 * Whether enrollment is currently suppressed.
	 */
	public static function is_enrollment_suppressed(): bool {
		return self::$suppress_enrollment;
	}

	/**
	 * Resolve contact and enroll into matching automations.
	 *
	 * @param TaskModel $task  Task.
	 * @param array     $extra Extra data merged into enrollment `data`.
	 */
	protected function enroll( TaskModel $task, array $extra = array() ): void {
		if ( self::$suppress_enrollment ) {
			return;
		}

		$contact = TaskContactResolver::resolve( $task );
		if ( ! $contact ) {
			return;
		}

		$this->process(
			array(
				'contact' => $contact,
				'task'    => $task,
				'data'    => array_merge(
					array(
						'task_id'     => (int) $task->id,
						'entity_type' => (int) $task->entity_type,
						'entity_id'   => (int) $task->entity_id,
						'deal_id'     => $task->deal_id,
						'project_id'  => $task->project_id,
					),
					$extra
				),
			)
		);
	}

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
	 * Task type select options (any + concrete types).
	 *
	 * @return array
	 */
	protected function get_task_type_options(): array {
		$options = array(
			'any-type' => $this->t( 'Any type' ),
		);

		if ( ! class_exists( \DoubleScale\Core\Constants\TaskType::class ) ) {
			return $options;
		}

		foreach ( \DoubleScale\Core\Constants\TaskType::get_all() as $value => $label ) {
			$options[ $value ] = $label;
		}

		return $options;
	}

	/**
	 * Priority select options (any + concrete priorities).
	 *
	 * @return array
	 */
	protected function get_priority_options(): array {
		$options = array(
			'any-priority' => $this->t( 'Any priority' ),
		);

		if ( ! class_exists( \DoubleScale\Core\Constants\TaskPriority::class ) ) {
			return $options;
		}

		foreach ( \DoubleScale\Core\Constants\TaskPriority::get_all() as $value => $label ) {
			$options[ $value ] = $label;
		}

		return $options;
	}

	/**
	 * User select options (any + users).
	 *
	 * @return array
	 */
	protected function get_assignee_options(): array {
		$options = array(
			'any-user' => $this->t( 'Any user' ),
		);

		// get_fields() is evaluated eagerly at registration; do not query users
		// outside a full WordPress bootstrap.
		if ( ! function_exists( 'get_users' ) ) {
			return $options;
		}

		$users = get_users(
			array(
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'fields'  => array( 'ID', 'display_name' ),
			)
		);

		foreach ( $users as $user ) {
			$options[ $user->ID ] = $user->display_name;
		}

		return $options;
	}

	/**
	 * Kanban status select options (any + stages).
	 *
	 * @return array
	 */
	protected function get_kanban_status_options(): array {
		$options = array(
			'any-status' => $this->t( 'Any status' ),
		);

		if ( ! \DoubleScale\Pro\Modules\Automations\Support\AutomationModuleStorage::is_ready(
			'tasks',
			TaskModel::class
		) ) {
			return $options;
		}

		if ( ! class_exists( \DoubleScale\Pro\Modules\Tasks\Models\TaskStatusModel::class ) ) {
			return $options;
		}

		$stages = \DoubleScale\Pro\Modules\Tasks\Models\TaskStatusModel::orderBy( 'sort_order', 'asc' )->get();
		foreach ( $stages as $stage ) {
			$options[ $stage->id ] = $stage->name;
		}

		return $options;
	}

	/**
	 * Whether a setting matches "any" or the concrete value.
	 *
	 * @param mixed  $setting       Automation setting.
	 * @param mixed  $actual        Actual value.
	 * @param string $any_sentinel  Sentinel for "any".
	 */
	protected function matches_any_or_value( $setting, $actual, string $any_sentinel ): bool {
		if ( null === $setting || '' === $setting || $any_sentinel === $setting ) {
			return true;
		}
		return (string) $setting === (string) $actual;
	}
}
