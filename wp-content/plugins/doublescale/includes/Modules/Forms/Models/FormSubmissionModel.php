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
use DoubleScale\Vendor\WPEloquent\Eloquent\Model;

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
		'status',
		'answers',
		'meta',
		'variant',
		'step',
		'resume_token',
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
	/**
	 * Casts.
	 *
	 * @var array
	 */
	protected $casts = array(
		'answers' => 'array',
		'meta'    => 'array',
	);

	public const STATUS_COMPLETE = 'complete';
	public const STATUS_PARTIAL  = 'partial';
	public const STATUS_SPAM     = 'spam';
	public const STATUS_PENDING  = 'pending_payment';

	public $timestamps = true;

	/**
	 * Get form relationship
	 *
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function form() {
		return $this->belongsTo( FormModel::class, 'form_id' );
	}

	/**
	 * Get contact relationship
	 *
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function contact() {
		return $this->belongsTo( ContactModel::class, 'contact_id' );
	}
}
