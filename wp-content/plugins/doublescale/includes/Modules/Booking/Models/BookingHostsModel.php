<?php

namespace DoubleScale\Modules\Booking\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

class BookingHostsModel extends Model {

	protected $table = 'doublescale_booking_hosts';

	protected $primary_key = 'id';

	public $timestamps = true;

	protected $fillable = array(
		'id',
		'booking_id',
		'user_id',
		'status',
		'created_at',
		'updated_at',
	);

	protected $casts = array(
		'booking_id' => 'integer',
		'user_id'    => 'integer',
		'status'     => 'string',
	);

	protected $rules = array(
		'booking_id' => 'required|integer',
		'user_id'    => 'required|integer',
		'status'     => 'required|string',
	);

	protected $messages = array(
		'booking_id.required' => 'Booking ID is required',
		'booking_id.integer'  => 'Booking ID must be an integer',
		'user_id.required'    => 'User ID is required',
		'user_id.integer'     => 'User ID must be an integer',
		'status.required'     => 'Status is required',
		'status.string'       => 'Status must be a string',
	);

	public function booking() {
		return $this->belongsTo( BookingModel::class, 'booking_id', 'id' );
	}

	public function user() {
		return $this->belongsTo( UserModel::class, 'user_id', 'id' );
	}
}
