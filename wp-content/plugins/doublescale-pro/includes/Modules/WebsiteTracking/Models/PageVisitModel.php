<?php

/**
 * Class PageVisitModel
 * Model for page visit tracking
 *
 * @since 1.2.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\WebsiteTracking\Models;

use WPEloquent\Eloquent\Model;

/**
 * PageVisitModel class
 */
class PageVisitModel extends Model
{
	/**
	 * Table name
	 *
	 * @var string
	 * @since 1.2.0
	 */
	protected $table = 'doublescale_page_visits';

	/**
	 * Primary key
	 *
	 * @var string
	 * @since 1.2.0
	 */
	protected $primaryKey = 'id';

	/**
	 * Fillable columns
	 *
	 * @var array
	 * @since 1.2.0
	 */
	protected $fillable = array(
		'contact_id',
		'path',
		'query',
		'ip_address',
		'user_agent',
		'created_at',
		'updated_at',
	);

	/**
	 * Timestamps
	 *
	 * @var bool
	 * @since 1.2.0
	 */
	public $timestamps = true;

	/**
	 * Date attributes
	 *
	 * @var array
	 * @since 1.2.0
	 */
	protected $dates = array(
		'created_at',
		'updated_at',
	);

	/**
	 * Contact relationship
	 *
	 * @since 1.2.0
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function contact()
	{
		return $this->belongsTo(ContactModel::class, 'contact_id');
	}
}
