<?php
/**
 * Clone CRM tasks with labels, custom fields, and subtask checklists.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\Tasks\Services
 */

namespace DoubleScale\Pro\Modules\Tasks\Services;

use DoubleScale\Core\Constants\TaskStatus;
use DoubleScale\Pro\Modules\Tasks\Models\SubtaskGroupModel;
use DoubleScale\Pro\Modules\Tasks\Models\SubtaskModel;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;

defined( 'ABSPATH' ) || exit;

/**
 * TaskCloneService class
 */
class TaskCloneService {

	/**
	 * Singleton instance.
	 *
	 * @var TaskCloneService|null
	 */
	private static $instance;

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
	 * Duplicate a task as a new pending row (no recurrence rule).
	 *
	 * @param TaskModel              $source             Task to copy.
	 * @param array<string, mixed>   $overrides          Optional attribute overrides.
	 * @return TaskModel|null
	 */
	public function clone_task( TaskModel $source, array $overrides = array() ): ?TaskModel {
		$title = isset( $overrides['title'] )
			? (string) $overrides['title']
			: $this->build_clone_title( (string) $source->title );

		$data = array(
			'title'        => $title,
			'description'  => $source->description,
			'entity_type'  => $source->entity_type,
			'entity_id'    => $source->entity_id,
			'assigned_to'  => $source->assigned_to,
			'task_type'    => $source->task_type,
			'priority'     => $source->priority,
			'status'       => TaskStatus::PENDING,
			'status_id'     => $source->status_id,
			'due_date'     => $source->due_date,
			'due_time'     => $source->due_time,
			'reminder_at'      => $source->reminder_at,
			'reminder_sent_at' => null,
			'completed_at'     => null,
		);

		$data = array_merge( $data, $overrides );
		unset( $data['id'] );

		$clone = TaskModel::create( $data );
		if ( ! $clone ) {
			return null;
		}

		if ( ! empty( $clone->status_id ) ) {
			TaskStatusManager::instance()->apply_status_to_task( $clone, (int) $clone->status_id );
			$clone->save();
		}

		$this->copy_children( $source, $clone );

		/**
		 * Fires after a task is cloned into a new row.
		 *
		 * @param TaskModel $clone  New task.
		 * @param TaskModel $source Source task.
		 */
		do_action( 'doublescale_task_cloned', $clone, $source );

		return $clone;
	}

	/**
	 * Copy labels, custom fields, and subtasks onto a target task.
	 *
	 * @param TaskModel   $source              Source task.
	 * @param TaskModel   $target              Target task.
	 * @param string|null $occurrence_due_date When set, shift subtask dates relative to the new due date.
	 * @return void
	 */
	public function copy_children( TaskModel $source, TaskModel $target, ?string $occurrence_due_date = null ): void {
		$source->loadMissing( 'labels' );
		if ( $source->relationLoaded( 'labels' ) && $source->labels->isNotEmpty() ) {
			$label_ids = $source->labels->pluck( 'id' )->map( 'intval' )->all();
			if ( ! empty( $label_ids ) ) {
				$target->labels()->sync( $label_ids );
			}
		}

		$this->copy_custom_fields( $source, $target );
		$this->copy_subtasks( $source, $target, $occurrence_due_date );
	}

	/**
	 * Copy task-scoped custom field values.
	 *
	 * @param TaskModel $source Source task.
	 * @param TaskModel $target Target task.
	 * @return void
	 */
	public function copy_custom_fields( TaskModel $source, TaskModel $target ): void {
		$source->loadMissing( 'custom_fields' );
		if ( ! $source->relationLoaded( 'custom_fields' ) || $source->custom_fields->isEmpty() ) {
			return;
		}

		$sync = array();
		foreach ( $source->custom_fields as $field ) {
			$sync[ (int) $field->id ] = array(
				'value'       => (string) ( $field->pivot->value ?? '' ),
				'entity_type' => 'task',
			);
		}

		if ( ! empty( $sync ) ) {
			$target->custom_fields()->sync( $sync );
		}
	}

	/**
	 * Copy subtask groups and checklist items (reset completion state).
	 *
	 * @param TaskModel   $source              Source task.
	 * @param TaskModel   $target              Target task.
	 * @param string|null $occurrence_due_date When set, shift subtask dates relative to the new due date.
	 * @return void
	 */
	public function copy_subtasks( TaskModel $source, TaskModel $target, ?string $occurrence_due_date = null ): void {
		$source->loadMissing(
			array(
				'subtaskGroups.subtasks',
				'subtasks',
			)
		);

		foreach ( $source->subtaskGroups as $group ) {
			$new_group = SubtaskGroupModel::create(
				array(
					'task_id'  => (int) $target->id,
					'title'    => (string) $group->title,
					'position' => (int) $group->position,
				)
			);

			foreach ( $group->subtasks as $subtask ) {
				$this->clone_subtask_row(
					$subtask,
					(int) $target->id,
					(int) $new_group->id,
					$source,
					$occurrence_due_date
				);
			}
		}

		foreach ( $source->subtasks as $subtask ) {
			if ( ! empty( $subtask->group_id ) ) {
				continue;
			}

			$this->clone_subtask_row( $subtask, (int) $target->id, null, $source, $occurrence_due_date );
		}
	}

	/**
	 * Build a clone title with a translated suffix, respecting max length.
	 *
	 * @param string $title Source title.
	 * @return string
	 */
	private function build_clone_title( string $title ): string {
		$suffix = ' (' . __( 'copy', 'doublescale' ) . ')';
		$max    = 255;

		if ( strlen( $title ) + strlen( $suffix ) <= $max ) {
			return $title . $suffix;
		}

		return substr( $title, 0, $max - strlen( $suffix ) ) . $suffix;
	}

	/**
	 * Clone one subtask row onto a target task.
	 *
	 * @param SubtaskModel $subtask               Source subtask.
	 * @param int          $task_id               Target task ID.
	 * @param int|null     $group_id              Target group ID, if any.
	 * @param TaskModel    $source                Source parent task.
	 * @param string|null  $occurrence_due_date   When set, shift dates relative to the new due date.
	 * @return void
	 */
	private function clone_subtask_row(
		SubtaskModel $subtask,
		int $task_id,
		?int $group_id,
		TaskModel $source,
		?string $occurrence_due_date
	): void {
		$due_date = $subtask->due_date ? (string) $subtask->due_date : null;
		$reminder = $subtask->reminder_at ? (string) $subtask->reminder_at : null;

		if ( null !== $occurrence_due_date ) {
			$due_date = $this->shift_occurrence_date( $source, $occurrence_due_date, $due_date );
			$reminder = $this->shift_occurrence_date( $source, $occurrence_due_date, $reminder );
		}

		SubtaskModel::create(
			array(
				'task_id'          => $task_id,
				'group_id'         => $group_id,
				'title'            => (string) $subtask->title,
				'notes'            => $subtask->notes ? (string) $subtask->notes : null,
				'is_completed'     => false,
				'position'         => (int) $subtask->position,
				'assigned_to'      => $subtask->assigned_to ? (int) $subtask->assigned_to : null,
				'due_date'         => $due_date,
				'reminder_at'      => $reminder,
				'reminder_sent_at' => null,
				'completed_at'     => null,
			)
		);
	}

	/**
	 * Preserve a date/datetime offset relative to the source task due date.
	 *
	 * @param TaskModel   $source              Source task.
	 * @param string      $occurrence_due_date Occurrence due date (Y-m-d).
	 * @param string|null $date                Source date or datetime.
	 * @return string|null
	 */
	private function shift_occurrence_date( TaskModel $source, string $occurrence_due_date, ?string $date ): ?string {
		if ( empty( $date ) || empty( $source->due_date ) ) {
			return $date;
		}

		$template_due_ts = strtotime( (string) $source->due_date );
		$date_ts         = strtotime( $date );
		if ( false === $template_due_ts || false === $date_ts ) {
			return $date;
		}

		$occurrence_ts = strtotime( $occurrence_due_date );
		if ( false === $occurrence_ts ) {
			return $date;
		}

		$offset_seconds = $date_ts - $template_due_ts;
		$format         = strlen( $date ) > 10 ? 'Y-m-d H:i:s' : 'Y-m-d';

		return wp_date( $format, $occurrence_ts + $offset_seconds );
	}
}
