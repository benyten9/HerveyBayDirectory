<?php

namespace DoubleScale\Modules\Booking\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;
use Illuminate\Support\Arr;

class AvailabilityModel extends Model {

	protected $table = 'doublescale_booking_availability';

	protected $primary_key = 'id';

	public $timestamps = true;

	protected $fillable = array(
		'user_id',
		'name',
		'value',
		'timezone',
		'is_default',
	);

	protected $casts = array(
		'user_id'    => 'integer',
		'timezone'   => 'string',
		'is_default' => 'boolean',
	);

	protected $rules = array(
		'user_id'    => 'required|integer',
		'name'       => 'required',
		'value'      => 'required',
		'timezone'   => 'required',
		'is_default' => 'required',
	);

	protected $messages = array(
		'user_id.required'    => 'User ID is required.',
		'user_id.integer'     => 'User ID must be an integer.',
		'name.required'       => 'Name is required.',
		'value.required'      => 'Value is required.',
		'timezone.required'   => 'Timezone is required.',
		'is_default.required' => 'Is default is required.',
	);

	public function user() {
		return $this->belongsTo( UserModel::class, 'user_id', 'id' );
	}

	public function events() {
		return $this->hasMany( EventModel::class, 'availability_id', 'id' );
	}

	public function getValueAttribute() {
		// Route the read through getValueDataAttribute so a single
		// normalization path handles stdClass leaves, serialized strings,
		// and missing data. Otherwise callers using ->value would silently
		// see a different shape than callers using ->value_data, and the
		// set* helpers below would read one shape while writing another.
		return $this->getValueDataAttribute();
	}

	public function setValueAttribute( $value ) {
		$this->attributes['value'] = is_array( $value ) ? maybe_serialize( $value ) : $value;
	}

	public function getValueDataAttribute() {
		$raw_value = $this->attributes['value'] ?? '';

		if ( empty( $raw_value ) ) {
			return array();
		}

		$unserialized = maybe_unserialize( $raw_value );

		// Coerce to nested arrays at the data boundary so every consumer can use
		// array-bracket access without defending against stdClass leaves.
		if ( is_object( $unserialized ) ) {
			$unserialized = (array) $unserialized;
		}
		if ( ! is_array( $unserialized ) ) {
			return array();
		}
		foreach ( array( 'weekly_hours', 'override' ) as $key ) {
			if ( isset( $unserialized[ $key ] ) && is_object( $unserialized[ $key ] ) ) {
				$unserialized[ $key ] = json_decode( wp_json_encode( $unserialized[ $key ] ), true );
			}
		}
		return $unserialized;
	}

	public function getWeeklyHoursAttribute() {
		return Arr::get( $this->getValueDataAttribute(), 'weekly_hours', array() );
	}

	public function getOverrideAttribute() {
		return Arr::get( $this->getValueDataAttribute(), 'override', array() );
	}

	public function setWeeklyHours( $weekly_hours ) {
		$value_data                 = $this->getValueDataAttribute();
		$value_data['weekly_hours'] = $weekly_hours;
		$this->value                = $value_data;
	}

	public function setOverride( $override ) {
		$value_data             = $this->getValueDataAttribute();
		$value_data['override'] = $override;
		$this->value            = $value_data;
	}

	public static function getDefaultAvailability() {
		return array(
			'name'  => __( 'Default', 'doublescale' ),
			'value' => array(
				'weekly_hours' => array(
					'monday'    => array(
						'times' => array(
							array(
								'start' => '09:00',
								'end'   => '17:00',
							),
						),
						'off'   => false,
					),
					'tuesday'   => array(
						'times' => array(
							array(
								'start' => '09:00',
								'end'   => '17:00',
							),
						),
						'off'   => false,
					),
					'wednesday' => array(
						'times' => array(
							array(
								'start' => '09:00',
								'end'   => '17:00',
							),
						),
						'off'   => false,
					),
					'thursday'  => array(
						'times' => array(
							array(
								'start' => '09:00',
								'end'   => '17:00',
							),
						),
						'off'   => false,
					),
					'friday'    => array(
						'times' => array(
							array(
								'start' => '09:00',
								'end'   => '17:00',
							),
						),
						'off'   => false,
					),
					'saturday'  => array(
						'times' => array(
							array(
								'start' => '09:00',
								'end'   => '17:00',
							),
						),
						'off'   => true,
					),
					'sunday'    => array(
						'times' => array(
							array(
								'start' => '09:00',
								'end'   => '17:00',
							),
						),
						'off'   => true,
					),
				),
				'override'     => array(),
			),
		);
	}

	public static function createDefaultForUser( $user_id ) {
		$default_data = self::getDefaultAvailability();

		// Seed the schedule in the site's configured timezone, not UTC.
		// `09:00`–`17:00` are wall-clock times the host actually means in
		// their local zone; storing them under UTC made the booking page
		// render 9 AM Cairo as 12 PM and 5 PM as 8 PM, exposing slots
		// hours after the host had stopped working. `wp_timezone_string()`
		// honours both `timezone_string` and the `gmt_offset` fallback.
		return self::create(
			array(
				'user_id'    => $user_id,
				'name'       => $default_data['name'],
				'value'      => $default_data['value'],
				'timezone'   => self::resolveSiteTimezone(),
				'is_default' => true,
			)
		);
	}

	/**
	 * Resolve a usable IANA timezone string for default availabilities.
	 * Falls back to UTC only when the site has no timezone configured at all.
	 *
	 * @return string
	 */
	public static function resolveSiteTimezone() {
		if ( function_exists( 'wp_timezone_string' ) ) {
			$tz = wp_timezone_string();
			if ( ! empty( $tz ) ) {
				// wp_timezone_string() can return numeric offsets like "+02:00"
				// which DateTimeZone accepts but downstream code (and the UI)
				// is friendlier with named zones. Keep the offset form when
				// that's all the site has, but prefer a named zone if set.
				return $tz;
			}
		}
		return 'UTC';
	}

	public static function getUserDefault( $user_id ) {
		return self::where( 'user_id', $user_id )
			->where( 'is_default', true )
			->first();
	}

	public static function getUserDefaultAvailabilityId( $user_id ) {
		$default = self::getUserDefault( $user_id );
		return $default ? $default->id : null;
	}

	public static function getUserAvailabilities( $user_id ) {
		return self::where( 'user_id', $user_id )->get();
	}

	public function toCompatibleArray() {
		$value_data = $this->getValueDataAttribute();

		return array(
			'id'           => $this->id,
			'user_id'      => $this->user_id,
			'name'         => $this->name,
			'weekly_hours' => Arr::get( $value_data, 'weekly_hours', array() ),
			'override'     => Arr::get( $value_data, 'override', array() ),
			'timezone'     => $this->timezone,
			'is_default'   => $this->is_default,
			'created_at'   => $this->created_at,
			'updated_at'   => $this->updated_at,
		);
	}
}
