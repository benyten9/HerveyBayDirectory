<?php
/**
 * Task Model
 * Handles CRM tasks (calls, meetings, to-dos, follow-ups) associated with contacts or deals
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\Models;

use WPEloquent\Eloquent\Model;
use DoubleScale\Core\Constants\TaskEntityType;
use DoubleScale\Core\Constants\TaskStatus;
use DoubleScale\Core\Constants\TaskType;
use DoubleScale\Core\Constants\TaskPriority;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Core\Models\UserModel;
use DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel;

/**
 * TaskModel class
 */
class TaskModel extends Model {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table = 'doublescale_tasks';

	/**
	 * Primary key
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $primary_key = 'id';

	/**
	 * Fillable columns
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	protected $fillable = array(
		'title',
		'description',
		'entity_type',
		'entity_id',
		'assigned_to',
		'task_type',
		'status',
		'status_id',
		'priority',
		'due_date',
		'due_time',
		'reminder_at',
		'reminder_sent_at',
		'completed_at',
		'created_at',
		'updated_at',
	);

	/**
	 * Timestamps
	 *
	 * @var bool
	 *
	 * @since 1.0.0
	 */
	public $timestamps = true;

	/**
	 * Attributes to append to model's array/JSON form
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	protected $appends = array(
		'contact_id',
		'deal_id',
		'project_id',
		'display_status',
		'is_overdue',
		'subtask_progress',
	);

	/**
	 * Validation rules
	 *
	 * Note: status only accepts DB statuses (pending, completed)
	 * Display statuses (overdue, upcoming, due_today) are calculated dynamically
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	public $rules = array(
		'title'       => 'required|string|min:3|max:255',
		'description' => 'nullable|string|max:5000',
		'entity_type' => 'required|integer|in:1,2,3',
		'entity_id'   => 'required|integer',
		'assigned_to' => 'required|integer',
		'task_type'   => 'required|string|in:call,email,meeting,todo,follow_up',
		'status'      => 'required|string|in:pending,completed',
		'priority'    => 'required|string|in:low,medium,high',
		'due_date'      => 'required|date',
		'due_time'      => 'nullable|date_format:H:i:s',
		'reminder_at'   => 'nullable|date_format:Y-m-d H:i:s',
	);

	/**
	 * Validation messages
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	public $messages = array(
		'title.required'       => 'Task title is required.',
		'title.min'            => 'Task title must be at least 3 characters.',
		'title.max'            => 'Task title must not exceed 255 characters.',
		'entity_type.required' => 'Entity type is required.',
		'entity_type.in'       => 'Entity type must be 1 (Contact), 2 (Deal), or 3 (Project).',
		'entity_id.required'   => 'Entity ID is required.',
		'assigned_to.required' => 'Assigned user is required.',
		'task_type.required'   => 'Task type is required.',
		'task_type.in'         => 'Invalid task type.',
		'status.required'      => 'Task status is required.',
		'priority.required'    => 'Task priority is required.',
		'due_date.required'    => 'Due date is required.',
		'due_time.date_format' => 'Due time must be in H:i:s format (e.g., 09:30:00).',
	);

	/**
	 * Get the polymorphic entity (Contact or Deal)
	 *
	 * Note: This uses a custom implementation since entity_type stores integers (1, 2)
	 * rather than class names. Use contact() or deal() relationships for eager loading.
	 *
	 * @since 1.0.0
	 *
	 * @return ContactModel|DealModel|null The related entity
	 */
	public function getEntityAttribute() {
		$entity_type = (int) $this->entity_type;

		if ( $entity_type === TaskEntityType::CONTACT ) {
			return $this->contact;
		}

		if ( $entity_type === TaskEntityType::DEAL && class_exists( '\DoubleScale\Pro\Modules\Deals\Models\DealModel' ) ) {
			return $this->deal;
		}

		if ( $entity_type === TaskEntityType::PROJECT && class_exists( '\DoubleScale\Pro\Modules\Projects\Models\ProjectModel' ) ) {
			return $this->project;
		}

		return null;
	}

	/**
	 * Get the contact this task belongs to (if entity_type is CONTACT)
	 * Note: This relationship only returns data when entity_type is CONTACT
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function contact() {
		// Only load contact if entity_type is CONTACT
		// The foreign key constraint is on entity_id, not a separate contact_id column
		return $this->belongsTo( ContactModel::class, 'entity_id', 'id' );
	}

	/**
	 * Get the deal this task belongs to (if entity_type is DEAL)
	 * Note: This relationship only returns data when entity_type is DEAL
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo|null
	 */
	public function deal() {
		if ( class_exists( '\DoubleScale\Pro\Modules\Deals\Models\DealModel' ) ) {
			return $this->belongsTo( '\DoubleScale\Pro\Modules\Deals\Models\DealModel', 'entity_id', 'id' );
		}
		return null;
	}

	/**
	 * Get the project this task belongs to (if entity_type is PROJECT).
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo|null
	 */
	public function project() {
		if ( class_exists( '\DoubleScale\Pro\Modules\Projects\Models\ProjectModel' ) ) {
			return $this->belongsTo( '\DoubleScale\Pro\Modules\Projects\Models\ProjectModel', 'entity_id', 'id' );
		}
		return null;
	}

	/**
	 * Get the user this task is assigned to
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function assignedUser() {
		return $this->belongsTo( UserModel::class, 'assigned_to', 'ID' );
	}

	/**
	 * Kanban board status for this task.
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function kanbanStatus() {
		return $this->belongsTo( TaskStatusModel::class, 'status_id', 'id' );
	}

	/**
	 * @deprecated Use kanbanStatus().
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function stage() {
		return $this->kanbanStatus();
	}

	/**
	 * Get the ordered checklist of subtasks for this task.
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function subtasks() {
		return $this->hasMany( SubtaskModel::class, 'task_id', 'id' )->orderBy( 'position' );
	}

	/**
	 * Get the ordered subtask groups for this task.
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function subtaskGroups() {
		return $this->hasMany( SubtaskGroupModel::class, 'task_id', 'id' )->orderBy( 'position' );
	}

	/**
	 * Colored labels assigned to this task.
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
	 */
	public function labels() {
		return $this->belongsToMany(
			TaskLabelModel::class,
			'doublescale_task_label_relationship',
			'task_id',
			'label_id'
		);
	}

	/**
	 * Recurrence rule when this task is the series template.
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasOne
	 */
	public function recurrence() {
		return $this->hasOne( TaskRecurrenceModel::class, 'template_task_id', 'id' );
	}

	/**
	 * Custom field values for this task (scope=task definitions).
	 *
	 * Note: pivot `entity_type='task'` is unrelated to TaskModel's integer
	 * `entity_type` column (1=Contact, 2=Deal) — different tables, no collision.
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
	 */
	public function custom_fields() {
		return $this->belongsToMany(
			CustomFieldModel::class,
			'doublescale_custom_field_relationship',
			'entity_id',
			'custom_field_id'
		)
			->withPivot( 'value' )
			->wherePivot( 'entity_type', 'task' );
	}

	/**
	 * Sync task-scoped custom field values with validation.
	 *
	 * @since 1.0.0
	 *
	 * @param array|null $custom_fields Submitted custom fields.
	 * @return void|\WP_Error
	 */
	public function sync_custom_fields( $custom_fields ) {
		try {
			$normalized_fields = CustomFieldModel::normalize_submission( $custom_fields ?: array() );

			$required_fields = CustomFieldModel::where( 'scope', 'task' )->get();
			foreach ( $required_fields as $field_model ) {
				if ( ! $field_model->is_required_field() ) {
					continue;
				}

				$value     = $normalized_fields[ $field_model->id ] ?? null;
				$validated = $field_model->validate_submission_value( $value );
				if ( is_wp_error( $validated ) ) {
					return $validated;
				}
			}

			if ( empty( $normalized_fields ) ) {
				return;
			}

			$old_values = array();
			if ( $this->relationLoaded( 'custom_fields' ) ) {
				foreach ( $this->custom_fields as $existing_field ) {
					$old_values[ (int) $existing_field->id ] = (string) ( $existing_field->pivot->value ?? '' );
				}
			} else {
				foreach ( $this->custom_fields()->get() as $existing_field ) {
					$old_values[ (int) $existing_field->id ] = (string) ( $existing_field->pivot->value ?? '' );
				}
			}

			$custom_fields_arr = array();

			foreach ( $normalized_fields as $field_id => $value ) {
				$custom_field_model = CustomFieldModel::find( $field_id );
				if ( ! $custom_field_model ) {
					continue;
				}

				$validated = $custom_field_model->validate_submission_value( $value );
				if ( is_wp_error( $validated ) ) {
					return $validated;
				}

				if ( is_array( $value ) ) {
					$value = implode( ',', $value );
				}

				$custom_fields_arr[ $field_id ] = array(
					'value'       => $value,
					'entity_type' => 'task',
				);
			}

			foreach ( $custom_fields_arr as $field_id => $pivot ) {
				$old = $old_values[ (int) $field_id ] ?? '';
				$new = (string) ( $pivot['value'] ?? '' );
				if ( $old !== $new ) {
					$field_model = CustomFieldModel::find( $field_id );
					if ( $field_model ) {
						/**
						 * Fires when a task custom field value changes.
						 *
						 * @param TaskModel        $task  The task.
						 * @param CustomFieldModel $field The field definition.
						 * @param string           $from  Previous value.
						 * @param string           $to    New value.
						 */
						do_action( 'doublescale_task_custom_field_changed', $this, $field_model, $old, $new );
					}
				}
			}

			$this->custom_fields()->syncWithoutDetaching( $custom_fields_arr );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Scope: Filter by entity type
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param int                                   $type Entity type constant.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeByEntityType( $query, $type ) {
		return $query->where( 'entity_type', $type );
	}

	/**
	 * Scope: Filter by specific entity (type + ID)
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param int                                   $type Entity type.
	 * @param int                                   $id Entity ID.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeByEntity( $query, $type, $id ) {
		return $query->where( 'entity_type', $type )->where( 'entity_id', $id );
	}

	/**
	 * Scope: Filter tasks for a specific contact
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param int                                   $contact_id Contact ID.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeForContact( $query, $contact_id ) {
		return $query->where( 'entity_type', TaskEntityType::CONTACT )
			->where( 'entity_id', $contact_id );
	}

	/**
	 * Scope: Filter tasks for a specific deal
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param int                                   $deal_id Deal ID.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeForDeal( $query, $deal_id ) {
		return $query->where( 'entity_type', TaskEntityType::DEAL )
			->where( 'entity_id', $deal_id );
	}

	/**
	 * Scope: Filter tasks for a specific project.
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param int                                   $project_id Project ID.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeForProject( $query, $project_id ) {
		return $query->where( 'entity_type', TaskEntityType::PROJECT )
			->where( 'entity_id', $project_id );
	}

	/**
	 * Scope: Filter tasks assigned to a specific user
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param int                                   $user_id WordPress user ID.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeAssignedTo( $query, $user_id ) {
		return $query->where( 'assigned_to', $user_id );
	}

	/**
	 * Scope: tasks visible to a sales rep (parent assignee or subtask assignee).
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query   Query builder.
	 * @param int                                   $user_id WordPress user ID.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeVisibleToSalesRep( $query, $user_id ) {
		$user_id = (int) $user_id;

		return $query->where(
			function ( $q ) use ( $user_id ) {
				$q->where( 'assigned_to', $user_id )
					->orWhereIn(
						'id',
						SubtaskModel::where( 'assigned_to', $user_id )->select( 'task_id' )
					);
			}
		);
	}

	/**
	 * Whether a sales rep owns the parent task (not merely a subtask assignee).
	 *
	 * @since 1.0.0
	 *
	 * @param TaskModel|object $task    Task model.
	 * @param int|null         $user_id WordPress user ID (defaults to current user).
	 *
	 * @return bool
	 */
	public static function salesRepOwns( $task, $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;

		return (int) $task->assigned_to === $user_id;
	}

	/**
	 * Whether a sales rep can view a task (parent assignee or assigned subtask).
	 *
	 * @since 1.0.0
	 *
	 * @param TaskModel|object $task    Task model.
	 * @param int|null         $user_id WordPress user ID (defaults to current user).
	 *
	 * @return bool
	 */
	public static function salesRepCanView( $task, $user_id = null ) {
		if ( self::salesRepOwns( $task, $user_id ) ) {
			return true;
		}

		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;

		return SubtaskModel::where( 'task_id', $task->id )
			->where( 'assigned_to', $user_id )
			->exists();
	}

	/**
	 * Scope: Filter by status
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param string                                $status Task status.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeByStatus( $query, $status ) {
		return $query->where( 'status', $status );
	}

	/**
	 * Scope: Get pending tasks only
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopePending( $query ) {
		return $query->where( 'status', TaskStatus::PENDING );
	}

	/**
	 * Scope: Get completed tasks only
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeCompleted( $query ) {
		return $query->where( 'status', TaskStatus::COMPLETED );
	}

	/**
	 * Scope: Get overdue tasks only (calculated from due_date)
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeOverdue( $query ) {
		return $query->where( 'status', TaskStatus::PENDING )
			->where( 'due_date', '<', current_time( 'Y-m-d' ) );
	}

	/**
	 * Scope: Get upcoming tasks only (due date in future)
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeUpcoming( $query ) {
		return $query->where( 'status', TaskStatus::PENDING )
			->where( 'due_date', '>', current_time( 'Y-m-d' ) );
	}

	/**
	 * Scope: Get tasks due today
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeDueToday( $query ) {
		return $query->where( 'status', TaskStatus::PENDING )
			->where( 'due_date', '=', current_time( 'Y-m-d' ) );
	}

	/**
	 * Scope: Get tasks due in a date range
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param string                                $start_date Start date (Y-m-d).
	 * @param string                                $end_date End date (Y-m-d).
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeDueBetween( $query, $start_date, $end_date ) {
		return $query->whereBetween( 'due_date', array( $start_date, $end_date ) );
	}

	/**
	 * Scope: Order by priority (high first)
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeByPriority( $query ) {
		return $query->orderByRaw(
			"FIELD(priority, 'high', 'medium', 'low')"
		);
	}

	/**
	 * Scope: Get tasks with pending reminders due now or in the past
	 *
	 * Includes safety limit: Only retries reminders up to 24 hours old to prevent
	 * infinite retries on permanently failing notifications.
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopePendingReminders( $query ) {
		$now            = current_time( 'mysql' );
		$retry_deadline = date( 'Y-m-d H:i:s', strtotime( '-24 hours', strtotime( $now ) ) );

		return $query->where( 'status', TaskStatus::PENDING )
			->where( 'reminder_at', '!=', null )
			->where( 'reminder_sent_at', '=', null )
			->where( 'reminder_at', '<=', $now )
			->where( 'reminder_at', '>=', $retry_deadline ); // Don't retry reminders older than 24 hours
	}

	/**
	 * Get contact_id accessor (for backward compatibility with frontend)
	 *
	 * @return int|null Contact ID if entity_type is CONTACT
	 */
	public function getContactIdAttribute() {
		return (int) $this->entity_type === TaskEntityType::CONTACT ? $this->entity_id : null;
	}

	/**
	 * Get deal_id accessor (for backward compatibility with frontend)
	 *
	 * @return int|null Deal ID if entity_type is DEAL
	 */
	public function getDealIdAttribute() {
		return (int) $this->entity_type === TaskEntityType::DEAL ? $this->entity_id : null;
	}

	/**
	 * @return int|null Project ID if entity_type is PROJECT
	 */
	public function getProjectIdAttribute() {
		return (int) $this->entity_type === TaskEntityType::PROJECT ? $this->entity_id : null;
	}

	/**
	 * Get calculated display status based on due_date
	 *
	 * - completed: Task is done
	 * - overdue: due_date < today AND not completed
	 * - due_today: due_date = today AND not completed
	 * - upcoming: due_date > today AND not completed
	 *
	 * @return string The calculated display status
	 */
	public function getDisplayStatusAttribute() {
		return TaskStatus::calculate_display_status( $this->status, $this->due_date );
	}

	/**
	 * Check if task is overdue
	 *
	 * @return bool True if task is past due date and not completed
	 */
	public function getIsOverdueAttribute() {
		return $this->display_status === TaskStatus::OVERDUE;
	}

	/**
	 * Get subtask checklist progress.
	 *
	 * Returns total and completed subtask counts so the UI can render a
	 * "2/4" indicator. Uses the loaded `subtasks` relationship when present
	 * to avoid extra queries; otherwise falls back to count queries.
	 *
	 * @since 1.0.0
	 *
	 * @return array{total:int, completed:int}
	 */
	public function getSubtaskProgressAttribute() {
		if ( $this->relationLoaded( 'subtasks' ) ) {
			$subtasks = $this->getRelation( 'subtasks' );

			return array(
				'total'     => (int) $subtasks->count(),
				'completed' => (int) $subtasks->where( 'is_completed', true )->count(),
			);
		}

		if ( ! $this->id ) {
			return array(
				'total'     => 0,
				'completed' => 0,
			);
		}

		return array(
			'total'     => (int) SubtaskModel::where( 'task_id', $this->id )->count(),
			'completed' => (int) SubtaskModel::where( 'task_id', $this->id )->where( 'is_completed', true )->count(),
		);
	}

	/**
	 * Mark task as completed
	 *
	 * @since 1.0.0
	 *
	 * @return bool True on success
	 */
	public function markCompleted() {
		$old_status = (string) $this->status;

		// Use query builder for atomic status update without full model validation
		$result = static::where( 'id', $this->id )->update(
			array(
				'status'       => TaskStatus::COMPLETED,
				'completed_at' => current_time( 'mysql', true ),
				'updated_at'   => current_time( 'mysql', true ),
			)
		);

		if ( $result ) {
			// Sync the model instance properties
			$this->status       = TaskStatus::COMPLETED;
			$this->completed_at = current_time( 'mysql', true );
			$this->updated_at   = current_time( 'mysql', true );

			$this->fire_lifecycle_hooks( array( 'status' => TaskStatus::COMPLETED ), $old_status );
		}

		return (bool) $result;
	}

	/**
	 * Mark task as pending
	 *
	 * @since 1.0.0
	 *
	 * @return bool True on success
	 */
	public function markPending() {
		$old_status = (string) $this->status;

		// Use query builder for atomic status update without full model validation
		$result = static::where( 'id', $this->id )->update(
			array(
				'status'           => TaskStatus::PENDING,
				'completed_at'     => null,
				'reminder_sent_at' => null, // Reset so reminder can be sent again
				'updated_at'       => current_time( 'mysql', true ),
			)
		);

		if ( $result ) {
			// Sync the model instance properties
			$this->status           = TaskStatus::PENDING;
			$this->completed_at     = null;
			$this->reminder_sent_at = null;
			$this->updated_at       = current_time( 'mysql', true );

			$this->fire_lifecycle_hooks( array( 'status' => TaskStatus::PENDING ), $old_status );
		}

		return (bool) $result;
	}

	/**
	 * Fire domain hooks after atomic status updates (markCompleted/markPending).
	 *
	 * Query-builder updates bypass Eloquent `updated` events, so we emit the same
	 * actions TaskModel::boot() would have fired via save().
	 *
	 * @param array  $changes    Changed attributes (new values).
	 * @param string $old_status Previous DB status.
	 */
	private function fire_lifecycle_hooks( array $changes, string $old_status ): void {
		/**
		 * Fires when a task is updated.
		 *
		 * @param TaskModel $task    The updated task.
		 * @param array     $changes Changed attributes.
		 */
		do_action( 'doublescale_task_updated', $this, $changes );

		if ( isset( $changes['status'] ) && TaskStatus::COMPLETED === $changes['status'] && TaskStatus::COMPLETED !== $old_status ) {
			/**
			 * Fires when a task is marked as completed.
			 *
			 * @param TaskModel $task The completed task.
			 */
			do_action( 'doublescale_task_completed', $this );
		}
	}

	/**
	 * Boot method - runs on model initialization
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function boot() {
		parent::boot();

		// Cascade: remove the task's subtask checklist when the task is deleted
		// so no orphaned subtask rows are left behind.
		static::deleting(
			function ( $task ) {
				SubtaskModel::where( 'task_id', $task->id )->delete();
				SubtaskGroupModel::where( 'task_id', $task->id )->delete();
				$task->labels()->detach();
				TaskRecurrenceModel::where( 'template_task_id', $task->id )->delete();
			}
		);

		// Validate on save
		static::saving(
			function ( $task ) {
				// Normalize status to DB status only (pending/completed)
				// Frontend might send display status, convert to DB status
				if ( ! TaskStatus::is_valid( $task->status ) ) {
					// If it's a display status like 'overdue' or 'upcoming', convert to 'pending'
					if ( in_array( $task->status, array( TaskStatus::OVERDUE, TaskStatus::UPCOMING, TaskStatus::DUE_TODAY ), true ) ) {
						$task->status = TaskStatus::PENDING;
					}
				}

				// Validate entity type (cast to int for consistent comparison)
				$entity_type = (int) $task->entity_type;
				if ( ! TaskEntityType::is_valid( $entity_type ) ) {
					throw new \Exception( 'Invalid entity type. Must be 1 (Contact), 2 (Deal), or 3 (Project).' );
				}

				if ( $entity_type === TaskEntityType::DEAL ) {
					if ( ! class_exists( \DoubleScale\Pro\Modules\Deals\Models\DealModel::class ) ) {
						throw new \Exception( 'Deal tasks require the Pipelines & Deals module.' );
					}
				}

				if ( $entity_type === TaskEntityType::PROJECT ) {
					if ( ! class_exists( \DoubleScale\Pro\Modules\Projects\Models\ProjectModel::class ) ) {
						throw new \Exception( 'Project tasks require the Projects module.' );
					}
				}
			}
		);

		// Set completed_at timestamp when marking as completed
		static::updating(
			function ( $task ) {
				if ( $task->isDirty( 'status' ) && $task->status === TaskStatus::COMPLETED && ! $task->completed_at ) {
					$task->completed_at = current_time( 'mysql', true );
				}

				// Clear completed_at and reminder_sent_at if status changed from completed to something else
				// This allows the reminder to be sent again when a task is reopened
				if ( $task->isDirty( 'status' ) && $task->getOriginal( 'status' ) === TaskStatus::COMPLETED && $task->status !== TaskStatus::COMPLETED ) {
					$task->completed_at     = null;
					$task->reminder_sent_at = null;
				}
			}
		);

		// Fire WordPress action when task is created.
		static::created(
			function ( $task ) {
				/**
				 * Fires when a new task is created.
				 *
				 * @since 1.2.0
				 *
				 * @param TaskModel $task The created task.
				 */
				do_action( 'doublescale_task_created', $task );
			}
		);

		// Fire WordPress action when task is updated.
		static::updated(
			function ( $task ) {
				$changes = $task->getChanges();

				/**
				 * Fires when a task is updated.
				 *
				 * @since 1.2.0
				 *
				 * @param TaskModel $task    The updated task.
				 * @param array      $changes Array of changed attributes.
				 */
				do_action( 'doublescale_task_updated', $task, $changes );

				// Fire specific action when task is marked as completed.
				if ( isset( $changes['status'] ) && $task->status === TaskStatus::COMPLETED ) {
					/**
					 * Fires when a task is marked as completed.
					 *
					 * @since 1.2.0
					 *
					 * @param TaskModel $task The completed task.
					 */
					do_action( 'doublescale_task_completed', $task );
				}

				// Fire specific action when assignment changes.
				if ( isset( $changes['assigned_to'] ) ) {
					$old_assigned_to = $task->getOriginal( 'assigned_to' );

					/**
					 * Fires when a task is reassigned to a different user.
					 *
					 * @since 1.2.0
					 *
					 * @param TaskModel $task            The updated task.
					 * @param int        $new_assigned_to New assignee user ID.
					 * @param int        $old_assigned_to Previous assignee user ID.
					 */
					do_action( 'doublescale_task_reassigned', $task, (int) $task->assigned_to, (int) $old_assigned_to );
				}
			}
		);
	}
}
