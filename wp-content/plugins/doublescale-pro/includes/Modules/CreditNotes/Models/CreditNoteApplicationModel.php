<?php
/**
 * Credit note application model.
 *
 * @package DoubleScale\Pro\Modules\CreditNotes
 */

namespace DoubleScale\Pro\Modules\CreditNotes\Models;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Vendor\WPEloquent\Eloquent\Model;
use DoubleScale\Core\Models\UserModel;
use DoubleScale\Modules\Documents\Models\InvoiceModel;

/**
 * CreditNoteApplicationModel class.
 */
class CreditNoteApplicationModel extends Model {

	/**
	 * @var string
	 */
	protected $table = 'doublescale_sales_credit_note_applications';

	/**
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * @var string[]
	 */
	protected $fillable = array(
		'credit_note_id',
		'invoice_id',
		'amount',
		'applied_date',
		'note',
		'applied_by_user_id',
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
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function creditNote() {
		return $this->belongsTo( CreditNoteModel::class, 'credit_note_id', 'id' );
	}

	/**
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function invoice() {
		// Invoices are the Documents module's data: matches nothing while it is off.
		if ( ! doublescale_module_data_ready( 'documents' ) ) {
			return $this->belongsTo( static::class, 'invoice_id', 'id' )->whereRaw( '1 = 0' );
		}
		return $this->belongsTo( InvoiceModel::class, 'invoice_id', 'id' );
	}

	/**
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function applied_by() {
		return $this->belongsTo( UserModel::class, 'applied_by_user_id', 'ID' );
	}
}
