<?php // phpcs:ignore
/**
 * SEOPress PRO Redirections functions.
 *
 * @package SEOPress PRO
 * @subpackage Redirections
 */
defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Do redirect
 */
function seopress_301_do_redirect() {
	if ( is_admin() ) {
		return;
	}

	global $wp;
	global $post;

	$home_url = home_url( $wp->request );

	// WPML.
	if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
		$home_url = untrailingslashit( home_url( $wp->request ) );
	}

	if ( ! isset( $_SERVER['QUERY_STRING'] ) ) {
		$_SERVER['QUERY_STRING'] = '';
	}

	$get_init_current_url = htmlspecialchars( rawurldecode( add_query_arg( $_SERVER['QUERY_STRING'], '', $home_url ) ) );
	$get_current_url      = wp_parse_url( $get_init_current_url );

	// WPML.
	if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
		add_filter( 'wpml_get_home_url', 'seopress_remove_wpml_home_url_filter', 20, 5 );
		$home_url2             = home_url( $wp->request );
		$get_init_current_url2 = htmlspecialchars( rawurldecode( add_query_arg( $_SERVER['QUERY_STRING'], '', $home_url2 ) ) );
		$get_current_url2      = wp_parse_url( $get_init_current_url2 );
		remove_filter( 'wpml_get_home_url', 'seopress_remove_wpml_home_url_filter', 20 );
	}

	// Weglot.
	if ( function_exists( 'weglot_get_current_full_url' ) ) {
		$get_current_url_weglot = wp_parse_url( weglot_get_current_full_url() );
	}

	$uri               = '';
	$uri2              = '';
	$uri3              = '';
	$uri4              = '';
	$seopress_get_page = '';
	$if_exact_match    = true;

	// Path and Query.
	if ( isset( $get_current_url['path'] ) && ! empty( $get_current_url['path'] ) && isset( $get_current_url['query'] ) && ! empty( $get_current_url['query'] ) ) {
		$uri  = trailingslashit( $get_current_url['path'] ) . '?' . $get_current_url['query'];
		$uri2 = $get_current_url['path'] . '?' . $get_current_url['query'];

		$uri  = ltrim( $uri, '/' );
		$uri2 = ltrim( $uri2, '/' );

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			if ( isset( $get_current_url2['path'] ) && ! empty( $get_current_url2['path'] ) && isset( $get_current_url2['query'] ) && ! empty( $get_current_url2['query'] ) ) {
				$uri3 = $get_current_url2['path'] . '?' . $get_current_url2['query'];
				$uri3 = ltrim( $uri3, '/' );
			}
		}

		if ( function_exists( 'weglot_get_current_full_url' ) ) {
			if ( isset( $get_current_url_weglot['path'] ) && ! empty( $get_current_url_weglot['path'] ) && isset( $get_current_url_weglot['query'] ) && ! empty( $get_current_url_weglot['query'] ) ) {
				$uri4 = $get_current_url_weglot['path'] . '?' . $get_current_url_weglot['query'];
				$uri4 = ltrim( $uri4, '/' );
			}
		}
	} elseif ( isset( $get_current_url['path'] ) && ! empty( $get_current_url['path'] ) && ! isset( $get_current_url['query'] ) ) { // Path only.
		$uri = $get_current_url['path'];
		$uri = ltrim( $uri, '/' );

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			if ( isset( $get_current_url2['path'] ) && ! empty( $get_current_url2['path'] ) && ! isset( $get_current_url2['query'] ) ) {
				$uri3 = $get_current_url2['path'];
				$uri3 = ltrim( $uri3, '/' );
			}
		}

		if ( function_exists( 'weglot_get_current_full_url' ) ) {
			if ( isset( $get_current_url_weglot['path'] ) && ! empty( $get_current_url_weglot['path'] ) && ! isset( $get_current_url_weglot['query'] ) ) {
				$uri4 = $get_current_url_weglot['path'];
				$uri4 = ltrim( $uri4, '/' );
			}
		}
	} elseif ( isset( $get_current_url['query'] ) && ! empty( $get_current_url['query'] ) && ! isset( $get_current_url['path'] ) ) { // Query only.
		$uri = '?' . $get_current_url['query'];
		$uri = ltrim( $uri, '/' );

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			if ( isset( $get_current_url2['query'] ) && ! empty( $get_current_url2['query'] ) && ! isset( $get_current_url2['path'] ) ) {
				$uri3 = '?' . $get_current_url2['query'];
				$uri3 = ltrim( $uri3, '/' );
			}
		}

		if ( function_exists( 'weglot_get_current_full_url' ) ) {
			if ( isset( $get_current_url_weglot['query'] ) && ! empty( $get_current_url_weglot['query'] ) && ! isset( $get_current_url_weglot['path'] ) ) {
				$uri4 = '?' . $get_current_url_weglot['query'];
				$uri4 = ltrim( $uri4, '/' );
			}
		}
	} elseif ( isset( $get_current_url['host'] ) ) { // Default - home.
		$uri = $get_current_url['host'];
	}

	// Necessary to allowed "&" in query.
	$uri  = htmlspecialchars_decode( $uri );
	$uri2 = htmlspecialchars_decode( $uri2 );
	$uri3 = htmlspecialchars_decode( $uri3 );
	$uri4 = htmlspecialchars_decode( $uri4 );

	$page_uri = seopress_pro_get_service( 'Redirection' )->getPageByTitle( trailingslashit( $uri ), '', 'seopress_404' );

	$page_uri2 = seopress_pro_get_service( 'Redirection' )->getPageByTitle( $uri2, '', 'seopress_404' );

	if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
		$page_uri4 = seopress_pro_get_service( 'Redirection' )->getPageByTitle( $uri3, '', 'seopress_404' );
	}

	if ( function_exists( 'weglot_get_current_full_url' ) ) {
		$page_uri5 = seopress_pro_get_service( 'Redirection' )->getPageByTitle( $uri4, '', 'seopress_404' );
	}

	$page_uri3 = seopress_pro_get_service( 'Redirection' )->getPageByTitle( $uri, '', 'seopress_404' );

	// Find URL in Redirections post type --- EXACT MATCH.
	// With trailing slash.
	if ( isset( $uri ) && '' != $uri && $page_uri ) {
		$seopress_get_page = $page_uri;
	} elseif ( isset( $uri2 ) && '' != $uri2 && $page_uri2 ) { // Without trailing slash.
		$seopress_get_page = $page_uri2;
	} elseif ( defined( 'ICL_SITEPRESS_VERSION' ) && isset( $uri3 ) && '' != $uri3 && $page_uri4 ) { // Without language prefix (WPML).
		$seopress_get_page = $page_uri4;
	} elseif ( function_exists( 'weglot_get_current_full_url' ) && isset( $uri4 ) && '' != $uri4 && $page_uri5 ) { // Without language prefix (Weglot).
		$seopress_get_page = $page_uri5;
	} else { // Default.
		$seopress_get_page = $page_uri3;
	}

	// Find URL in Redirections post type --- IGNORE ALL PARAMETERS.
	if ( empty( $seopress_get_page ) ) {
		$if_exact_match = false;

		$uri  = wp_parse_url( $uri, PHP_URL_PATH );
		$uri2 = wp_parse_url( $uri2, PHP_URL_PATH );
		$uri3 = wp_parse_url( $uri3, PHP_URL_PATH );
		$uri4 = wp_parse_url( $uri4, PHP_URL_PATH );

		$uri  = is_string( $uri ) ? ltrim( $uri, '/' ) : '';
		$uri2 = is_string( $uri2 ) ? ltrim( $uri2, '/' ) : '';
		$uri3 = is_string( $uri3 ) ? ltrim( $uri3, '/' ) : '';
		$uri4 = is_string( $uri4 ) ? ltrim( $uri4, '/' ) : '';

		$page_uri  = seopress_pro_get_service( 'Redirection' )->getPageByTitle( trailingslashit( $uri ), '', 'seopress_404' );
		$page_uri2 = seopress_pro_get_service( 'Redirection' )->getPageByTitle( $uri2, '', 'seopress_404' );

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$page_uri4 = seopress_pro_get_service( 'Redirection' )->getPageByTitle( $uri3, '', 'seopress_404' );
		}

		if ( function_exists( 'weglot_get_current_full_url' ) ) {
			$page_uri5 = seopress_pro_get_service( 'Redirection' )->getPageByTitle( $uri4, '', 'seopress_404' );
		}

		$page_uri3 = seopress_pro_get_service( 'Redirection' )->getPageByTitle( $uri, '', 'seopress_404' );

		$page_uri  = seopress_pro_get_service( 'Redirection' )->getPageByTitle( trailingslashit( $uri ), '', 'seopress_404' );
		$page_uri2 = seopress_pro_get_service( 'Redirection' )->getPageByTitle( $uri2, '', 'seopress_404' );
		$page_uri3 = seopress_pro_get_service( 'Redirection' )->getPageByTitle( $uri, '', 'seopress_404' );

		// With trailing slash.
		if ( isset( $uri ) && '' != $uri && $page_uri ) {
			$seopress_get_page = $page_uri;
		} elseif ( isset( $uri2 ) && '' != $uri2 && $page_uri2 ) { // Without trailing slash.
			$seopress_get_page = $page_uri2;
		} elseif ( defined( 'ICL_SITEPRESS_VERSION' ) && isset( $uri3 ) && '' != $uri3 && $page_uri4 ) { // Without language prefix (WPML).
			$seopress_get_page = $page_uri4;
		} elseif ( function_exists( 'weglot_get_current_full_url' ) && isset( $uri4 ) && '' != $uri4 && $page_uri5 ) { // Without language prefix (Weglot).
			$seopress_get_page = $page_uri5;
		} else { // Default.
			$seopress_get_page = $page_uri3;
		}
	}

	do_action( 'seopress_before_redirect', $seopress_get_page );

	if ( ! isset( $seopress_get_page->ID ) ) {
		seopress_pro_get_service( 'Redirection' )->checkRegexRedirect();
		return;
	}

	if ( 'publish' !== get_post_status( $seopress_get_page->ID ) ) {
		seopress_pro_get_service( 'Redirection' )->checkRegexRedirect();
		return;
	}

	seopress_pro_get_service( 'Redirection' )->handleRedirectionWithId(
		$seopress_get_page->ID,
		array(
			'init_url'       => $get_init_current_url,
			'if_exact_match' => $if_exact_match,
		)
	);

	// If handleRedirectionWithId() returned without redirecting (e.g. the
	// matched post is a 404 log, or the redirection is disabled / does not
	// apply to the current logged-in status), fall through to regex check.
	seopress_pro_get_service( 'Redirection' )->checkRegexRedirect();
}
add_action( 'template_redirect', 'seopress_301_do_redirect', 1 );

// Disable guess redirect URL for 404.
if ( seopress_pro_get_service( 'OptionPro' )->get404DisableGuessAutomaticRedirects() === '1' ) {
	add_filter( 'do_redirect_guess_404_permalink', '__return_false' );
}

/**
 * Create Redirection in Post Type.
 */
function seopress_404_create_redirect() {
	global $wp;
	global $post;

	$get_current_url = htmlspecialchars( rawurldecode( add_query_arg( array(), $wp->request ) ) );

	// Exclude URLs from cache and user-defined patterns.
	$match                = false;
	$seopress_404_exclude = array( 'wp-content/cache' );
	$seopress_404_exclude = apply_filters( 'seopress_404_exclude', $seopress_404_exclude );

	foreach ( $seopress_404_exclude as $kw ) {
		$kw = trim( $kw );
		if ( empty( $kw ) ) {
			continue;
		}

		// Check if pattern starts with a dot (file extension like .js, .css).
		if ( 0 === strpos( $kw, '.' ) ) {
			// Match file extension at end of URL.
			if ( substr( strtolower( $get_current_url ), -strlen( $kw ) ) === strtolower( $kw ) ) {
				$match = true;
				break;
			}
		} elseif ( false !== strpos( $get_current_url, $kw ) ) {
			// Match if URL contains the pattern anywhere.
			$match = true;
			break;
		}
	}

	// Get Current Time.
	$seopress_get_current_time = time();

	// Creating 404 error in seopress_404.
	if ( false === $match ) {
		$seopress_get_page = seopress_pro_get_service( 'Redirection' )->getPageByTitle( $get_current_url, '', 'seopress_404' );

		// Get Title.
		if ( '' != $seopress_get_page ) {
			$seopress_get_post_title = $seopress_get_page->post_title;
		} else {
			$seopress_get_post_title = '';
		}

		// Get User Agent.
		$seopress_get_ua = '';
		if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$seopress_get_ua = $_SERVER['HTTP_USER_AGENT'];
		}

		// Get Full Origin.
		$seopress_get_referer = '';
		$seopress_get_referer = htmlspecialchars( rawurldecode( home_url( $wp->request ) ) );

		if ( $get_current_url && $seopress_get_post_title != $get_current_url ) {
			// Security: Enforce maximum 404 entries limit to prevent DDOS attacks.
			seopress_404_enforce_entry_limit();

			$id = wp_insert_post(
				array(
					'post_title'  => $get_current_url,
					'meta_input'  => array(
						'seopress_404_count'            => 1,
						'seopress_redirections_ua'      => sanitize_text_field( $seopress_get_ua ),
						'seopress_redirections_referer' => sanitize_url( $seopress_get_referer ),
						'_seopress_404_redirect_date_request' => $seopress_get_current_time,
					),
					'post_type'   => 'seopress_404',
					'post_status' => 'publish',
				)
			);

			do_action( 'seopress_after_create_404', $id );
		} elseif ( $get_current_url && $seopress_get_page->post_title == $get_current_url ) {
			$seopress_404_count = (int) get_post_meta( $seopress_get_page->ID, 'seopress_404_count', true );
			update_post_meta( $seopress_get_page->ID, 'seopress_404_count', ++$seopress_404_count );
			update_post_meta( $seopress_get_page->ID, '_seopress_404_redirect_date_request', $seopress_get_current_time );
			update_post_meta( $seopress_get_page->ID, 'seopress_redirections_ua', sanitize_text_field( $seopress_get_ua ) );
			update_post_meta( $seopress_get_page->ID, 'seopress_redirections_referer', sanitize_url( $seopress_get_referer ) );
		}
	}
}

/**
 * Enforce 404 entry limit to prevent DDOS attacks.
 *
 * Limits the number of 404 error entries to prevent database overflow from malicious attacks.
 * When the limit is reached, automatically deletes the oldest entries (FIFO).
 * Only deletes actual 404 errors, not configured redirects (301, 302, 307, 410, 451).
 *
 * IMPORTANT: This works in conjunction with the daily cleanup cron (seopress_404_cron_cleaning)
 * but serves different purposes:
 * - Daily cleanup (optional): Deletes 404s older than 30 days (time-based)
 * - This function (always-on): Limits total count to 1000 max (count-based)
 *
 * Both can run simultaneously without conflict. This provides defense-in-depth:
 * - If daily cleanup is enabled: Time-based + Count-based protection
 * - If daily cleanup is disabled: Count-based protection prevents unlimited growth
 *
 * @since 9.4.0
 * @return void
 */
function seopress_404_enforce_entry_limit() {
	global $wpdb;

	// Allow customization of the limit via filter. Default: 1000 entries.
	$max_entries = apply_filters( 'seopress_404_max_entries', 1000 );

	// Batch size to prevent timeouts. Default: 500 entries per execution.
	$batch_size = apply_filters( 'seopress_404_cleanup_batch_size', 500 );

	// Count only 404 errors (posts without redirect type meta).
	$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for real-time count.
		$wpdb->prepare(
			"SELECT COUNT(p.ID)
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
			WHERE p.post_type = %s
			AND p.post_status = %s
			AND pm.meta_id IS NULL",
			'_seopress_redirections_type',
			'seopress_404',
			'publish'
		)
	);

	// If we're at or over the limit, delete oldest entries.
	if ( $count >= $max_entries ) {
		// Calculate how many to delete (keep it at max_entries - 1 to make room for new entry).
		$to_delete = $count - $max_entries + 1;

		// Limit deletion to batch size to prevent timeouts.
		$immediate_delete = min( $to_delete, $batch_size );

		// Get IDs of oldest 404 entries (without redirect type).
		$old_entries = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for cleanup operation.
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
				WHERE p.post_type = %s
				AND p.post_status = %s
				AND pm.meta_id IS NULL
				ORDER BY p.post_date ASC
				LIMIT %d",
				'_seopress_redirections_type',
				'seopress_404',
				'publish',
				$immediate_delete
			)
		);

		// Delete old entries.
		if ( ! empty( $old_entries ) ) {
			foreach ( $old_entries as $post_id ) {
				wp_delete_post( $post_id, true ); // Force delete (bypass trash).
			}
		}

		// If more deletions needed, schedule background cleanup.
		if ( $to_delete > $batch_size ) {
			seopress_404_schedule_cleanup();
		}
	}
}

/**
 * Schedule background cleanup for 404 entries.
 *
 * Schedules a cron job to gradually clean up excess 404 entries in the background.
 * This prevents timeouts when dealing with large numbers of 404 entries.
 *
 * @since 9.4.0
 * @return void
 */
function seopress_404_schedule_cleanup() {
	// Check if cleanup is already scheduled.
	if ( ! wp_next_scheduled( 'seopress_404_background_cleanup' ) ) {
		// Schedule cleanup to run in 1 minute, then every 5 minutes until complete.
		wp_schedule_event( time() + 60, 'seopress_404_cleanup_interval', 'seopress_404_background_cleanup' );
	}
}

/**
 * Execute background cleanup of excess 404 entries.
 *
 * Runs via cron to delete oldest 404 entries in batches until the limit is reached.
 * Automatically unschedules itself when cleanup is complete.
 *
 * This is crucial for sites updating to this version with millions of existing 404 entries.
 * Example: A site with 5 million 404s would take approximately 10,000 cron runs
 * (at 500 per batch) over ~35 days at 5-minute intervals to clean up.
 *
 * For faster cleanup of extreme cases, admins can:
 * 1. Increase batch size: add_filter('seopress_404_cleanup_batch_size', function() { return 5000; });
 * 2. Use the SQL query from SEOPress documentation
 *
 * @since 9.4.0
 * @return void
 */
function seopress_404_background_cleanup() {
	global $wpdb;

	$max_entries = apply_filters( 'seopress_404_max_entries', 1000 );
	$batch_size  = apply_filters( 'seopress_404_cleanup_batch_size', 500 );

	// Count current 404 errors.
	$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for real-time count.
		$wpdb->prepare(
			"SELECT COUNT(p.ID)
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
			WHERE p.post_type = %s
			AND p.post_status = %s
			AND pm.meta_id IS NULL",
			'_seopress_redirections_type',
			'seopress_404',
			'publish'
		)
	);

	// If we're within the limit, unschedule and exit.
	if ( $count <= $max_entries ) {
		wp_clear_scheduled_hook( 'seopress_404_background_cleanup' );
		return;
	}

	// Calculate how many to delete in this batch.
	$to_delete = min( $count - $max_entries, $batch_size );

	// Get oldest entries.
	$old_entries = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for cleanup operation.
		$wpdb->prepare(
			"SELECT p.ID
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
			WHERE p.post_type = %s
			AND p.post_status = %s
			AND pm.meta_id IS NULL
			ORDER BY p.post_date ASC
			LIMIT %d",
			'_seopress_redirections_type',
			'seopress_404',
			'publish',
			$to_delete
		)
	);

	// Delete entries.
	if ( ! empty( $old_entries ) ) {
		foreach ( $old_entries as $post_id ) {
			wp_delete_post( $post_id, true );
		}
	}
}
add_action( 'seopress_404_background_cleanup', 'seopress_404_background_cleanup' );

/**
 * Add custom cron schedule for 404 cleanup.
 *
 * Runs every 5 minutes to process 404 cleanup in the background.
 *
 * Note: While WordPress Coding Standards recommend a minimum of 15 minutes for cron intervals,
 * we use 5 minutes here for a critical reason: cleanup for sites with millions of 404 entries
 * would take too long with a 15-minute interval. The cron auto-unschedules when cleanup is complete,
 * so it only runs temporarily during migration periods, not indefinitely.
 *
 * Suppressing PHPCS warning: phpcs:disable WordPress.WP.CronInterval.CronSchedulesInterval
 *
 * @since 9.4.0
 * @param array $schedules Existing cron schedules.
 * @return array Modified schedules.
 */
function seopress_404_cleanup_cron_schedule( $schedules ) {
	$schedules['seopress_404_cleanup_interval'] = array(
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval, WordPress.WP.CronInterval.ChangeDetected -- Justified: Temporary 5-min cron for migration cleanup. Auto-unschedules when complete. Filter allows customization.
		'interval' => apply_filters( 'seopress_404_cleanup_interval', 300 ),
		'display'  => esc_html__( 'Every 5 minutes (SEOPress 404 Cleanup)', 'wp-seopress-pro' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'seopress_404_cleanup_cron_schedule' ); // phpcs:ignore -- https://github.com/WordPress/WordPress-Coding-Standards/issues/1865

/**
 * One-time migration to enforce 404 limit on plugin activation/update.
 *
 * Checks if the site has more than the allowed 404 entries and schedules
 * background cleanup if needed. Only runs once per version.
 *
 * This is critical for sites with millions of 404 entries.
 * The migration schedules background cleanup which runs every 5 minutes in batches of 500.
 * Sites can speed up cleanup by increasing batch size via filter or using manual tools.
 *
 * @since 9.4.0
 * @return void
 */
function seopress_404_one_time_migration() {
	global $wpdb;

	// Check if migration already ran for this version.
	$migration_version = get_option( 'seopress_404_limit_migration_version' );
	if ( SEOPRESS_PRO_VERSION === $migration_version ) {
		return; // Already migrated for this version.
	}

	$max_entries = apply_filters( 'seopress_404_max_entries', 1000 );

	// Count current 404 errors.
	$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for migration check.
		$wpdb->prepare(
			"SELECT COUNT(p.ID)
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
			WHERE p.post_type = %s
			AND p.post_status = %s
			AND pm.meta_id IS NULL",
			'_seopress_redirections_type',
			'seopress_404',
			'publish'
		)
	);

	// If over limit, schedule background cleanup.
	if ( $count > $max_entries ) {
		seopress_404_schedule_cleanup();
	}

	// Mark migration as complete for this version.
	update_option( 'seopress_404_limit_migration_version', SEOPRESS_PRO_VERSION, false );
}
add_action( 'seopress_pro_activation', 'seopress_404_one_time_migration' );

/**
 * Check if the user is a bot.
 */
function seopress_is_bot() {
	$bot_regex = '/bot|crawler|spider|curl|slurp|bingbot|DuckDuckBot|YandexBot|Baiduspider|Sogou|Exabot|facebot|ia_archiver|MJ12bot|AhrefsBot|SemrushBot|DotBot|Googlebot|AppEngine-Google|AdsBot-Google|Google-Structured-Data-Testing-Tool|mediapartners-google|Twitterbot|Pinterest|LinkedInBot|PetalBot|OpenGraphRobot|TelegramBot|Discordbot|WhatsApp|facebookexternalhit|python-requests|Wget|HTTPClient|libwww-perl|okhttp|Slackbot-LinkExpanding|Tumblr|Apache-HttpClient|Postman|Zapier|Cloudflare-AMP|axios|PageSpeed|phantomjs|Nutch|SeznamBot|CCBot|serpstatbot|Upptime|statuscake|Datadog|kube-probe|Go-http-client|screenshot|HeadlessChrome|Headless|GTmetrix|Bytespider|nextcrawl|ai|indexing|crawler$/i';

	$bot_regex = apply_filters( 'seopress_404_bots', $bot_regex );

	$user_agent = empty( $_SERVER['HTTP_USER_AGENT'] ) ? false : $_SERVER['HTTP_USER_AGENT'];

	if ( ! empty( $bot_regex ) && ! empty( $user_agent ) ) {
		return preg_match( $bot_regex, $user_agent );
	}

	return false;
}

/**
 * Log the 404 error.
 */
function seopress_404_log() {
	if ( is_404() && ! is_admin() && '' != seopress_pro_get_service( 'OptionPro' )->get404RedirectHome() ) {
		if ( 'home' === seopress_pro_get_service( 'OptionPro' )->get404RedirectHome() ) {
			if ( '' != seopress_pro_get_service( 'OptionPro' )->get404RedirectStatusCode() ) {
				if ( '1' != seopress_is_bot() && seopress_pro_get_service( 'OptionPro' )->get404Enable() ) {
					seopress_404_create_redirect();
				}
				wp_redirect( get_home_url(), seopress_pro_get_service( 'OptionPro' )->get404RedirectStatusCode() );
				exit;
			} else {
				if ( '1' != seopress_is_bot() && seopress_pro_get_service( 'OptionPro' )->get404Enable() ) {
					seopress_404_create_redirect();
				}
				wp_redirect( get_home_url(), '301' );
				exit;
			}
		} elseif ( 'custom' === seopress_pro_get_service( 'OptionPro' )->get404RedirectHome() && '' !== seopress_pro_get_service( 'OptionPro' )->get404RedirectUrl() ) {
			if ( '' != seopress_pro_get_service( 'OptionPro' )->get404RedirectStatusCode() ) {
				if ( '1' != seopress_is_bot() && seopress_pro_get_service( 'OptionPro' )->get404Enable() ) {
					seopress_404_create_redirect();
				}
				wp_redirect( seopress_pro_get_service( 'OptionPro' )->get404RedirectUrl(), seopress_pro_get_service( 'OptionPro' )->get404RedirectStatusCode() );
				exit;
			} else {
				if ( '1' != seopress_is_bot() && seopress_pro_get_service( 'OptionPro' )->get404Enable() ) {
					seopress_404_create_redirect();
				}
				wp_redirect( seopress_pro_get_service( 'OptionPro' )->get404RedirectUrl(), '301' );
				exit;
			}
		} elseif ( '1' != seopress_is_bot() && seopress_pro_get_service( 'OptionPro' )->get404Enable() ) {
				seopress_404_create_redirect();
		}
	} elseif ( is_404() && ! is_admin() && seopress_pro_get_service( 'OptionPro' )->get404Enable() ) {
		if ( '1' != seopress_is_bot() && seopress_pro_get_service( 'OptionPro' )->get404Enable() ) {
			seopress_404_create_redirect();
		}
	}
}
add_action( 'template_redirect', 'seopress_404_log' );

/**
 * Add user-defined paths to 404 exclude list.
 *
 * @param array $exclude The default exclude paths.
 * @return array The modified exclude paths.
 */
function seopress_404_add_user_exclude_paths( $exclude ) {
	$options = get_option( 'seopress_pro_option_name' );

	if ( empty( $options['seopress_404_exclude_paths'] ) ) {
		return $exclude;
	}

	// Parse the textarea content - one path per line.
	$user_paths = explode( "\n", $options['seopress_404_exclude_paths'] );
	$user_paths = array_map( 'trim', $user_paths );
	$user_paths = array_filter( $user_paths ); // Remove empty lines.

	return array_merge( $exclude, $user_paths );
}
add_filter( 'seopress_404_exclude', 'seopress_404_add_user_exclude_paths' );

/**
 * Prevent title redirection already exists.
 *
 * @param WP_Post $post The post object.
 * @return void
 */
function seopress_prevent_title_redirection_already_exist( $post ) {
	if ( 'seopress_404' !== $post->post_type ) {
		return;
	}

	if ( wp_is_post_revision( $post ) ) {
		return;
	}

	global $wpdb;

	$sql = $wpdb->prepare(
		"SELECT *
		FROM $wpdb->posts
		WHERE 1=1
		AND post_title = %s
		AND post_type = %s
		AND post_status = 'publish'",
		$post->post_title,
		'seopress_404'
	);

	$wpdb->get_results( $sql );

	$count_post_title_exist = $wpdb->num_rows;

	if ( $count_post_title_exist > 1 ) { // Already exist.
		wp_delete_post( $post->ID );
		$exist_redirect_post = seopress_pro_get_service( 'Redirection' )->getPageByTitle( $post->post_title, '', 'seopress_404' );

		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : admin_url( 'edit.php?post_type=seopress_404' );
		$url     = remove_query_arg( 'wp-post-new-reload', $referer );
		set_transient(
			'seopress_prevent_title_redirection_already_exist',
			array(
				'insert_post'                 => $post,
				'post_exist'                  => $exist_redirect_post,
				'seopress_redirections_value' => isset( $_POST['seopress_redirections_value'] ) ? $_POST['seopress_redirections_value'] : null,
			),
			3600
		);

		wp_safe_redirect( $url );
		exit;
	}

	// Remove notice watcher if needed.
	$notices = seopress_get_option_post_need_redirects();

	if ( $notices ) {
		foreach ( $notices as $key => $notice ) {
			if ( false !== strpos( $notice['before_url'], $post->post_title ) ) {
				seopress_remove_notification_for_redirect( $notice['id'] );
			}
		}
	}
}
add_filter( 'auto-draft_to_publish', 'seopress_prevent_title_redirection_already_exist' );
add_filter( 'draft_to_publish', 'seopress_prevent_title_redirection_already_exist' );


/**
 * Notice prevent create title redirection.
 *
 * @return void
 */
function seopress_notice_prevent_create_title_redirection() {
	$transient = get_transient( 'seopress_prevent_title_redirection_already_exist' );
	if ( ! $transient ) {
		return;
	}

	// Remove notice watcher if needed.
	$notices = seopress_get_option_post_need_redirects();
	if ( $notices ) {
		foreach ( $notices as $key => $notice ) {
			if ( false !== strpos( $notice['before_url'], $transient['insert_post']->post_name ) ) {
				seopress_remove_notification_for_redirect( $notice['id'] );
			}
		}
	}

	delete_transient( 'seopress_prevent_title_redirection_already_exist' );

	$edit_post_link = get_edit_post_link( $transient['post_exist']->ID );

	$message  = '<p>';
	$message .= sprintf(
		/* translators: %1$s: post name (slug) %2$s: url redirect */
		__( 'We were unable to create the redirection you requested (<code>%1$s</code> to <code>%2$s</code>).', 'wp-seopress-pro' ),
		$transient['insert_post']->post_name,
		$transient['seopress_redirections_value']
	);
	$message .= '</p>';

	$message .= '<p>';
	$message .= sprintf(
		/* translators: %1$s: get_edit_post_link() %2$s: post name (slug) */
		__( 'This URL is already listed as a redirection or a 404 error. Click this link to edit it: <a href="%1$s">%2$s</a>.', 'wp-seopress-pro' ),
		$edit_post_link,
		$transient['post_exist']->post_name
	);
	$message .= '</p>';
	?>
<div class="error notice is-dismissable">
	<?php echo $message; ?>
</div>
	<?php
}
add_action( 'seopress_admin_notices', 'seopress_notice_prevent_create_title_redirection' );

/**
 * Need add term auto redirect.
 *
 * @param int     $post_id The post ID.
 * @param WP_Post $post The post object.
 * @return void
 */
function seopress_need_add_term_auto_redirect( $post_id, $post ) {
	if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
		return;
	}

	$referer = wp_get_referer();
	if ( ! $referer ) {
		return;
	}

	$parse_referer = wp_parse_url( $referer );
	if ( array_key_exists( 'query', $parse_referer ) && false === strpos( $parse_referer['query'], 'prepare_redirect=1' ) ) {
		return;
	}

	$name_term         = 'Auto Redirect';
	$slug_term         = 'autoredirect_by_seopress';
	$term_autoredirect = get_term_by( 'slug', $slug_term, 'seopress_404_cat', ARRAY_A );
	if ( ! $term_autoredirect ) {
		$term_autoredirect = wp_insert_term(
			$name_term,
			'seopress_404_cat',
			array(
				'slug' => $slug_term,
			)
		);
	}

	$terms_id = array();

	if ( $term_autoredirect && ! is_wp_error( $term_autoredirect ) ) {
		$term_id = $term_autoredirect['term_id'];

		$terms    = get_the_terms( $post_id, 'seopress_404_cat' );
		$terms_id = array( $term_id );
		if ( $terms && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$terms_id[] = $term->term_id;
			}
		}
	}

	if ( empty( $terms_id ) ) {
		return;
	}

	wp_set_post_terms( $post_id, $terms_id, 'seopress_404_cat' );
}
add_action( 'save_post_seopress_404', 'seopress_need_add_term_auto_redirect', 10, 2 );
