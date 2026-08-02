<?php
/**
 * Booking settings helper.
 *
 * Reads from the shared `doublescale_settings` option (CRM core
 * {@see \DoubleScale\Core\Settings\Settings}) and projects it into the
 * booking schema with defaults filled in. Single source of truth for both the
 * REST controller and the public booking renderers, so a freshly-installed
 * site (where `payments.currency` etc. haven't been saved yet) doesn't crash
 * the renderer.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Helpers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;

final class BookingSettings {

	/**
	 * Booking settings shape with default values. Mirrors the schema declared
	 * in {@see \DoubleScale\Modules\Booking\Rest\Controllers\RestBookingSettingsController::get_schema()}.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function defaults(): array {
		return array(
			'general'  => array(
				'start_from'              => 'Monday',
				'time_format'             => '12',
				// Minutes (UI populates this in minutes; consumers in
				// `BookingJobs` multiply by MINUTE_IN_SECONDS at point of use).
				'auto_cancel_after'       => 30,
				'auto_complete_after'     => 60,
				'default_country_code'    => 'us',
				'default_time_slot_step'  => 15,
				'enable_summary_email'    => false,
				'summary_email_frequency' => 'daily',
				'include_ics'             => false,
			),
			'payments' => array(
				'currency' => 'USD',
			),
			'theme'    => array(
				'color_scheme' => 'system',
			),
		);
	}

	/**
	 * Stored settings overlaid on the defaults. Always returns a fully-shaped
	 * array — every group + key from {@see self::defaults()} is present.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$stored   = Settings::get_all();
		$defaults = self::defaults();

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$result = array();
		foreach ( $defaults as $group_key => $group_defaults ) {
			$result[ $group_key ] = array();
			$stored_group         = isset( $stored[ $group_key ] ) && is_array( $stored[ $group_key ] )
				? $stored[ $group_key ]
				: array();
			foreach ( $group_defaults as $setting_key => $default_value ) {
				$result[ $group_key ][ $setting_key ] = array_key_exists( $setting_key, $stored_group )
					? $stored_group[ $setting_key ]
					: $default_value;
			}
		}

		return $result;
	}
}
