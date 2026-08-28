<?php

/**
 * Class FormSubmissionModel
 *
 * This class is responsible for handling the form submission model
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Modules\Forms
 */

namespace DoubleScale\Modules\Forms\Models;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use WPEloquent\Eloquent\Model;

/**
 * FormSubmissionModel class
 */
class FormSubmissionModel extends Model {


	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table = 'doublescale_form_submissions';

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
		'form_id',
		'contact_id',
		'external_entry_id',
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
	 * Get form relationship
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function form() {
		return $this->belongsTo( FormModel::class, 'form_id' );
	}

	/**
	 * Get contact relationship
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function contact() {
		return $this->belongsTo( ContactModel::class, 'contact_id' );
	}
}
