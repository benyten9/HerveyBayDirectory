<?php
/**
 * Task Recurrence Scheduler — scans due recurrence rows and spawns task copies.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\Tasks\Recurrences
 */

namespace DoubleScale\Pro\Modules\Tasks\Recurrences;

use DoubleScale\Core\Constants\TaskStatus;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskRecurrenceModel;
use DoubleScale\Pro\Modules\Tasks\Services\TaskCloneService;
use DoubleScale\Pro\Modules\Tasks\Services\TaskStatusManager;
use DoubleScale\Pro\Modules\Tasks\Tasks;

/**
 * TaskRecurrenceScheduler class
 */
class TaskRecurrenceScheduler {

	/**
	 * Singleton instance.
	 *
	 * @var TaskRecurrenceScheduler|null
	 */
	private static $instance;

	/**
	 * Action Scheduler helper.
	 *
	 * @var Tasks
	 */
	private $tasks;

	/**
	 * Get singleton.
	 *
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
	 * Register cron callback and init hook.
	 *
	 * @return void
	 */
	private function init_hooks() {
		$this->tasks->register_callback( 'process_recurring_tasks', array( $this, 'process_recurring_tasks' ) );
		add_action( 'init', array( $this, 'schedule_recurring_checker' ) );
		add_action( 'rest_api_init', array( $this, 'maybe_process_due_on_api_request' ), 999 );
		add_action( 'doublescale_task_completed', array( $this, 'maybe_process_on_completion' ), 10, 1 );
	}

	/**
	 * Poll interval (seconds) for the background recurrence scan.
	 */
	private const POLL_INTERVAL_SECONDS = 60;

	/**
	 * While the CRM UI is open, process due rules without waiting for the next cron tick.
	 *
	 * @return void
	 */
	public function maybe_process_due_on_api_request() {
		if ( get_transient( 'doublescale_recurrence_api_poll' ) ) {
			return;
		}

		set_transient( 'doublescale_recurrence_api_poll', 1, 30 );

		if ( ! TaskRecurrenceModel::due()->exists() ) {
			return;
		}

		$this->process_recurring_tasks();
	}

	/**
	 * Schedule the recurring scan if not already queued (runs every minute).
	 *
	 * @return void
	 */
	public function schedule_recurring_checker() {
		$stored_interval = (int) get_option( 'doublescale_recurrence_poll_interval', 0 );
		if ( $stored_interval !== self::POLL_INTERVAL_SECONDS ) {
			$this->tasks->unschedule_all( 'process_recurring_tasks' );
			update_option( 'doublescale_recurrence_poll_interval', self::POLL_INTERVAL_SECONDS, false );
		}

		if ( false === $this->tasks->get_next_timestamp( 'process_recurring_tasks' ) ) {
			$this->tasks->schedule_recurring( time(), self::POLL_INTERVAL_SECONDS, 'process_recurring_tasks' );
		}
	}

	/**
	 * After a recurrence rule is saved, spawn when already due or queue a one-shot scan at next_run_at.
	 *
	 * The 15-minute recurring job can lag up to one interval after the scheduled slot; this
	 * closes that gap so users see the first copy without waiting for the next cron tick.
	 *
	 * @param TaskRecurrenceModel $recurrence Saved recurrence row.
	 * @return void
	 */
	public function kick_recurrence_processing( TaskRecurrenceModel $recurrence ) {
		$recurrence = $recurrence->fresh( 'templateTask' );
		if ( ! $recurrence || ! $recurrence->is_active ) {
			return;
		}

		$template = $recurrence->templateTask;
		if ( ! $template ) {
			return;
		}

		if ( $recurrence->canSpawnForTemplate( $template ) ) {
			$this->process_single_recurrence( $recurrence );
			$recurrence = $recurrence->fresh( 'templateTask' );
			if ( ! $recurrence || ! $recurrence->is_active ) {
				return;
			}
		}

		$next_ts = strtotime( (string) $recurrence->next_run_at );
		if ( false === $next_ts ) {
			return;
		}

		// Queue a one-shot run at the exact slot (Action Scheduler may still lag on low-traffic sites).
		$this->tasks->schedule_single( $next_ts, 'process_recurring_tasks' );
	}

	/**
	 * Process due recurrence rows (at most one spawn per row per run).
	 *
	 * @return void
	 */
	public function process_recurring_tasks() {
		$batch_size  = 50;
		$max_batches = 10;
		$batch_count = 0;

		do {
			$recurrences = TaskRecurrenceModel::due()
				->with( 'templateTask' )
				->limit( $batch_size )
				->get();

			if ( $recurrences->isEmpty() ) {
				break;
			}

			foreach ( $recurrences as $recurrence ) {
				$this->process_single_recurrence( $recurrence );
			}

			++$batch_count;
			if ( $batch_count >= $max_batches ) {
				break;
			}
		} while ( $recurrences->count() === $batch_size );
	}

	/**
	 * When a template is completed, spawn immediately if the rule waits for completion and is due.
	 *
	 * @param TaskModel $task Completed task.
	 * @return void
	 */
	public function maybe_process_on_completion( TaskModel $task ) {
		$recurrence = TaskRecurrenceModel::where( 'template_task_id', $task->id )
			->where( 'is_active', 1 )
			->first();

		if ( ! $recurrence ) {
			return;
		}

		$recurrence->setRelation( 'templateTask', $task );
		$this->process_single_recurrence( $recurrence );
	}

	/**
	 * Spawn one occurrence and advance next_run_at (skip backlog storms).
	 *
	 * @param TaskRecurrenceModel $recurrence Recurrence row.
	 * @return void
	 */
	public function process_single_recurrence( TaskRecurrenceModel $recurrence ) {
		$template = $recurrence->templateTask;
		if ( ! $template ) {
			TaskRecurrenceModel::where( 'id', $recurrence->id )->update(
				array(
					'is_active' => 0,
				)
			);
			return;
		}

		if ( ! $recurrence->canSpawnForTemplate( $template ) ) {
			return;
		}

		$run_at = (string) $recurrence->next_run_at;
		$tz     = $recurrence->resolveTimezone();
		$anchor = new \DateTime( (string) $template->due_date . ' ' . $recurrence->resolveTime(), $tz );

		$processed = false;
		if ( $recurrence->createsNewTaskOnRepeat() ) {
			$spawned = $this->spawn_occurrence( $template, $recurrence, $run_at );
			if ( ! $spawned ) {
				return;
			}

			if ( $recurrence->waitsForCompletion() ) {
				$this->reset_template_for_next_cycle( $template, (string) $spawned->due_date );
			}

			$processed = true;
		} else {
			$processed = $this->advance_template_occurrence( $template, $recurrence, $run_at );
			if ( ! $processed ) {
				return;
			}
		}

		$now = current_time( 'mysql' );

		$next = $recurrence->compute_next_run_at( $run_at, $anchor );
		$now_dt = new \DateTime( $now, $tz );

		while ( strtotime( $next ) <= $now_dt->getTimestamp() ) {
			$next = $recurrence->compute_next_run_at( $next, $anchor );
		}

		TaskRecurrenceModel::where( 'id', $recurrence->id )->update(
			array(
				'last_run_at' => $now,
				'next_run_at' => $next,
			)
		);
	}

	/**
	 * Reopen the template for the next recurrence cycle after a gated spawn.
	 *
	 * @param TaskModel $template           Series template.
	 * @param string    $occurrence_due_date Due date used for the spawned occurrence (Y-m-d).
	 * @return void
	 */
	private function reset_template_for_next_cycle( TaskModel $template, string $occurrence_due_date ): void {
		TaskModel::where( 'id', $template->id )->update(
			array(
				'status'       => TaskStatus::PENDING,
				'completed_at' => null,
				'due_date'     => $occurrence_due_date,
				'updated_at'   => current_time( 'mysql', true ),
			)
		);

		$template->status       = TaskStatus::PENDING;
		$template->completed_at   = null;
		$template->due_date       = $occurrence_due_date;
		$template->updated_at     = current_time( 'mysql', true );
		$template->reminder_sent_at = null;
	}

	/**
	 * Move the template task forward to the next occurrence (no copy).
	 *
	 * @param TaskModel           $template   Template task.
	 * @param TaskRecurrenceModel $recurrence Recurrence rule.
	 * @param string              $run_at     Scheduled run datetime.
	 * @return bool
	 */
	private function advance_template_occurrence( TaskModel $template, TaskRecurrenceModel $recurrence, string $run_at ): bool {
		$tz       = $recurrence->resolveTimezone();
		$run_dt   = new \DateTime( $run_at, $tz );
		$due_date = $run_dt->format( 'Y-m-d' );
		$due_time = $recurrence->resolveTime();

		$status_id = $recurrence->resolveOccurrenceStatusId( $template );
		$reminder_at = $this->compute_occurrence_reminder( $template, $due_date );

		TaskModel::where( 'id', $template->id )->update(
			array(
				'status'           => TaskStatus::PENDING,
				'completed_at'     => null,
				'due_date'         => $due_date,
				'due_time'         => $due_time,
				'reminder_at'      => $reminder_at,
				'reminder_sent_at' => null,
				'updated_at'       => current_time( 'mysql', true ),
			)
		);

		$template->status           = TaskStatus::PENDING;
		$template->completed_at     = null;
		$template->due_date         = $due_date;
		$template->due_time         = $due_time;
		$template->reminder_at      = $reminder_at;
		$template->reminder_sent_at = null;
		$template->updated_at       = current_time( 'mysql', true );

		if ( $status_id ) {
			TaskStatusManager::instance()->apply_status_to_task( $template, $status_id );
			$template->save();
		}

		/**
		 * Fires when a recurring task advances the same template instead of spawning a copy.
		 *
		 * @param TaskModel           $template   Template task.
		 * @param TaskRecurrenceModel $recurrence Recurrence rule.
		 * @param string              $run_at     Scheduled run datetime.
		 */
		do_action( 'doublescale_task_recurrence_advanced', $template, $recurrence, $run_at );

		return true;
	}

	/**
	 * Clone the template into a fresh pending task for this occurrence.
	 *
	 * @param TaskModel           $template   Template task.
	 * @param TaskRecurrenceModel $recurrence Recurrence rule.
	 * @param string              $run_at     Scheduled run datetime.
	 * @return TaskModel|null
	 */
	public function spawn_occurrence( TaskModel $template, TaskRecurrenceModel $recurrence, string $run_at ) {
		$tz       = $recurrence->resolveTimezone();
		$run_dt   = new \DateTime( $run_at, $tz );
		$due_date = $run_dt->format( 'Y-m-d' );
		$due_time = $recurrence->resolveTime();
		$status_id = $recurrence->resolveOccurrenceStatusId( $template );

		$data = array(
			'title'        => $template->title,
			'description'  => $template->description,
			'entity_type'  => $template->entity_type,
			'entity_id'    => $template->entity_id,
			'assigned_to'  => $template->assigned_to,
			'task_type'    => $template->task_type,
			'priority'     => $template->priority,
			'status'       => TaskStatus::PENDING,
			'status_id'     => $status_id,
			'due_date'     => $due_date,
			'due_time'     => $due_time,
			'completed_at' => null,
		);

		$data['reminder_at'] = $this->compute_occurrence_reminder( $template, $due_date );

		$new_task = TaskModel::create( $data );

		if ( ! $new_task ) {
			return null;
		}

		if ( $status_id ) {
			TaskStatusManager::instance()->apply_status_to_task( $new_task, $status_id );
			$new_task->save();
		}

		TaskCloneService::instance()->copy_children( $template, $new_task, $due_date );

		/**
		 * Fires when a recurring task occurrence is spawned from a template.
		 *
		 * @param TaskModel           $new_task   Spawned occurrence.
		 * @param TaskModel           $template   Series template task.
		 * @param TaskRecurrenceModel $recurrence Recurrence rule.
		 */
		do_action( 'doublescale_task_recurrence_spawned', $new_task, $template, $recurrence );

		return $new_task;
	}

	/**
	 * Shift template reminder offset onto the new due date.
	 *
	 * @param TaskModel $template Template task.
	 * @param string    $due_date Occurrence due date (Y-m-d).
	 * @return string|null
	 */
	private function compute_occurrence_reminder( TaskModel $template, string $due_date ): ?string {
		if ( empty( $template->reminder_at ) || empty( $template->due_date ) ) {
			return null;
		}

		$template_due_ts = strtotime( (string) $template->due_date );
		$reminder_ts     = strtotime( (string) $template->reminder_at );
		if ( false === $template_due_ts || false === $reminder_ts ) {
			return null;
		}

		$offset_seconds = $reminder_ts - $template_due_ts;
		$new_due_ts     = strtotime( $due_date );
		if ( false === $new_due_ts ) {
			return null;
		}

		return wp_date( 'Y-m-d H:i:s', $new_due_ts + $offset_seconds );
	}
}
