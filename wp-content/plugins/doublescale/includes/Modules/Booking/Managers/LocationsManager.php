<?php

/**
 * Class Locations Manager
 * This class is responsible for handling the locations manager
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Managers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Abstracts\Manager;
use DoubleScale\Modules\Booking\Abstracts\Location;
use DoubleScale\Modules\Booking\Traits\Singleton;

/**
 * Locations Manager class
 */
class LocationsManager extends \DoubleScale\Modules\Booking\Abstracts\Manager {


	use \DoubleScale\Modules\Booking\Traits\Singleton;

	/**
	 * Register Location
	 *
	 * @since 1.0.0
	 *
	 * @param Location $location
	 * @throws \Exception
	 */
	public function register_location( Location $location ) {
		$this->register(
			$location,
			Location::class,
			'slug',
			array(
				'title'           => 'title',
				'is_integration'  => 'is_integration',
				'fields'          => 'get_admin_fields',
				'frontend_fields' => 'get_fields',
			)
		);
	}

	/**
	 * Get Location
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug
	 * @return Location|null
	 */
	public function get_location( $slug ) {
		return $this->get_item( $slug );
	}

	/**
	 * Get Locations
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_locations() {
		return $this->get_items();
	}

	/**
	 * Conferencing location types that require the Pro add-on.
	 *
	 * Keep this list in sync with the front-end conferencing section
	 * (Google Meet / Zoom / MS Teams).
	 *
	 * @return string[]
	 */
	public static function get_pro_conferencing_types() {
		return array( 'google-meet', 'zoom', 'ms-teams' );
	}

	/**
	 * Whether the Pro add-on is required for the given location type.
	 *
	 * @param string $type Location type slug.
	 * @return bool
	 */
	public static function is_pro_conferencing_type( $type ) {
		return in_array( (string) $type, self::get_pro_conferencing_types(), true );
	}

	/**
	 * Whether the Pro add-on is currently active.
	 *
	 * @return bool
	 */
	public static function is_pro_active() {
		return function_exists( 'doublescale_is_pro_addon_active' )
			&& doublescale_is_pro_addon_active();
	}

	/**
	 * Find the first Pro-only conferencing location in a list and return
	 * its type. Useful for building a precise validation message.
	 *
	 * @param array $locations Array of location entries with a `type` key.
	 * @return string|null
	 */
	public static function find_pro_conferencing_type( $locations ) {
		if ( ! is_array( $locations ) ) {
			return null;
		}

		foreach ( $locations as $location ) {
			$type = is_array( $location ) ? ( $location['type'] ?? null ) : null;
			if ( $type && self::is_pro_conferencing_type( $type ) ) {
				return $type;
			}
		}

		return null;
	}

	/**
	 * Human-readable label for a conferencing type.
	 *
	 * @param string $type
	 * @return string
	 */
	public static function get_conferencing_label( $type ) {
		switch ( $type ) {
			case 'google-meet':
				return __( 'Google Meet', 'doublescale' );
			case 'zoom':
				return __( 'Zoom Video', 'doublescale' );
			case 'ms-teams':
				return __( 'MS Teams', 'doublescale' );
			default:
				return (string) $type;
		}
	}

	public function get_location_label( $location ) {

		if ( ! $location ) {
			return null;
		}

		$location_fields = $location['fields'] ?? array();

		if ( empty( $location_fields ) ) {
			return $this->get_location( $location['type'] )->title;
		}

		if ( isset( $location_fields['display_on_booking'] ) && $location_fields['display_on_booking'] ) {
			switch ( $location['type'] ) {
				case 'online':
					return $location_fields['meeting_url'];
				case 'person_address':
					return $location_fields['location'];
				case 'person_phone':
					return $location_fields['phone'];
				case 'custom':
					return $location_fields['description'];
			}
		} else {
			if ( $location['type'] === 'custom' ) {
				return $location_fields['location'];
			}
			return $this->get_location( $location['type'] )->title;
		}
	}
}
