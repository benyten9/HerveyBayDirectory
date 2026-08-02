<?php
/**
 * Sweeps overdue / due-soon tasks and fires automation hooks (idempotent).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\Reminders;

use DoubleScale\Core\Constants\TaskStatus;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;
use DoubleScale\Pro\Modules\Tasks\Tasks;

defined( 'ABSPATH' ) || exit;

/**
 * TaskAutomationSweeper class.
 */
class TaskAutomationSweeper {

	/**
	 * Option key storing announced event keys => timestamp.
	 */
	const OPTION_KEY = 'doublescale_task_automation_announced';

	/**
	 * Due-soon windows in hours.
	 *
	 * @var int[]
	 */
	const DUE_SOON_WINDOWS = array( 1, 4, 24, 48, 72 );

	/**
	 * @var self|null
	 */
	private static $instance;

	/**
	 * @var Tasks
	 */
	private $tasks;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->tasks = new Tasks( 'doublescale' );
		$this->init_hooks();
	}

	/**
	 * Register AS callback and schedule.
	 */
	private function init_hooks(): void {
		$this->tasks->register_callback( 'process_task_automation_sweep', array( $this, 'process_sweep' ) );
		add_action( 'init', array( $this, 'schedule_sweep' ) );
	}

	/**
	 * Schedule the recurring sweep every 15 minutes.
	 */
	public function schedule_sweep(): void {
		if ( false === $this->tasks->get_next_timestamp( 'process_task_automation_sweep' ) ) {
			$this->tasks->schedule_recurring( time(), 900, 'process_task_automation_sweep' );
		}
	}

	/**
	 * Process overdue + due-soon announcements.
	 */
	public function process_sweep(): void {
		$this->process_overdue();
		$this->process_due_soon();
		$this->prune_announced();
	}

	/**
	 * Announce overdue pending tasks once each.
	 */
	private function process_overdue(): void {
		$batch_size  = 50;
		$max_batches = 10;
		$batch_count = 0;
		$announced   = $this->get_announced();

		do {
			$tasks = TaskModel::overdue()
				->orderBy( 'id', 'asc' )
				->limit( $batch_size )
				->get();

			if ( $tasks->isEmpty() ) {
				break;
			}

			foreach ( $tasks as $task ) {
				$key = $this->event_key( (int) $task->id, 'overdue' );
				if ( isset( $announced[ $key ] ) ) {
					continue;
				}

				/**
				 * Fires once when a pending task is overdue.
				 *
				 * @param TaskModel $task Overdue task.
				 */
				do_action( 'doublescale_automation_task_overdue', $task );
				$announced[ $key ] = time();
			}

			$batch_count++;
			if ( $batch_count >= $max_batches ) {
				break;
			}
		} while ( $tasks->count() === $batch_size );

		$this->save_announced( $announced );
	}

	/**
	 * Announce due-soon pending tasks once per window.
	 */
	private function process_due_soon(): void {
		$batch_size  = 50;
		$max_batches = 10;
		$batch_count = 0;
		$announced   = $this->get_announced();
		$max_hours   = max( self::DUE_SOON_WINDOWS );
		$now         = current_time( 'timestamp' );
		$horizon     = $now + ( $max_hours * HOUR_IN_SECONDS );
		$today       = current_time( 'Y-m-d' );
		$horizon_day = date( 'Y-m-d', $horizon );

		do {
			$tasks = TaskModel::query()
				->where( 'status', TaskStatus::PENDING )
				->where( 'due_date', '>=', $today )
				->where( 'due_date', '<=', $horizon_day )
				->orderBy( 'id', 'asc' )
				->limit( $batch_size )
				->offset( $batch_count * $batch_size )
				->get();

			if ( $tasks->isEmpty() ) {
				break;
			}

			foreach ( $tasks as $task ) {
				$due_ts = $this->task_due_timestamp( $task );
				if ( ! $due_ts || $due_ts <= $now || $due_ts > $horizon ) {
					continue;
				}

				$hours_until = (int) ceil( ( $due_ts - $now ) / HOUR_IN_SECONDS );
				foreach ( self::DUE_SOON_WINDOWS as $window ) {
					if ( $hours_until > $window ) {
						continue;
					}

					$key = $this->event_key( (int) $task->id, 'due_soon_' . $window );
					if ( isset( $announced[ $key ] ) ) {
						continue;
					}

					/**
					 * Fires once per window when a pending task is due soon.
					 *
					 * @param TaskModel $task  Task.
					 * @param int       $hours Window hours.
					 */
					do_action( 'doublescale_automation_task_due_soon', $task, $window );
					$announced[ $key ] = time();
				}
			}

			$batch_count++;
			if ( $batch_count >= $max_batches ) {
				break;
			}
		} while ( $tasks->count() === $batch_size );

		$this->save_announced( $announced );
	}

	/**
	 * Build a unix timestamp for the task's due date/time (site timezone).
	 *
	 * @param TaskModel $task Task.
	 * @return int|null
	 */
	private function task_due_timestamp( TaskModel $task ): ?int {
		if ( empty( $task->due_date ) ) {
			return null;
		}

		$time = ! empty( $task->due_time ) ? (string) $task->due_time : '23:59:59';
		$ts   = strtotime( $task->due_date . ' ' . $time );
		return $ts ? (int) $ts : null;
	}

	/**
	 * @param int    $task_id Task ID.
	 * @param string $event   Event slug.
	 */
	private function event_key( int $task_id, string $event ): string {
		return $task_id . ':' . $event;
	}

	/**
	 * @return array<string,int>
	 */
	private function get_announced(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * @param array<string,int> $announced Map.
	 */
	private function save_announced( array $announced ): void {
		update_option( self::OPTION_KEY, $announced, false );
	}

	/**
	 * Drop announcement keys older than 30 days.
	 */
	private function prune_announced(): void {
		$announced = $this->get_announced();
		$cutoff    = time() - ( 30 * DAY_IN_SECONDS );
		$changed   = false;

		foreach ( $announced as $key => $ts ) {
			if ( (int) $ts < $cutoff ) {
				unset( $announced[ $key ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			$this->save_announced( $announced );
		}
	}
}
