<?php

namespace DoubleScale\Modules\Booking\Services;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Response;
use Exception;
use DoubleScale\Modules\Booking\Models\AvailabilityModel;

class AvailabilityService {

	/**
	 * Create a new availability schedule
	 *
	 * @since 1.0.0
	 *
	 * @param int    $user_id       WordPress user ID associated with the availability
	 * @param string $name          Unique name for the availability schedule
	 * @param array  $weekly_hours  Weekly availability structure (day-based time slots)
	 * @param array  $override      Special overrides for specific dates (format: ['YYYY-MM-DD' => [time slots]])
	 * @param string $timezone      Valid PHP timezone identifier (default: 'UTC')
	 * @param bool   $default       Whether this is the default availability
	 *
	 * @return array|WP_Error Returns availability details on success, WP_Error on validation failure
	 */
	public function create_availability( $user_id, $name, $weekly_hours, $override = array(), $timezone = 'UTC', $default = false ) {
		if ( ! $name ) {
			return new WP_Error( 'rest_availability_invalid_name', __( 'Invalid availability name.', 'doublescale' ), array( 'status' => 400 ) );
		}

		if ( empty( $weekly_hours ) ) {
			return new WP_Error( 'rest_availability_invalid_weekly_hours', __( 'Invalid weekly hours.', 'doublescale' ), array( 'status' => 400 ) );
		}

		if ( empty( $timezone ) ) {
			return new WP_Error( 'rest_availability_invalid_timezone', __( 'Invalid timezone.', 'doublescale' ), array( 'status' => 400 ) );
		}

		// Invariant: a user's first availability row MUST be the default,
		// because events require an availability and the system needs a
		// fallback when no explicit choice is made. Enforced here as the
		// single source of truth, so every entry point (REST, facade, tests)
		// gets the same behaviour.
		$has_existing = AvailabilityModel::where( 'user_id', $user_id )->exists();
		if ( ! $has_existing ) {
			$default = true;
		}

		// Prepare value data
		$value_data = array(
			'weekly_hours' => $weekly_hours,
			'override'     => $override,
		);

		$availability_data = array(
			'user_id'    => $user_id,
			'name'       => $name,
			'value'      => $value_data,
			'timezone'   => $timezone,
			'is_default' => $default,
		);

		try {
			// If this is being set as default, unset other defaults for this
			// user. Skip when has_existing is false because the upsert above
			// already established this row as the only default.
			if ( $default && $has_existing ) {
				AvailabilityModel::where( 'user_id', $user_id )
					->where( 'is_default', true )
					->update( array( 'is_default' => false ) );
			}

			$availability = AvailabilityModel::create( $availability_data );

			// Prepare response data
			$value_data = $availability->value ?: array();

			$response_data = array(
				'id'           => $availability->id,
				'user_id'      => $availability->user_id,
				'name'         => $availability->name,
				'weekly_hours' => $value_data['weekly_hours'] ?? array(),
				'override'     => $value_data['override'] ?? array(),
				'timezone'     => $availability->timezone,
				'is_default'   => $availability->is_default,
				'created_at'   => $availability->created_at,
				'updated_at'   => $availability->updated_at,
			);

			return $response_data;
		} catch ( Exception $e ) {
			return new WP_Error( 'rest_availability_create_failed', $e->getMessage(), array( 'status' => 400 ) );
		}
	}
}
