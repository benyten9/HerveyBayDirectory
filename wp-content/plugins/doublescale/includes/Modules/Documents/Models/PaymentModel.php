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

	/**
	 * Let Pro (and other listeners) unlink credit-note applications before the row is gone.
	 *
	 * @return bool|null
	 */
	public function delete() {
		/**
		 * Fires immediately before an invoice payment row is deleted.
		 *
		 * Credit-note applications write a payment with transaction_id
		 * `cn_application:{id}`. Deleting that payment must revoke the matching
		 * application or the credit note still shows Applications / Revoke.
		 *
		 * @param PaymentModel $payment Payment about to be deleted.
		 */
		do_action( 'doublescale_sales_invoice_payment_deleting', $this );

		return parent::delete();
	}
}
