<?php
/**
 * Sales contract type model.
 *
 * @package DoubleScale\Pro\Modules\Contracts
 */

namespace DoubleScale\Pro\Modules\Contracts\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

/**
 * ContractTypeModel class.
 */
class ContractTypeModel extends Model {

	/**
	 * @var string
	 */
	protected $table = 'doublescale_sales_contract_types';

	/**
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * @var string[]
	 */
	protected $fillable = array(
		'name',
	);

	/**
	 * @var bool
	 */
	public $timestamps = true;
}
