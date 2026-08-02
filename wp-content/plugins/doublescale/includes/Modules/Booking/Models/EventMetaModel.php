<?php

namespace DoubleScale\Modules\Booking\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

class EventMetaModel extends Model {

	protected $table = 'doublescale_booking_events_meta';

	protected $primary_key = 'id';

	public $timestamps = true;

	protected $fillable = array(
		'event_id',
		'meta_key',
		'meta_value',
	);

	protected $casts = array(
		'event_id' => 'integer',
	);

	protected $rules = array(
		'event_id' => 'required|integer',
		'meta_key' => 'required',
	);

	protected $messages = array(
		'event_id.required' => 'Event ID is required',
		'event_id.integer'  => 'Event ID must be an integer',
		'meta_key.required' => 'Meta key is required',
	);

	public function event() {
		return $this->belongsTo( EventModel::class, 'event_id', 'id' );
	}
}
