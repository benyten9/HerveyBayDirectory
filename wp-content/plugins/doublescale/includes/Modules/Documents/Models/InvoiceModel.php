<?php
/**
 * Invoice model.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;
use DoubleScale\Core\Models\UserModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Documents\Constants\InvoiceStatus;
use DoubleScale\Modules\Sales\Services\SalesNumbering;
use DoubleScale\Modules\Documents\Services\TotalsCalculator;

/**
 * InvoiceModel class.
 */
class InvoiceModel extends Model {

	/**
	 * @var string
	 */
	protected $table = 'doublescale_sales_invoices';

	/**
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * @var string[]
	 */
	protected $fillable = array(
		'invoice_number',
		'hash',
		'status',
		'template',
		'template_color',
		'contact_id',
		'proposal_id',
		'subscription_id',
		'recurrence_id',
		'sale_agent_user_id',
		'invoice_date',
		'due_date',
		'currency',
		'allowed_payment_modes',
		'discount_type',
		'discount_value',
		'line_items',
		'subtotal',
		'total_tax',
		'adjustment',
		'total',
		'amount_paid',
		'external_payment_ref',
		'stripe_payment_intent_id',
		'billing_address',
		'shipping_address',
		'client_note',
		'terms',
		'sections',
		'issuer_snapshot',
		'sent_at',
		'viewed_at',
	);

	/**
	 * @var array<string, string>
	 */
	protected $casts = array(
		'template'              => 'int',
		'sections'              => 'array',
		'line_items'            => 'array',
		'allowed_payment_modes' => 'array',
		'discount_value'        => 'float',
		'subtotal'              => 'float',
		'total_tax'             => 'float',
		'adjustment'            => 'float',
		'total'                 => 'float',
		'amount_paid'           => 'float',
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
	public function proposal() {
		return $this->belongsTo( ProposalModel::class, 'proposal_id', 'id' );
	}

	/**
	 * Subscription that generated this child invoice (add-on feature).
	 *
	 * The model class is resolved through the `doublescale_subscription_model_class`
	 * filter, supplied by the DoubleScale Subscriptions add-on. Free holds no
	 * compile-time dependency on that plugin; the string literal default keeps the
	 * relation resolvable once the add-on is active even if the filter is
	 * detached. The relation is only ever read by the add-on UI — when the add-on
	 * is absent, `subscription_id` is an inert column and this method is never
	 * called, so the (then non-existent) default class is never instantiated.
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function subscription() {
		$class = apply_filters( 'doublescale_subscription_model_class', '\\DoubleScale\\Subscriptions\\Models\\SubscriptionModel' );
		if ( ! is_string( $class ) || '' === $class ) {
			$class = '\\DoubleScale\\Subscriptions\\Models\\SubscriptionModel';
		}
		return $this->belongsTo( $class, 'subscription_id', 'id' );
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
	public function payments() {
		return $this->hasMany( PaymentModel::class, 'invoice_id', 'id' );
	}

	/**
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder.
	 * @param string                                $status Status value.
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeByStatus( $query, $status ) {
		return $query->where( 'status', $status );
	}

	/**
	 * @return void
	 */
	public static function boot() {
		parent::boot();

		static::creating(
			function ( $invoice ) {
				if ( empty( $invoice->hash ) ) {
					$invoice->hash = self::generate_hash();
				}
				if ( empty( $invoice->invoice_number ) ) {
					$invoice->invoice_number = SalesNumbering::next_invoice_number();
				}
				if ( empty( $invoice->status ) ) {
					$invoice->status = InvoiceStatus::DRAFT;
				}
			}
		);

		static::saving(
			function ( $invoice ) {
				$totals             = TotalsCalculator::compute(
					$invoice->line_items,
					(string) ( $invoice->discount_type ?? 'none' ),
					(float) ( $invoice->discount_value ?? 0 ),
					(float) ( $invoice->adjustment ?? 0 )
				);
				$invoice->subtotal  = $totals['subtotal'];
				$invoice->total_tax = $totals['total_tax'];
				$invoice->total     = $totals['total'];
			}
		);

		static::deleting(
			function ( $invoice ) {
				// Deal/project links are soft activity associations; remove this
				// invoice's rows so nothing keeps resolving a deleted document.
				if ( class_exists( '\DoubleScale\Modules\Activities\Models\ActivityAssociationModel' ) ) {
					\DoubleScale\Modules\Activities\Models\ActivityAssociationModel::where( 'entity_type', \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_INVOICE )
						->where( 'entity_id', $invoice->id )
						->delete();
				}

				PaymentModel::query()
					->where( 'invoice_id', (int) $invoice->id )
					->delete();
			}
		);
	}

	/**
	 * @param string $hash Invoice hash.
	 * @return InvoiceModel|null
	 */
	public static function get_by_hash( $hash ) {
		$hash = trim( (string) $hash );
		if ( '' === $hash || ! preg_match( '/^[a-f0-9]{32}$/', $hash ) ) {
			return null;
		}
		return self::query()->where( 'hash', $hash )->first();
	}

	/**
	 * Find an invoice by in-progress gateway payment reference.
	 *
	 * @param string $ref External payment reference (e.g. Stripe PI id).
	 * @return InvoiceModel|null
	 */
	public static function find_by_external_payment_ref( string $ref ): ?self {
		$ref = trim( $ref );
		if ( '' === $ref ) {
			return null;
		}

		$invoice = self::query()->where( 'external_payment_ref', $ref )->first();
		if ( $invoice ) {
			return $invoice;
		}

		// Legacy rows may only have stripe_payment_intent_id populated.
		return self::query()->where( 'stripe_payment_intent_id', $ref )->first();
	}

	/**
	 * Persist an in-progress payment reference from a gateway.
	 *
	 * @param string $ref           Gateway payment reference.
	 * @param bool   $is_stripe_pi  When true, also store in stripe_payment_intent_id.
	 * @return void
	 */
	public function set_in_progress_payment_ref( string $ref, bool $is_stripe_pi = true ): void {
		$ref = trim( $ref );
		if ( '' === $ref ) {
			return;
		}

		$this->external_payment_ref = $ref;
		if ( $is_stripe_pi ) {
			$this->stripe_payment_intent_id = $ref;
		}
		$this->save();
	}

	/**
	 * Clear in-progress payment references after completion, cancel, or full refund.
	 *
	 * @return void
	 */
	public function clear_in_progress_payment_refs(): void {
		$this->external_payment_ref     = null;
		$this->stripe_payment_intent_id = null;
		$this->save();
	}

	/**
	 * Active in-progress payment reference (external column preferred).
	 *
	 * @return string|null
	 */
	public function in_progress_payment_ref(): ?string {
		$ref = trim( (string) ( $this->external_payment_ref ?? '' ) );
		if ( '' !== $ref ) {
			return $ref;
		}

		$legacy = trim( (string) ( $this->stripe_payment_intent_id ?? '' ) );
		return '' !== $legacy ? $legacy : null;
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
