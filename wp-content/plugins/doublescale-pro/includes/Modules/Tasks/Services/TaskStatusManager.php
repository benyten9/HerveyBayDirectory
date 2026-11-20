<?php
/**
 * Task kanban status operations.
 *
 * @package DoubleScale\Pro\Modules\Tasks\Services
 */

namespace DoubleScale\Pro\Modules\Tasks\Services;

use DoubleScale\Core\Constants\TaskStatus;
use DoubleScale\Pro\Database\SeedLock;
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
	 * Lock held by every write that creates or protects the board's columns.
	 */
	private const SEED_LOCK = 'task_statuses';

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

		// The Tasks page asks for tasks and for statuses at the same moment, and
		// on a new site both requests find the board empty. Only the one holding
		// the lock may seed, and it has to look again first.
		SeedLock::run(
			self::SEED_LOCK,
			static function () {
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
		);
	}

	/**
	 * Guarantee exactly one protected Open and one protected Closed status.
	 *
	 * Idempotent: safe to call on every list_stages() / migration boot.
	 *
	 * @return void
	 */
	public function ensure_protected_stages(): void {
		if ( 1 === $this->count_protected_stages( 'open' ) && 1 === $this->count_protected_stages( 'closed' ) ) {
			return;
		}

		// Same lock as the seeding: a request that arrives while another one is
		// still creating the defaults must not add an "Open" and a "Closed" of
		// its own next to them.
		SeedLock::run(
			self::SEED_LOCK,
			function () {
				$this->protect_one_stage_of_each_type();
			}
		);
	}

	/**
	 * How many statuses of this type cannot be deleted.
	 *
	 * @param string $status open|closed.
	 * @return int
	 */
	private function count_protected_stages( string $status ): int {
		return (int) TaskStatusModel::where( 'status', $status )
			->where( 'is_protected', 1 )
			->count();
	}

	/**
	 * Leave one protected Open and one protected Closed status, creating the
	 * one the board has none of.
	 *
	 * @return void
	 */
	private function protect_one_stage_of_each_type(): void {
		$protected_open = TaskStatusModel::where( 'status', 'open' )
			->where( 'is_protected', 1 )
			->orderBy( 'sort_order', 'asc' )
			->orderBy( 'id', 'asc' )
			->first();

		if ( $protected_open ) {
			$this->unprotect_copies_of( $protected_open );
		} else {
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

		if ( $protected_closed ) {
			$this->unprotect_copies_of( $protected_closed );
		} else {
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
	 * A board seeded twice has two protected copies of a status, and a
	 * protected status cannot be deleted. Keep the given one.
	 *
	 * @param TaskStatusModel $kept The copy that stays protected.
	 * @return void
	 */
	private function unprotect_copies_of( TaskStatusModel $kept ): void {
		TaskStatusModel::where( 'status', $kept->status )
			->where( 'is_protected', 1 )
			->where( 'id', '!=', $kept->id )
			->update( array( 'is_protected' => 0 ) );
	}

	/**
	 * @return \DoubleScale\Vendor\WPEloquent\Eloquent\Collection
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
