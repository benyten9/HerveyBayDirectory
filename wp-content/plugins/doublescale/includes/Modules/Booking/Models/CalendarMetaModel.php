<?php

namespace DoubleScale\Modules\Booking\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

class CalendarMetaModel extends Model {

	protected $table = 'doublescale_booking_calendars_meta';

	protected $primary_key = 'id';

	public $timestamps = true;

	protected $fillable = array(
		'calendar_id',
		'meta_key',
		'meta_value',
	);

	protected $casts = array(
		'calendar_id' => 'integer',
	);

	protected $rules = array(
		'calendar_id' => 'required|integer',
		'meta_key'    => 'required',
	);

	protected $messages = array(
		'calendar_id.required' => 'Calendar ID is required.',
		'calendar_id.integer'  => 'Calendar ID must be an integer.',
		'meta_key.required'    => 'Meta key is required.',
	);

	public function calendar() {
		return $this->belongsTo( CalendarModel::class, 'calendar_id', 'id' );
	}
}
