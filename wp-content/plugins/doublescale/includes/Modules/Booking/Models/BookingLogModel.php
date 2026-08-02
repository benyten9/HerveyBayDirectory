<?php

namespace DoubleScale\Modules\Booking\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

class BookingLogModel extends Model {

	protected $table = 'doublescale_booking_log';

	protected $primary_key = 'id';

	public $timestamps = true;

	protected $fillable = array(
		'booking_id',
		'type',
		'source',
		'message',
		'details',
	);

	protected $casts = array(
		'booking_id' => 'integer',
	);

	protected $rules = array(
		'booking_id' => 'required|integer',
		'type'       => 'required|string',
		'source'     => 'string',
		'message'    => 'required|string',
		'details'    => 'string',
	);

	protected $messages = array(
		'booking_id.required' => 'Booking ID is required.',
		'booking_id.integer'  => 'Booking ID must be an integer.',
		'type.required'       => 'Type is required.',
		'type.string'         => 'Type must be a string.',
		'source.string'       => 'Source must be a string.',
		'message.required'    => 'Message is required.',
		'message.string'      => 'Message must be a string.',
		'details.string'      => 'Details must be a string.',
	);

	public function booking() {
		return $this->belongsTo( BookingModel::class, 'booking_id', 'id' );
	}
}
