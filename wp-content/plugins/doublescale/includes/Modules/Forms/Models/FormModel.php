<?php

/**
 * Class FormModel
 *
 * This class is responsible for handling the form model
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Modules\Forms
 */

namespace DoubleScale\Modules\Forms\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

/**
 * FormModel class
 */
class FormModel extends Model {


	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table = 'doublescale_forms';

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
		'name',
		'form_type',
		'form_id',
		'data',
		'status',
		'created_at',
		'updated_at',
	);

	/**
	 * Casts
	 *
	 * @var array
	 */
	protected $casts = array(
		'data' => 'array',
	);

	/**
	 * Rules
	 *
	 * @var array
	 */
	protected $rules = array(
		'name' => 'required',
	);

	/**
	 * Messages
	 *
	 * @var array
	 */
	protected $messages = array(
		'name.required' => 'From name is required',
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
	 * Get form by form id
	 *
	 * @param int    $form_id Form ID
	 * @param string $form_type Form Type
	 * @param string $status Status
	 *
	 * @return FormModel
	 */
	public static function get_form_by_form_id( $form_id, $form_type, $status = 'active' ) {
		return self::where( 'form_id', $form_id )
			->where( 'form_type', $form_type )
			->where( 'status', $status )
			->firstOrFail();
	}
}
