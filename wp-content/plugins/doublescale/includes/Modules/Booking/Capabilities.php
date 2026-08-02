<?php
/**
 * Booking module capabilities.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking;

defined( 'ABSPATH' ) || exit;

use WP_Roles;
use WP_User;
use DoubleScale\Modules\Booking\Models\CalendarModel;
use DoubleScale\Modules\Booking\Models\EventModel;
use DoubleScale\Modules\Booking\Models\BookingModel;
use DoubleScale\Core\UserRoles\UserRoles;

class Capabilities {

	/**
	 * Bump when the booking role-to-capability map changes so existing installs
	 * re-run {@see sync_capabilities_for_user_roles()} on next boot.
	 */
	private const CAPS_SYNC_VERSION = '2026-06-10-booking-roles-v2';

	public static function get_core_capabilities() {
		return array(
			'calendars'    => array(
				'title'        => __( 'Calendar Management', 'doublescale' ),
				'capabilities' => array(
					'doublescale_booking_manage_own_calendars' => __( 'Manage only the user\'s own calendars', 'doublescale' ),
					'doublescale_booking_read_all_calendars'   => __( 'Read access to all calendars across users', 'doublescale' ),
					'doublescale_booking_manage_all_calendars' => __( 'Manage all calendars created by all users', 'doublescale' ),
				),
			),
			'bookings'     => array(
				'title'        => __( 'Booking Access', 'doublescale' ),
				'capabilities' => array(
					'doublescale_booking_read_own_bookings'   => __( 'Read only the user\'s own bookings', 'doublescale' ),
					'doublescale_booking_read_all_bookings'   => __( 'Read access to all bookings', 'doublescale' ),
					'doublescale_booking_manage_own_bookings' => __( 'Manage only the user\'s own bookings', 'doublescale' ),
					'doublescale_booking_manage_all_bookings' => __( 'Manage all bookings across calendars', 'doublescale' ),
				),
			),
			'availability' => array(
				'title'        => __( 'Availability Management', 'doublescale' ),
				'capabilities' => array(
					'doublescale_booking_read_own_availability'   => __( 'Read only the user\'s own availability', 'doublescale' ),
					'doublescale_booking_read_all_availability'   => __( 'Read access to all availability schedules across users', 'doublescale' ),
					'doublescale_booking_manage_own_availability' => __( 'Manage only the user\'s own availability schedules', 'doublescale' ),
					'doublescale_booking_manage_all_availability' => __( 'Manage all availability schedules for all users', 'doublescale' ),
				),
			),
		);
	}

	public static function current_user_can( $capability ) {
		if ( is_multisite() && is_super_admin() ) {
			return true;
		}
		return current_user_can( $capability );
	}

	public static function get_current_user_capabilities() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return array();
		}

		if ( is_multisite() && is_super_admin( $user_id ) ) {
			$all_caps = self::get_all_capabilities();
			return array_fill_keys( $all_caps, true );
		}

		$user         = new WP_User( $user_id );
		$capabilities = $user->get_role_caps();
		$booking_caps = self::get_all_capabilities();
		$allowed_keys = array_merge( $booking_caps, array( 'manage_options' ) );

		return array_intersect_key( $capabilities, array_flip( $allowed_keys ) );
	}

	/**
	 * Booking-module capability slugs only (`doublescale_booking_*`).
	 *
	 * `doublescale_access` is owned by {@see UserRoles} — never add/remove it
	 * during booking cap sync or Sales/Support roles lose admin menu access.
	 *
	 * @return string[]
	 */
	public static function get_booking_capability_slugs(): array {
		$slugs = array();

		foreach ( self::get_core_capabilities() as $group ) {
			$slugs = array_merge( $slugs, array_keys( $group['capabilities'] ) );
		}

		return $slugs;
	}

	public static function get_all_capabilities() {
		return array_merge( array( 'doublescale_access' ), self::get_booking_capability_slugs() );
	}

	public static function get_basic_capabilities() {
		$basic_capabilities = array();

		foreach ( self::get_core_capabilities() as $group ) {
			foreach ( $group['capabilities'] as $capability => $description ) {
				if ( strpos( $capability, 'own' ) !== false ) {
					$basic_capabilities[] = $capability;
				}
			}
		}

		return $basic_capabilities;
	}

	/**
	 * Capabilities granted to a single role per the matrix.
	 *
	 * - administrator + CRM Manager + Booking Manager: full caps (own + all_*).
	 * - Booking Agent: own caps only.
	 * - Sales Manager / Sales Rep: no booking access (exclusive to booking roles).
	 *
	 * @param string $role Role slug.
	 * @return string[] Capability names.
	 */
	public static function get_caps_for_role( string $role ): array {
		switch ( $role ) {
			case 'administrator':
			case UserRoles::CRM_MANAGER:
			case UserRoles::BOOKING_MANAGER:
				return self::get_all_capabilities();

			case UserRoles::BOOKING_AGENT:
				return self::get_basic_capabilities();
		}

		return array();
	}

	/**
	 * Roles that may receive booking caps (including roles we must strip caps from).
	 *
	 * @return string[]
	 */
	private static function sync_role_slugs(): array {
		return array(
			'administrator',
			UserRoles::CRM_MANAGER,
			UserRoles::SALES_MANAGER,
			UserRoles::SALES_REP,
			UserRoles::BOOKING_MANAGER,
			UserRoles::BOOKING_AGENT,
		);
	}

	/**
	 * Idempotently sync booking caps onto every role that may hold them.
	 *
	 * @return void
	 */
	public static function sync_capabilities_for_user_roles() {
		global $wp_roles;

		if ( ! class_exists( 'WP_Roles' ) ) {
			return;
		}

		if ( ! isset( $wp_roles ) ) {
			$wp_roles = new WP_Roles();
		}

		$all_caps = self::get_booking_capability_slugs();

		// TODO: multisite — `$wp_roles->add_cap()` only affects the current blog. For multisite installs
		// where non-super-admins should receive booking caps on sub-sites, wrap this loop in a
		// `switch_to_blog()` over `get_sites()`. Single-site is the assumption for now.
		foreach ( self::sync_role_slugs() as $role_slug ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}

			$should_have = array_fill_keys( self::get_caps_for_role( $role_slug ), true );

			foreach ( $all_caps as $capability ) {
				if ( isset( $should_have[ $capability ] ) ) {
					$wp_roles->add_cap( $role_slug, $capability );
				} else {
					$wp_roles->remove_cap( $role_slug, $capability );
				}
			}
		}

		if ( is_multisite() ) {
			add_filter( 'user_has_cap', array( __CLASS__, 'grant_super_admin_capabilities' ), 10, 4 );
		}

		update_option( 'doublescale_booking_caps_version', self::CAPS_SYNC_VERSION, false );
	}

	/**
	 * Re-sync booking caps when the role map changes.
	 *
	 * @return void
	 */
	public static function ensure_capabilities_synced(): void {
		$current = (string) get_option( 'doublescale_booking_caps_version', '' );
		if ( self::CAPS_SYNC_VERSION === $current ) {
			return;
		}

		self::sync_capabilities_for_user_roles();

		// The WP_User object may have been loaded before caps changed on the role.
		if ( is_user_logged_in() ) {
			wp_get_current_user()->get_role_caps();
		}
	}

	/**
	 * @deprecated Use {@see sync_capabilities_for_user_roles()} instead.
	 */
	public static function assign_capabilities_for_user_roles() {
		self::sync_capabilities_for_user_roles();
	}

	public static function grant_super_admin_capabilities( $allcaps, $caps, $args, $user ) {
		if ( ! is_multisite() || ! is_super_admin( $user->ID ) ) {
			return $allcaps;
		}

		$plugin_capabilities = self::get_booking_capability_slugs();

		foreach ( $caps as $cap ) {
			if ( in_array( $cap, $plugin_capabilities, true ) ) {
				$allcaps[ $cap ] = true;
			}
		}

		return $allcaps;
	}

	public static function can_manage_calendar( $calendar_id ) {
		if ( is_multisite() && is_super_admin() ) {
			return true;
		}

		$calendar = CalendarModel::find( $calendar_id );

		// Deny-by-default for missing resources. Prior behavior granted access
		// when the calendar didn't exist, which let stale UI mutate
		// freshly-deleted IDs and returned a 404 (info leak) instead of 403.
		if ( ! $calendar ) {
			return false;
		}

		if ( (int) $calendar->user_id === get_current_user_id() ) {
			return true;
		}

		return current_user_can( 'doublescale_booking_manage_all_calendars' );
	}

	public static function can_read_calendar( $calendar_id ) {
		if ( is_multisite() && is_super_admin() ) {
			return true;
		}

		$calendar = CalendarModel::find( $calendar_id );

		if ( ! $calendar ) {
			return false;
		}

		if ( (int) $calendar->user_id === get_current_user_id() ) {
			return true;
		}

		return current_user_can( 'doublescale_booking_read_all_calendars' );
	}

	public static function can_manage_event( $event_id ) {
		if ( is_multisite() && is_super_admin() ) {
			return true;
		}

		$event = EventModel::find( $event_id );

		if ( ! $event ) {
			return false;
		}

		// Null-safe calendar access: if the event's calendar was deleted via
		// a non-cascading path, optional()->user_id yields null instead of a
		// PHP 8 warning. We then fall through to the manage_all_calendars
		// check, which is the correct deny-by-default for orphaned events.
		$calendar_owner = optional( $event->calendar )->user_id;
		if ( null !== $calendar_owner && (int) $calendar_owner === get_current_user_id() ) {
			return true;
		}

		return current_user_can( 'doublescale_booking_manage_all_calendars' );
	}

	public static function can_read_event( $event_id ) {
		if ( is_multisite() && is_super_admin() ) {
			return true;
		}

		$event = EventModel::find( $event_id );

		if ( ! $event ) {
			return false;
		}

		$calendar_owner = optional( $event->calendar )->user_id;
		if ( null !== $calendar_owner && (int) $calendar_owner === get_current_user_id() ) {
			return true;
		}

		return current_user_can( 'doublescale_booking_read_all_calendars' );
	}

	public static function can_manage_booking( $booking_id ) {
		if ( is_multisite() && is_super_admin() ) {
			return true;
		}

		if ( current_user_can( 'doublescale_booking_manage_all_bookings' ) ) {
			return true;
		}

		$booking = BookingModel::with( 'event', 'calendar', 'hosts' )->find( $booking_id );

		if ( ! $booking ) {
			return false;
		}

		return $booking->userCanAccessAsStaff( get_current_user_id() );
	}

	public static function can_read_booking( $booking_id ) {
		if ( is_multisite() && is_super_admin() ) {
			return true;
		}

		if ( current_user_can( 'doublescale_booking_read_all_bookings' ) ) {
			return true;
		}

		$booking = BookingModel::with( 'event', 'calendar', 'hosts' )->find( $booking_id );

		if ( ! $booking ) {
			return false;
		}

		if ( current_user_can( 'doublescale_booking_read_own_bookings' ) && $booking->userCanAccessAsStaff( get_current_user_id() ) ) {
			return true;
		}

		return false;
	}

	public static function remove_capabilities() {
		global $wp_roles;

		if ( ! class_exists( 'WP_Roles' ) ) {
			return;
		}

		if ( ! isset( $wp_roles ) ) {
			$wp_roles = new WP_Roles();
		}

		$all_caps = self::get_booking_capability_slugs();

		foreach ( self::sync_role_slugs() as $role_slug ) {
			foreach ( $all_caps as $capability ) {
				$wp_roles->remove_cap( $role_slug, $capability );
			}
		}

		delete_option( 'doublescale_booking_caps_version' );
		delete_option( 'doublescale_booking_caps_assigned' );

		remove_filter( 'user_has_cap', array( __CLASS__, 'grant_super_admin_capabilities' ), 10 );
	}
}
