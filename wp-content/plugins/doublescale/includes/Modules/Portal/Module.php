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
		return __( 'Unified logged-in customer portal: support tickets, bookings, and a dashboard.', 'doublescale' );
	}

	/**
	 * Host shell — always on (not toggleable).
	 *
	 * @return bool
	 */
	public function is_toggleable(): bool {
		return false;
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
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );

		// Wire the shortcode + enqueue listener.
		$container->get( PortalFrontendHandler::class );

		// Auto-create the portal page once so the portal is discoverable on a
		// fresh install (admin_init: post types + home_url() are ready, and it
		// never touches front-end requests).
		add_action( 'admin_init', array( PortalPageProvisioner::class, 'maybe_provision' ) );

		// Re-point the booking details URL (consumed by the
		// {{booking.details_url}} merge tag) to the customer portal when a
		// portal page exists. Falls through to the admin URL otherwise, so
		// nothing breaks when no portal page is published.
		add_filter( 'doublescale_booking_details_url', array( $this, 'filter_booking_details_url' ), 10, 2 );
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

		return '' !== $portal_url ? $portal_url : $admin_url;
	}
}
