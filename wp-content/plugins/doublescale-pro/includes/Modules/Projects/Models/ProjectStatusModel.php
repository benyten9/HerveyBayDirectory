<?php
/**
 * Project status model.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\Models;

use WPEloquent\Eloquent\Model;

class ProjectStatusModel extends Model {

	/**
	 * @var string
	 */
	protected $table = 'doublescale_project_statuses';

	/**
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * @var array<int, string>
	 */
	protected $fillable = array(
		'name',
		'color',
		'bg_color',
		'position',
		'is_completed',
		'is_protected',
		'created_at',
		'updated_at',
	);

	/**
	 * @var bool
	 */
	public $timestamps = true;

	/**
	 * @var array<string, string>
	 */
	protected $casts = array(
		'position'     => 'integer',
		'is_completed' => 'boolean',
		'is_protected' => 'boolean',
	);

	/**
	 * @var array<string, string>
	 */
	public $rules = array(
		'name'         => 'required|string|max:100',
		'color'        => 'nullable|string|max:20',
		'bg_color'     => 'nullable|string|max:20',
		'position'     => 'nullable|integer',
		'is_completed' => 'nullable|boolean',
		'is_protected' => 'nullable|boolean',
	);

	/**
	 * @var array<string, string>
	 */
	public $messages = array(
		'name.required' => 'Status name is required.',
		'name.max'      => 'Status name must not exceed 100 characters.',
	);

	/**
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function projects() {
		return $this->hasMany( ProjectModel::class, 'status_id', 'id' );
	}
}
