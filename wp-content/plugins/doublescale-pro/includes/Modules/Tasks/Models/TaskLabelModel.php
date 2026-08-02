<?php
/**
 * Task Label Model — global colored tags assignable to CRM tasks.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\Tasks\Models
 */

namespace DoubleScale\Pro\Modules\Tasks\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

/**
 * TaskLabelModel class
 */
class TaskLabelModel extends Model {

	/**
	 * Table name
	 *
	 * @var string
	 */
	protected $table = 'doublescale_task_labels';

	/**
	 * Primary key
	 *
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * Fillable columns
	 *
	 * @var array
	 */
	protected $fillable = array(
		'title',
		'color',
	);

	/**
	 * Attribute casts
	 *
	 * @var array<string, string>
	 */
	protected $casts = array();

	/**
	 * Timestamps
	 *
	 * @var bool
	 */
	public $timestamps = true;

	/**
	 * Validation rules
	 *
	 * @var array
	 */
	public $rules = array(
		'color' => 'required|string|size:7',
		'title' => 'nullable|string|max:255',
	);

	/**
	 * Validation messages
	 *
	 * @var array
	 */
	public $messages = array(
		'color.required' => 'Label color is required.',
		'color.size'     => 'Label color must be a 7-character hex value.',
		'title.max'      => 'Label title must not exceed 255 characters.',
	);

	/**
	 * Tasks carrying this label.
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
	 */
	public function tasks() {
		return $this->belongsToMany(
			TaskModel::class,
			'doublescale_task_label_relationship',
			'label_id',
			'task_id'
		);
	}

	/**
	 * Detach from all tasks when the label is deleted.
	 *
	 * @return void
	 */
	public static function boot() {
		parent::boot();

		static::deleting(
			function ( $label ) {
				$label->tasks()->detach();
			}
		);
	}
}
