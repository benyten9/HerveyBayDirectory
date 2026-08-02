<?php
/**
 * Resolves customer-facing project URLs.
 *
 * @package DoubleScale\Pro\Modules\Projects
 */

namespace DoubleScale\Pro\Modules\Projects\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;
use DoubleScale\Pro\Modules\Projects\Renderer\ProjectFrontendHandler;

/**
 * ProjectUrl helper.
 */
final class ProjectUrl {

	/**
	 * Query argument for hash-based project access.
	 */
	public const HASH_QUERY_ARG = 'doublescale_project_hash';

	/**
	 * Transient key for the resolved project page permalink.
	 */
	private const PAGE_URL_TRANSIENT = 'doublescale_project_page_url';

	/**
	 * @return string Empty when no project page is found.
	 */
	public static function get_page_url(): string {
		$cached = get_transient( self::PAGE_URL_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			/**
			 * Filter the cached project page URL.
			 *
			 * @param string $url Project page permalink (may be empty).
			 */
			return (string) apply_filters( 'doublescale_project_page_url', $cached );
		}

		$page_id = self::locate_page_id();
		$url     = $page_id > 0 ? (string) get_permalink( $page_id ) : '';

		if ( '' !== $url ) {
			set_transient( self::PAGE_URL_TRANSIENT, $url, HOUR_IN_SECONDS );
		}

		/**
		 * Filter the resolved project page URL.
		 *
		 * @param string $url Project page permalink (may be empty).
		 */
		return (string) apply_filters( 'doublescale_project_page_url', $url );
	}

	/**
	 * @param ProjectModel $project Project model.
	 * @return string Empty when the page cannot be resolved.
	 */
	public static function get_public_url( ProjectModel $project ): string {
		$base = self::get_page_url();
		if ( '' === $base ) {
			return '';
		}

		$hash = trim( (string) $project->hash );
		if ( '' === $hash ) {
			return '';
		}

		$url = add_query_arg( self::HASH_QUERY_ARG, $hash, $base );

		/**
		 * Filter the public project URL.
		 *
		 * @param string       $url     Project URL.
		 * @param ProjectModel $project Project model.
		 */
		return (string) apply_filters( 'doublescale_project_public_url', $url, $project );
	}

	/**
	 * @return void
	 */
	public static function flush_cache(): void {
		delete_transient( self::PAGE_URL_TRANSIENT );
	}

	/**
	 * @return int Page ID, or 0.
	 */
	private static function locate_page_id(): int {
		global $wpdb;

		$needle = ProjectFrontendHandler::SHORTCODE_NAME;
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
