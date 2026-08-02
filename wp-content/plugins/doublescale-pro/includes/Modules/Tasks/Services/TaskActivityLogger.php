<?php
/**
 * TaskActivityLogger — turns task/subtask lifecycle domain events into
 * `activity_type='task_event'` rows on the parent task's activity stream.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\Tasks
 */

namespace DoubleScale\Pro\Modules\Tasks\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Core\Constants\TaskStatus;
use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Pro\Modules\Tasks\Models\SubtaskGroupModel;
use DoubleScale\Pro\Modules\Tasks\Models\SubtaskModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskLabelModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskRecurrenceModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskStatusModel;
use DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel;
use DoubleScale\Core\Models\AttachmentModel;
use DoubleScale\Core\Services\AttachmentService;

/**
 * TaskActivityLogger class.
 */
class TaskActivityLogger {

	/**
	 * Register WP action listeners.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'doublescale_task_created', array( $this, 'on_task_created' ), 10, 1 );
		add_action( 'doublescale_task_completed', array( $this, 'on_task_completed' ), 10, 1 );
		add_action( 'doublescale_task_reassigned', array( $this, 'on_task_reassigned' ), 10, 3 );
		add_action( 'doublescale_task_updated', array( $this, 'on_task_updated' ), 10, 2 );

		add_action( 'doublescale_subtask_created', array( $this, 'on_subtask_created' ), 10, 1 );
		add_action( 'doublescale_subtask_updated', array( $this, 'on_subtask_updated' ), 10, 2 );
		add_action( 'doublescale_subtask_deleted', array( $this, 'on_subtask_deleted' ), 10, 1 );

		add_action( 'doublescale_subtask_group_created', array( $this, 'on_group_created' ), 10, 1 );
		add_action( 'doublescale_subtask_group_updated', array( $this, 'on_group_updated' ), 10, 2 );
		add_action( 'doublescale_subtask_group_deleting', array( $this, 'on_group_deleting' ), 10, 1 );

		add_action( 'doublescale_task_file_attached', array( $this, 'on_file_attached' ), 10, 2 );
		add_action( 'doublescale_task_file_removed', array( $this, 'on_file_removed' ), 10, 2 );

		add_action( 'doublescale_task_label_added', array( $this, 'on_label_added' ), 10, 2 );
		add_action( 'doublescale_task_label_removed', array( $this, 'on_label_removed' ), 10, 2 );
		add_action( 'doublescale_task_custom_field_changed', array( $this, 'on_custom_field_changed' ), 10, 4 );

		add_action( 'doublescale_task_recurrence_set', array( $this, 'on_recurrence_set' ), 10, 3 );
		add_action( 'doublescale_task_recurrence_removed', array( $this, 'on_recurrence_removed' ), 10, 1 );
		add_action( 'doublescale_task_recurrence_spawned', array( $this, 'on_recurrence_spawned' ), 10, 3 );
	}

	/**
	 * @param TaskModel $task Created task.
	 */
	public function on_task_created( $task ): void {
		if ( ! $task instanceof TaskModel ) {
			return;
		}

		$this->log_task_event(
			$task,
			'created',
			array(
				'status'   => $task->status,
				'priority' => $task->priority,
				'due_date' => $task->due_date,
			)
		);
	}

	/**
	 * @param TaskModel $task Completed task.
	 */
	public function on_task_completed( $task ): void {
		if ( ! $task instanceof TaskModel ) {
			return;
		}

		$this->log_task_event(
			$task,
			'completed',
			array(
				'from' => TaskStatus::PENDING,
				'to'   => TaskStatus::COMPLETED,
			)
		);
	}

	/**
	 * @param TaskModel $task            Updated task.
	 * @param int       $new_assigned_to New assignee.
	 * @param int       $old_assigned_to Previous assignee.
	 */
	public function on_task_reassigned( $task, $new_assigned_to, $old_assigned_to ): void {
		if ( ! $task instanceof TaskModel ) {
			return;
		}

		$this->log_task_event(
			$task,
			'reassigned',
			array_merge(
				$this->user_change_payload( (int) $old_assigned_to, (int) $new_assigned_to ),
				array( 'field' => 'assigned_to' )
			)
		);
	}

	/**
	 * @param TaskModel $task    Updated task.
	 * @param array     $changes Changed attributes.
	 */
	public function on_task_updated( $task, $changes ): void {
		if ( ! $task instanceof TaskModel || ! is_array( $changes ) ) {
			return;
		}

		if ( isset( $changes['status'] ) ) {
			$old_status = $task->getOriginal( 'status' );
			if ( TaskStatus::COMPLETED === $old_status && TaskStatus::COMPLETED !== $task->status ) {
				$this->log_task_event(
					$task,
					'reopened',
					array(
						'field' => 'status',
						'from'  => $old_status,
						'to'    => $task->status,
					)
				);
			}
		}

		if ( isset( $changes['priority'] ) ) {
			$this->log_task_event(
				$task,
				'priority_changed',
				array(
					'field' => 'priority',
					'from'  => $task->getOriginal( 'priority' ),
					'to'    => $task->priority,
				)
			);
		}

		if ( isset( $changes['due_date'] ) ) {
			$this->log_task_event(
				$task,
				'due_date_changed',
				array(
					'field' => 'due_date',
					'from'  => $task->getOriginal( 'due_date' ),
					'to'    => $task->due_date,
				)
			);
		}

		if ( isset( $changes['status_id'] ) ) {
			$old_status_id = $task->getOriginal( 'status_id' );
			$new_status_id = $task->status_id;

			if ( (int) $old_status_id !== (int) $new_status_id ) {
				$this->log_task_event(
					$task,
					'stage_changed',
					array(
						'field'     => 'status_id',
						'from'      => $old_status_id ? (int) $old_status_id : null,
						'to'        => $new_status_id ? (int) $new_status_id : null,
						'from_name' => self::resolve_status_label( $old_status_id ),
						'to_name'   => self::resolve_status_label( $new_status_id ),
					)
				);
			}
		}
	}

	/**
	 * @param SubtaskModel $subtask Created subtask.
	 */
	public function on_subtask_created( $subtask ): void {
		if ( ! $subtask instanceof SubtaskModel ) {
			return;
		}

		$task = TaskModel::find( $subtask->task_id );
		if ( ! $task ) {
			return;
		}

		$this->log_task_event(
			$task,
			'subtask_added',
			array(
				'subtask_id'    => (int) $subtask->id,
				'subtask_title' => (string) $subtask->title,
			)
		);

		if ( $subtask->assigned_to ) {
			$this->log_task_event(
				$task,
				'subtask_assigned',
				array_merge(
					$this->user_change_payload( 0, (int) $subtask->assigned_to ),
					array(
						'subtask_id'    => (int) $subtask->id,
						'subtask_title' => (string) $subtask->title,
						'field'         => 'assigned_to',
					)
				)
			);
		}
	}

	/**
	 * @param SubtaskModel $subtask Updated subtask.
	 * @param array        $changes Changed attributes.
	 */
	public function on_subtask_updated( $subtask, $changes ): void {
		if ( ! $subtask instanceof SubtaskModel || ! is_array( $changes ) ) {
			return;
		}

		$task = TaskModel::find( $subtask->task_id );
		if ( ! $task ) {
			return;
		}

		if ( isset( $changes['assigned_to'] ) ) {
			$old_assignee = (int) $subtask->getOriginal( 'assigned_to' );
			$new_assignee = (int) $subtask->assigned_to;

			if ( $old_assignee !== $new_assignee ) {
				$this->log_task_event(
					$task,
					'subtask_assigned',
					array_merge(
						$this->user_change_payload( $old_assignee, $new_assignee ),
						array(
							'subtask_id'    => (int) $subtask->id,
							'subtask_title' => (string) $subtask->title,
							'field'         => 'assigned_to',
						)
					)
				);
			}
		}

		if ( isset( $changes['is_completed'] ) && $subtask->is_completed ) {
			$this->log_task_event(
				$task,
				'subtask_completed',
				array(
					'subtask_id'    => (int) $subtask->id,
					'subtask_title' => (string) $subtask->title,
				)
			);
			return;
		}

		if ( isset( $changes['is_completed'] ) && ! $subtask->is_completed ) {
			$this->log_task_event(
				$task,
				'subtask_reopened',
				array(
					'subtask_id'    => (int) $subtask->id,
					'subtask_title' => (string) $subtask->title,
				)
			);
		}
	}

	/**
	 * @param SubtaskModel $subtask Deleted subtask.
	 */
	public function on_subtask_deleted( $subtask ): void {
		if ( ! $subtask instanceof SubtaskModel ) {
			return;
		}

		$task = TaskModel::find( $subtask->task_id );
		if ( ! $task ) {
			return;
		}

		$this->log_task_event(
			$task,
			'subtask_deleted',
			array(
				'subtask_id'    => (int) $subtask->id,
				'subtask_title' => (string) $subtask->title,
			)
		);
	}

	/**
	 * @param SubtaskGroupModel $group Created group.
	 */
	public function on_group_created( $group ): void {
		if ( ! $group instanceof SubtaskGroupModel ) {
			return;
		}

		$task = TaskModel::find( $group->task_id );
		if ( ! $task ) {
			return;
		}

		$this->log_task_event(
			$task,
			'group_added',
			array(
				'group_id'    => (int) $group->id,
				'group_title' => (string) $group->title,
			)
		);
	}

	/**
	 * @param SubtaskGroupModel $group   Updated group.
	 * @param array             $changes Changed attributes.
	 */
	public function on_group_updated( $group, $changes ): void {
		if ( ! $group instanceof SubtaskGroupModel || ! is_array( $changes ) || ! isset( $changes['title'] ) ) {
			return;
		}

		$task = TaskModel::find( $group->task_id );
		if ( ! $task ) {
			return;
		}

		$this->log_task_event(
			$task,
			'group_renamed',
			array(
				'group_id' => (int) $group->id,
				'from'     => (string) $group->getOriginal( 'title' ),
				'to'       => (string) $group->title,
			)
		);
	}

	/**
	 * @param SubtaskGroupModel $group Group being deleted.
	 */
	public function on_group_deleting( $group ): void {
		if ( ! $group instanceof SubtaskGroupModel ) {
			return;
		}

		$task = TaskModel::find( $group->task_id );
		if ( ! $task ) {
			return;
		}

		$this->log_task_event(
			$task,
			'group_deleted',
			array(
				'group_id'    => (int) $group->id,
				'group_title' => (string) $group->title,
			)
		);
	}

	/**
	 * @param TaskModel       $task       Parent task.
	 * @param AttachmentModel $attachment Attached file.
	 */
	public function on_file_attached( $task, $attachment ): void {
		if ( ! $task instanceof TaskModel || ! $attachment instanceof AttachmentModel ) {
			return;
		}

		$this->log_task_event(
			$task,
			'file_attached',
			$this->attachment_payload( $attachment )
		);
	}

	/**
	 * @param TaskModel       $task       Parent task.
	 * @param AttachmentModel $attachment Removed file.
	 */
	public function on_file_removed( $task, $attachment ): void {
		if ( ! $task instanceof TaskModel || ! $attachment instanceof AttachmentModel ) {
			return;
		}

		$this->log_task_event(
			$task,
			'file_removed',
			$this->attachment_payload( $attachment )
		);
	}

	/**
	 * @param AttachmentModel $attachment Attachment row.
	 * @return array<string, mixed>
	 */
	private function attachment_payload( AttachmentModel $attachment ): array {
		$shaped = ( new AttachmentService() )->shape_for_api( $attachment );

		return array(
			'file_name'     => (string) $attachment->file_name,
			'attachment_id' => (int) $attachment->id,
			'file_size'     => (int) $attachment->file_size,
			'file_type'     => (string) $attachment->file_type,
			'url'           => (string) ( $shaped['url'] ?? '' ),
		);
	}

	/**
	 * @param TaskModel      $task  Parent task.
	 * @param TaskLabelModel $label Added label.
	 */
	public function on_label_added( $task, $label ): void {
		if ( ! $task instanceof TaskModel || ! $label instanceof TaskLabelModel ) {
			return;
		}

		$this->log_task_event(
			$task,
			'label_added',
			array(
				'label_id'    => (int) $label->id,
				'label_title' => $label->title ? (string) $label->title : null,
				'color'       => (string) $label->color,
			)
		);
	}

	/**
	 * @param TaskModel      $task  Parent task.
	 * @param TaskLabelModel $label Removed label.
	 */
	public function on_label_removed( $task, $label ): void {
		if ( ! $task instanceof TaskModel || ! $label instanceof TaskLabelModel ) {
			return;
		}

		$this->log_task_event(
			$task,
			'label_removed',
			array(
				'label_id'    => (int) $label->id,
				'label_title' => $label->title ? (string) $label->title : null,
				'color'       => (string) $label->color,
			)
		);
	}

	/**
	 * @param TaskModel        $task  Parent task.
	 * @param CustomFieldModel $field Changed field definition.
	 * @param string           $from  Previous value.
	 * @param string           $to    New value.
	 */
	public function on_custom_field_changed( $task, $field, $from, $to ): void {
		if ( ! $task instanceof TaskModel || ! $field instanceof CustomFieldModel ) {
			return;
		}

		$this->log_task_event(
			$task,
			'custom_field_changed',
			array(
				'field_name' => (string) $field->name,
				'field_id'   => (int) $field->id,
				'from'       => (string) $from,
				'to'         => (string) $to,
			)
		);
	}

	/**
	 * @param TaskModel           $task       Template task.
	 * @param TaskRecurrenceModel $recurrence Recurrence rule.
	 * @param bool                $is_new     True when first created.
	 */
	public function on_recurrence_set( $task, $recurrence, $is_new ): void {
		if ( ! $task instanceof TaskModel || ! $recurrence instanceof TaskRecurrenceModel ) {
			return;
		}

		$this->log_task_event(
			$task,
			'recurrence_set',
			array(
				'frequency'      => (string) $recurrence->frequency,
				'interval_count' => (int) $recurrence->interval_count,
				'next_run_at'    => (string) $recurrence->next_run_at,
				'is_new'         => (bool) $is_new,
			)
		);
	}

	/**
	 * @param TaskModel $task Template task.
	 */
	public function on_recurrence_removed( $task ): void {
		if ( ! $task instanceof TaskModel ) {
			return;
		}

		$this->log_task_event( $task, 'recurrence_removed', array() );
	}

	/**
	 * @param TaskModel           $spawned    New occurrence task.
	 * @param TaskModel           $template   Series template.
	 * @param TaskRecurrenceModel $recurrence Recurrence rule.
	 */
	public function on_recurrence_spawned( $spawned, $template, $recurrence ): void {
		if ( ! $spawned instanceof TaskModel || ! $template instanceof TaskModel ) {
			return;
		}

		$this->log_task_event(
			$spawned,
			'recurrence_spawned',
			array(
				'recurrence_parent_id' => (int) $template->id,
				'recurrence_id'        => $recurrence instanceof TaskRecurrenceModel ? (int) $recurrence->id : null,
				'template_title'       => (string) $template->title,
			),
			0
		);
	}

	/**
	 * Write a task_event activity associated with the given task.
	 *
	 * @param TaskModel  $task      Parent task.
	 * @param string     $event_key Stable event identifier.
	 * @param array      $payload   Extra data merged into activity.data.
	 * @param int|null   $user_id   Acting user; defaults to current user.
	 */
	private function log_task_event( TaskModel $task, string $event_key, array $payload = array(), $user_id = null ): void {
		if ( ! class_exists( ActivityAssociationModel::class ) ) {
			return;
		}

		$data = array_merge(
			$payload,
			array(
				'task_id'   => (int) $task->id,
				'event_key' => $event_key,
			)
		);

		$activity = ActivityModel::create(
			array(
				'activity_type' => ActivityTypes::TASK_EVENT,
				'data'          => $data,
				'user_id'       => null === $user_id ? get_current_user_id() : (int) $user_id,
			)
		);

		if ( ! $activity ) {
			return;
		}

		ActivityAssociationModel::create(
			array(
				'activity_id' => $activity->id,
				'entity_type' => ActivityAssociationModel::ENTITY_TYPE_TASK,
				'entity_id'   => (int) $task->id,
			)
		);
	}

	/**
	 * Build a from/to payload with human-readable user labels.
	 *
	 * @param int $from_id Previous user ID (0 = unassigned).
	 * @param int $to_id   New user ID (0 = unassigned).
	 * @return array{from: int, to: int, from_name: string, to_name: string}
	 */
	private function user_change_payload( int $from_id, int $to_id ): array {
		return array(
			'from'      => $from_id,
			'to'        => $to_id,
			'from_name' => self::resolve_user_label( $from_id ),
			'to_name'   => self::resolve_user_label( $to_id ),
		);
	}

	/**
	 * Resolve a WP user ID to a display label for activity rendering.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function resolve_user_label( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return __( 'Unassigned', 'doublescale' );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return (string) $user_id;
		}

		return (string) $user->display_name;
	}

	/**
	 * Resolve a stage ID to a display label for activity rendering.
	 *
	 * @param int|string|null $stage_id Stage ID.
	 * @return string
	 */
	private static function resolve_status_label( $status_id ): string {
		if ( empty( $status_id ) ) {
			return __( 'No status', 'doublescale' );
		}

		$status = TaskStatusModel::find( (int) $status_id );
		if ( ! $status ) {
			return (string) $status_id;
		}

		return (string) $status->name;
	}

	/**
	 * @deprecated Use resolve_status_label().
	 */
	private static function resolve_stage_label( $status_id ): string {
		return self::resolve_status_label( $status_id );
	}
}
