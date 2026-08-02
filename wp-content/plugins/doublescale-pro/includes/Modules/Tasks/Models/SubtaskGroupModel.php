<?php
/**
 * Subtask Group Model
 * Named container for organizing checklist items on a parent CRM task.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

/**
 * SubtaskGroupModel class
 */
class SubtaskGroupModel extends Model {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table = 'doublescale_task_subtask_groups';

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
		'title',
		'position',
	);

	/**
	 * Attribute casts
	 *
	 * @var array<string, string>
	 *
	 * @since 1.0.0
	 */
	protected $casts = array(
		'position' => 'integer',
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
		'title.required'   => 'Group title is required.',
		'title.max'        => 'Group title must not exceed 255 characters.',
	);

	/**
	 * Get the parent task this group belongs to.
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function task() {
		return $this->belongsTo( TaskModel::class, 'task_id', 'id' );
	}

	/**
	 * Get the ordered subtasks in this group.
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function subtasks() {
		return $this->hasMany( SubtaskModel::class, 'group_id', 'id' )->orderBy( 'position' );
	}

	/**
	 * Boot method — deleting a group removes its subtasks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function boot() {
		parent::boot();

		static::created(
			function ( $group ) {
				do_action( 'doublescale_subtask_group_created', $group );
			}
		);

		static::updated(
			function ( $group ) {
				do_action( 'doublescale_subtask_group_updated', $group, $group->getChanges() );
			}
		);

		static::deleting(
			function ( $group ) {
				do_action( 'doublescale_subtask_group_deleting', $group );
				SubtaskModel::where( 'group_id', $group->id )->delete();
			}
		);
	}
}
