<?php
/**
 * Injects booking-specific config into window.doublescaleConfig.booking.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Admin;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Models\CalendarModel;
use DoubleScale\Modules\Booking\Models\AvailabilityModel;
use DoubleScale\Modules\Booking\Managers\FieldsManager;
use DoubleScale\Modules\Booking\Managers\LocationsManager;
use DoubleScale\Modules\Booking\Managers\MergeTagsManager;
use DoubleScale\Modules\Booking\Managers\IntegrationsManager;
use DoubleScale\Modules\Booking\Helpers\IntegrationsHelper;
use DoubleScale\Core\UserRoles\Permissions;

final class BookingAdminConfig {

	public static function register(): void {
		add_filter( 'doublescale_admin_config', array( __CLASS__, 'inject_booking_config' ) );
	}

	/**
	 * Build integration definitions for the booking admin bundle (host calendar popups, global settings tabs).
	 *
	 * Mirrors {@see IntegrationsManager::get_options()} after ensuring calendar integrations are registered.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_booking_integrations_for_admin(): array {
		if ( ! IntegrationsHelper::has_integrations() ) {
			return array();
		}

		$manager = IntegrationsManager::instance();
		$options = $manager->get_options();

		// Pro tier exposes the full set of calendar integrations via the
		// `doublescale_booking_integrations` filter (Apple/Google/Outlook/Zoom).
		// Free returns an empty list. Prime any class the manager hasn't seen
		// yet so the admin config payload includes its `fields` definition for
		// the host-calendar popups and global settings tabs.
		$calendar_primer = (array) apply_filters( 'doublescale_booking_integrations', array() );
		$calendar_slugs  = array( 'google', 'outlook', 'apple', 'zoom' );
		$needs_primer    = empty( $options );
		if ( ! $needs_primer ) {
			foreach ( $calendar_slugs as $slug ) {
				$fields = $options[ $slug ]['fields'] ?? null;
				if ( ! is_array( $fields ) || array() === $fields ) {
					$needs_primer = true;
					break;
				}
			}
		}

		if ( $needs_primer ) {
			foreach ( $calendar_primer as $class ) {
				if ( ! is_string( $class ) || ! class_exists( $class ) || ! method_exists( $class, 'instance' ) ) {
					continue;
				}
				try {
					$class::instance();
				} catch ( \Throwable $e ) {
					doublescale_get_logger()->error(
						'Booking integration admin primer failed',
						array(
							'source' => 'booking-admin-config',
							'class'  => $class,
							'error'  => $e->getMessage(),
						)
					);
				}
			}
			$options = $manager->get_options();
		}

		return $options;
	}

	/**
	 * @param array $config The existing admin config payload.
	 * @return array Config with booking data appended.
	 */
	public static function inject_booking_config( array $config ): array {
		$user_id = get_current_user_id();

		$has_calendars    = CalendarModel::where( 'user_id', $user_id )->exists();
		$availabilities   = AvailabilityModel::where( 'user_id', $user_id )->get()->toArray();
		$has_availability = ! empty( $availabilities );

		$locations    = LocationsManager::instance()->get_options();
		$fields_types = FieldsManager::instance()->get_options();
		$merge_tags   = MergeTagsManager::instance()->get_groups();

		$timezones = \DateTimeZone::listIdentifiers();
		$tz_map    = array();
		foreach ( $timezones as $tz ) {
			$tz_map[ $tz ] = $tz;
		}

		$wp_user = wp_get_current_user();

		$config['booking'] = array(
			'hasCalendars'    => $has_calendars,
			'hasAvailability' => $has_availability,
			'timezones'       => $tz_map,
			'locations'       => $locations,
			'availabilities'  => $availabilities,
			'fieldsTypes'     => $fields_types,
			'mergeTags'       => $merge_tags,
			'integrations'    => self::get_booking_integrations_for_admin(),
			'paymentGateways' => array(),
			'capabilities'    => array(),
			'currentUser'     => array(
				'id'           => (int) $wp_user->ID,
				'display_name' => $wp_user->display_name,
				'email'        => $wp_user->user_email,
				// Booking-scoped "admin": can view every host's calendars /
				// bookings / availability (CRM Manager, WP admin, or Booking
				// Manager). Booking Agent evaluates false (own scope only).
				'is_admin'     => Permissions::is_crm_manager()
					|| current_user_can( 'doublescale_booking_read_all_calendars' )
					|| current_user_can( 'doublescale_booking_manage_all_calendars' ),
				'capabilities' => array(),
			),
		);

		return self::append_booking_caps_and_gateways( $config );
	}

	/**
	 * Merge booking caps and gateway options into config['booking'] (must exist).
	 *
	 * @param array $config Admin config with booking key set.
	 * @return array
	 */
	private static function append_booking_caps_and_gateways( array $config ): array {
		$booking_caps = array();
		$all_caps     = wp_get_current_user()->allcaps;
		foreach ( $all_caps as $cap => $granted ) {
			if ( strpos( $cap, 'doublescale_booking_' ) === 0 && $granted ) {
				$booking_caps[ $cap ] = true;
			}
		}
		$config['booking']['capabilities']                = $booking_caps;
		$config['booking']['currentUser']['capabilities'] = $booking_caps;

		$payment_gateways = (array) apply_filters( 'doublescale_booking_payment_gateways', array() );
		$gw_options       = array();
		foreach ( $payment_gateways as $gw ) {
			if ( is_object( $gw ) && method_exists( $gw, 'get_options' ) ) {
				$gw_options[ $gw->slug ] = $gw->get_options();
			}
		}
		$config['booking']['paymentGateways'] = array_replace( (array) $config['booking']['paymentGateways'], $gw_options );

		return $config;
	}
}
