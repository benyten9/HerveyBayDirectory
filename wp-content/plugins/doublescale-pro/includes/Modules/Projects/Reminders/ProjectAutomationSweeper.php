<?php
/**
 * Sweeps overdue / due-soon projects and fires automation hooks (idempotent).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\Reminders;

use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectStatusModel;
use DoubleScale\Pro\Modules\Tasks\Tasks;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectAutomationSweeper class.
 */
class ProjectAutomationSweeper {

	/**
	 * Option key storing announced event keys => timestamp.
	 */
	const OPTION_KEY = 'doublescale_project_automation_announced';

	/**
	 * Due-soon windows in hours (project-appropriate; wider than tasks).
	 *
	 * @var int[]
	 */
	const DUE_SOON_WINDOWS = array( 24, 48, 72 );

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
		$this->tasks->register_callback( 'process_project_automation_sweep', array( $this, 'process_sweep' ) );
		add_action( 'init', array( $this, 'schedule_sweep' ) );
	}

	/**
	 * Schedule the recurring sweep every 15 minutes.
	 */
	public function schedule_sweep(): void {
		if ( false === $this->tasks->get_next_timestamp( 'process_project_automation_sweep' ) ) {
			$this->tasks->schedule_recurring( time(), 900, 'process_project_automation_sweep' );
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
	 * Announce overdue incomplete projects once each.
	 */
	private function process_overdue(): void {
		$batch_size  = 50;
		$max_batches = 10;
		$batch_count = 0;
		$announced   = $this->get_announced();
		$today       = current_time( 'Y-m-d' );
		$completed   = $this->completed_status_ids();

		do {
			$query = ProjectModel::query()
				->whereNotNull( 'due_date' )
				->where( 'due_date', '<', $today )
				->orderBy( 'id', 'asc' )
				->limit( $batch_size )
				->offset( $batch_count * $batch_size );

			if ( ! empty( $completed ) ) {
				$query->whereNotIn( 'status_id', $completed );
			}

			$projects = $query->get();

			if ( $projects->isEmpty() ) {
				break;
			}

			foreach ( $projects as $project ) {
				$key = $this->event_key( (int) $project->id, 'overdue' );
				if ( isset( $announced[ $key ] ) ) {
					continue;
				}

				/**
				 * Fires once when an incomplete project is overdue.
				 *
				 * @param ProjectModel $project Overdue project.
				 */
				do_action( 'doublescale_automation_project_overdue', $project );
				$announced[ $key ] = time();
			}

			$batch_count++;
			if ( $batch_count >= $max_batches ) {
				break;
			}
		} while ( $projects->count() === $batch_size );

		$this->save_announced( $announced );
	}

	/**
	 * Announce due-soon incomplete projects once per window.
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
		$completed   = $this->completed_status_ids();

		do {
			$query = ProjectModel::query()
				->whereNotNull( 'due_date' )
				->where( 'due_date', '>=', $today )
				->where( 'due_date', '<=', $horizon_day )
				->orderBy( 'id', 'asc' )
				->limit( $batch_size )
				->offset( $batch_count * $batch_size );

			if ( ! empty( $completed ) ) {
				$query->whereNotIn( 'status_id', $completed );
			}

			$projects = $query->get();

			if ( $projects->isEmpty() ) {
				break;
			}

			foreach ( $projects as $project ) {
				$due_ts = $this->project_due_timestamp( $project );
				if ( ! $due_ts || $due_ts <= $now || $due_ts > $horizon ) {
					continue;
				}

				$hours_until = (int) ceil( ( $due_ts - $now ) / HOUR_IN_SECONDS );
				foreach ( self::DUE_SOON_WINDOWS as $window ) {
					if ( $hours_until > $window ) {
						continue;
					}

					$key = $this->event_key( (int) $project->id, 'due_soon_' . $window );
					if ( isset( $announced[ $key ] ) ) {
						continue;
					}

					/**
					 * Fires once per window when an incomplete project is due soon.
					 *
					 * @param ProjectModel $project Project.
					 * @param int          $hours   Window hours.
					 */
					do_action( 'doublescale_automation_project_due_soon', $project, $window );
					$announced[ $key ] = time();
				}
			}

			$batch_count++;
			if ( $batch_count >= $max_batches ) {
				break;
			}
		} while ( $projects->count() === $batch_size );

		$this->save_announced( $announced );
	}

	/**
	 * IDs of statuses marked as completed.
	 *
	 * @return int[]
	 */
	private function completed_status_ids(): array {
		return array_map(
			'intval',
			ProjectStatusModel::query()
				->where( 'is_completed', true )
				->pluck( 'id' )
				->toArray()
		);
	}

	/**
	 * Build a unix timestamp for the project's due date (end of day, site timezone).
	 *
	 * @param ProjectModel $project Project.
	 * @return int|null
	 */
	private function project_due_timestamp( ProjectModel $project ): ?int {
		if ( empty( $project->due_date ) ) {
			return null;
		}

		$ts = strtotime( $project->due_date . ' 23:59:59' );
		return $ts ? (int) $ts : null;
	}

	/**
	 * @param int    $project_id Project ID.
	 * @param string $event      Event slug.
	 */
	private function event_key( int $project_id, string $event ): string {
		return $project_id . ':' . $event;
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
