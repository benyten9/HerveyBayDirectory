<?php
/**
 * Invoice payment model.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;
use DoubleScale\Core\Models\UserModel;

/**
 * PaymentModel class.
 */
class PaymentModel extends Model {

	/**
	 * @var string
	 */
	protected $table = 'doublescale_sales_invoice_payments';

	/**
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * @var string[]
	 */
	protected $fillable = array(
		'invoice_id',
		'amount',
		'payment_mode',
		'payment_date',
		'transaction_id',
		'note',
		'recorded_by_user_id',
	);

	/**
	 * @var array<string, string>
	 */
	protected $casts = array(
		'amount' => 'float',
	);

	/**
	 * @var bool
	 */
	public $timestamps = true;

	/**
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function invoice() {
		return $this->belongsTo( InvoiceModel::class, 'invoice_id', 'id' );
	}

	/**
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function recorded_by() {
		return $this->belongsTo( UserModel::class, 'recorded_by_user_id', 'ID' );
	}
}
