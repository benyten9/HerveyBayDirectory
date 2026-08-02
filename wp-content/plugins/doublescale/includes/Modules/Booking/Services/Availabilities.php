<?php
/**
 * Class Availabilities
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Services;

defined( 'ABSPATH' ) || exit;

use Illuminate\Support\Arr;
use DoubleScale\Modules\Booking\Models\AvailabilityModel;

/**
 * Availabilities service facade.
 *
 * Exposes a static, array-shaped surface over {@see AvailabilityModel} for
 * call sites (EventModel, REST controllers) that pass availabilities around
 * as plain arrays.
 */
class Availabilities {


	/**
	 * Option Name.
	 *
	 * @var string
	 */
	public static $option_name = 'doublescale_booking_availabilities';

	/**
	 * Get Availabilities
	 *
	 * @return array
	 */
	public static function get_availabilities() {
		$availabilities = AvailabilityModel::all();
		$result         = array();

		foreach ( $availabilities as $availability ) {
			$result[] = $availability->toCompatibleArray();
		}

		return $result;
	}

	/**
	 * Get Availability
	 *
	 * @param string $id
	 *
	 * @return array|null
	 */
	public static function get_availability( $id ) {
		$availability = AvailabilityModel::find( $id );

		return $availability ? $availability->toCompatibleArray() : null;
	}

	/**
	 * Get User Availabilities
	 *
	 * @param string $id
	 *
	 * @return array
	 */
	public static function get_user_availabilities( $id ) {
		$availabilities = AvailabilityModel::getUserAvailabilities( $id );
		$result         = array();

		foreach ( $availabilities as $availability ) {
			$result[] = $availability->toCompatibleArray();
		}

		return $result;
	}

	/**
	 * Get User Default Availability
	 *
	 * @param string $id
	 *
	 * @return array|null
	 */
	public static function get_user_default_availability( $id ) {
		$availability = AvailabilityModel::getUserDefault( $id );

		return $availability ? $availability->toCompatibleArray() : null;
	}

	/**
	 * Add Availability.
	 *
	 * Returns the WP_Error from AvailabilityService instead of swallowing
	 * it as an empty array, so callers can distinguish "missing data" from
	 * "validation failed" and surface the actual reason. The previous
	 * silent-fail made provisioning bugs invisible.
	 *
	 * @param array $availability
	 *
	 * @return array|\WP_Error
	 */
	public static function add_availability( $availability ) {
		// Delegates to AvailabilityService so the "force first as default"
		// invariant is applied consistently.
		$service = new AvailabilityService();
		$result  = $service->create_availability(
			Arr::get( $availability, 'user_id' ),
			Arr::get( $availability, 'name' ),
			Arr::get( $availability, 'weekly_hours', array() ),
			Arr::get( $availability, 'override', array() ),
			Arr::get( $availability, 'timezone', 'UTC' ),
			(bool) Arr::get( $availability, 'is_default', false )
		);

		if ( is_wp_error( $result ) ) {
			doublescale_get_logger()->warning(
				'Failed to add availability',
				array(
					'source'  => 'booking-availabilities-facade',
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
			);
			return $result;
		}

		$model = AvailabilityModel::find( $result['id'] );
		return $model ? $model->toCompatibleArray() : array();
	}

	/**
	 * Update Availability
	 *
	 * @param array $availability
	 *
	 * @return boolean
	 */
	public static function update_availability( $availability ) {
		$id    = Arr::get( $availability, 'id' );
		$model = AvailabilityModel::find( $id );

		if ( ! $model ) {
			return false;
		}

		// Prepare value data
		$value_data = array(
			'weekly_hours' => Arr::get( $availability, 'weekly_hours', array() ),
			'override'     => Arr::get( $availability, 'override', array() ),
		);

		$update_data = array(
			'name'       => Arr::get( $availability, 'name', $model->name ),
			'value'      => $value_data,
			'timezone'   => Arr::get( $availability, 'timezone', $model->timezone ),
			'is_default' => Arr::get( $availability, 'is_default', $model->is_default ),
		);

		// If setting as default, unset other defaults for this user
		if ( $update_data['is_default'] && ! $model->is_default ) {
			AvailabilityModel::where( 'user_id', $model->user_id )
				->where( 'is_default', true )
				->update( array( 'is_default' => false ) );
		}

		return $model->update( $update_data );
	}

	/**
	 * Delete Availability
	 *
	 * @param string $id
	 *
	 * @return boolean
	 */
	public static function delete_availability( $id ) {
		$model = AvailabilityModel::find( $id );

		if ( ! $model ) {
			return false;
		}

		return $model->delete();
	}

	/**
	 * Get default availability
	 *
	 * @since 1.0.0
	 *
	 * @return array|null
	 */
	public static function get_default_availability() {
		$availability = AvailabilityModel::where( 'is_default', true )->first();

		return $availability ? $availability->toCompatibleArray() : null;
	}

	/**
	 * Get system availability structure
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function get_system_availability() {
		$default_data = AvailabilityModel::getDefaultAvailability();

		return array(
			'id'           => 'default',
			'is_default'   => true,
			'user_id'      => 'system',
			'name'         => $default_data['name'],
			'weekly_hours' => $default_data['weekly_hours'],
			'override'     => $default_data['override'] ?? array(),
			// Use the site's configured timezone instead of a hard-coded
			// Africa/Cairo. The previous value silently bound every
			// fallback host to Cairo time regardless of where the site
			// actually lived.
			'timezone'     => AvailabilityModel::resolveSiteTimezone(),
		);
	}

	/**
	 * Add default availability if not exists
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function add_default_availability() {
		$availabilities = AvailabilityModel::all();

		if ( $availabilities->isEmpty() ) {
			$default_availability = self::get_system_availability();
			// Remove the 'id' field as it will be auto-generated
			unset( $default_availability['id'] );
			self::add_availability( $default_availability );
		}
	}
}
