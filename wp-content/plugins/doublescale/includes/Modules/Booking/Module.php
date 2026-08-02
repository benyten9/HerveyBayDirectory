<?php
/**
 * Booking module bootstrap.
 *
 * Owns: calendars, events, bookings, guests, availability,
 * booked slots, booking orders, booking hosts, booking logs,
 * and the public booking renderer.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;
use DoubleScale\Admin\AdminLoader;
use DoubleScale\Admin\MenuRegistry;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'booking';
	}

	public function label(): string {
		return __( 'Booking', 'doublescale' );
	}

	public function description(): string {
		return __( 'Calendars, events, availability, and scheduled bookings.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	public function is_toggleable(): bool {
		return true;
	}

	public function onActivate(): void {
		\DoubleScale\Core\UserRoles\UserRoles::provision_booking_roles();
		Capabilities::sync_capabilities_for_user_roles();
	}

	public function onDeactivate(): void {
		\DoubleScale\Core\UserRoles\UserRoles::deprovision_booking_roles();
	}

	public function dependencies(): array {
		// Campaigns supplies the Emails class used by EmailNotifications to
		// send booking confirmations/reschedule notices. Without this in the
		// dependency list, disabling Campaigns + leaving Booking on produces
		// a "Class not found" fatal when a booking is created.
		return array( 'core', 'contacts', 'campaigns' );
	}

	public function register( Container $container ): void {
		$container->singleton(
			Services\AvailabilityService::class,
			static fn () => new Services\AvailabilityService()
		);

		$container->singleton(
			Services\BookingService::class,
			static fn () => new Services\BookingService()
		);

		$container->singleton(
			Services\BookingValidator::class,
			static fn () => new Services\BookingValidator()
		);

		$container->singleton(
			Services\BookingProvisioner::class,
			static fn () => new Services\BookingProvisioner()
		);

		$container->singleton( Services\BookingAjax::class, static fn () => new Services\BookingAjax() );
		$container->singleton( Services\BookingPortalProvider::class, static fn () => new Services\BookingPortalProvider() );
		$container->singleton( Services\BookingCalendarProvider::class, static fn () => new Services\BookingCalendarProvider() );
		$container->singleton( Services\EmailNotifications::class, static fn () => new Services\EmailNotifications() );
		$container->singleton( Services\BookingJobs::class, static fn () => new Services\BookingJobs() );
		$container->singleton( Services\BookingTasks::class, static fn () => new Services\BookingTasks() );
		$container->singleton( Services\BookingActions::class, static fn () => new Services\BookingActions() );

		$container->singleton(
			Managers\FieldsManager::class,
			static fn () => Managers\FieldsManager::instance()
		);

		$container->singleton(
			Managers\LocationsManager::class,
			static fn () => Managers\LocationsManager::instance()
		);

		$container->singleton(
			Managers\MergeTagsManager::class,
			static fn () => Managers\MergeTagsManager::instance()
		);

		$container->singleton(
			Managers\IntegrationsManager::class,
			static fn () => Managers\IntegrationsManager::instance()
		);

		$container->singleton( Renderer\BookingFrontendHandler::class, static fn () => new Renderer\BookingFrontendHandler() );

		$container->singleton( EventLocations\PersonAddress::class, static fn () => EventLocations\PersonAddress::instance() );
		$container->singleton( EventLocations\AttendeeAddress::class, static fn () => EventLocations\AttendeeAddress::instance() );
		$container->singleton( EventLocations\AttendeePhone::class, static fn () => EventLocations\AttendeePhone::instance() );
		$container->singleton( EventLocations\PersonPhone::class, static fn () => EventLocations\PersonPhone::instance() );
		$container->singleton( EventLocations\Custom::class, static fn () => EventLocations\Custom::instance() );
		$container->singleton( EventLocations\Online::class, static fn () => EventLocations\Online::instance() );
		$container->singleton( EventLocations\GoogleMeet::class, static fn () => EventLocations\GoogleMeet::instance() );
		$container->singleton( EventLocations\Zoom::class, static fn () => EventLocations\Zoom::instance() );
		$container->singleton( EventLocations\MsTeams::class, static fn () => EventLocations\MsTeams::instance() );

		/**
		 * Fires after free booking module bindings are registered.
		 *
		 * Pro extensions add their own container bindings (e.g. WaitingListHandler,
		 * BookingSmsNotifier) on this hook. Listeners must register here rather
		 * than in `boot()`, because filter listeners that pro adds in its own
		 * `register()` must already be in place before free's `boot()` iterates
		 * the `doublescale_booking_integrations` filter.
		 *
		 * @param Container $container Application service container.
		 */
		do_action( 'doublescale_booking_module_register', $container );
	}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestCalendarController::class,
			Rest\Controllers\RestEventController::class,
			Rest\Controllers\RestBookingController::class,
			Rest\Controllers\RestAvailabilityController::class,
			Rest\Controllers\RestBookingSettingsController::class,
			Rest\Controllers\RestPortalBookingController::class,
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );

		Services\EventBusBootstrap::init();

		$container->get( Services\AvailabilityService::class );
		$container->get( Services\BookingService::class );

		// Constructors of these services register their own AJAX/action hooks; resolving them is the registration step.
		$container->get( Services\BookingAjax::class );
		// Client Portal bridge: registers the Bookings section + summary +
		// timeline filters (only while Booking is enabled).
		$container->get( Services\BookingPortalProvider::class );
		// Admin/staff calendar bridge: contributes bookings to the cross-module
		// calendar feed (host-scoped for agents, all for managers).
		$container->get( Services\BookingCalendarProvider::class );
		$container->get( Services\EmailNotifications::class );
		$container->get( Services\BookingJobs::class );
		$container->get( Services\BookingTasks::class );
		$container->get( Services\BookingActions::class );

		MenuRegistry::add(
			array(
				'page_title'      => __( 'Booking', 'doublescale' ),
				'menu_title'      => __( 'Booking', 'doublescale' ),
				'capability'      => 'doublescale_access',
				'slug'            => 'doublescale&path=booking',
				'callback'        => array( AdminLoader::class, 'page_wrapper' ),
				'position'        => 45,
				'group'           => 'sales',
				'requires_module' => 'booking',
			)
		);

		$container->get( Renderer\BookingFrontendHandler::class );

		$container->get( Managers\FieldsManager::class );
		$container->get( Managers\LocationsManager::class );

		$container->get( EventLocations\PersonAddress::class );
		$container->get( EventLocations\AttendeeAddress::class );
		$container->get( EventLocations\AttendeePhone::class );
		$container->get( EventLocations\PersonPhone::class );
		$container->get( EventLocations\Custom::class );
		$container->get( EventLocations\Online::class );
		$container->get( EventLocations\GoogleMeet::class );
		$container->get( EventLocations\Zoom::class );
		$container->get( EventLocations\MsTeams::class );

		MergeTags\Loader::register();
		$container->get( Managers\MergeTagsManager::class );
		$container->get( Managers\IntegrationsManager::class );

		// Payment gateways: free abstract layer (registers the manager singleton).
		// Pro tier appends concrete gateways (e.g. Stripe) via the
		// `doublescale_booking_payment_gateways` filter.
		PaymentGateway\Loader::register();

		// Eagerly instantiate every booking integration so its constructor's
		// `add_action` calls run and subscribe to the EventBus tail-hooks
		// (`doublescale_booking_*`). Integrations are supplied by Pro via the
		// `doublescale_booking_integrations` filter — free ships none.
		foreach ( (array) apply_filters( 'doublescale_booking_integrations', array() ) as $class ) {
			if ( ! is_string( $class ) || ! class_exists( $class ) ) {
				continue;
			}
			try {
				if ( method_exists( $class, 'instance' ) ) {
					$class::instance();
				} else {
					new $class();
				}
			} catch ( \Throwable $e ) {
				// One broken integration must not block the rest from booting,
				// but log so silent class-not-found regressions are visible.
				doublescale_get_logger()->error(
					'Booking integration boot failed',
					array(
						'source' => 'booking-module',
						'class'  => $class,
						'error'  => $e->getMessage(),
					)
				);
			}
		}

		Capabilities::ensure_capabilities_synced();

		// For users whose only DoubleScale role is Booking Agent / Booking
		// Manager, strip every non-Booking submenu from the DoubleScale menu.
		add_action( 'admin_menu', array( self::class, 'scope_menu_for_booking_only_users' ), 9999 );

		$this->register_provisioner_hooks( $container );

		Admin\BookingAdminConfig::register();

		/**
		 * Fires after free booking module is fully booted (caps, admin config,
		 * provisioner hooks all registered). Pro extensions hook here when they
		 * need free's wiring already in place — e.g. to register their own
		 * `rest_api_init` primers.
		 *
		 * @param Container $container Application service container.
		 */
		do_action( 'doublescale_booking_module_boot', $container );
	}

	/**
	 * Wire {@see Services\BookingProvisioner} to its provisioning sources.
	 *
	 * CRM Settings → Team is the source of truth for who is a booking host. The CRM REST
	 * controller fires `doublescale_user_role_assigned` / `_revoked` whenever a user is
	 * granted or removed a CRM role through that UI. Auto-provisioning listens to those.
	 *
	 * Direct WordPress role assignment (wp-admin → Users) is intentionally NOT a
	 * provisioning trigger for Booking Manager / Booking Agent — admins must add
	 * those users through the CRM Team UI for them to become booking hosts. The single
	 * exception is the `administrator` role, which auto-grants full CRM access by virtue
	 * of `Capabilities::assign_capabilities_for_user_roles()`; an administrator added via
	 * any path still gets a host calendar so the booking module is usable out of the box.
	 *
	 * Also runs a one-shot bulk pass at activation for existing eligible users.
	 */
	private function register_provisioner_hooks( Container $container ): void {
		// One-shot bulk provisioning for existing users.
		// Re-run when the calendars table is empty even if the option flag is
		// set, so the dev workflow of "drop plugin tables + reactivate" still
		// provisions host calendars (option lives in wp_options, calendars in
		// the dropped plugin table — without this they go out of sync).
		$needs_bulk_provision = ! get_option( 'doublescale_booking_provisioned' )
			|| ! Models\CalendarModel::query()->exists();
		if ( $needs_bulk_provision ) {
			$provisioner = $container->get( Services\BookingProvisioner::class );

			$users = get_users(
				array(
					'role__in' => array(
						'administrator',
						\DoubleScale\Core\UserRoles\UserRoles::CRM_MANAGER,
						\DoubleScale\Core\UserRoles\UserRoles::BOOKING_MANAGER,
						\DoubleScale\Core\UserRoles\UserRoles::BOOKING_AGENT,
					),
					'fields'   => array( 'ID' ),
				)
			);

			foreach ( $users as $user ) {
				$provisioner->ensure_host_calendar( (int) $user->ID );
			}

			update_option( 'doublescale_booking_provisioned', true );
		}

		// One-shot cleanup for installs that accumulated duplicate host calendars
		// before ensure_host_calendar() became dedupe-aware. Merges each user's
		// duplicate host calendars down to a single canonical row. Gated by its
		// own option so it runs exactly once per install.
		if ( ! get_option( 'doublescale_booking_host_calendars_deduped' ) ) {
			$provisioner = $container->get( Services\BookingProvisioner::class );

			$duplicate_user_ids = Models\CalendarModel::query()
				->where( 'type', 'host' )
				->groupBy( 'user_id' )
				->havingRaw( 'COUNT(*) > 1' )
				->pluck( 'user_id' );

			foreach ( $duplicate_user_ids as $user_id ) {
				$provisioner->dedupe_host_calendars( (int) $user_id );
			}

			update_option( 'doublescale_booking_host_calendars_deduped', true );
		}

		$resolve = static function () use ( $container ): Services\BookingProvisioner {
			return $container->get( Services\BookingProvisioner::class );
		};

		// CRM REST role-grant/revoke events (Settings → Team UI). Source of truth for hosts.
		add_action(
			'doublescale_user_role_assigned',
			static function ( $user_id, $role ) use ( $resolve ) {
				$resolve()->on_role_assigned( (int) $user_id, (string) $role );
			},
			10,
			2
		);

		add_action(
			'doublescale_user_role_revoked',
			static function ( $user_id, $role ) use ( $resolve ) {
				$resolve()->on_role_revoked( (int) $user_id, (string) $role );
			},
			10,
			2
		);

		// Administrator-only WP core paths: a new admin (wp-admin or programmatic) gets a
		// host calendar because admins auto-hold every CRM capability. Non-admin role
		// assignments via WP core are deliberately ignored — go through CRM Team UI.
		add_action(
			'set_user_role',
			static function ( $user_id, $role ) use ( $resolve ) {
				if ( 'administrator' !== (string) $role ) {
					return;
				}
				$resolve()->on_set_user_role( (int) $user_id, (string) $role );
			},
			10,
			2
		);

		add_action(
			'user_register',
			static function ( $user_id ) use ( $resolve ) {
				$user = get_userdata( (int) $user_id );
				if ( ! $user || ! in_array( 'administrator', (array) $user->roles, true ) ) {
					return;
				}
				$resolve()->on_user_register( (int) $user_id );
			},
			10,
			1
		);

		// User deletion: same cleanup as a team-removal (drop dead config,
		// deactivate calendars with historical bookings, preserve booking_hosts
		// for audit trail). `delete_user` covers single-site; `wpmu_delete_user`
		// and `remove_user_from_blog` cover multisite paths.
		$purge = static function ( $user_id ) use ( $resolve ) {
			$resolve()->purge_host_data( (int) $user_id );
		};

		add_action( 'delete_user', $purge, 10, 1 );
		add_action( 'wpmu_delete_user', $purge, 10, 1 );
		add_action( 'remove_user_from_blog', $purge, 10, 1 );
	}

	/**
	 * Remove every DoubleScale submenu except Booking for users whose only
	 * DoubleScale roles are the booking ones. Administrators and CRM roles
	 * are untouched.
	 *
	 * @return void
	 */
	public static function scope_menu_for_booking_only_users(): void {
		if ( ! \DoubleScale\Core\UserRoles\Permissions::is_booking_only() ) {
			return;
		}

		$menu_slug = apply_filters( 'doublescale_admin_menu_slug', 'doublescale' );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional submenu trimming
		global $submenu;
		if ( empty( $submenu[ $menu_slug ] ) || ! is_array( $submenu[ $menu_slug ] ) ) {
			return;
		}

		foreach ( $submenu[ $menu_slug ] as $key => $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( false === strpos( $slug, 'path=booking' ) ) {
				unset( $submenu[ $menu_slug ][ $key ] );
			}
		}

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional submenu trimming (re-indexing the same global filtered above).
		$submenu[ $menu_slug ] = array_values( $submenu[ $menu_slug ] );
	}
}
