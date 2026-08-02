<?php

namespace DoubleScale\Modules\Booking\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

class BookingMetaModel extends Model {

	protected $table = 'doublescale_booking_meta';

	protected $primary_key = 'id';

	protected $fillable = array(
		'booking_id',
		'meta_key',
		'meta_value',
	);

	protected $casts = array(
		'booking_id' => 'int',
	);

	protected $rules = array(
		'booking_id' => 'required|integer',
		'meta_key'   => 'required',
	);

	protected $messages = array(
		'booking_id.required' => 'Booking ID is required',
		'booking_id.integer'  => 'Booking ID must be an integer',
		'meta_key.required'   => 'Meta key is required',
	);

	public function booking() {
		return $this->belongsTo( BookingModel::class, 'booking_id', 'id' );
	}
}
