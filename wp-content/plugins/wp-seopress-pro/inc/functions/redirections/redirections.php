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

	// Parsed before being decoded: parse_url() turns every C1 byte into an
	// underscore, so decoding first destroyed the non-Latin characters this
	// path is then compared against. See seopress_pro_parse_url_decoded().
	$raw_current_url = add_query_arg( $_SERVER['QUERY_STRING'], '', $home_url );
	$get_current_url = seopress_pro_parse_url_decoded( $raw_current_url );

	// Kept decoded and escaped exactly as before: this one is not compared
	// against anything, it is handed to handleRedirectionWithId() as `init_url`
	// and ends up in the Location header of a 410/451. Reordering the parse
	// must not change what those visitors receive.
	$get_init_current_url = htmlspecialchars( rawurldecode( $raw_current_url ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );

	// home_url() puts back the path the site is served from, which
	// WP::parse_request() had just stripped out of $wp->request. Origins are
	// stored without it, so on an install in a subdirectory the comparison was
	// `blog/tag/promo/` against `tag/promo/` and nothing ever matched.
	if ( isset( $get_current_url['path'] ) ) {
		$get_current_url['path'] = seopress_pro_strip_home_path( $get_current_url['path'] );
	}

	// WPML.
	if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
		add_filter( 'wpml_get_home_url', 'seopress_remove_wpml_home_url_filter', 20, 5 );
		$home_url2        = home_url( $wp->request );
		$get_current_url2 = seopress_pro_parse_url_decoded( add_query_arg( $_SERVER['QUERY_STRING'], '', $home_url2 ) );
		remove_filter( 'wpml_get_home_url', 'seopress_remove_wpml_home_url_filter', 20 );
	}

	// Weglot. Decoded like the others: the origin is stored decoded, so an
	// encoded path here could never match it either.
	if ( function_exists( 'weglot_get_current_full_url' ) ) {
		$get_current_url_weglot = seopress_pro_parse_url_decoded( weglot_get_current_full_url() );
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
	$uri  = htmlspecialchars_decode( $uri, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );
	$uri2 = htmlspecialchars_decode( $uri2, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );
	$uri3 = htmlspecialchars_decode( $uri3, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );
	$uri4 = htmlspecialchars_decode( $uri4, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );

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

		// Not wp_parse_url(): these strings have already been decoded above, and
		// parse_url() is the exact call that turns every C1 byte into an
		// underscore. Feeding it a decoded URI here mangled `остров` back into
		// `о_ _ _ов` and this branch never matched a non-Latin origin, which is
		// the same defect seopress_pro_parse_url_decoded() fixes upstream.
		// Ignoring the parameters only means dropping the query string.
		$uri  = seopress_pro_strip_query_string( $uri );
		$uri2 = seopress_pro_strip_query_string( $uri2 );
		$uri3 = seopress_pro_strip_query_string( $uri3 );
		$uri4 = seopress_pro_strip_query_string( $uri4 );

		$uri  = ltrim( $uri, '/' );
		$uri2 = ltrim( $uri2, '/' );
		$uri3 = ltrim( $uri3, '/' );
		$uri4 = ltrim( $uri4, '/' );

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

	$get_current_url = htmlspecialchars( rawurldecode( add_query_arg( array(), $wp->request ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );

	// A request the site makes to itself is not a visitor hitting a missing
	// page. Guarding here rather than at each caller means no call site can
	// reintroduce the loop by forgetting the check.
	if ( seopress_404_is_loopback_request() ) {
		return;
	}

	// Exclude URLs from cache and user-defined patterns.
	$match                = false;
	// `^purge/` covers the cache-purge endpoints that fed the loop above. The
	// anchor is what keeps it from swallowing an ordinary page: matching the
	// word anywhere in the request would also drop `blog/how-to-purge/`,
	// `tag/purge/` or `cache-purge/`, silently and for good. The purge
	// endpoint sits at the root of the install, so the start of the request
	// is where it belongs. A site serving it under a prefix, from a language
	// directory for instance, can add `^fr/purge/` to the exclusion list.
	$seopress_404_exclude = array( 'wp-content/cache', '^purge/' );
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
		} elseif ( 0 === strpos( $kw, '^' ) ) {
			// Match at the start of the request only. A leading slash is
			// tolerated, since `$wp->request` carries none and writing
			// `^/purge/` is the natural thing to type.
			$prefix = ltrim( substr( $kw, 1 ), '/' );

			// `strpos()` accepts an empty needle and answers 0, so a bare `^`
			// would exclude every request. It is not a pattern, skip it.
			if ( '' !== $prefix && 0 === strpos( $get_current_url, $prefix ) ) {
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
		$seopress_get_referer = htmlspecialchars( rawurldecode( home_url( $wp->request ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );

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
 * Whether the current request is the site calling itself.
 *
 * WordPress sends `WordPress/<version>; <home_url>` on every `wp_remote_*`
 * call, so a loopback carries our own address in its user agent. Such a
 * request is not a visitor hitting a missing page, and logging it is what
 * turns any cache layer that purges on post save into a self-feeding loop:
 * the purge URL 404s, the 404 log creates a post, creating the post triggers
 * another purge, one path segment longer each turn. A production site was
 * taken down repeatedly that way, with the slug recording eight levels of
 * `purge-seopress_404-purge-seopress_404-…`.
 *
 * `seopress_is_bot()` never caught it: the regex knows plenty of crawlers and
 * nothing about WordPress itself.
 *
 * @since 10.2.0
 *
 * @return bool
 */
function seopress_404_is_loopback_request() {
	$user_agent = empty( $_SERVER['HTTP_USER_AGENT'] ) ? '' : sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );

	if ( '' === $user_agent || 0 !== strpos( $user_agent, 'WordPress/' ) ) {
		return (bool) apply_filters( 'seopress_404_is_loopback_request', false, $user_agent );
	}

	// The version alone is not enough: another WordPress site may crawl us, and
	// that is an ordinary visitor as far as the log is concerned. Only our own
	// address makes it a loopback.
	$host       = wp_parse_url( home_url(), PHP_URL_HOST );
	$agent_host = wp_parse_url( trim( substr( $user_agent, strpos( $user_agent, ';' ) + 1 ) ), PHP_URL_HOST );

	$is_loopback = ! empty( $host ) && ! empty( $agent_host ) && strtolower( $host ) === strtolower( $agent_host );

	return (bool) apply_filters( 'seopress_404_is_loopback_request', $is_loopback, $user_agent );
}

/**
 * Check if the user is a bot.
 *
 * `ai` and `indexing` used to sit in the alternation bare, with no word
 * boundary, so `ai` matched any user agent carrying those two letters in
 * sequence. `Mozilla/5.0 Mail/16.0` was treated as a bot, which silently
 * dropped the 404s of every visitor on Apple Mail. That is the opposite
 * failure to the loopback one, quieter and probably older: it made the log
 * incomplete without anyone noticing.
 */
function seopress_is_bot() {
	$bot_regex = '/bot|crawler|spider|curl|slurp|bingbot|DuckDuckBot|YandexBot|Baiduspider|Sogou|Exabot|facebot|ia_archiver|MJ12bot|AhrefsBot|SemrushBot|DotBot|Googlebot|AppEngine-Google|AdsBot-Google|Google-Structured-Data-Testing-Tool|mediapartners-google|Twitterbot|Pinterest|LinkedInBot|PetalBot|OpenGraphRobot|TelegramBot|Discordbot|WhatsApp|facebookexternalhit|python-requests|Wget|HTTPClient|libwww-perl|okhttp|Slackbot-LinkExpanding|Tumblr|Apache-HttpClient|Postman|Zapier|Cloudflare-AMP|axios|PageSpeed|phantomjs|Nutch|SeznamBot|CCBot|serpstatbot|Upptime|statuscake|Datadog|kube-probe|Go-http-client|screenshot|HeadlessChrome|Headless|GTmetrix|Bytespider|nextcrawl|\bai\b|\bindexing\b|crawler$/i';

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
 * A pattern takes one of three forms, matched against the request without its
 * leading slash:
 *
 * - `.css`      a file extension, matched at the end of the request
 * - `^purge/`   anchored, matched at the start of the request only
 * - `wp-content/cache` anywhere in the request
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
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the post save this hangs off already verified its own nonce.
				'seopress_redirections_value' => isset( $_POST['seopress_redirections_value'] ) ? sanitize_text_field( wp_unslash( $_POST['seopress_redirections_value'] ) ) : null,
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

	// The redirection value comes straight from the submitted form and the
	// transient is site-wide, so the notice can render for a different user
	// than the one who triggered it: everything interpolated here is escaped.
	$message  = '<p>';
	$message .= sprintf(
		/* translators: %1$s: post name (slug) %2$s: url redirect */
		__( 'We were unable to create the redirection you requested (<code>%1$s</code> to <code>%2$s</code>).', 'wp-seopress-pro' ),
		esc_html( $transient['insert_post']->post_name ),
		esc_html( (string) $transient['seopress_redirections_value'] )
	);
	$message .= '</p>';

	$message .= '<p>';
	$message .= sprintf(
		/* translators: %1$s: get_edit_post_link() %2$s: post name (slug) */
		__( 'This URL is already listed as a redirection or a 404 error. Click this link to edit it: <a href="%1$s">%2$s</a>.', 'wp-seopress-pro' ),
		esc_url( $edit_post_link ),
		esc_html( $transient['post_exist']->post_name )
	);
	$message .= '</p>';
	?>
<div class="error notice is-dismissable">
	<?php echo wp_kses_post( $message ); ?>
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
