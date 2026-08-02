<?php
/**
 * Resolves customer-facing support portal URLs.
 *
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Support\Models\TicketModel;
use DoubleScale\Modules\Support\Renderer\PortalFrontendHandler;

/**
 * PortalUrl helper.
 */
final class PortalUrl {

	/**
	 * Query argument used to deep-link a ticket in the portal SPA.
	 */
	public const TICKET_QUERY_ARG = 'doublescale_support_ticket';

	/**
	 * Query argument for guest hash-based ticket access.
	 */
	public const TICKET_HASH_QUERY_ARG = 'doublescale_support_ticket_hash';

	/**
	 * Transient key for the resolved portal page permalink.
	 */
	private const PAGE_URL_TRANSIENT = 'doublescale_support_portal_page_url';

	/**
	 * Permalink of the first published page that contains the portal shortcode.
	 *
	 * @return string Empty when no portal page is found.
	 */
	public static function get_portal_page_url(): string {
		$cached = get_transient( self::PAGE_URL_TRANSIENT );
		// Only trust a non-empty cached URL — an earlier miss must not block a
		// portal page the operator publishes later in the same hour.
		if ( is_string( $cached ) && '' !== $cached ) {
			/**
			 * Filter the cached support portal page URL.
			 *
			 * @param string $url Portal page permalink (may be empty).
			 */
			return (string) apply_filters( 'doublescale_support_portal_page_url', $cached );
		}

		$page_id = self::locate_portal_page_id();
		$url     = $page_id > 0 ? (string) get_permalink( $page_id ) : '';

		if ( '' !== $url ) {
			set_transient( self::PAGE_URL_TRANSIENT, $url, HOUR_IN_SECONDS );
		}

		/**
		 * Filter the resolved support portal page URL.
		 *
		 * @param string $url Portal page permalink (may be empty).
		 */
		return (string) apply_filters( 'doublescale_support_portal_page_url', $url );
	}

	/**
	 * Customer portal URL for a ticket (portal page + ticket query arg).
	 *
	 * @param TicketModel $ticket Ticket model.
	 * @return string Empty when the portal page cannot be resolved.
	 */
	public static function get_ticket_url( TicketModel $ticket ): string {
		$base = self::get_portal_page_url();
		if ( '' === $base ) {
			return '';
		}

		$url = add_query_arg( self::TICKET_QUERY_ARG, (int) $ticket->id, $base );

		/**
		 * Filter the customer portal URL for a support ticket.
		 *
		 * @param string      $url    Ticket portal URL.
		 * @param TicketModel $ticket Ticket model.
		 */
		return (string) apply_filters( 'doublescale_support_ticket_portal_url', $url, $ticket );
	}

	/**
	 * Public guest URL for a ticket (portal page + hash query arg).
	 *
	 * @param TicketModel $ticket Ticket model.
	 * @return string Empty when the portal page cannot be resolved.
	 */
	public static function get_public_ticket_url( TicketModel $ticket ): string {
		$base = self::get_portal_page_url();
		if ( '' === $base ) {
			return '';
		}

		$hash = trim( (string) $ticket->hash );
		if ( '' === $hash ) {
			return '';
		}

		$url = add_query_arg( self::TICKET_HASH_QUERY_ARG, $hash, $base );

		/**
		 * Filter the public guest URL for a support ticket.
		 *
		 * @param string      $url    Guest ticket URL.
		 * @param TicketModel $ticket Ticket model.
		 */
		return (string) apply_filters( 'doublescale_support_ticket_public_url', $url, $ticket );
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
	 * Find a published page that embeds the portal shortcode.
	 *
	 * @return int Page ID, or 0.
	 */
	private static function locate_portal_page_id(): int {
		global $wpdb;

		$needle = PortalFrontendHandler::SHORTCODE_NAME;
		$like   = '%' . $wpdb->esc_like( $needle ) . '%';

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
			if ( has_shortcode( $page->post_content, $needle ) ) {
				return (int) $page->ID;
			}
			if ( false !== strpos( $page->post_content, $needle ) ) {
				return (int) $page->ID;
			}
		}

		return 0;
	}
}
