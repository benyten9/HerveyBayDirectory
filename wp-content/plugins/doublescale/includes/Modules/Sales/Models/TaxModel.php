<?php
/**
 * Sales tax model.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

/**
 * TaxModel class.
 */
class TaxModel extends Model {

	/**
	 * @var string
	 */
	protected $table = 'doublescale_sales_taxes';

	/**
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * @var string[]
	 */
	protected $fillable = array(
		'name',
		'rate',
	);

	/**
	 * @var array<string, string>
	 */
	protected $casts = array(
		'rate' => 'float',
	);

	/**
	 * @var bool
	 */
	public $timestamps = true;
}
