<?php
/**
 * Task Reminder Scheduler
 * Handles scheduling and sending task reminder notifications
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\Reminders;

use DoubleScale\Pro\Modules\Tasks\Tasks;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;
use DoubleScale\Pro\Modules\Tasks\Models\SubtaskModel;
use DoubleScale\Core\Constants\TaskEntityType;
use DoubleScale\Modules\Notifications\Services\NotificationService;
use DoubleScale\Modules\Notifications\Services\NotificationCategories;

/**
 * TaskReminderScheduler class
 */
class TaskReminderScheduler {

	/**
	 * Class Instance.
	 *
	 * @since 1.0.0
	 *
	 * @var TaskReminderScheduler
	 */
	private static $instance;

	/**
	 * Tasks instance for scheduling cron jobs
	 *
	 * @since 1.0.0
	 *
	 * @var Tasks
	 */
	private $tasks;

	/**
	 * TaskReminderScheduler Instance.
	 *
	 * @since 1.0.0
	 * @static
	 *
	 * @return self - Single instance
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->tasks = new Tasks( 'doublescale' );
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function init_hooks() {
		// Register the callback for processing reminders
		$this->tasks->register_callback( 'process_task_reminders', array( $this, 'process_reminders' ) );

		// Schedule the recurring job on init
		add_action( 'init', array( $this, 'schedule_reminder_checker' ) );
	}

	/**
	 * Schedule the recurring cron job to check task reminders
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function schedule_reminder_checker() {
		// Check if the recurring job is already scheduled
		if ( false === $this->tasks->get_next_timestamp( 'process_task_reminders' ) ) {
			// Schedule to run every 15 minutes (900 seconds)
			$this->tasks->schedule_recurring( time(), 900, 'process_task_reminders' );
		}
	}

	/**
	 * Process pending task reminders
	 * Called by Action Scheduler every 15 minutes
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function process_reminders() {
		$batch_size     = 50;
		$max_batches    = 10; // Safety limit: max 500 reminders per run
		$batch_count    = 0;
		$total_sent     = 0;

		do {
			// Get tasks with pending reminders (due today or earlier, not yet sent, not completed)
			$tasks = TaskModel::pendingReminders()
				->with( array( 'assignedUser' ) )
				->limit( $batch_size )
				->get();

			if ( $tasks->isEmpty() ) {
				break;
			}

			foreach ( $tasks as $task ) {
				// Only count successfully sent reminders
				if ( $this->send_reminder( $task ) ) {
					$total_sent++;
				}
			}

			$batch_count++;

			// Safety check: don't run forever
			if ( $batch_count >= $max_batches ) {
				doublescale_get_logger()->info(
					'Task reminder processing reached max batch limit',
					array(
						'batches_processed' => $batch_count,
						'reminders_sent'    => $total_sent,
					)
				);
				break;
			}

		} while ( $tasks->count() === $batch_size ); // Continue if batch was full (more may exist)

		$this->process_subtask_reminders();

		// Clean up expired reminders (older than 24 hours that failed to send)
		$this->cleanup_expired_reminders();
	}

	/**
	 * Process pending subtask reminders.
	 *
	 * @return void
	 */
	private function process_subtask_reminders() {
		$batch_size  = 50;
		$max_batches = 10;
		$batch_count = 0;

		do {
			$subtasks = SubtaskModel::pendingReminders()
				->with( array( 'assignedUser', 'task' ) )
				->limit( $batch_size )
				->get();

			if ( $subtasks->isEmpty() ) {
				break;
			}

			foreach ( $subtasks as $subtask ) {
				$this->send_subtask_reminder( $subtask );
			}

			++$batch_count;
			if ( $batch_count >= $max_batches ) {
				break;
			}
		} while ( $subtasks->count() === $batch_size );
	}

	/**
	 * Send reminder notification for a subtask.
	 *
	 * @param SubtaskModel $subtask Subtask.
	 *
	 * @return bool
	 */
	private function send_subtask_reminder( $subtask ) {
		$user = $subtask->assignedUser;
		if ( ! $user && $subtask->task ) {
			$subtask->task->loadMissing( 'assignedUser' );
			$user = $subtask->task->assignedUser;
		}

		if ( ! $user ) {
			$this->mark_subtask_reminder_sent( $subtask );
			return false;
		}

		$title = sprintf(
			/* translators: %s: subtask title */
			__( 'Subtask Reminder: %s', 'doublescale' ),
			$subtask->title
		);

		$message_parts = array();
		if ( $subtask->due_date ) {
			$due_ts = strtotime( (string) $subtask->due_date );
			$message_parts[] = sprintf(
				/* translators: 1: due date, 2: due time */
				__( 'Due: %1$s at %2$s', 'doublescale' ),
				date_i18n( get_option( 'date_format' ), $due_ts ),
				date_i18n( get_option( 'time_format' ), $due_ts )
			);
		}
		if ( $subtask->task ) {
			$message_parts[] = sprintf(
				/* translators: %s: parent task title */
				__( 'Parent task: %s', 'doublescale' ),
				$subtask->task->title
			);
		}

		$link = $subtask->task ? $this->get_task_link( $subtask->task ) : '';

		$result = NotificationService::create(
			$user->ID,
			$title,
			implode( ' · ', $message_parts ),
			$link,
			NotificationCategories::TASKS_REMINDER
		);

		if ( $result['notification'] || $result['email_sent'] || ! $result['channels_enabled'] ) {
			$this->mark_subtask_reminder_sent( $subtask );
			return true;
		}

		return false;
	}

	/**
	 * Mark a subtask reminder as sent.
	 *
	 * @param SubtaskModel $subtask Subtask.
	 *
	 * @return void
	 */
	private function mark_subtask_reminder_sent( $subtask ) {
		SubtaskModel::where( 'id', $subtask->id )->update(
			array(
				'reminder_sent_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Clean up expired reminders that failed to send
	 *
	 * Marks reminders older than 24 hours as "sent" to prevent them from being
	 * retried indefinitely. Logs these failures for admin review.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function cleanup_expired_reminders() {
		$retry_deadline = wp_date( 'Y-m-d H:i:s', strtotime( '-24 hours' ) );

		// Find expired reminders (older than 24 hours, not sent, still pending)
		$expired_tasks = TaskModel::where( 'status', 'pending' )
			->whereNotNull( 'reminder_at' )
			->whereNull( 'reminder_sent_at' )
			->where( 'reminder_at', '<', $retry_deadline )
			->limit( 100 ) // Safety limit
			->get();

		if ( $expired_tasks->isEmpty() ) {
			return;
		}

		foreach ( $expired_tasks as $task ) {
			// Mark as "sent" to stop retry attempts
			$this->mark_reminder_sent( $task );

			// Log the expiration
			doublescale_get_logger()->info(
				'Task reminder expired after 24 hours of retry attempts',
				array(
					'task_id'      => $task->id,
					'task_title'   => $task->title,
					'reminder_at'  => $task->reminder_at,
					'assigned_to'  => $task->assigned_to,
				)
			);
		}

		doublescale_get_logger()->info(
			'Cleaned up expired task reminders',
			array( 'count' => $expired_tasks->count() )
		);
	}

	/**
	 * Send reminder notification for a task
	 *
	 * Uses NotificationService to send both bell and email notifications
	 * based on user preferences.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Integrated with NotificationService for bell/email preferences.
	 *
	 * @param TaskModel $task The task to send reminder for.
	 *
	 * @return bool True if reminder was processed successfully (sent or user has all channels disabled)
	 */
	private function send_reminder( $task ) {
		// Get assigned user
		$user = $task->assignedUser;
		if ( ! $user ) {
			// Mark as sent to avoid retrying (no valid recipient)
			$this->mark_reminder_sent( $task );
			doublescale_get_logger()->info(
				'Task reminder has no assigned user',
				array(
					'task_id' => $task->id,
				)
			);
			return false;
		}

		// Build notification content
		$title   = $this->get_notification_title( $task );
		$message = $this->get_notification_message( $task );
		$link    = $this->get_task_link( $task );

		// Send via NotificationService (handles bell and email based on preferences)
		$result = NotificationService::create(
			$user->ID,
			$title,
			$message,
			$link,
			NotificationCategories::TASKS_REMINDER
		);

		// Success cases:
		// 1. Notification was created (bell/browser enabled)
		// 2. Email was sent (email-only mode)
		// 3. No channels enabled (user chose to disable all - honor their preference)
		if ( $result['notification'] || $result['email_sent'] || ! $result['channels_enabled'] ) {
			$this->mark_reminder_sent( $task );
			return true;
		}

		// If we reach here, channels were enabled but nothing was created/sent.
		// This indicates a real failure that needs investigation.
		doublescale_get_logger()->error(
			'Failed to create task reminder notification',
			array(
				'task_id'          => $task->id,
				'user_id'          => $task->assigned_to,
				'task_title'       => $task->title,
				'reminder_at'      => $task->reminder_at,
				'channels_enabled' => $result['channels_enabled'],
			)
		);

		// Don't mark as sent - allow retry on next scheduler run
		return false;
	}

	/**
	 * Mark a task reminder as sent
	 *
	 * @since 1.0.0
	 *
	 * @param TaskModel $task The task to mark.
	 *
	 * @return void
	 */
	private function mark_reminder_sent( $task ) {
		TaskModel::where( 'id', $task->id )->update(
			array(
				'reminder_sent_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Get notification title for task reminder
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Renamed from get_email_subject.
	 *
	 * @param TaskModel $task The task.
	 *
	 * @return string Notification title
	 */
	private function get_notification_title( $task ) {
		return sprintf(
			/* translators: %s: task title */
			__( 'Task Reminder: %s', 'doublescale'),
			$task->title
		);
	}

	/**
	 * Get notification message for task reminder
	 *
	 * @since 1.2.0
	 *
	 * @param TaskModel $task The task.
	 *
	 * @return string Notification message
	 */
	private function get_notification_message( $task ) {
		$due_date = date_i18n( get_option( 'date_format' ), strtotime( $task->due_date ) );
		$due_time = $task->due_time ? date_i18n( get_option( 'time_format' ), strtotime( $task->due_time ) ) : '';

		// Get entity info for context.
		$entity_info = $this->get_entity_info( $task );

		// Build message.
		$parts = array();

		// Due date/time.
		if ( $due_time ) {
			/* translators: 1: due date, 2: due time */
			$parts[] = sprintf( __( 'Due: %1$s at %2$s', 'doublescale'), $due_date, $due_time );
		} else {
			/* translators: %s: due date */
			$parts[] = sprintf( __( 'Due: %s', 'doublescale'), $due_date );
		}

		// Priority.
		/* translators: %s: priority level */
		$parts[] = sprintf( __( 'Priority: %s', 'doublescale'), ucfirst( $task->priority ) );

		// Entity context.
		if ( $entity_info ) {
			/* translators: 1: entity type label, 2: entity name */
			$parts[] = sprintf( '%1$s: %2$s', $entity_info['label'], $entity_info['name'] );
		}

		return implode( ' | ', $parts );
	}

	/**
	 * Get link to task in admin
	 *
	 * @since 1.2.0
	 *
	 * @param TaskModel $task The task.
	 *
	 * @return string Admin URL to task
	 */
	private function get_task_link( $task ) {
		$entity_type = (int) $task->entity_type;

		if ( TaskEntityType::CONTACT === $entity_type && $task->entity_id ) {
			$web = admin_url( "admin.php?page=doublescale&path=contacts&id={$task->entity_id}&tab=tasks" );
		} elseif ( TaskEntityType::DEAL === $entity_type && $task->entity_id ) {
			$web = admin_url( "admin.php?page=doublescale&path=pipeline/deal&id={$task->entity_id}" );
		} else {
			$web = admin_url( 'admin.php?page=doublescale&path=tasks' );
		}

		return array(
			'web'    => $web,
			'mobile' => '/tasks/' . $task->id,
		);
	}

	/**
	 * Get entity information (contact or deal) for the task
	 *
	 * @since 1.0.0
	 *
	 * @param TaskModel $task The task.
	 *
	 * @return array|null Array with 'label' and 'name' keys, or null
	 */
	private function get_entity_info( $task ) {
		$entity_type = (int) $task->entity_type;

		if ( TaskEntityType::CONTACT === $entity_type ) {
			$contact = $task->contact;
			if ( $contact ) {
				$name = trim( $contact->first_name . ' ' . $contact->last_name );
				if ( empty( $name ) ) {
					$name = $contact->email ?: __( 'Unknown Contact', 'doublescale');
				}
				return array(
					'label' => __( 'Contact', 'doublescale'),
					'name'  => $name,
				);
			}
		} elseif ( TaskEntityType::DEAL === $entity_type ) {
			$deal = $task->deal;
			if ( $deal ) {
				return array(
					'label' => __( 'Deal', 'doublescale'),
					'name'  => $deal->title ?: __( 'Unknown Deal', 'doublescale'),
				);
			}
		}

		return null;
	}
}

