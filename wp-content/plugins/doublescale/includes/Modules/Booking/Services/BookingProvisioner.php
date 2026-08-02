<?php
/**
 * Booking provisioner: auto-creates / soft-retires host calendars based on CRM role grants.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Models\AvailabilityModel;
use DoubleScale\Modules\Booking\Models\CalendarModel;
use DoubleScale\Core\UserRoles\UserRoles;

final class BookingProvisioner {

	/**
	 * Idempotently ensure a host calendar exists (and is active) for the given user.
	 *
	 * Re-activates a previously soft-retired calendar on role re-grant.
	 *
	 * @param int $user_id WP user id.
	 * @return CalendarModel|null
	 */
	public function ensure_host_calendar( int $user_id ): ?CalendarModel {
		if ( $user_id <= 0 ) {
			return null;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || ! $user->exists() ) {
			return null;
		}

		// `set_user_role` / `user_register` can fire for a blog whose DoubleScale
		// tables don't exist yet — e.g. multisite site-duplicator tools (Easy
		// Plugin Demo et al.) assign the registrant's admin role via
		// `add_user_to_blog()` *inside* `wp_insert_site()`, before WP fires the
		// `wpmu_new_blog`/`wp_initialize_site` action this plugin's own table
		// provisioning listens on. Bail quietly rather than let WPEloquent throw
		// on the missing table; the module's bulk-provisioning pass picks up
		// this admin once the new blog's tables exist (its first real page load).
		if ( ! self::host_calendar_table_ready() ) {
			return null;
		}

		// Collapse any pre-existing duplicate host calendars for this user down to
		// a single canonical row before doing anything else. Historically the
		// check-then-create below was not atomic (and bulk provisioning could
		// re-run), so some installs accumulated 2-3 host calendars per user. We
		// keep the canonical one and re-point its data here so the rest of this
		// method — and the whole app — can safely assume one host per user.
		$calendar = $this->dedupe_host_calendars( $user_id );

		if ( ! $calendar ) {
			$calendar = CalendarModel::where( 'user_id', $user_id )
				->where( 'type', 'host' )
				->orderBy( 'id' )
				->first();
		}

		if ( $calendar ) {
			if ( 'active' !== $calendar->status ) {
				$calendar->status = 'active';
				$calendar->save();
			}
			// Calendars created without a stored timezone fall back to the
			// user's site/profile timezone so the settings UI never renders
			// `timezone: null` (which would disable the Save button).
			if ( ! $calendar->timezone ) {
				$calendar->timezone = self::resolve_default_timezone( $user_id );
			}
			self::ensure_default_availability( $user_id );
			return $calendar;
		}

		$name = $user->display_name ? $user->display_name : $user->user_login;

		$calendar = CalendarModel::create(
			array(
				'user_id' => $user_id,
				'name'    => $name,
				'type'    => 'host',
				'status'  => 'active',
			)
		);

		$calendar->timezone = self::resolve_default_timezone( $user_id );

		self::ensure_default_availability( $user_id );

		return $calendar;
	}

	/**
	 * Whether the booking_calendars table exists for the current blog.
	 *
	 * @return bool
	 */
	private static function host_calendar_table_ready(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_booking_calendars';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- existence probe before an Eloquent query that would otherwise throw on a missing table.
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Collapse duplicate host calendars for a user into a single canonical row.
	 *
	 * Picks the calendar with the most bookings (ties broken by lowest id) as the
	 * survivor, re-points events / bookings / meta from the duplicates onto it,
	 * then deletes the now-empty duplicates. A no-op (returning the single
	 * calendar, or null) when there is 0 or 1 host calendar.
	 *
	 * @param int $user_id WP user id.
	 * @return CalendarModel|null The surviving host calendar, or null if none exist.
	 */
	public function dedupe_host_calendars( int $user_id ): ?CalendarModel {
		if ( $user_id <= 0 ) {
			return null;
		}

		if ( ! self::host_calendar_table_ready() ) {
			return null;
		}

		$calendars = CalendarModel::where( 'user_id', $user_id )
			->where( 'type', 'host' )
			->withCount( 'bookings' )
			->orderByDesc( 'bookings_count' )
			->orderBy( 'id' )
			->get();

		if ( $calendars->count() <= 1 ) {
			return $calendars->first();
		}

		$survivor    = $calendars->first();
		$survivor_id = (int) $survivor->id;

		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		try {
			foreach ( $calendars as $duplicate ) {
				if ( (int) $duplicate->id === $survivor_id ) {
					continue;
				}

				$duplicate_id = (int) $duplicate->id;

				// Re-point child rows onto the survivor.
				$duplicate->events()->update( array( 'calendar_id' => $survivor_id ) );
				$duplicate->bookings()->update( array( 'calendar_id' => $survivor_id ) );

				// Meta is keyed by (calendar_id, meta_key); only migrate keys the
				// survivor does not already own to avoid duplicate-key rows, then
				// drop the rest.
				$survivor_meta_keys = $survivor->meta()->pluck( 'meta_key' )->all();
				$duplicate->meta()
					->whereNotIn( 'meta_key', $survivor_meta_keys )
					->update( array( 'calendar_id' => $survivor_id ) );
				$duplicate->meta()->delete();

				$duplicate->delete();
			}

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			doublescale_get_logger()->error(
				'Failed to dedupe host calendars',
				array(
					'source'  => 'booking-provisioner',
					'user_id' => $user_id,
					'error'   => $e->getMessage(),
				)
			);
			// Fall back to the survivor regardless; the duplicates are harmless
			// extra rows and a later run can retry the merge.
		}

		return $survivor->fresh();
	}

	/**
	 * Ensure the user has at least one availability row flagged as default.
	 * Required by RestEventController, which refuses to create events when no
	 * default exists for the calendar owner.
	 */
	private static function ensure_default_availability( int $user_id ): void {
		$existing = AvailabilityModel::where( 'user_id', $user_id )
			->where( 'is_default', 1 )
			->first();
		if ( $existing ) {
			return;
		}
		AvailabilityModel::createDefaultForUser( $user_id );
	}

	/**
	 * Resolve a sensible default timezone string for a user, falling back to
	 * the WP site timezone and finally UTC.
	 */
	private static function resolve_default_timezone( int $user_id ): string {
		$timezone = get_user_meta( $user_id, 'timezone_string', true );
		if ( $timezone ) {
			return (string) $timezone;
		}
		$site_tz = wp_timezone_string();
		return $site_tz ? $site_tz : 'UTC';
	}

	/**
	 * Soft-retire all host calendars for a user. Bookings are preserved.
	 *
	 * @param int $user_id WP user id.
	 */
	public function deactivate_host_calendars( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		CalendarModel::where( 'user_id', $user_id )
			->where( 'type', 'host' )
			->update( array( 'status' => 'inactive' ) );
	}

	/**
	 * Clean up booking-side data when a user is no longer a booking host.
	 *
	 * Used both when a user is removed from the CRM team (role revoked, WP
	 * user still exists) and when the WP user is deleted entirely. In both
	 * cases the user is no longer a host, so their dead config (availability
	 * + calendars without bookings) is removed. Calendars with historical
	 * bookings are deactivated rather than deleted so `bookings`/`booking_hosts`
	 * keep referential context for past appointments. `booking_hosts` rows
	 * are intentionally left in place — past attendees should still see who
	 * they were booked with even after the host is gone.
	 */
	public function purge_host_data( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		try {
			$calendars = CalendarModel::where( 'user_id', $user_id )->get();
			foreach ( $calendars as $calendar ) {
				if ( $calendar->bookings()->exists() ) {
					$calendar->status = 'inactive';
					$calendar->save();
					continue;
				}

				foreach ( $calendar->events as $event ) {
					$event->delete();
				}
				$calendar->meta()->delete();
				$calendar->delete();
			}

			AvailabilityModel::where( 'user_id', $user_id )->delete();
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Failed to purge booking host data',
				array(
					'source'  => 'booking-provisioner',
					'user_id' => $user_id,
					'error'   => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Whether a role slug entitles its holder to a host calendar.
	 */
	public static function is_booking_eligible_role( string $role ): bool {
		return in_array(
			$role,
			array(
				'administrator',
				UserRoles::CRM_MANAGER,
				UserRoles::BOOKING_MANAGER,
				UserRoles::BOOKING_AGENT,
			),
			true
		);
	}

	/**
	 * Whether the user currently holds any booking-eligible role.
	 */
	public static function user_has_any_booking_role( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		foreach ( (array) $user->roles as $role ) {
			if ( self::is_booking_eligible_role( $role ) ) {
				return true;
			}
		}

		return false;
	}

	/*
	------------------------------------------------------------------ *
	 * Action listeners (wired in Module::boot()).
	 *
	 * `on_role_assigned` / `on_role_revoked` fire from the CRM Team REST
	 * controller (Settings → Team UI), which is the source of truth for booking
	 * hosts. `on_set_user_role` / `on_user_register` are gated on the
	 * `administrator` role at the hook layer in Module::register_provisioner_hooks().
	 * ------------------------------------------------------------------ */

	public function on_role_assigned( int $user_id, string $role ): void {
		if ( ! self::is_booking_eligible_role( $role ) ) {
			return;
		}
		$this->ensure_host_calendar( $user_id );
	}

	public function on_role_revoked( int $user_id, string $role ): void {
		unset( $role );
		if ( self::user_has_any_booking_role( $user_id ) ) {
			return;
		}
		$this->purge_host_data( $user_id );
	}

	public function on_set_user_role( int $user_id, string $role ): void {
		if ( self::is_booking_eligible_role( $role ) ) {
			$this->ensure_host_calendar( $user_id );
			return;
		}

		// Role replaced with a non-booking role: clean up if no other booking role remains.
		if ( ! self::user_has_any_booking_role( $user_id ) ) {
			$this->purge_host_data( $user_id );
		}
	}

	public function on_user_register( int $user_id ): void {
		if ( self::user_has_any_booking_role( $user_id ) ) {
			$this->ensure_host_calendar( $user_id );
		}
	}
}
