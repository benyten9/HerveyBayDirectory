<?php

namespace SEOPressPro\Actions\Admin;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

use SEOPress\Core\Hooks\ExecuteHooks;

/**
 * Purges the robots.txt page cache whenever the SEOPress PRO robots settings change.
 *
 * Supports:
 *  - WP Rocket
 *  - LiteSpeed Cache
 *  - W3 Total Cache
 *  - WP Super Cache
 *  - Cache Enabler
 *  - SG Optimizer (SiteGround)
 *  - Hummingbird
 *  - Breeze (Cloudways)
 *  - Comet Cache
 *  - Autoptimize
 */
class PurgeRobotsCache implements ExecuteHooks {

	/**
	 * @return void
	 */
	public function hooks() {
		add_action( 'updated_option', array( $this, 'maybeFlush' ), 10, 3 );
	}

	/**
	 * Fires after any option update. Only acts when the PRO option bag
	 * changes in a way that affects the virtual robots.txt output.
	 *
	 * @param string $option     Option name.
	 * @param mixed  $old_value  Previous value.
	 * @param mixed  $new_value  New value.
	 *
	 * @return void
	 */
	public function maybeFlush( $option, $old_value, $new_value ) {
		if ( 'seopress_pro_option_name' !== $option ) {
			return;
		}

		$old_enable = isset( $old_value['seopress_robots_enable'] ) ? $old_value['seopress_robots_enable'] : '';
		$new_enable = isset( $new_value['seopress_robots_enable'] ) ? $new_value['seopress_robots_enable'] : '';

		$old_file = isset( $old_value['seopress_robots_file'] ) ? $old_value['seopress_robots_file'] : '';
		$new_file = isset( $new_value['seopress_robots_file'] ) ? $new_value['seopress_robots_file'] : '';

		$old_mu_enable = isset( $old_value['seopress_mu_robots_enable'] ) ? $old_value['seopress_mu_robots_enable'] : '';
		$new_mu_enable = isset( $new_value['seopress_mu_robots_enable'] ) ? $new_value['seopress_mu_robots_enable'] : '';

		$old_mu_file = isset( $old_value['seopress_mu_robots_file'] ) ? $old_value['seopress_mu_robots_file'] : '';
		$new_mu_file = isset( $new_value['seopress_mu_robots_file'] ) ? $new_value['seopress_mu_robots_file'] : '';

		$robots_changed = (
			$old_enable !== $new_enable ||
			$old_file !== $new_file ||
			$old_mu_enable !== $new_mu_enable ||
			$old_mu_file !== $new_mu_file
		);

		if ( ! $robots_changed ) {
			return;
		}

		flush_rewrite_rules( false );

		$robots_url = home_url( '/robots.txt' );

		$this->purgeCache( $robots_url );
	}

	/**
	 * Calls the purge API of every supported caching plugin.
	 *
	 * @param string $url The robots.txt URL to purge.
	 *
	 * @return void
	 */
	private function purgeCache( $url ) {
		// WP Rocket.
		if ( function_exists( 'rocket_clean_files' ) ) {
			rocket_clean_files( array( $url ) );
		}

		// LiteSpeed Cache.
		do_action( 'litespeed_purge_url', $url );

		// W3 Total Cache.
		if ( function_exists( 'w3tc_pgcache_flush_url' ) ) {
			w3tc_pgcache_flush_url( $url );
		}

		// WP Super Cache.
		if ( function_exists( 'wpsc_delete_url_cache' ) ) {
			wpsc_delete_url_cache( $url );
		}

		// Cache Enabler (KeyCDN).
		do_action( 'cache_enabler_clear_page_cache_by_url', $url );

		// SG Optimizer (SiteGround).
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
		do_action( 'sgo_cc_purge_url', $url );

		// Hummingbird (WPMU Dev).
		do_action( 'wphb_clear_page_cache', $url );

		// Breeze (Cloudways).
		do_action( 'breeze_clear_all_cache' );

		// Comet Cache.
		if ( class_exists( 'comet_cache' ) && method_exists( 'comet_cache', 'clearCurrentUser' ) ) {
			\comet_cache::clearCurrentUser();
		}

		// Autoptimize.
		do_action( 'autoptimize_action_cachepurged' );
	}
}
