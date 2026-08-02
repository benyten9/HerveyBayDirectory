<?php
/**
 * Task kanban status operations.
 *
 * @package DoubleScale\Pro\Modules\Tasks\Services
 */

namespace DoubleScale\Pro\Modules\Tasks\Services;

use DoubleScale\Core\Constants\TaskStatus;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskStatusModel;

defined( 'ABSPATH' ) || exit;

/**
 * TaskStatusManager class.
 */
class TaskStatusManager {

	/**
	 * Default kanban columns seeded on first use.
	 *
	 * @var array<int, array<string, string>>
	 */
	private const DEFAULT_STATUSES = array(
		array(
			'name'         => 'To Do',
			'status'       => 'open',
			'color'        => '#EEF2FF',
			'is_protected' => 1,
		),
		array(
			'name'         => 'In Progress',
			'status'       => 'open',
			'color'        => '#E0F2FE',
			'is_protected' => 0,
		),
		array(
			'name'         => 'Done',
			'status'       => 'closed',
			'color'        => '#DCFCE7',
			'is_protected' => 1,
		),
	);

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Seed default statuses when the board is empty.
	 *
	 * @return void
	 */
	public function ensure_default_stages(): void {
		if ( TaskStatusModel::count() > 0 ) {
			return;
		}

		foreach ( self::DEFAULT_STATUSES as $index => $status ) {
			TaskStatusModel::create(
				array(
					'name'         => $status['name'],
					'status'       => $status['status'],
					'color'        => $status['color'],
					'is_protected' => ! empty( $status['is_protected'] ) ? 1 : 0,
					'sort_order'   => $index,
				)
			);
		}
	}

	/**
	 * Guarantee exactly one protected Open and one protected Closed status.
	 *
	 * Idempotent: safe to call on every list_stages() / migration boot.
	 *
	 * @return void
	 */
	public function ensure_protected_stages(): void {
		$protected_open = TaskStatusModel::where( 'status', 'open' )
			->where( 'is_protected', 1 )
			->orderBy( 'sort_order', 'asc' )
			->orderBy( 'id', 'asc' )
			->first();

		if ( ! $protected_open ) {
			$first_open = TaskStatusModel::where( 'status', 'open' )
				->orderBy( 'sort_order', 'asc' )
				->orderBy( 'id', 'asc' )
				->first();

			if ( $first_open ) {
				$first_open->is_protected = 1;
				$first_open->save();
			} else {
				TaskStatusModel::create(
					array(
						'name'         => 'Open',
						'status'       => 'open',
						'color'        => '#EEF2FF',
						'is_protected' => 1,
						'sort_order'   => 0,
					)
				);
			}
		}

		$protected_closed = TaskStatusModel::where( 'status', 'closed' )
			->where( 'is_protected', 1 )
			->orderBy( 'sort_order', 'asc' )
			->orderBy( 'id', 'asc' )
			->first();

		if ( ! $protected_closed ) {
			$first_closed = TaskStatusModel::where( 'status', 'closed' )
				->orderBy( 'sort_order', 'asc' )
				->orderBy( 'id', 'asc' )
				->first();

			if ( $first_closed ) {
				$first_closed->is_protected = 1;
				$first_closed->save();
			} else {
				$max_order = (int) TaskStatusModel::max( 'sort_order' );
				TaskStatusModel::create(
					array(
						'name'         => 'Closed',
						'status'       => 'closed',
						'color'        => '#DCFCE7',
						'is_protected' => 1,
						'sort_order'   => $max_order + 1,
					)
				);
			}
		}
	}

	/**
	 * @return \WPEloquent\Eloquent\Collection
	 */
	public function list_stages() {
		$this->ensure_default_stages();
		$this->ensure_protected_stages();
		$this->backfill_unstaged_tasks();

		return TaskStatusModel::orderBy( 'sort_order', 'asc' )->orderBy( 'id', 'asc' )->get();
	}

	/**
	 * Assign legacy tasks (no stage) to the first open status.
	 *
	 * @return void
	 */
	public function backfill_unstaged_tasks(): void {
		$first_open = TaskStatusModel::where( 'status', 'open' )
			->orderBy( 'sort_order', 'asc' )
			->orderBy( 'id', 'asc' )
			->first();

		if ( ! $first_open ) {
			return;
		}

		TaskModel::whereNull( 'status_id' )->update(
			array(
				'status_id' => (int) $first_open->id,
			)
		);
	}

	/**
	 * @param string      $name     Status name.
	 * @param string      $status   open|closed.
	 * @param string|null $color    Hex color.
	 * @param int|null    $position Insert position.
	 * @return TaskStatusModel|null
	 */
	public function create_stage( $name, $status = 'open', $color = null, $position = null ) {
		$this->ensure_default_stages();

		$max_order  = (int) TaskStatusModel::max( 'sort_order' );
		$sort_order = null === $position ? $max_order + 1 : max( 0, (int) $position );

		if ( null !== $position ) {
			TaskStatusModel::where( 'sort_order', '>=', $sort_order )->increment( 'sort_order' );
		}

		return TaskStatusModel::create(
			array(
				'name'       => $name,
				'status'     => in_array( $status, array( 'open', 'closed' ), true ) ? $status : 'open',
				'color'      => $color ?: '#6d78d8',
				'sort_order' => $sort_order,
			)
		);
	}

	/**
	 * Apply status to a task and sync completion state for closed columns.
	 *
	 * @param TaskModel $task     Task.
	 * @param int|null  $status_id Status ID or null to clear.
	 * @return void
	 */
	public function apply_status_to_task( TaskModel $task, $status_id ): void {
		if ( empty( $status_id ) ) {
			$task->status_id = null;
			return;
		}

		$status_row = TaskStatusModel::find( (int) $status_id );
		if ( ! $status_row ) {
			return;
		}

		$task->status_id = (int) $status_row->id;

		if ( 'closed' === $status_row->status && TaskStatus::PENDING === $task->status ) {
			$task->status       = TaskStatus::COMPLETED;
			$task->completed_at = current_time( 'mysql', true );
		} elseif ( 'open' === $status_row->status && TaskStatus::COMPLETED === $task->status ) {
			$task->status       = TaskStatus::PENDING;
			$task->completed_at = null;
		}
	}

	/**
	 * @deprecated Use apply_status_to_task().
	 */
	public function apply_stage_to_task( TaskModel $task, $status_id ): void {
		$this->apply_status_to_task( $task, $status_id );
	}
}
