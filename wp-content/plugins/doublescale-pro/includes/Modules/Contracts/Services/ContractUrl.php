<?php
/**
 * Resolves customer-facing contract URLs.
 *
 * @package DoubleScale\Pro\Modules\Contracts
 */

namespace DoubleScale\Pro\Modules\Contracts\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\Contracts\Models\ContractModel;
use DoubleScale\Pro\Modules\Contracts\Renderer\ContractFrontendHandler;

/**
 * ContractUrl helper.
 */
final class ContractUrl {

	/**
	 * Query argument for hash-based contract access.
	 */
	public const HASH_QUERY_ARG = 'doublescale_contract_hash';

	/**
	 * Transient key for the resolved contract page permalink.
	 */
	private const PAGE_URL_TRANSIENT = 'doublescale_sales_contract_page_url';

	/**
	 * Permalink of the first published page that contains the contract shortcode.
	 *
	 * @return string Empty when no contract page is found.
	 */
	public static function get_page_url(): string {
		$cached = get_transient( self::PAGE_URL_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			/**
			 * Filter the cached sales contract page URL.
			 *
			 * @param string $url Contract page permalink (may be empty).
			 */
			return (string) apply_filters( 'doublescale_sales_contract_page_url', $cached );
		}

		$page_id = self::locate_page_id();
		$url     = $page_id > 0 ? (string) get_permalink( $page_id ) : '';

		if ( '' !== $url ) {
			set_transient( self::PAGE_URL_TRANSIENT, $url, HOUR_IN_SECONDS );
		}

		/**
		 * Filter the resolved sales contract page URL.
		 *
		 * @param string $url Contract page permalink (may be empty).
		 */
		return (string) apply_filters( 'doublescale_sales_contract_page_url', $url );
	}

	/**
	 * Public URL for a contract (page + hash query arg).
	 *
	 * @param ContractModel $contract Contract model.
	 * @return string Empty when the page cannot be resolved.
	 */
	public static function get_public_url( ContractModel $contract ): string {
		$base = self::get_page_url();
		if ( '' === $base ) {
			return '';
		}

		$hash = trim( (string) $contract->hash );
		if ( '' === $hash ) {
			return '';
		}

		$url = add_query_arg( self::HASH_QUERY_ARG, $hash, $base );

		/**
		 * Filter the public contract URL.
		 *
		 * @param string        $url      Contract URL.
		 * @param ContractModel $contract Contract model.
		 */
		return (string) apply_filters( 'doublescale_sales_contract_public_url', $url, $contract );
	}

	/**
	 * Clear the cached contract page URL.
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		delete_transient( self::PAGE_URL_TRANSIENT );
	}

	/**
	 * Find a published page that embeds the contract shortcode.
	 *
	 * @return int Page ID, or 0.
	 */
	private static function locate_page_id(): int {
		global $wpdb;

		$needle = ContractFrontendHandler::SHORTCODE_NAME;
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
