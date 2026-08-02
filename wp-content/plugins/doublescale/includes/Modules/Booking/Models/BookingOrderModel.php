<?php

namespace DoubleScale\Modules\Booking\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

class BookingOrderModel extends Model {

	protected $table = 'doublescale_booking_orders';

	protected $primary_key = 'id';

	public $timestamps = true;

	protected $fillable = array(
		'booking_id',
		'items',
		'discount',
		'total',
		'currency',
		'payment_method',
		'status',
		'transaction_id',
	);

	protected $casts = array(
		'booking_id'     => 'integer',
		'discount'       => 'float',
		'total'          => 'float',
		'items'          => 'array',
		'transaction_id' => 'string',
	);

	protected $rules = array(
		'booking_id'     => 'required|integer',
		'discount'       => 'numeric',
		'total'          => 'required',
		'currency'       => 'required',
		'payment_method' => 'required',
		'status'         => 'required',
	);

	protected $messages = array(
		'booking_id.required'     => 'Booking ID is required',
		'booking_id.integer'      => 'Booking ID must be an integer',
		'discount.numeric'        => 'Discount must be a number',
		'total.required'          => 'Total is required',
		'currency.required'       => 'Currency is required',
		'payment_method.required' => 'Payment method is required',
		'status.required'         => 'Status is required',
	);

	public function get_transaction_id() {
		return $this->transaction_id;
	}

	public function booking() {
		return $this->belongsTo( BookingModel::class, 'booking_id', 'id' );
	}
}
