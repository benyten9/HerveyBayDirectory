<?php
/**
 * Client Portal module bootstrap.
 *
 * Owns the unified customer portal shell: the `[doublescale_client_portal]`
 * shortcode + login gate, the renderer bundle enqueue, the section-provider seam
 * (`doublescale_portal_sections`), the dashboard aggregator (`/portal/bootstrap`),
 * and the whitelisted activity timeline (`/portal/timeline`).
 *
 * Individual sections (Tickets, Bookings, Documents) are contributed by their
 * own modules via the section filter, so this module is the host shell only —
 * hence it is NOT toggleable.
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;
use DoubleScale\Core\Services\ShortcodePageProvisioner;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Portal\Renderer\PortalFrontendHandler;
use DoubleScale\Modules\Portal\Services\PortalPageProvisioner;
use DoubleScale\Modules\Portal\Services\PortalUrl;

/**
 * Portal Module.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'portal';
	}

	public function label(): string {
		return __( 'Client Portal', 'doublescale' );
	}

	public function description(): string {
		return __( 'A logged-in area where clients see their documents, bookings, projects, tickets and details.', 'doublescale' );
	}

	/**
	 * Settings → Modules can turn the client portal off; it is on until then.
	 *
	 * @return bool
	 */
	public function is_toggleable(): bool {
		return true;
	}

	/**
	 * On unless the admin turned it off: sites from before the toggle have no
	 * stored choice and keep their portal.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		$stored = get_option( 'doublescale_enabled_modules', array() );
		$on     = ! is_array( $stored ) || ! array_key_exists( $this->slug(), $stored ) || (bool) $stored[ $this->slug() ];

		return (bool) apply_filters( 'doublescale_module_enabled_' . $this->slug(), $on );
	}

	public function version(): string {
		return '1.0.0';
	}

	/**
	 * @return array<int, string>
	 */
	public function dependencies(): array {
		return array( 'core', 'contacts' );
	}

	public function register( Container $container ): void {
		// Singleton: resolving it (in boot) is what wires the shortcode +
		// wp_enqueue_scripts. A second resolve must not double-register.
		$container->singleton(
			PortalFrontendHandler::class,
			static fn() => new PortalFrontendHandler()
		);
	}

	/**
	 * @return array<int, class-string>
	 */
	public function restControllers(): array {
		return array(
			Rest\Controllers\RestPortalBootstrapController::class,
			Rest\Controllers\RestPortalTimelineController::class,
			Rest\Controllers\RestPortalPageController::class,
			Rest\Controllers\RestPortalCalendarController::class,
			Rest\Controllers\RestPortalContactController::class,
			Rest\Controllers\RestPortalUsersController::class,
			Rest\Controllers\RestPortalFilesController::class,
			Rest\Controllers\RestPortalFeaturesController::class,
			Rest\Controllers\RestContactPortalAccessController::class,
			Rest\Controllers\RestPortalNotificationsController::class,
			Rest\Controllers\RestPortalSearchController::class,
			Rest\Controllers\RestPortalFirstVisitController::class,
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );

		// Wire the shortcode + enqueue listener.
		$container->get( PortalFrontendHandler::class );

		// The portal's bell: what the team did for the client, until read; and
		// the daily summary of team updates for clients who chose one.
		Migrations\PortalNotificationsTable::ensure();
		Services\PortalNotifications::register();
		Services\PortalLoginEmail::register();
		Services\PortalInvite::register();
		Services\PortalEmailConfirmation::register();
		Services\PortalEmailConfirmation::ensure();
		// Full-screen portal pages (the default), without the theme around them.
		Services\PortalPageLayout::register();
		add_action( 'init', array( $this, 'schedule_notification_summaries' ) );

		// The day the first-visit welcome started: older accounts are not new.
		Services\PortalWelcome::since();

		// Auto-create portal accounts for new contacts when enabled in settings.
		add_action( 'doublescale_contact_created', array( Services\PortalUserProvisioner::class, 'on_contact_created' ) );
		add_action( 'doublescale_contact_list_apply', array( Services\PortalUserProvisioner::class, 'on_lists_applied' ), 10, 2 );

		// Auto-create the portal page once so the portal is discoverable on a
		// fresh install. Registers into the shared provisioner alongside every
		// other customer-facing shortcode; the single `admin_init` pass below
		// provisions whatever each enabled module registered (admin_init: post
		// types + home_url() are ready, and front-end requests are untouched).
		ShortcodePageProvisioner::register(
			PortalFrontendHandler::SHORTCODE_NAME,
			array(
				'title' => __( 'Client Portal', 'doublescale' ),
				'slug'  => 'client-portal',
			)
		);

		// Portal is non-toggleable, so hooking the shared pass here runs it on
		// every admin load regardless of which other modules are enabled.
		add_action( 'admin_init', array( ShortcodePageProvisioner::class, 'maybe_provision_all' ) );

		// Re-point the booking details URL (consumed by the
		// {{booking.details_url}} merge tag) to the customer portal when a
		// portal page exists. Falls through to the admin URL otherwise, so
		// nothing breaks when no portal page is published.
		add_filter( 'doublescale_booking_details_url', array( $this, 'filter_booking_details_url' ), 10, 2 );

		// "Files": customers upload to their own contact record. Shown only
		// while an admin has switched it on (Client Portal settings, Pro).
		add_filter( 'doublescale_portal_sections', array( $this, 'register_files_section' ) );

		// "My Profile": every customer keeps their own name, phone and address
		// up to date (`/portal/contact`).
		add_filter( 'doublescale_portal_sections', array( $this, 'register_profile_section' ) );

		// What each customer can see: one decision, applied to every surface
		// (menu, dashboard, recent activity, calendar, documents, routes).
		Services\PortalAccessGate::register();
		add_filter( 'doublescale_client_portal_config', array( $this, 'inject_access' ) );

		// A customer's own access belongs to whichever side of a merge keeps
		// the email they log in with, and leaves with a deleted contact.
		add_action( 'doublescale_contact_merge_before_meta', array( Services\PortalAccess::class, 'settle_merge' ), 10, 5 );
		add_filter( 'doublescale_contact_merge_note_lines', array( Services\PortalAccess::class, 'merge_note_lines' ), 10, 2 );
		add_action( 'doublescale_contact_deleting', array( Services\PortalAccess::class, 'forget_contact' ) );
	}

	/**
	 * Run the daily summary of team updates (and drop old read rows).
	 *
	 * @return void
	 */
	public function schedule_notification_summaries(): void {
		$hook  = 'doublescale_portal_notification_summaries';
		$tasks = new \DoubleScale\Core\Tasks( 'doublescale_portal' );
		$tasks->register_callback( $hook, array( Services\PortalNotifications::class, 'send_daily_summaries' ) );

		$lock_key = 'doublescale_register_tasks_lock_' . $hook;
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, 1, MINUTE_IN_SECONDS );
		if ( false === $tasks->get_next_timestamp( $hook ) ) {
			$tasks->schedule_recurring( time(), DAY_IN_SECONDS, $hook );
		}
	}

	/**
	 * Tell the portal renderer which areas this customer can see, so the
	 * Documents page offers only the tabs it is allowed to list.
	 *
	 * @param array<string, mixed> $config Renderer config.
	 * @return array<string, mixed>
	 */
	public function inject_access( array $config ): array {
		$contact          = Services\PortalIdentity::current_contact();
		$config['access'] = Services\PortalAccess::for_contact( $contact );
		// Sections this client can open: widgets of switched-off modules
		// (whose routes do not exist) ask for nothing.
		$config['sections'] = Services\PortalSections::slugs_for_contact( $contact );

		return $config;
	}

	/**
	 * Contribute the Files section descriptor.
	 *
	 * @param array<int, array<string, mixed>> $sections Section descriptors.
	 * @return array<int, array<string, mixed>>
	 */
	public function register_files_section( array $sections ): array {
		$sections[] = array(
			'slug'         => 'files',
			'label'        => __( 'Files', 'doublescale' ),
			'icon'         => 'paperclip',
			'order'        => 70,
			// Whether THIS customer sees it is decided per contact by the
			// access gate; here only whether uploads can work at all.
			'is_available' => array( Services\PortalUploadSettings::class, 'is_supported' ),
		);

		return $sections;
	}

	/**
	 * Contribute the My Profile section descriptor.
	 *
	 * @param array<int, array<string, mixed>> $sections Section descriptors.
	 * @return array<int, array<string, mixed>>
	 */
	public function register_profile_section( array $sections ): array {
		$sections[] = array(
			'slug'  => 'profile',
			'label' => __( 'My Profile', 'doublescale' ),
			'icon'  => 'user',
			'order' => 90,
		);

		return $sections;
	}

	/**
	 * Supply the customer-facing portal URL for a booking.
	 *
	 * @param string $admin_url Default (admin SPA) URL.
	 * @param object $booking   BookingModel instance.
	 * @return string
	 */
	public function filter_booking_details_url( string $admin_url, $booking ): string {
		if ( ! is_object( $booking ) || ! isset( $booking->id ) ) {
			return $admin_url;
		}

		$portal_url = PortalUrl::get_booking_url( (int) $booking->id );
		if ( '' === $portal_url ) {
			return $admin_url;
		}

		// Bookings hidden from this customer: the email must not send them to a
		// portal page they cannot open. The public booking page shows it instead.
		$contact = isset( $booking->contact_id ) ? ContactModel::find( (int) $booking->contact_id ) : null;
		if ( ! Services\PortalAccess::allows( 'appointments', $contact instanceof ContactModel ? $contact : null )
			&& method_exists( $booking, 'getConfirmUrl' ) ) {
			return (string) $booking->getConfirmUrl();
		}

		return $portal_url;
	}
}
