<?php
/**
 * Subtask Model
 * Handles a simple ordered checklist item attached to a parent CRM task.
 *
 * Deliberately lightweight: a subtask is only a title + completion flag +
 * position. It does NOT reuse TaskModel's validation (required due_date,
 * assignee, entity, etc.) so short checklist items like "Call" are valid.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;
use DoubleScale\Core\Models\UserModel;

/**
 * SubtaskModel class
 */
class SubtaskModel extends Model {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table = 'doublescale_task_subtasks';

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
		'task_id',
		'group_id',
		'title',
		'notes',
		'is_completed',
		'position',
		'assigned_to',
		'due_date',
		'reminder_at',
		'reminder_sent_at',
		'completed_at',
	);

	/**
	 * Attribute casts
	 *
	 * @var array<string, string>
	 *
	 * @since 1.0.0
	 */
	protected $casts = array(
		'is_completed' => 'boolean',
		'position'     => 'integer',
		'group_id'     => 'integer',
		'assigned_to'  => 'integer',
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
	 * Validation rules
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	public $rules = array(
		'task_id' => 'required|integer',
		'title'   => 'required|string|max:255',
	);

	/**
	 * Validation messages
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	public $messages = array(
		'task_id.required' => 'A parent task is required.',
		'title.required'   => 'Subtask title is required.',
		'title.max'        => 'Subtask title must not exceed 255 characters.',
	);

	/**
	 * Get the parent task this subtask belongs to.
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function task() {
		return $this->belongsTo( TaskModel::class, 'task_id', 'id' );
	}

	/**
	 * Get the group this subtask belongs to (if any).
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function group() {
		return $this->belongsTo( SubtaskGroupModel::class, 'group_id', 'id' );
	}

	/**
	 * Get the user this subtask is assigned to.
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function assignedUser() {
		return $this->belongsTo( UserModel::class, 'assigned_to', 'ID' );
	}

	/**
	 * Pending reminders that are due and not yet sent.
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopePendingReminders( $query ) {
		$now            = current_time( 'mysql' );
		$retry_deadline = date( 'Y-m-d H:i:s', strtotime( '-24 hours', strtotime( $now ) ) );

		return $query->where( 'is_completed', false )
			->whereNotNull( 'reminder_at' )
			->whereNull( 'reminder_sent_at' )
			->where( 'reminder_at', '<=', $now )
			->where( 'reminder_at', '>=', $retry_deadline );
	}

	/**
	 * Boot method - keep completed_at in sync with the completion flag.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function boot() {
		parent::boot();

		static::saving(
			function ( $subtask ) {
				if ( $subtask->is_completed && ! $subtask->completed_at ) {
					$subtask->completed_at = current_time( 'mysql', true );
				}

				if ( ! $subtask->is_completed && $subtask->completed_at ) {
					$subtask->completed_at = null;
				}

				if ( $subtask->isDirty( 'reminder_at' ) ) {
					$subtask->reminder_sent_at = null;
				}

				if ( $subtask->is_completed ) {
					$subtask->reminder_sent_at = null;
				}

				if ( empty( $subtask->due_date ) ) {
					$subtask->reminder_at      = null;
					$subtask->reminder_sent_at = null;
				}
			}
		);

		static::created(
			function ( $subtask ) {
				do_action( 'doublescale_subtask_created', $subtask );
			}
		);

		static::updated(
			function ( $subtask ) {
				$changes = $subtask->getChanges();
				do_action( 'doublescale_subtask_updated', $subtask, $changes );
			}
		);

		static::deleted(
			function ( $subtask ) {
				do_action( 'doublescale_subtask_deleted', $subtask );
			}
		);
	}
}
