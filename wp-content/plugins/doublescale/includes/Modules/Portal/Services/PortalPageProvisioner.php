<?php
/**
 * One-time Client Portal page provisioner.
 *
 * On a fresh install nothing tells the admin the `[doublescale_client_portal]`
 * shortcode exists, so the portal is undiscoverable until someone reads the
 * docs. This service auto-creates a published "Client Portal" page once, so the
 * portal works out of the box — mirroring how WooCommerce provisions its
 * My Account / Cart / Checkout pages on install.
 *
 * Discipline:
 *   - Runs once, gated by {@see PROVISIONED_FLAG}. After the first attempt we
 *     never recreate automatically — an admin who later trashes the page is not
 *     fought (they recreate it from the settings card instead).
 *   - Adopts an existing shortcode page (e.g. one the admin built by hand, or a
 *     page from before this option existed) rather than creating a duplicate.
 *   - Hooked on `admin_init` (not activation): the `page` post type and
 *     `home_url()` are guaranteed ready there, and it never touches front-end
 *     requests. Reliable because the Portal module is non-toggleable.
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\ShortcodePageProvisioner;
use DoubleScale\Modules\Portal\Renderer\PortalFrontendHandler;

/**
 * PortalPageProvisioner.
 */
final class PortalPageProvisioner {

	/**
	 * Option flag recording that the one-time auto-provision has run, so a later
	 * admin deletion is not undone on the next admin load.
	 */
	public const PROVISIONED_FLAG = 'doublescale_client_portal_page_provisioned';

	/**
	 * Ensure the portal descriptor is present in the shared registry.
	 *
	 * The settings "Create page" action reaches this class through REST, which
	 * may run before (or without) the module boot that registers the descriptor.
	 * Registering here too is idempotent and keeps that path working.
	 *
	 * @return void
	 */
	private static function ensure_registered(): void {
		ShortcodePageProvisioner::register(
			PortalFrontendHandler::SHORTCODE_NAME,
			array(
				'title' => __( 'Client Portal', 'doublescale' ),
				'slug'  => 'client-portal',
			)
		);
	}

	/**
	 * `admin_init` entry point: provision the page once, then never again.
	 *
	 * Retained for back-compat; the live `admin_init` hook now runs the shared
	 * {@see ShortcodePageProvisioner::maybe_provision_all()} pass, which covers
	 * the portal alongside every other customer-facing shortcode.
	 *
	 * @return void
	 */
	public static function maybe_provision(): void {
		// First creation should land on a real admin page load, not mid-AJAX.
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return;
		}

		if ( 'yes' === get_option( self::PROVISIONED_FLAG ) ) {
			return;
		}

		self::provision();
	}

	/**
	 * Adopt-or-create the portal page and record it. Backs the settings
	 * "Create page" action.
	 *
	 * @return int Resolved page id, or 0 on failure.
	 */
	public static function provision(): int {
		self::ensure_registered();

		return ShortcodePageProvisioner::provision( PortalFrontendHandler::SHORTCODE_NAME );
	}

	/**
	 * Status payload for the admin settings card.
	 *
	 * Keeps the historical key set (no `title`) so the existing settings card
	 * contract is unchanged.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_status(): array {
		$status = ShortcodePageProvisioner::get_status( PortalFrontendHandler::SHORTCODE_NAME );

		unset( $status['title'] );

		return $status;
	}
}
