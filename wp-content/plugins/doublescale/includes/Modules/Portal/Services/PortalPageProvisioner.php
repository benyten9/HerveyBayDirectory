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
	 * Page body. Wrapped in a shortcode block so it is clean in the block editor
	 * and still renders on classic themes.
	 */
	private const PAGE_CONTENT = "<!-- wp:shortcode -->\n[" . PortalFrontendHandler::SHORTCODE_NAME . "]\n<!-- /wp:shortcode -->";

	/**
	 * `admin_init` entry point: provision the page once, then never again.
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
	 * Adopt-or-create the portal page and record it. Sets the one-time flag only
	 * on success, so a failed creation retries on the next admin load. Shared by
	 * the auto path and the settings "Create page" action.
	 *
	 * @return int Resolved page id, or 0 on failure.
	 */
	public static function provision(): int {
		$page_id = self::ensure_page();

		if ( $page_id > 0 ) {
			update_option( self::PROVISIONED_FLAG, 'yes' );
		}

		return $page_id;
	}

	/**
	 * Status payload for the admin settings card.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_status(): array {
		$page_id = self::resolve_existing_page_id();
		$exists  = $page_id > 0;

		return array(
			'provisioned' => 'yes' === get_option( self::PROVISIONED_FLAG ),
			'page_id'     => $page_id,
			'exists'      => $exists,
			'view_url'    => $exists ? (string) get_permalink( $page_id ) : '',
			'edit_url'    => $exists ? (string) get_edit_post_link( $page_id, 'raw' ) : '',
			'shortcode'   => '[' . PortalFrontendHandler::SHORTCODE_NAME . ']',
		);
	}

	/**
	 * Resolve the live portal page: the recorded id when still valid, else the
	 * content scan (covers a hand-built page).
	 *
	 * @return int Page id, or 0.
	 */
	private static function resolve_existing_page_id(): int {
		$stored = (int) get_option( PortalUrl::PAGE_ID_OPTION, 0 );
		if ( $stored > 0 && self::page_is_live( $stored ) ) {
			return $stored;
		}

		$found = PortalUrl::find_existing_page_id();

		return $found > 0 ? $found : 0;
	}

	/**
	 * Adopt an existing shortcode page or create a new one, recording its id in
	 * {@see PortalUrl::PAGE_ID_OPTION} and flushing the cached permalink.
	 *
	 * @return int Page id, or 0 on failure.
	 */
	private static function ensure_page(): int {
		$existing = PortalUrl::find_existing_page_id();
		if ( $existing > 0 ) {
			update_option( PortalUrl::PAGE_ID_OPTION, $existing );
			PortalUrl::flush_cache();

			return $existing;
		}

		$page_id = self::create_page();
		if ( $page_id > 0 ) {
			update_option( PortalUrl::PAGE_ID_OPTION, $page_id );
			PortalUrl::flush_cache();
		}

		return $page_id;
	}

	/**
	 * Insert the published portal page.
	 *
	 * @return int New page id, or 0 on failure.
	 */
	private static function create_page(): int {
		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Client Portal', 'doublescale' ),
				'post_name'    => 'client-portal',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => self::PAGE_CONTENT,
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $page_id ) || ! $page_id ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Failed to auto-create the Client Portal page',
					array(
						'source' => 'portal-page-provision',
						'error'  => is_wp_error( $page_id ) ? $page_id->get_error_message() : 'unknown',
					)
				);
			}

			return 0;
		}

		return (int) $page_id;
	}

	/**
	 * Whether a page id points at a live (publish/private) page.
	 *
	 * @param int $page_id Page id.
	 * @return bool
	 */
	private static function page_is_live( int $page_id ): bool {
		$post = get_post( $page_id );

		return $post instanceof \WP_Post
			&& 'page' === $post->post_type
			&& in_array( $post->post_status, array( 'publish', 'private' ), true );
	}
}
