<?php
/**
 * Product group model.
 *
 * @package DoubleScale\Pro\Modules\ProductCatalog
 */

namespace DoubleScale\Pro\Modules\ProductCatalog\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

/**
 * ProductGroupModel class.
 *
 * An organizational label for products. Groups never reach documents — a line
 * item carries no group reference — so deleting one only detaches products.
 */
class ProductGroupModel extends Model {

	/**
	 * @var string
	 */
	protected $table = 'doublescale_product_groups';

	/**
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * @var string[]
	 */
	protected $fillable = array(
		'name',
		'created_at',
		'updated_at',
	);

	/**
	 * @var bool
	 */
	public $timestamps = true;

	/**
	 * Products belonging to this group.
	 */
	public function products() {
		return $this->hasMany( ProductModel::class, 'group_id' );
	}
}
