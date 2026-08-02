<?php
/**
 * Task kanban status model.
 *
 * @package DoubleScale\Pro\Modules\Tasks\Models
 */

namespace DoubleScale\Pro\Modules\Tasks\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

/**
 * TaskStatusModel class.
 */
class TaskStatusModel extends Model {

	/**
	 * @var string
	 */
	protected $table = 'doublescale_task_statuses';

	/**
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * @var array<int, string>
	 */
	protected $fillable = array(
		'name',
		'status',
		'is_protected',
		'color',
		'sort_order',
	);

	/**
	 * @var bool
	 */
	public $timestamps = true;

	/**
	 * @var array<string, string>
	 */
	public $rules = array(
		'name'   => 'required|string|min:1|max:255',
		'status' => 'required|string|in:open,closed',
		'color'  => 'nullable|string|max:7',
	);

	/**
	 * Tasks in this status.
	 *
	 * @return \WPEloquent\Eloquent\Relations\HasMany
	 */
	public function tasks() {
		return $this->hasMany( TaskModel::class, 'status_id', 'id' );
	}
}
