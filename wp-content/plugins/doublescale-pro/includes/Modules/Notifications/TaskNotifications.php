<?php
/**
 * Task Notifications Handler
 * Listens to task events and creates notifications
 *
 * @since 1.2.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Notifications;

use DoubleScale\Modules\Notifications\Services\NotificationService;
use DoubleScale\Modules\Notifications\Services\NotificationCategories;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;

/**
 * TaskNotifications class
 *
 * Handles notification creation for task-related events:
 * - Task assigned (on creation with assignee different from creator)
 * - Task reassigned (when assigned_to changes)
 * - Task completed (status changed to completed)
 * - Task overdue (via daily cron check)
 *
 * Note: Task reminders are handled by TaskReminderScheduler.
 *
 * @listens doublescale_task_created Fired from TaskModel::boot() in Pro plugin
 * @listens doublescale_task_reassigned Fired from TaskModel::boot() in Pro plugin
 * @listens doublescale_task_completed Fired when task is marked complete
 *
 * @since 1.2.0
 */
class TaskNotifications {

	/**
	 * Option name for tracking overdue task notifications
	 *
	 * @var string
	 */
	const OVERDUE_NOTIFIED_OPTION = '_doublescale_tasks_overdue_notified';

	/**
	 * Constructor - register hooks
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		if ( ! NotificationCategories::is_module_active( NotificationCategories::TASKS ) ) {
			return;
		}
		add_action( 'doublescale_task_created', array( $this, 'on_task_created' ), 10, 1 );
		add_action( 'doublescale_task_reassigned', array( $this, 'on_task_reassigned' ), 10, 3 );
		add_action( 'doublescale_task_completed', array( $this, 'on_task_completed' ), 10, 1 );
	}

	/**
	 * Handle task created event
	 *
	 * Notifies the assignee when they are assigned to a new task.
	 * Only notifies if the assignee is different from the creator.
	 *
	 * @since 1.2.0
	 *
	 * @param TaskModel $task The created task.
	 */
	public function on_task_created( $task ) {
		$assigned_to  = (int) $task->assigned_to;
		$current_user = get_current_user_id();

		// Skip if no assignee or assignee is the creator.
		if ( ! $assigned_to || $assigned_to === $current_user ) {
			return;
		}

		// Get creator name.
		$creator      = get_userdata( $current_user );
		$creator_name = $creator ? $creator->display_name : __( 'Someone', 'doublescale');

		// Build message based on task type.
		$task_type_label = $this->get_task_type_label( $task->task_type );

		NotificationService::create(
			$assigned_to,
			/* translators: %s: task title */
			sprintf( __( 'New Task Assigned: "%s"', 'doublescale'), $task->title ),
			/* translators: 1: creator name, 2: task type, 3: due date */
			sprintf(
				__( '%1$s assigned you a %2$s task due %3$s.', 'doublescale'),
				$creator_name,
				strtolower( $task_type_label ),
				$this->format_due_date( $task->due_date )
			),
			$this->get_task_link( $task ),
			NotificationCategories::TASKS_ASSIGNED
		);
	}

	/**
	 * Handle task reassigned event
	 *
	 * Notifies the new assignee when a task is reassigned to them.
	 * Only notifies the new assignee, not the old one.
	 *
	 * @since 1.2.0
	 *
	 * @param TaskModel $task            The updated task.
	 * @param int        $new_assigned_to New assignee user ID.
	 * @param int        $old_assigned_to Previous assignee user ID.
	 */
	public function on_task_reassigned( $task, $new_assigned_to, $old_assigned_to ) {
		$current_user = get_current_user_id();

		// Skip if new assignee is the one making the change (self-assignment).
		if ( $new_assigned_to === $current_user ) {
			return;
		}

		// Skip if assignee didn't actually change.
		if ( $new_assigned_to === $old_assigned_to ) {
			return;
		}

		// Get the user who made the change.
		$changer      = get_userdata( $current_user );
		$changer_name = $changer ? $changer->display_name : __( 'Someone', 'doublescale');

		// Build message.
		$task_type_label = $this->get_task_type_label( $task->task_type );

		NotificationService::create(
			$new_assigned_to,
			/* translators: %s: task title */
			sprintf( __( 'Task Reassigned: "%s"', 'doublescale'), $task->title ),
			/* translators: 1: user name, 2: task type, 3: due date */
			sprintf(
				__( '%1$s reassigned a %2$s task to you, due %3$s.', 'doublescale'),
				$changer_name,
				strtolower( $task_type_label ),
				$this->format_due_date( $task->due_date )
			),
			$this->get_task_link( $task ),
			NotificationCategories::TASKS_ASSIGNED
		);
	}

	/**
	 * Get link to task in admin
	 *
	 * Tasks are viewed in the context of their entity (contact or deal).
	 *
	 * @since 1.2.0
	 *
	 * @param TaskModel $task The task.
	 * @return string Admin URL to view the task.
	 */
	private function get_task_link( $task ) {
		return self::get_task_link_static( $task );
	}

	/**
	 * Get human-readable task type label
	 *
	 * @since 1.2.0
	 *
	 * @param string $task_type Task type slug.
	 * @return string Translated label.
	 */
	private function get_task_type_label( $task_type ) {
		$labels = array(
			'call'      => __( 'Call', 'doublescale'),
			'email'     => __( 'Email', 'doublescale'),
			'meeting'   => __( 'Meeting', 'doublescale'),
			'todo'      => __( 'To-do', 'doublescale'),
			'follow_up' => __( 'Follow-up', 'doublescale'),
		);

		return $labels[ $task_type ] ?? ucfirst( str_replace( '_', ' ', $task_type ) );
	}

	/**
	 * Handle task completed event
	 *
	 * Notifies relevant users when a task is marked as completed.
	 *
	 * @since 1.2.0
	 *
	 * @param TaskModel $task The completed task.
	 */
	public function on_task_completed( $task ) {
		$current_user = get_current_user_id();
		$assigned_to  = (int) $task->assigned_to;

		// Get the user who completed the task.
		$completer      = get_userdata( $current_user );
		$completer_name = $completer ? $completer->display_name : __( 'Someone', 'doublescale');

		// Notify the assignee if they didn't complete it themselves.
		if ( $assigned_to && $assigned_to !== $current_user ) {
			NotificationService::create(
				$assigned_to,
				/* translators: %s: task title */
				sprintf( __( 'Task Completed: "%s"', 'doublescale'), $task->title ),
				/* translators: %s: user name who completed */
				sprintf( __( '%s has completed this task.', 'doublescale'), $completer_name ),
				$this->get_task_link( $task ),
				NotificationCategories::TASKS_COMPLETED
			);
		}
	}

	/**
	 * Check for overdue tasks and send notifications
	 *
	 * Called by daily cron. Queries tasks that are past their due date
	 * and haven't been notified yet. Each task is notified only once.
	 *
	 * @since 1.2.0
	 */
	public static function check_overdue_tasks() {
		$today = wp_date( 'Y-m-d' );

		// Get previously notified task IDs.
		$notified_ids = get_option( self::OVERDUE_NOTIFIED_OPTION, array() );
		if ( ! is_array( $notified_ids ) ) {
			$notified_ids = array();
		}

		// Get incomplete tasks that are past their due date.
		$overdue_tasks = TaskModel::where( 'status', '!=', 'completed' )
			->whereNotNull( 'due_date' )
			->where( 'due_date', '<', $today )
			->whereNotNull( 'assigned_to' )
			->get();

		$new_notifications = false;

		foreach ( $overdue_tasks as $task ) {
			// Skip if already notified.
			if ( in_array( $task->id, $notified_ids, true ) ) {
				continue;
			}

			// Calculate days overdue.
			$due_date     = new \DateTime( $task->due_date );
			$now          = new \DateTime( $today );
			$days_overdue = $now->diff( $due_date )->days;

			// Send notification to assignee.
			NotificationService::create(
				(int) $task->assigned_to,
				/* translators: %s: task title */
				sprintf( __( 'Task "%s" is Overdue', 'doublescale'), $task->title ),
				/* translators: %d: number of days overdue */
				sprintf(
					_n(
						'This task is %d day past its due date.',
						'This task is %d days past its due date.',
						$days_overdue,
						'doublescale'
					),
					$days_overdue
				),
				self::get_task_link_static( $task ),
				NotificationCategories::TASKS_OVERDUE
			);

			// Mark as notified.
			$notified_ids[]    = $task->id;
			$new_notifications = true;
		}

		// Save updated notified list if changed.
		if ( $new_notifications ) {
			update_option( self::OVERDUE_NOTIFIED_OPTION, $notified_ids, false );
		}

		// Cleanup: Remove IDs of completed tasks.
		self::cleanup_notified_tasks( $notified_ids );
	}

	/**
	 * Cleanup notified tasks list
	 *
	 * Removes task IDs from the notified list if the task is completed.
	 *
	 * @since 1.2.0
	 *
	 * @param array $notified_ids Current list of notified task IDs.
	 */
	private static function cleanup_notified_tasks( $notified_ids ) {
		if ( empty( $notified_ids ) ) {
			return;
		}

		// Get IDs of tasks that are still incomplete.
		$still_incomplete_ids = TaskModel::whereIn( 'id', $notified_ids )
			->where( 'status', '!=', 'completed' )
			->pluck( 'id' )
			->toArray();

		// If some tasks were completed, update the list.
		if ( count( $still_incomplete_ids ) < count( $notified_ids ) ) {
			update_option( self::OVERDUE_NOTIFIED_OPTION, $still_incomplete_ids, false );
		}
	}

	/**
	 * Get link to task in admin (static version for cron context)
	 *
	 * @since 1.2.0
	 *
	 * @param TaskModel $task The task.
	 * @return string Admin URL to view the task.
	 */
	private static function get_task_link_static( $task ) {
		$entity_type = (int) $task->entity_type;

		if ( 1 === $entity_type ) {
			$web = admin_url( 'admin.php?page=doublescale&path=contacts&id=' . $task->entity_id . '&tab=tasks' );
		} elseif ( 2 === $entity_type ) {
			$web = admin_url( 'admin.php?page=doublescale&path=pipeline/deal&id=' . $task->entity_id );
		} else {
			$web = admin_url( 'admin.php?page=doublescale&path=tasks' );
		}

		return array(
			'web'    => $web,
			'mobile' => '/tasks/' . $task->id,
		);
	}

	/**
	 * Format due date for notification message
	 *
	 * @since 1.2.0
	 *
	 * @param string $due_date Due date in Y-m-d format.
	 * @return string Formatted date string.
	 */
	private function format_due_date( $due_date ) {
		if ( empty( $due_date ) ) {
			return __( 'no due date', 'doublescale');
		}

		$today    = wp_date( 'Y-m-d' );
		$tomorrow = wp_date( 'Y-m-d', strtotime( '+1 day' ) );

		if ( $due_date === $today ) {
			return __( 'today', 'doublescale');
		}

		if ( $due_date === $tomorrow ) {
			return __( 'tomorrow', 'doublescale');
		}

		// Format as readable date.
		return wp_date( get_option( 'date_format' ), strtotime( $due_date ) );
	}
}
