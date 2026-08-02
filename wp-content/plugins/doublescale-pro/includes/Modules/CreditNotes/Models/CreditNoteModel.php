<?php
/**
 * Credit note model.
 *
 * @package DoubleScale\Pro\Modules\CreditNotes
 */

namespace DoubleScale\Pro\Modules\CreditNotes\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;
use DoubleScale\Core\Models\UserModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Services\TotalsCalculator;
use DoubleScale\Modules\Sales\Services\SalesNumbering;
use DoubleScale\Pro\Modules\CreditNotes\Constants\CreditNoteStatus;

/**
 * CreditNoteModel class.
 */
class CreditNoteModel extends Model {

	/**
	 * @var string
	 */
	protected $table = 'doublescale_sales_credit_notes';

	/**
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * @var string[]
	 */
	protected $fillable = array(
		'credit_note_number',
		'hash',
		'status',
		'contact_id',
		'invoice_id',
		'sale_agent_user_id',
		'credit_note_date',
		'reason',
		'currency',
		'discount_type',
		'discount_value',
		'line_items',
		'subtotal',
		'total_tax',
		'adjustment',
		'total',
		'amount_applied',
		'billing_address',
		'client_note',
		'terms',
		'issuer_snapshot',
		'sent_at',
		'viewed_at',
	);

	/**
	 * @var array<string, string>
	 */
	protected $casts = array(
		'line_items'     => 'array',
		'discount_value' => 'float',
		'subtotal'       => 'float',
		'total_tax'      => 'float',
		'adjustment'     => 'float',
		'total'          => 'float',
		'amount_applied' => 'float',
	);

	/**
	 * @var bool
	 */
	public $timestamps = true;

	/**
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function contact() {
		return $this->belongsTo( ContactModel::class, 'contact_id', 'id' );
	}

	/**
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function invoice() {
		return $this->belongsTo( InvoiceModel::class, 'invoice_id', 'id' );
	}

	/**
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function sale_agent() {
		return $this->belongsTo( UserModel::class, 'sale_agent_user_id', 'ID' );
	}

	/**
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function applications() {
		return $this->hasMany( CreditNoteApplicationModel::class, 'credit_note_id', 'id' );
	}

	/**
	 * @return void
	 */
	public static function boot() {
		parent::boot();

		static::creating(
			function ( $credit_note ) {
				if ( empty( $credit_note->hash ) ) {
					$credit_note->hash = self::generate_hash();
				}
				if ( empty( $credit_note->credit_note_number ) ) {
					$credit_note->credit_note_number = SalesNumbering::next_credit_note_number();
				}
				if ( empty( $credit_note->status ) ) {
					$credit_note->status = CreditNoteStatus::OPEN;
				}
			}
		);

		static::saving(
			function ( $credit_note ) {
				$totals                    = TotalsCalculator::compute(
					$credit_note->line_items,
					(string) ( $credit_note->discount_type ?? 'none' ),
					(float) ( $credit_note->discount_value ?? 0 ),
					(float) ( $credit_note->adjustment ?? 0 )
				);
				$credit_note->subtotal  = $totals['subtotal'];
				$credit_note->total_tax = $totals['total_tax'];
				$credit_note->total     = $totals['total'];
			}
		);
	}

	/**
	 * @param string $hash Credit note hash.
	 * @return CreditNoteModel|null
	 */
	public static function get_by_hash( $hash ) {
		$hash = trim( (string) $hash );
		if ( '' === $hash || ! preg_match( '/^[a-f0-9]{32}$/', $hash ) ) {
			return null;
		}
		return self::query()->where( 'hash', $hash )->first();
	}

	/**
	 * @return string
	 */
	private static function generate_hash() {
		try {
			return md5( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			return md5( uniqid( (string) wp_rand(), true ) );
		}
	}
}
