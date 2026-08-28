<?php
/**
 * Proposal model.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;
use DoubleScale\Core\Models\UserModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Documents\Constants\ProposalStatus;
use DoubleScale\Modules\Sales\Services\SalesNumbering;
use DoubleScale\Modules\Documents\Services\TotalsCalculator;

/**
 * ProposalModel class.
 */
class ProposalModel extends Model {

	/**
	 * @var string
	 */
	protected $table = 'doublescale_sales_proposals';

	/**
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * @var string[]
	 */
	protected $fillable = array(
		'proposal_number',
		'hash',
		'subject',
		'status',
		'template',
		'template_color',
		'contact_id',
		'assigned_user_id',
		'date',
		'open_till',
		'currency',
		'discount_type',
		'discount_value',
		'line_items',
		'subtotal',
		'adjustment',
		'total',
		'to_name',
		'address',
		'city',
		'state',
		'country',
		'zip',
		'email',
		'phone',
		'allow_comments',
		'issuer_snapshot',
		'sent_at',
		'viewed_at',
		'accepted_at',
		'declined_at',
		'decline_reason',
		'signed_name',
		'signature',
		'signed_ip',
		'sections',
		'terms',
	);

	/**
	 * @var array<string, string>
	 */
	protected $casts = array(
		'template'        => 'int',
		'sections'        => 'array',
		'line_items'      => 'array',
		'discount_value'  => 'float',
		'subtotal'        => 'float',
		'adjustment'      => 'float',
		'total'           => 'float',
		'allow_comments'  => 'boolean',
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
	public function assigned_user() {
		return $this->belongsTo( UserModel::class, 'assigned_user_id', 'ID' );
	}

	/**
	 * @return \Illuminate\Database\Eloquent\Relations\HasOne
	 */
	public function invoice() {
		return $this->hasOne( InvoiceModel::class, 'proposal_id', 'id' );
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
			function ( $proposal ) {
				if ( empty( $proposal->hash ) ) {
					$proposal->hash = self::generate_hash();
				}
				if ( empty( $proposal->proposal_number ) ) {
					$proposal->proposal_number = SalesNumbering::next_proposal_number();
				}
				if ( empty( $proposal->status ) ) {
					$proposal->status = ProposalStatus::DRAFT;
				}
			}
		);

		static::saving(
			function ( $proposal ) {
				$totals = TotalsCalculator::compute(
					$proposal->line_items,
					(string) ( $proposal->discount_type ?? 'none' ),
					(float) ( $proposal->discount_value ?? 0 ),
					(float) ( $proposal->adjustment ?? 0 )
				);
				$proposal->subtotal = $totals['subtotal'];
				$proposal->total    = $totals['total'];
			}
		);

		static::deleting(
			function ( $proposal ) {
				// Deal/project links are soft activity associations; remove this
				// proposal's rows so nothing keeps resolving a deleted document.
				if ( class_exists( '\DoubleScale\Modules\Activities\Models\ActivityAssociationModel' ) ) {
					\DoubleScale\Modules\Activities\Models\ActivityAssociationModel::where( 'entity_type', \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_PROPOSAL )
						->where( 'entity_id', $proposal->id )
						->delete();
				}

				// The converted invoice is a financial record and must survive,
				// but its proposal pointer would 404 — detach it.
				InvoiceModel::query()
					->where( 'proposal_id', (int) $proposal->id )
					->update( array( 'proposal_id' => null ) );
			}
		);
	}

	/**
	 * @param string $hash Proposal hash.
	 * @return ProposalModel|null
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
