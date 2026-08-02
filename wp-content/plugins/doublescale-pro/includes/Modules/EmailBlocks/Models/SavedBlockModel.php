<?php
/**
 * Saved block Eloquent model.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Modules\EmailBlocks
 */

namespace DoubleScale\Pro\Modules\EmailBlocks\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

/**
 * SavedBlockModel class
 */
class SavedBlockModel extends Model {

	/**
	 * Table name
	 *
	 * @var string
	 */
	protected $table = 'doublescale_saved_blocks';

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
		'name',
		'category',
		'content',
		'thumbnail',
		'created_by',
		'created_at',
		'updated_at',
	);

	/**
	 * Casts
	 *
	 * @var array
	 */
	protected $casts = array(
		'content' => 'array',
	);

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
	protected $rules = array(
		'name'     => 'required',
		'category' => 'required',
		'content'  => 'required',
	);

	/**
	 * Validation messages
	 *
	 * @var array
	 */
	protected $messages = array(
		'name.required'     => 'Block name is required',
		'category.required' => 'Block category is required',
		'content.required'  => 'Block content is required',
	);
}
