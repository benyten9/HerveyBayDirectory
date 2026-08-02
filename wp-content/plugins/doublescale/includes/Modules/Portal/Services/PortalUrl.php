<?php
/**
 * Resolves customer-facing Client Portal URLs.
 *
 * The portal SPA uses a hash router, but email/notification deep-links must
 * survive the WordPress login redirect (URL fragments do NOT — they never reach
 * the server, so `redirect_to` can't carry them). We therefore deep-link with a
 * `doublescale_portal_path` *query* arg that the renderer reads on boot and
 * translates into the initial hash route.
 *
 * Mirrors {@see \DoubleScale\Modules\Support\Services\PortalUrl} and the Sales
 * InvoiceUrl/ProposalUrl helpers (locate the page hosting the shortcode, cache
 * the permalink for an hour).
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Portal\Renderer\PortalFrontendHandler;

/**
 * PortalUrl helper.
 */
final class PortalUrl {

	/**
	 * Query argument the renderer reads to set its initial route.
	 */
	public const PATH_QUERY_ARG = 'doublescale_portal_path';

	/**
	 * Option holding the canonical portal page id (written by
	 * {@see \DoubleScale\Modules\Portal\Services\PortalPageProvisioner}). Preferred
	 * over the content scan so resolution is an O(1) lookup that can't pick the
	 * wrong page when several embed the shortcode.
	 */
	public const PAGE_ID_OPTION = 'doublescale_client_portal_page_id';

	/**
	 * Transient key for the resolved portal page permalink.
	 */
	private const PAGE_URL_TRANSIENT = 'doublescale_client_portal_page_url';

	/**
	 * Permalink of the first published page that contains the portal shortcode.
	 *
	 * @return string Empty when no portal page is found.
	 */
	public static function get_portal_page_url(): string {
		$cached = get_transient( self::PAGE_URL_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return (string) apply_filters( 'doublescale_client_portal_page_url', $cached );
		}

		$page_id = self::resolve_page_id();
		$url     = $page_id > 0 ? (string) get_permalink( $page_id ) : '';

		if ( '' !== $url ) {
			set_transient( self::PAGE_URL_TRANSIENT, $url, HOUR_IN_SECONDS );
		}

		/**
		 * Filter the resolved Client Portal page URL.
		 *
		 * @param string $url Portal page permalink (may be empty).
		 */
		return (string) apply_filters( 'doublescale_client_portal_page_url', $url );
	}

	/**
	 * Deep link into a portal route (e.g. `bookings/12`).
	 *
	 * @param string $path Hash-router path without the leading slash.
	 * @return string Empty when the portal page cannot be resolved.
	 */
	public static function get_route_url( string $path ): string {
		$base = self::get_portal_page_url();
		if ( '' === $base ) {
			return '';
		}

		$path = ltrim( $path, '/' );

		return add_query_arg( self::PATH_QUERY_ARG, rawurlencode( $path ), $base );
	}

	/**
	 * Customer portal URL for a booking detail.
	 *
	 * @param int $booking_id Numeric booking id (ownership-gated server-side).
	 * @return string Empty when the portal page cannot be resolved.
	 */
	public static function get_booking_url( int $booking_id ): string {
		return self::get_route_url( 'bookings/' . $booking_id );
	}

	/**
	 * Clear the cached portal page URL (call when portal page content changes).
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		delete_transient( self::PAGE_URL_TRANSIENT );
	}

	/**
	 * Resolve the portal page id: prefer the provisioned page recorded in
	 * {@see PAGE_ID_OPTION}, falling back to a content scan so a page the admin
	 * built by hand (or one created before this option existed) still resolves.
	 *
	 * @return int Page ID, or 0.
	 */
	private static function resolve_page_id(): int {
		$stored = (int) get_option( self::PAGE_ID_OPTION, 0 );
		if ( $stored > 0 && self::is_valid_portal_page( $stored ) ) {
			return $stored;
		}

		return self::find_existing_page_id();
	}

	/**
	 * Whether a page id points at a live page that still embeds the shortcode.
	 * Guards against a stored id whose page was trashed, deleted, or had the
	 * shortcode edited out.
	 *
	 * @param int $page_id Candidate page id.
	 * @return bool
	 */
	private static function is_valid_portal_page( int $page_id ): bool {
		$post = get_post( $page_id );
		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type ) {
			return false;
		}
		if ( ! in_array( $post->post_status, array( 'publish', 'private' ), true ) ) {
			return false;
		}

		$needle = PortalFrontendHandler::SHORTCODE_NAME;

		return has_shortcode( (string) $post->post_content, $needle )
			|| false !== strpos( (string) $post->post_content, $needle );
	}

	/**
	 * Find a published page that embeds the portal shortcode. Shared with
	 * {@see \DoubleScale\Modules\Portal\Services\PortalPageProvisioner} so the
	 * provisioner adopts an existing page instead of creating a duplicate.
	 *
	 * @return int Page ID, or 0.
	 */
	public static function find_existing_page_id(): int {
		global $wpdb;

		$needle = PortalFrontendHandler::SHORTCODE_NAME;
		$like   = '%' . $wpdb->esc_like( $needle ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off page lookup, result cached in a transient by the caller.
		$page_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type = 'page'
				AND post_status IN ('publish', 'private')
				AND post_content LIKE %s
				ORDER BY ID ASC
				LIMIT 1",
				$like
			)
		);

		if ( $page_id > 0 ) {
			return $page_id;
		}

		// Block editor / page builders sometimes store the tag without the
		// literal `[shortcode]` brackets in post_content.
		$pages = get_posts(
			array(
				'post_type'              => 'page',
				'post_status'            => array( 'publish', 'private' ),
				'posts_per_page'         => 50,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $pages as $page ) {
			if ( ! $page instanceof \WP_Post ) {
				continue;
			}
			if ( has_shortcode( $page->post_content, $needle ) || false !== strpos( $page->post_content, $needle ) ) {
				return (int) $page->ID;
			}
		}

		return 0;
	}
}
