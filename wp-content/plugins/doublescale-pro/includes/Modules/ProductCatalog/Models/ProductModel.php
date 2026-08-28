<?php
/**
 * Product model.
 *
 * @package DoubleScale\Pro\Modules\ProductCatalog
 */

namespace DoubleScale\Pro\Modules\ProductCatalog\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

/**
 * ProductModel class.
 *
 * A saved line-item template. Values are copied onto documents at insert time,
 * so editing a product never alters documents that already reference it.
 */
class ProductModel extends Model {

	/**
	 * @var string
	 */
	protected $table = 'doublescale_products';

	/**
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * @var string[]
	 */
	protected $fillable = array(
		'name',
		'long_description',
		'unit',
		'group_id',
		'rate',
		'tax',
		'created_at',
		'updated_at',
	);

	/**
	 * `tax` mirrors LineItemTax[] ({id, name, rate} snapshots).
	 *
	 * @var array<string, string>
	 */
	protected $casts = array(
		'tax'  => 'array',
		'rate' => 'float',
	);
	// group_id is intentionally uncast: it is nullable, and an 'integer' cast
	// would turn NULL ("no group") into 0.

	/**
	 * @var bool
	 */
	public $timestamps = true;

	/**
	 * Optional organizational group.
	 */
	public function group() {
		return $this->belongsTo( ProductGroupModel::class, 'group_id' );
	}
}
