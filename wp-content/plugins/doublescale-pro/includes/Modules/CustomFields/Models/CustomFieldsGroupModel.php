<?php
/**
 * Custom fields group model.
 *
 * @package DoubleScale\Pro\Modules\CustomFields\Models
 */

namespace DoubleScale\Pro\Modules\CustomFields\Models;

use Illuminate\Support\Str;
use WPEloquent\Eloquent\Model;

/**
 * CustomFieldsGroupModel class
 */
class CustomFieldsGroupModel extends Model {

	/**
	 * Table name (logical; WPEloquent adds prefix if configured).
	 *
	 * @var string
	 */
	protected $table = 'doublescale_custom_fields_groups';

	/**
	 * Primary key.
	 *
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * Fillable columns.
	 *
	 * @var array
	 */
	protected $fillable = array(
		'name',
		'slug',
		'scope',
		'created_at',
		'updated_at',
	);

	/**
	 * Timestamps.
	 *
	 * @var bool
	 */
	public $timestamps = true;

	/**
	 * Rules.
	 *
	 * @var array
	 */
	protected $rules = array(
		'name'  => 'required',
		'scope' => 'required',
	);

	/**
	 * Validation messages.
	 *
	 * @var array
	 */
	protected $messages = array(
		'name.required'  => 'Group name is required',
		'scope.required' => 'Group scope is required',
	);

	/**
	 * Custom fields in this group.
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function custom_fields() {
		return $this->hasMany( CustomFieldModel::class, 'group_id' );
	}

	/**
	 * Boot — slug from name on create.
	 */
	public static function boot() {
		parent::boot();

		static::creating(
			function ( $group ) {
				$group->slug = Str::slug( $group->name );
			}
		);
	}
}
