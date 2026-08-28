<?php

/**
 * Class WcOrderModel
 *
 * This class is responsible for handling the WooCommerce order model
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Models;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use WPEloquent\Eloquent\Model;

/**
 * WcOrderModel class
 */
class WcOrderModel extends Model {


	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table = 'wc_orders';

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
		'id',
		'status',
		'currency',
		'type',
		'tax_amount',
		'total_amount',
		'customer_id',
		'billing_email',
		'date_created_gmt',
		'date_updated_gmt',
		'parent_order_id',
		'payment_method',
		'payment_method_title',
		'transaction_id',
		'ip_address',
		'user_agent',
		'customer_note',
	);

	/**
	 * Casts
	 *
	 * @var array
	 */
	protected $casts = array(
		'id'               => 'integer',
		'customer_id'      => 'integer',
		'tax_amount'       => 'float',
		'total_amount'     => 'float',
		'date_created_gmt' => 'datetime',
		'date_updated_gmt' => 'datetime',
		'parent_order_id'  => 'integer',
	);

	/**
	 * Get contacts
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function contact() {
		return $this->belongsTo( ContactModel::class, 'email', 'billing_email' );
	}

	/**
	 * Boot method
	 *
	 * @since 1.0.0
	 */
	public static function boot() {
		parent::boot();

		// Add order status name not slug while retrieving
		static::retrieved(
			function ( $order ) {
				$status        = $order->status;
				$order->status = wc_get_order_status_name( $status );

				// Use HPOS-aware URL and avoid HTML entity escaping.
				if (
					class_exists( 'Automattic\Woocommerce\Utilities\OrderUtil' )
					&& method_exists( 'Automattic\Woocommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' )
					&& \Automattic\Woocommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
				) {
					$order->url = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . absint( $order->id ) );
				} else {
					$order->url = get_edit_post_link( $order->id, '' );
				}
			}
		);
	}
}
