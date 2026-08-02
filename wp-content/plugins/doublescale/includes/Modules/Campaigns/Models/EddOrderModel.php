<?php

/**
 * Class EddOrderModel
 *
 * This class is responsible for handling the Easy Digital Downloads order model
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

/**
 * EddOrderModel class
 */
class EddOrderModel extends Model {


	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table = 'edd_orders';

	/**
	 * Primary key
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $primary_key = 'id';

	/**
	 * Fillable columns
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	protected $fillable = array(
		'parent',
		'order_number',
		'status',
		'type',
		'user_id',
		'customer_id',
		'email',
		'ip',
		'gateway',
		'mode',
		'currency',
		'tax_rate_id',
		'subtotal',
		'total',
		'tax',
		'discount',
		'rate',
		'date_created',
		'date_modified',
		'date_completed',
		'date_refundable',
	);

	/**
	 * Attributes that should be hidden for arrays and JSON.
	 *
	 * @var array
	 */
	protected $visible = array(
		'id',
		'user_id',
		'email',
		'currency',
		'total',
		'subtotal',
		'discount',
		'tax',
		'status',
		'date_created',
		'date_modified',
		'date_completed',
		'date_refundable',
		'url',
	);

	/**
	 * Casts
	 *
	 * @var array
	 */
	protected $casts = array(
		'id'              => 'integer',
		'user_id'         => 'integer',
		'tax_rate_id'     => 'integer',
		'subtotal'        => 'float',
		'total'           => 'float',
		'tax'             => 'float',
		'discount'        => 'float',
		'rate'            => 'float',
		'status'          => 'string',
		'date_created'    => 'datetime',
		'date_modified'   => 'datetime',
		'date_completed'  => 'datetime',
		'date_refundable' => 'datetime',
	);

	/**
	 * Get contacts
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function contact() {
		return $this->belongsTo( ContactModel::class, 'email', 'email' );
	}

	/**
	 * Boot method
	 *
	 * @since 1.0.0
	 */
	public static function boot() {
		parent::boot();

		static::retrieved(
			function ( $order ) {
				$order->url = admin_url( 'edit.php?post_type=download&page=edd-payment-history&view=view-order-details&id=' . $order->id );
			}
		);
	}
}
