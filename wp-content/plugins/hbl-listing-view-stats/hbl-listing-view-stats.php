<?php
/**
 * Plugin Name:  HBL – Listing View Stats → DoubleScale
 * Plugin URI:
 * Description:  Surfaces Directorist listing view data as DoubleScale contact
 *               custom fields (and therefore as email merge tags), refreshed
 *               automatically once per calendar quarter.
 *
 *               Two numbers are published per listing owner:
 *                 1. Total views the listing received in the PREVIOUS calendar
 *                    quarter.
 *                 2. The average view count across all listings sharing the
 *                    same subcategory, for the same quarter — so a listing can
 *                    be compared against similar listings.
 *
 *               Applies to Bronze, Silver and Gold listings alike; the plan
 *               tier is published as its own field so campaigns can segment.
 *
 * Version:      1.1.107
 * Requires PHP: 7.4
 * Author:       HBL
 * License:      GPL-2.0+
 * Text Domain:  hbl-lvs
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * WHY THIS PLUGIN EXISTS (READ THIS FIRST)
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Directorist does not store view history. It keeps a single cumulative integer
 * per listing in postmeta `_atbdp_post_views_count` and increments it by one per
 * view (see directorist_set_listing_views_count() in the plugin's
 * helper-functions.php). There are no timestamps and no per-view records.
 *
 * Consequently "views during a given quarter" CANNOT be derived from Directorist
 * alone, and CANNOT be backfilled for any quarter that has already passed.
 *
 * This plugin therefore records its own timestamped view events from the moment
 * it is activated. Quarterly totals are exact from that point forward. The first
 * fully-complete calendar quarter is the first one that starts after activation;
 * any earlier partial period is labelled honestly (e.g. "1 Aug – 30 Sep 2026")
 * rather than being passed off as a whole quarter.
 *
 * As a secondary safety net the cumulative Directorist counter is snapshotted at
 * every quarter boundary. If the event log is ever empty for a period (logger
 * broken, table truncated) the rollup falls back to the difference between two
 * boundary snapshots.
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * HOW IT WORKS
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * 1. LOGGING — TWO SOURCES, ONE ROW
 *    (a) Directorist fires `directorist_listing_views_count_updated` on every
 *        view it counts. We hook it.
 *    (b) We ALSO record the view server-side on `template_redirect` for any
 *        single listing page.
 *
 *    (b) exists because (a) cannot be trusted. Directorist counts views from
 *        JavaScript that only runs when the page contains
 *        `.directorist-single-contents-area`, emitted by its own single-listing
 *        template. Where listing pages are built with a page builder or a
 *        custom theme template, neither that element nor the tracking script is
 *        present and Directorist records nothing — silently, indefinitely.
 *        Server-side recording is immune to how the page is rendered.
 *
 *    Both write one row to {prefix}hbl_lvs_events with a GMT timestamp and a
 *    salted visitor hash (no raw IP is ever stored). They share a derived
 *    de-duplication key, so running together cannot double-count.
 *
 *    Server-side tracking sees crawlers that JavaScript tracking excluded for
 *    free, so a user-agent filter does that job (see hbl_lvs_is_bot). Toggle
 *    the whole behaviour from the admin screen or via the
 *    `hbl_lvs_server_side_tracking` filter.
 *
 *    De-duplication: repeat views of the same listing by the same visitor inside
 *    a 30-minute window collapse into one row, enforced by a UNIQUE index on a
 *    derived key. Directorist itself performs NO de-duplication (its own source
 *    carries a @todo about this), so raw Directorist counts are materially
 *    higher than the numbers produced here. Ours are "unique views".
 *
 * 2. ROLLUP (quarterly, automatic)
 *    A daily cron checks whether the previous calendar quarter has been rolled
 *    up yet. If not, it runs a batched three-phase job:
 *
 *      collect   → per listing: quarter views, subcategory, tier, owner email
 *      aggregate → subcategory averages; pick one primary listing per contact
 *      push      → write the custom fields onto each DoubleScale contact
 *
 *    The daily-check design is deliberate: a single quarterly wp-cron event that
 *    misses its window (wp-cron only fires on traffic) would lose an entire
 *    quarter with no way to recover it. This job is idempotent and self-healing,
 *    and is additionally nudged by a once-per-hour page-load fallback.
 *
 * 3. DELIVERY
 *    DoubleScale runs on this same site, so values are written straight into its
 *    contact custom-field tables via the CRM Bridge plugin's helpers — no
 *    webhook round-trip. Once written they are usable as merge tags and as
 *    segment/automation filters.
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * MERGE TAGS PRODUCED
 * ──────────────────────────────────────────────────────────────────────────────
 *
 *   {{contact:contact_field:listing-views-last-quarter}}        184
 *   {{contact:contact_field:views-quarter-label}}               Apr–Jun 2026
 *   {{contact:contact_field:category-avg-views-last-quarter}}   112
 *   {{contact:contact_field:category-comparison-name}}          Plumbers
 *   {{contact:contact_field:category-comparison-count}}         23
 *   {{contact:contact_field:views-vs-category-percent}}         +64%
 *   {{contact:contact_field:views-performance-band}}            Above average
 *   {{contact:contact_field:listing-plan-tier}}                 Gold
 *
 * The percentage and the band are precomputed because merge tags cannot do
 * arithmetic or conditionals.
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * ADMIN SCREEN
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Standalone top-level menu: "Listing Views" (eye icon), sitting directly below
 * CRM Bridge and above Appearance. Five tabs —
 *
 *   Overview       health checks, schedule, and the last run
 *   Merge Tags     every published field, its tag, and whether it exists yet
 *   Results        per-listing figures for a quarter, filterable + CSV export
 *   Subcategories  the comparison pools and which are big enough to use
 *   Tools          manual rollup, maintenance, diagnostics, reset
 *
 * Styling follows the HBL admin tools design system (stat cards, numbered step
 * cards, badge pills, table cards, teal/orange buttons) shared with CRM Bridge
 * and Place ID Manager. Styles live in assets/css/admin.css and are enqueued on
 * this screen only — DEPLOY THE assets/ FOLDER ALONGSIDE THIS FILE.
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * DEVELOPER HOOKS
 * ──────────────────────────────────────────────────────────────────────────────
 *
 *   Filter: hbl_lvs_dedupe_window        ( int $seconds )            default 1800
 *   Filter: hbl_lvs_min_comparison_pool  ( int $listings )           default 5
 *   Filter: hbl_lvs_band_threshold       ( float $fraction )         default 0.10
 *   Filter: hbl_lvs_listing_post_statuses( array $statuses )         default ['publish']
 *   Filter: hbl_lvs_create_missing_contacts ( bool )                 default false
 *   Filter: hbl_lvs_log_activity         ( bool )                    default true
 *   Filter: hbl_lvs_retention_months     ( int $months )             default 24
 *   Filter: hbl_lvs_field_values         ( array $values, $row, $ctx )
 *   Action: hbl_lvs_rollup_complete      ( string $quarter, array $summary )
 *
 * @package HBL_ListingViewStats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ──────────────────────────────────────────────────────────────────

define( 'HBL_LVS_VERSION', '1.1.107' );

/** Schema version — bump to trigger dbDelta on the next load. */
define( 'HBL_LVS_DB_VERSION', 1 );

/** Daily maintenance cron: snapshot, prune, and start a rollup when one is due. */
define( 'HBL_LVS_CRON_DAILY', 'hbl_lvs_daily' );

/** Self-rescheduling worker that advances an in-progress rollup. */
define( 'HBL_LVS_CRON_TICK', 'hbl_lvs_tick' );

/** Option holding the state of the rollup currently in progress (autoload off). */
define( 'HBL_LVS_OPT_RUN', 'hbl_lvs_run' );

/** Option: quarter key of the most recently completed rollup, e.g. "2026Q2". */
define( 'HBL_LVS_OPT_LAST_ROLLUP', 'hbl_lvs_last_rollup' );

/** Option: quarter key of the most recent boundary snapshot. */
define( 'HBL_LVS_OPT_LAST_SNAPSHOT', 'hbl_lvs_last_snapshot' );

/** Option: GMT datetime the event logger first became active. */
define( 'HBL_LVS_OPT_LOGGER_START', 'hbl_lvs_logger_started' );

/** Option: installed schema version. */
define( 'HBL_LVS_OPT_DB_VERSION', 'hbl_lvs_db_version' );

/** Transient throttling the page-load fallback nudge to once per hour. */
define( 'HBL_LVS_FALLBACK_LOCK', 'hbl_lvs_fallback_ran' );

// ── Lifecycle ──────────────────────────────────────────────────────────────────

register_activation_hook( __FILE__, 'hbl_lvs_on_activate' );
register_deactivation_hook( __FILE__, 'hbl_lvs_on_deactivate' );

// Priority 30: after Directorist (10) and the CRM Bridge (20), whose contact
// helpers this plugin calls during the push phase.
add_action( 'plugins_loaded', 'hbl_lvs_boot', 30 );

/**
 * Wires up hooks once Directorist is known to be present.
 */
function hbl_lvs_boot(): void {

	if ( ! defined( 'ATBDP_POST_TYPE' ) ) {
		return;
	}

	// ① Record every view Directorist counts, with a timestamp.
	add_action( 'directorist_listing_views_count_updated', 'hbl_lvs_record_view', 10, 1 );

	// ①b Record views server-side as well, because Directorist's own tracking
	//     only fires when its single-listing template rendered the page (its JS
	//     looks for `.directorist-single-contents-area`). Sites whose listing
	//     pages are built with a page builder or a custom theme template never
	//     satisfy that, and record nothing at all. Both paths funnel into the
	//     same de-duplicated writer, so running them together cannot double-count.
	add_action( 'template_redirect', 'hbl_lvs_maybe_record_server_side_view' );

	// ② Daily maintenance: boundary snapshot, prune, start rollup when due.
	if ( ! wp_next_scheduled( HBL_LVS_CRON_DAILY ) ) {
		wp_schedule_event( time() + 300, 'daily', HBL_LVS_CRON_DAILY );
	}
	add_action( HBL_LVS_CRON_DAILY, 'hbl_lvs_run_daily' );

	// ③ Batched worker that advances an in-progress rollup.
	add_action( HBL_LVS_CRON_TICK, 'hbl_lvs_run_tick' );

	// ④ Page-load fallback (throttled to hourly) so a stalled wp-cron cannot
	//    strand a rollup half-finished.
	add_action( 'wp', 'hbl_lvs_maybe_fallback' );
	add_action( 'admin_init', 'hbl_lvs_maybe_fallback' );

	// ⑤ Late schema upgrades (e.g. plugin files replaced without reactivation).
	if ( (int) get_option( HBL_LVS_OPT_DB_VERSION ) !== HBL_LVS_DB_VERSION ) {
		hbl_lvs_install_tables();
	}

	// ⑥ Create the DoubleScale field definitions once, on the first admin load
	//    after install. Deferred to admin_init because DoubleScale Pro's models
	//    are not guaranteed to be loaded at activation time, and because writing
	//    to them must never happen on a front-end request.
	add_action( 'admin_init', 'hbl_lvs_maybe_ensure_custom_fields' );

	// ⑦ Admin screen.
	add_action( 'admin_menu', 'hbl_lvs_add_admin_page' );
	add_action( 'admin_enqueue_scripts', 'hbl_lvs_enqueue_admin_assets' );
	add_action( 'admin_post_hbl_lvs_admin_action', 'hbl_lvs_handle_admin_action' );
	add_action( 'admin_post_hbl_lvs_export', 'hbl_lvs_handle_export' );
}

// ══════════════════════════════════════════════════════════════════════════════
// INSTALL / SCHEMA
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Table name helper.
 *
 * @param string $which events|snapshots|stats
 * @return string Fully-prefixed table name.
 */
function hbl_lvs_table( string $which ): string {
	global $wpdb;
	return $wpdb->prefix . 'hbl_lvs_' . $which;
}

/**
 * Creates/updates the three tables.
 *
 * events    — one row per de-duplicated view, timestamped. The source of truth.
 * snapshots — cumulative Directorist counter captured at each quarter boundary,
 *             used only as a fallback and for reconciliation.
 * stats     — computed per-listing results for a given quarter. Persisting these
 *             (rather than holding them in an option) keeps the batched job
 *             restartable and leaves an auditable record of what was sent.
 */
function hbl_lvs_install_tables(): void {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();
	$events    = hbl_lvs_table( 'events' );
	$snapshots = hbl_lvs_table( 'snapshots' );
	$stats     = hbl_lvs_table( 'stats' );

	// dedupe_key is md5(listing|visitor|time-bucket); the UNIQUE index makes
	// de-duplication a single atomic INSERT IGNORE with no read-then-write race.
	dbDelta(
		"CREATE TABLE {$events} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			listing_id BIGINT(20) UNSIGNED NOT NULL,
			viewed_at DATETIME NOT NULL,
			dedupe_key CHAR(32) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY dedupe_key (dedupe_key),
			KEY listing_time (listing_id, viewed_at),
			KEY viewed_at (viewed_at)
		) {$charset};"
	);

	dbDelta(
		"CREATE TABLE {$snapshots} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			listing_id BIGINT(20) UNSIGNED NOT NULL,
			period_key VARCHAR(8) NOT NULL,
			cumulative BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			captured_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY listing_period (listing_id, period_key)
		) {$charset};"
	);

	dbDelta(
		"CREATE TABLE {$stats} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			period_key VARCHAR(8) NOT NULL,
			listing_id BIGINT(20) UNSIGNED NOT NULL,
			views BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			term_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			term_path VARCHAR(255) NOT NULL DEFAULT '',
			tier VARCHAR(16) NOT NULL DEFAULT '',
			tier_rank TINYINT(1) NOT NULL DEFAULT 1,
			contact_email VARCHAR(191) NOT NULL DEFAULT '',
			is_primary TINYINT(1) NOT NULL DEFAULT 0,
			pushed TINYINT(1) NOT NULL DEFAULT 0,
			computed_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY period_listing (period_key, listing_id),
			KEY period_email (period_key, contact_email),
			KEY period_push (period_key, is_primary, pushed)
		) {$charset};"
	);

	update_option( HBL_LVS_OPT_DB_VERSION, HBL_LVS_DB_VERSION, false );
}

/**
 * Activation: build tables, stamp the logger start time, take a baseline
 * snapshot, and schedule crons.
 *
 * The baseline snapshot matters — it is what makes a partial first period
 * measurable via the fallback path if the event log is somehow unusable.
 */
function hbl_lvs_on_activate(): void {
	hbl_lvs_install_tables();

	if ( ! get_option( HBL_LVS_OPT_LOGGER_START ) ) {
		update_option( HBL_LVS_OPT_LOGGER_START, gmdate( 'Y-m-d H:i:s' ), false );
	}

	if ( defined( 'ATBDP_POST_TYPE' ) ) {
		hbl_lvs_capture_snapshot( hbl_lvs_current_quarter_key() );
	}

	if ( ! wp_next_scheduled( HBL_LVS_CRON_DAILY ) ) {
		wp_schedule_event( time() + 300, 'daily', HBL_LVS_CRON_DAILY );
	}
}

/**
 * Deactivation: unschedule crons. Tables and collected view history are left
 * intact — that data cannot be recreated, so destroying it on deactivation
 * would be unrecoverable.
 */
function hbl_lvs_on_deactivate(): void {
	wp_clear_scheduled_hook( HBL_LVS_CRON_DAILY );
	wp_clear_scheduled_hook( HBL_LVS_CRON_TICK );
}

// ══════════════════════════════════════════════════════════════════════════════
// LOGGING
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Writes one timestamped view event.
 *
 * Hooked to Directorist's `directorist_listing_views_count_updated`, which fires
 * only after Directorist has decided a view counts — so its own settings
 * (notably "count logged-in users") are respected automatically.
 *
 * Privacy: the visitor is identified by a salted one-way hash of IP + user
 * agent. No raw IP or user agent is persisted.
 *
 * @param int $listing_id
 */
function hbl_lvs_record_view( $listing_id ): void {
	global $wpdb;

	$listing_id = absint( $listing_id );
	if ( ! $listing_id ) {
		return;
	}

	$window = (int) apply_filters( 'hbl_lvs_dedupe_window', 1800 );
	$window = $window > 0 ? $window : 1800;

	$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

	$visitor = md5( $ip . '|' . $agent . '|' . wp_salt( 'nonce' ) );
	$bucket  = (int) floor( time() / $window );
	$dedupe  = md5( $listing_id . '|' . $visitor . '|' . $bucket );

	// INSERT IGNORE: a duplicate inside the window hits the UNIQUE index and is
	// silently discarded.
	$table = hbl_lvs_table( 'events' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"INSERT IGNORE INTO {$table} (listing_id, viewed_at, dedupe_key) VALUES (%d, %s, %s)",
			$listing_id,
			gmdate( 'Y-m-d H:i:s' ),
			$dedupe
		)
	);
}

/**
 * Records a view server-side on any single listing page.
 *
 * WHY THIS EXISTS
 * Directorist counts a view from JavaScript that only runs when the page
 * contains `.directorist-single-contents-area` — an element emitted by its own
 * single-listing template. Where listing pages are rendered by a page builder
 * or a custom theme template instead, neither that element nor the tracking
 * script is present, so Directorist records nothing, forever, silently. Relying
 * on it alone means quarterly stats of zero.
 *
 * This path runs on the server, so it works regardless of how the page is
 * rendered or whether the visitor executes JavaScript.
 *
 * NOT DOUBLE COUNTING: both this and the Directorist hook call
 * hbl_lvs_record_view(), which derives the same de-duplication key from
 * listing + visitor + time bucket. Whichever fires second hits the UNIQUE index
 * and is discarded, so the two are safe to run together.
 *
 * TRADE-OFF: JavaScript tracking filtered out crawlers for free. Server-side
 * tracking sees them, so a user-agent filter does that job instead — imperfect
 * by nature, but far better than counting nothing.
 */
function hbl_lvs_maybe_record_server_side_view(): void {
	if ( ! hbl_lvs_server_tracking_enabled() ) {
		return;
	}

	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}

	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}

	// Only real page reads: no feeds, previews, robots.txt, or non-GET requests.
	if ( is_feed() || is_preview() || is_robots() ) {
		return;
	}

	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
	if ( 'GET' !== $method ) {
		return;
	}

	if ( ! is_singular( ATBDP_POST_TYPE ) ) {
		return;
	}

	if ( hbl_lvs_is_bot() ) {
		return;
	}

	// Mirror Directorist's own "count logged-in users" setting so both sources
	// agree on what counts as a view.
	if ( is_user_logged_in() && function_exists( 'get_directorist_option' ) && ! get_directorist_option( 'count_loggedin_user' ) ) {
		return;
	}

	$listing_id = get_queried_object_id();
	if ( $listing_id ) {
		hbl_lvs_record_view( $listing_id );
	}
}

/**
 * Is server-side view tracking switched on?
 *
 * Stored as an option so it is toggleable from the admin screen, and
 * filterable for code-level control. Defaults to on: the JS path cannot be
 * relied upon, and a silent zero is the worst outcome here.
 *
 * @return bool
 */
function hbl_lvs_server_tracking_enabled(): bool {
	$enabled = get_option( 'hbl_lvs_server_tracking', '1' );

	return (bool) apply_filters( 'hbl_lvs_server_side_tracking', '1' === (string) $enabled );
}

/**
 * Crude user-agent bot check.
 *
 * Deliberately errs toward exclusion: under-counting a real visitor skews a
 * listing's number slightly, whereas counting a crawler sweep can multiply it,
 * and these figures get emailed to business owners as fact.
 *
 * @return bool
 */
function hbl_lvs_is_bot(): bool {
	$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

	// No user agent at all is almost never a browser.
	if ( '' === trim( $agent ) ) {
		return true;
	}

	$pattern = apply_filters(
		'hbl_lvs_bot_pattern',
		'/(bot|crawl|spider|slurp|scrape|search|fetch|monitor|uptime|ping|check|validator|'
		. 'curl|wget|python|java\/|ruby|perl|go-http|libwww|okhttp|httpclient|axios|guzzle|'
		. 'headless|phantom|puppeteer|playwright|lighthouse|gtmetrix|pagespeed|pingdom|'
		. 'facebookexternalhit|whatsapp|telegram|slackbot|discord|embedly|preview|'
		. 'semrush|ahrefs|mj12|dotbot|petal|bytespider|yandex|baidu|sogou|applebot|duckduck)/i'
	);

	return (bool) preg_match( $pattern, $agent );
}

// ══════════════════════════════════════════════════════════════════════════════
// CALENDAR QUARTER HELPERS
// ══════════════════════════════════════════════════════════════════════════════
//
// Quarters are evaluated in the SITE timezone (that is what "calendar quarter"
// means to the business), while event rows are stored in GMT. Bounds are
// therefore built in site time and converted to GMT for querying.

/**
 * @param int|null $timestamp Unix timestamp, defaults to now.
 * @return string Quarter key, e.g. "2026Q3".
 */
function hbl_lvs_current_quarter_key( ?int $timestamp = null ): string {
	$tz   = wp_timezone();
	$date = new DateTimeImmutable( '@' . ( $timestamp ?? time() ) );
	$date = $date->setTimezone( $tz );

	$quarter = (int) ceil( (int) $date->format( 'n' ) / 3 );

	return $date->format( 'Y' ) . 'Q' . $quarter;
}

/**
 * @param string $key Quarter key, e.g. "2026Q3".
 * @return string The quarter immediately before it, e.g. "2026Q2".
 */
function hbl_lvs_previous_quarter_key( string $key ): string {
	list( $year, $quarter ) = hbl_lvs_parse_quarter_key( $key );

	--$quarter;
	if ( $quarter < 1 ) {
		$quarter = 4;
		--$year;
	}

	return $year . 'Q' . $quarter;
}

/**
 * @param string $key Quarter key.
 * @return string The quarter immediately after it.
 */
function hbl_lvs_next_quarter_key( string $key ): string {
	list( $year, $quarter ) = hbl_lvs_parse_quarter_key( $key );

	++$quarter;
	if ( $quarter > 4 ) {
		$quarter = 1;
		++$year;
	}

	return $year . 'Q' . $quarter;
}

/**
 * @param string $key Quarter key.
 * @return array{0:int,1:int} [ year, quarter 1-4 ]
 */
function hbl_lvs_parse_quarter_key( string $key ): array {
	$parts   = explode( 'Q', strtoupper( $key ) );
	$year    = isset( $parts[0] ) ? (int) $parts[0] : (int) gmdate( 'Y' );
	$quarter = isset( $parts[1] ) ? (int) $parts[1] : 1;

	$quarter = max( 1, min( 4, $quarter ) );

	return [ $year, $quarter ];
}

/**
 * Quarter boundaries as GMT datetime strings, half-open: [start, end).
 *
 * @param string $key Quarter key.
 * @return array{start:string, end:string, start_ts:int, end_ts:int}
 */
function hbl_lvs_quarter_bounds_gmt( string $key ): array {
	list( $year, $quarter ) = hbl_lvs_parse_quarter_key( $key );

	$tz          = wp_timezone();
	$start_month = ( ( $quarter - 1 ) * 3 ) + 1;

	$start = new DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $year, $start_month ), $tz );
	$end   = $start->modify( '+3 months' );

	$gmt = new DateTimeZone( 'UTC' );

	return [
		'start'    => $start->setTimezone( $gmt )->format( 'Y-m-d H:i:s' ),
		'end'      => $end->setTimezone( $gmt )->format( 'Y-m-d H:i:s' ),
		'start_ts' => $start->getTimestamp(),
		'end_ts'   => $end->getTimestamp(),
	];
}

/**
 * Human label for the period actually measured.
 *
 * Returns a normal quarter label ("Apr–Jun 2026") when the event logger was
 * running for the whole quarter, and an explicit partial-range label
 * ("1 Aug – 30 Sep 2026") when it was not. Never presents a partial period as a
 * complete quarter — owners compare these numbers year on year.
 *
 * @param string $key  Quarter key.
 * @param bool   $live When true (default) and the quarter is still in progress,
 *                     the end of the range is truncated to today — appropriate
 *                     for "here is what's been measured so far". Callers
 *                     describing a report that has not run yet (e.g. "next
 *                     report covers") should pass false to see the period's
 *                     true, final range instead of a range that grows daily.
 * @return string
 */
function hbl_lvs_period_label( string $key, bool $live = true ): string {
	$bounds = hbl_lvs_quarter_bounds_gmt( $key );
	$tz     = wp_timezone();

	$start = ( new DateTimeImmutable( '@' . $bounds['start_ts'] ) )->setTimezone( $tz );

	// A quarter still in progress is only measured up to now, not to its calendar
	// end — otherwise a preview run would label today's numbers with a future date.
	$last_ts = $live ? min( $bounds['end_ts'] - 1, time() ) : $bounds['end_ts'] - 1;
	$last    = ( new DateTimeImmutable( '@' . $last_ts ) )->setTimezone( $tz );

	if ( hbl_lvs_period_is_complete( $key ) ) {
		return $start->format( 'M' ) . '–' . $last->format( 'M Y' );
	}

	$effective = hbl_lvs_effective_start_ts( $key );
	$eff       = ( new DateTimeImmutable( '@' . $effective ) )->setTimezone( $tz );

	return $eff->format( 'j M' ) . ' – ' . $last->format( 'j M Y' );
}

/**
 * Is the quarter both fully elapsed AND fully covered by the event logger?
 *
 * Both halves matter: a quarter still running is not complete no matter how good
 * the logging is, and a fully-elapsed quarter is still partial if logging only
 * began part-way through it.
 *
 * @param string $key Quarter key.
 * @return bool
 */
function hbl_lvs_period_is_complete( string $key ): bool {
	$started = get_option( HBL_LVS_OPT_LOGGER_START );
	if ( ! $started ) {
		return false;
	}

	if ( ! hbl_lvs_quarter_has_ended( $key ) ) {
		return false;
	}

	$bounds = hbl_lvs_quarter_bounds_gmt( $key );

	return strtotime( $started . ' UTC' ) <= $bounds['start_ts'];
}

/**
 * Has the quarter finished?
 *
 * @param string $key Quarter key.
 * @return bool
 */
function hbl_lvs_quarter_has_ended( string $key ): bool {
	$bounds = hbl_lvs_quarter_bounds_gmt( $key );

	return time() >= $bounds['end_ts'];
}

/**
 * The later of (quarter start, logger start) — i.e. the first instant for which
 * data actually exists.
 *
 * @param string $key Quarter key.
 * @return int Unix timestamp.
 */
function hbl_lvs_effective_start_ts( string $key ): int {
	$bounds  = hbl_lvs_quarter_bounds_gmt( $key );
	$started = get_option( HBL_LVS_OPT_LOGGER_START );

	if ( ! $started ) {
		return $bounds['start_ts'];
	}

	$effective = max( $bounds['start_ts'], (int) strtotime( $started . ' UTC' ) );

	// Clamp inside the quarter itself. A logger that only started after this
	// quarter had already finished has no data for it at all — callers must
	// never be handed a "start" that lands after the quarter's own end.
	return min( $effective, $bounds['end_ts'] - 1 );
}

/**
 * The quarter that the next rollup should cover.
 *
 * Ordinarily this is simply the previous calendar quarter. But if the event
 * logger did not exist for any part of that quarter (activation happened
 * after it had already ended), there is no data for it and never will be —
 * advance forward to the first quarter that ends after logging started, the
 * earliest quarter that can ever produce a real number.
 *
 * @return string Quarter key.
 */
function hbl_lvs_next_rollup_target(): string {
	$target  = hbl_lvs_previous_quarter_key( hbl_lvs_current_quarter_key() );
	$started = get_option( HBL_LVS_OPT_LOGGER_START );

	if ( ! $started ) {
		return $target;
	}

	$started_ts = (int) strtotime( $started . ' UTC' );

	while ( hbl_lvs_quarter_bounds_gmt( $target )['end_ts'] <= $started_ts ) {
		$target = hbl_lvs_next_quarter_key( $target );
	}

	return $target;
}

// ══════════════════════════════════════════════════════════════════════════════
// BOUNDARY SNAPSHOTS (fallback / reconciliation)
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Captures the cumulative Directorist counter for every listing, tagged with the
 * quarter that is OPENING. A snapshot keyed "2026Q4" is therefore the cumulative
 * total as at the end of 2026Q3, and
 *
 *     views(2026Q3) = snapshot(2026Q4) − snapshot(2026Q3)
 *
 * Done as a single INSERT…SELECT so cost is one query regardless of catalogue
 * size. INSERT IGNORE makes re-running it within the same quarter a no-op, which
 * is what preserves the boundary value rather than overwriting it with a later
 * one.
 *
 * @param string $period_key Quarter being opened.
 * @return int Rows captured.
 */
function hbl_lvs_capture_snapshot( string $period_key ): int {
	global $wpdb;

	$snapshots = hbl_lvs_table( 'snapshots' );
	$statuses  = hbl_lvs_listing_statuses();
	$in        = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

	$params = array_merge(
		[ $period_key, gmdate( 'Y-m-d H:i:s' ), '_atbdp_post_views_count', ATBDP_POST_TYPE ],
		$statuses
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"INSERT IGNORE INTO {$snapshots} (listing_id, period_key, cumulative, captured_at)
			 SELECT p.ID, %s, COALESCE(CAST(pm.meta_value AS UNSIGNED), 0), %s
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
			 WHERE p.post_type = %s AND p.post_status IN ({$in})",
			$params
		)
	);

	update_option( HBL_LVS_OPT_LAST_SNAPSHOT, $period_key, false );

	return (int) $rows;
}

/**
 * Post statuses that count as a live listing.
 *
 * Note that HBL's expiry plugin deliberately returns expired Silver/Gold
 * listings to `publish`, so 'publish' alone covers them.
 *
 * @return array<int,string>
 */
function hbl_lvs_listing_statuses(): array {
	$statuses = apply_filters( 'hbl_lvs_listing_post_statuses', [ 'publish' ] );
	$statuses = array_values( array_filter( array_map( 'strval', (array) $statuses ) ) );

	// Never return empty — the value is interpolated into an SQL IN() list, and
	// "IN ()" is a syntax error rather than a harmless empty result.
	return $statuses ? $statuses : [ 'publish' ];
}

// ══════════════════════════════════════════════════════════════════════════════
// SCHEDULING
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Daily maintenance.
 */
function hbl_lvs_run_daily(): void {
	// ① Capture the boundary snapshot once per quarter.
	$current = hbl_lvs_current_quarter_key();
	if ( get_option( HBL_LVS_OPT_LAST_SNAPSHOT ) !== $current ) {
		hbl_lvs_capture_snapshot( $current );
	}

	// ② Start the rollup for the previous quarter if it has not run yet.
	hbl_lvs_maybe_start_rollup();

	// ③ Trim event history.
	hbl_lvs_prune_events();
}

/**
 * Starts a rollup for the next reportable quarter when one is due.
 *
 * Idempotent: safe to call on every daily cron and every fallback nudge.
 *
 * @return bool True if a run was started.
 */
function hbl_lvs_maybe_start_rollup(): bool {
	$target = hbl_lvs_next_rollup_target();

	if ( ! hbl_lvs_quarter_has_ended( $target ) ) {
		return false; // The next reportable quarter hasn't finished yet.
	}

	if ( get_option( HBL_LVS_OPT_LAST_ROLLUP ) === $target ) {
		return false; // Already done.
	}

	$run = get_option( HBL_LVS_OPT_RUN );
	if ( is_array( $run ) && ! empty( $run['quarter'] ) ) {
		return false; // Already in progress.
	}

	hbl_lvs_start_rollup( $target );

	return true;
}

/**
 * Initialises run state and kicks off the worker.
 *
 * @param string $quarter Quarter key to roll up.
 */
function hbl_lvs_start_rollup( string $quarter ): void {
	global $wpdb;

	// Clear any previous results for this quarter so a re-run is a clean rebuild
	// rather than a merge with stale rows.
	$stats = hbl_lvs_table( 'stats' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$stats} WHERE period_key = %s", $quarter ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	update_option(
		HBL_LVS_OPT_RUN,
		[
			'quarter'    => $quarter,
			'phase'      => 'collect',
			'offset'     => 0,
			'source'     => hbl_lvs_choose_source( $quarter ),
			'started_at' => gmdate( 'Y-m-d H:i:s' ),
			'collected'  => 0,
			'pushed'     => 0,
			'skipped'    => 0,
		],
		false
	);

	hbl_lvs_schedule_tick( 5 );
}

/**
 * Decides whether the quarter is measured from the event log or from snapshot
 * deltas.
 *
 * The event log is always preferred. Snapshots are used only when the log holds
 * nothing at all for the period — which means the logger was down — and both
 * boundary snapshots exist.
 *
 * @param string $quarter Quarter key.
 * @return string 'events'|'snapshots'
 */
function hbl_lvs_choose_source( string $quarter ): string {
	global $wpdb;

	$bounds = hbl_lvs_quarter_bounds_gmt( $quarter );
	$events = hbl_lvs_table( 'events' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$count = (int) $wpdb->get_var(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM {$events} WHERE viewed_at >= %s AND viewed_at < %s",
			$bounds['start'],
			$bounds['end']
		)
	);

	if ( $count > 0 ) {
		return 'events';
	}

	$snapshots = hbl_lvs_table( 'snapshots' );
	$next      = hbl_lvs_next_quarter_key( $quarter );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$have = (int) $wpdb->get_var(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(DISTINCT period_key) FROM {$snapshots} WHERE period_key IN (%s, %s)",
			$quarter,
			$next
		)
	);

	return ( 2 === $have ) ? 'snapshots' : 'events';
}

/**
 * Schedules the next worker tick.
 *
 * @param int $delay Seconds from now.
 */
function hbl_lvs_schedule_tick( int $delay = 60 ): void {
	if ( ! wp_next_scheduled( HBL_LVS_CRON_TICK ) ) {
		wp_schedule_single_event( time() + max( 1, $delay ), HBL_LVS_CRON_TICK );
	}
}

/**
 * Hourly page-load fallback. Guarantees progress even when wp-cron is disabled
 * or stalled — a quarterly job that silently never runs is the main failure mode
 * worth engineering against here.
 */
function hbl_lvs_maybe_fallback(): void {
	if ( wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}

	if ( get_transient( HBL_LVS_FALLBACK_LOCK ) ) {
		return;
	}
	set_transient( HBL_LVS_FALLBACK_LOCK, 1, HOUR_IN_SECONDS );

	$current = hbl_lvs_current_quarter_key();
	if ( get_option( HBL_LVS_OPT_LAST_SNAPSHOT ) !== $current ) {
		hbl_lvs_capture_snapshot( $current );
	}

	if ( ! hbl_lvs_maybe_start_rollup() ) {
		$run = get_option( HBL_LVS_OPT_RUN );
		if ( is_array( $run ) && ! empty( $run['quarter'] ) ) {
			hbl_lvs_schedule_tick( 5 );
		}
	}
}

// ══════════════════════════════════════════════════════════════════════════════
// ROLLUP WORKER
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Advances the current rollup for a bounded slice of time, then reschedules
 * itself until the run completes.
 */
function hbl_lvs_run_tick(): void {
	$run = get_option( HBL_LVS_OPT_RUN );
	if ( ! is_array( $run ) || empty( $run['quarter'] ) ) {
		return;
	}

	$budget   = (int) apply_filters( 'hbl_lvs_time_budget', 20 );
	$deadline = microtime( true ) + max( 5, $budget );

	while ( microtime( true ) < $deadline ) {
		$run = get_option( HBL_LVS_OPT_RUN );
		if ( ! is_array( $run ) || empty( $run['quarter'] ) ) {
			return; // Finished.
		}

		switch ( $run['phase'] ) {
			case 'collect':
				hbl_lvs_phase_collect( $run );
				break;

			case 'aggregate':
				hbl_lvs_phase_aggregate( $run );
				break;

			case 'push':
				hbl_lvs_phase_push( $run );
				break;

			default:
				hbl_lvs_finish_rollup( $run );
				return;
		}
	}

	// More work remains — come back shortly.
	$run = get_option( HBL_LVS_OPT_RUN );
	if ( is_array( $run ) && ! empty( $run['quarter'] ) ) {
		hbl_lvs_schedule_tick( 60 );
	}
}

/**
 * COLLECT — one batch of listings: quarter views, subcategory, tier, owner email.
 *
 * @param array $run Run state (by value; persisted at the end).
 */
function hbl_lvs_phase_collect( array $run ): void {
	global $wpdb;

	$quarter  = (string) $run['quarter'];
	$offset   = (int) $run['offset'];
	$batch    = (int) apply_filters( 'hbl_lvs_collect_batch', 100 );
	$statuses = hbl_lvs_listing_statuses();

	$in     = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	$params = array_merge( [ ATBDP_POST_TYPE ], $statuses, [ $batch, $offset ] );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$listing_ids = $wpdb->get_col(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = %s AND post_status IN ({$in})
			 ORDER BY ID ASC
			 LIMIT %d OFFSET %d",
			$params
		)
	);

	if ( empty( $listing_ids ) ) {
		$run['phase']  = 'aggregate';
		$run['offset'] = 0;
		update_option( HBL_LVS_OPT_RUN, $run, false );
		return;
	}

	$listing_ids = array_map( 'absint', $listing_ids );
	$views_map   = ( 'snapshots' === $run['source'] )
		? hbl_lvs_views_from_snapshots( $quarter, $listing_ids )
		: hbl_lvs_views_from_events( $quarter, $listing_ids );

	$stats = hbl_lvs_table( 'stats' );
	$now   = gmdate( 'Y-m-d H:i:s' );

	foreach ( $listing_ids as $listing_id ) {
		$path     = hbl_lvs_listing_term_path( $listing_id );
		$term_id  = $path ? (int) end( $path ) : 0;
		$tier     = hbl_lvs_listing_tier( $listing_id );
		$resolved = function_exists( 'hbl_crm_bridge_resolve_listing_contact' )
			? hbl_crm_bridge_resolve_listing_contact( $listing_id )
			: null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->replace(
			$stats,
			[
				'period_key'    => $quarter,
				'listing_id'    => $listing_id,
				'views'         => isset( $views_map[ $listing_id ] ) ? (int) $views_map[ $listing_id ] : 0,
				'term_id'       => $term_id,
				'term_path'     => implode( ',', $path ),
				'tier'          => $tier,
				'tier_rank'     => hbl_lvs_tier_rank( $tier ),
				'contact_email' => $resolved && ! empty( $resolved['email'] ) ? $resolved['email'] : '',
				'is_primary'    => 0,
				'pushed'        => 0,
				'computed_at'   => $now,
			],
			[ '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%s' ]
		);
	}

	$run['offset']    = $offset + count( $listing_ids );
	$run['collected'] = (int) $run['collected'] + count( $listing_ids );
	update_option( HBL_LVS_OPT_RUN, $run, false );
}

/**
 * Per-listing view totals for a quarter, from the timestamped event log.
 *
 * @param string           $quarter     Quarter key.
 * @param array<int,int>   $listing_ids Listing IDs.
 * @return array<int,int>  listing_id => views
 */
function hbl_lvs_views_from_events( string $quarter, array $listing_ids ): array {
	global $wpdb;

	if ( empty( $listing_ids ) ) {
		return [];
	}

	$bounds = hbl_lvs_quarter_bounds_gmt( $quarter );
	$events = hbl_lvs_table( 'events' );
	$ids    = implode( ',', array_map( 'absint', $listing_ids ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT listing_id, COUNT(*) AS views FROM {$events}
			 WHERE viewed_at >= %s AND viewed_at < %s AND listing_id IN ({$ids})
			 GROUP BY listing_id",
			$bounds['start'],
			$bounds['end']
		),
		ARRAY_A
	);

	$map = [];
	foreach ( (array) $rows as $row ) {
		$map[ (int) $row['listing_id'] ] = (int) $row['views'];
	}

	return $map;
}

/**
 * Per-listing view totals derived from the difference between the two boundary
 * snapshots. Fallback path only.
 *
 * Negative deltas are clamped to zero: the underlying Directorist counter is
 * editable in wp-admin and settable by CSV import, so it can legitimately move
 * backwards between snapshots. A negative "view count" in an email would be
 * worse than an understated one.
 *
 * @param string         $quarter     Quarter key.
 * @param array<int,int> $listing_ids Listing IDs.
 * @return array<int,int>
 */
function hbl_lvs_views_from_snapshots( string $quarter, array $listing_ids ): array {
	global $wpdb;

	if ( empty( $listing_ids ) ) {
		return [];
	}

	$snapshots = hbl_lvs_table( 'snapshots' );
	$next      = hbl_lvs_next_quarter_key( $quarter );
	$ids       = implode( ',', array_map( 'absint', $listing_ids ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT a.listing_id, GREATEST(CAST(b.cumulative AS SIGNED) - CAST(a.cumulative AS SIGNED), 0) AS views
			 FROM {$snapshots} a
			 INNER JOIN {$snapshots} b ON b.listing_id = a.listing_id AND b.period_key = %s
			 WHERE a.period_key = %s AND a.listing_id IN ({$ids})",
			$next,
			$quarter
		),
		ARRAY_A
	);

	$map = [];
	foreach ( (array) $rows as $row ) {
		$map[ (int) $row['listing_id'] ] = max( 0, (int) $row['views'] );
	}

	return $map;
}

/**
 * The listing's category ancestry, root first, deepest term last.
 *
 * Directorist categories are a hierarchical taxonomy and a listing may carry
 * several terms, or only a parent. The deepest assigned term is treated as the
 * listing's subcategory; ties are broken on the lowest term ID so the choice is
 * stable across runs. The full path is stored so the comparison can climb to a
 * parent when a subcategory is too small to average meaningfully.
 *
 * @param int $listing_id
 * @return array<int,int> Term IDs, root → leaf. Empty when uncategorised.
 */
function hbl_lvs_listing_term_path( int $listing_id ): array {
	$terms = wp_get_object_terms( $listing_id, ATBDP_CATEGORY, [ 'fields' => 'ids' ] );

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return [];
	}

	$best       = 0;
	$best_depth = -1;
	$best_path  = [];

	foreach ( $terms as $term_id ) {
		$term_id   = (int) $term_id;
		$ancestors = get_ancestors( $term_id, ATBDP_CATEGORY, 'taxonomy' );
		$depth     = count( $ancestors );

		if ( $depth > $best_depth || ( $depth === $best_depth && $term_id < $best ) ) {
			$best       = $term_id;
			$best_depth = $depth;
			$best_path  = array_merge( array_reverse( array_map( 'intval', $ancestors ) ), [ $term_id ] );
		}
	}

	return $best_path;
}

/**
 * The listing's plan tier, lower-cased.
 *
 * Defers to the theme's hbl_get_plan_tier() so tier logic lives in exactly one
 * place (it already handles both Directorist Pricing Plans v4's custom table and
 * legacy installs via HBL_Pricing_Plans).
 *
 * @param int $listing_id
 * @return string bronze|silver|gold
 */
function hbl_lvs_listing_tier( int $listing_id ): string {
	// Resolve the listing's plan via HBL_Pricing_Plans — on Directorist Pricing
	// Plans v4 the assignment is in the packages table, not the legacy _fm_plans
	// meta. Reading _fm_plans directly was returning the wrong plan (hence every
	// listing resolving to the same tier).
	if ( class_exists( 'HBL_Pricing_Plans' ) && method_exists( 'HBL_Pricing_Plans', 'get_listing_plan_id' ) ) {
		$plan_id = (int) \HBL_Pricing_Plans::get_listing_plan_id( $listing_id );
	} else {
		$plan_id = (int) get_post_meta( $listing_id, '_fm_plans', true );
	}

	if ( function_exists( 'hbl_get_plan_tier' ) ) {
		$tier = (string) hbl_get_plan_tier( $plan_id );
		return $tier ? strtolower( $tier ) : 'bronze';
	}

	return 'bronze';
}

/**
 * @param string $tier
 * @return int 3 = gold, 2 = silver, 1 = bronze.
 */
function hbl_lvs_tier_rank( string $tier ): int {
	switch ( strtolower( $tier ) ) {
		case 'gold':
			return 3;
		case 'silver':
			return 2;
		default:
			return 1;
	}
}

/**
 * AGGREGATE — subcategory averages, then choose one primary listing per contact.
 *
 * Averages are accumulated against every term in each listing's ancestry, not
 * just its leaf. That makes a parent-category average automatically include all
 * of its descendants' listings, which is what the fallback needs when a
 * subcategory is too small to compare against.
 *
 * @param array $run Run state.
 */
function hbl_lvs_phase_aggregate( array $run ): void {
	global $wpdb;

	$quarter = (string) $run['quarter'];
	$stats   = hbl_lvs_table( 'stats' );

	// ── Averages ──────────────────────────────────────────────────────────────
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare( "SELECT views, term_path FROM {$stats} WHERE period_key = %s", $quarter ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ARRAY_A
	);

	$totals = [ 0 => [ 'n' => 0, 'sum' => 0 ] ]; // Term 0 = site-wide pool.

	foreach ( (array) $rows as $row ) {
		$views = (int) $row['views'];

		$totals[0]['n']   += 1;
		$totals[0]['sum'] += $views;

		if ( '' === $row['term_path'] ) {
			continue;
		}

		foreach ( explode( ',', $row['term_path'] ) as $term_id ) {
			$term_id = (int) $term_id;
			if ( ! $term_id ) {
				continue;
			}
			if ( ! isset( $totals[ $term_id ] ) ) {
				$totals[ $term_id ] = [ 'n' => 0, 'sum' => 0 ];
			}
			$totals[ $term_id ]['n']   += 1;
			$totals[ $term_id ]['sum'] += $views;
		}
	}

	$aggregates = [];
	foreach ( $totals as $term_id => $totals_row ) {
		$aggregates[ $term_id ] = [
			'n'   => (int) $totals_row['n'],
			'avg' => $totals_row['n'] > 0 ? ( $totals_row['sum'] / $totals_row['n'] ) : 0.0,
		];
	}

	update_option( hbl_lvs_aggregate_option( $quarter ), $aggregates, false );

	// ── Primary listing per contact ───────────────────────────────────────────
	// Custom fields are single-valued per contact, so an owner with several
	// listings would otherwise get whichever listing was written last. Pick
	// deterministically: highest tier, then most views, then lowest listing ID.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$candidates = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT id, contact_email FROM {$stats}
			 WHERE period_key = %s AND contact_email <> ''
			 ORDER BY contact_email ASC, tier_rank DESC, views DESC, listing_id ASC",
			$quarter
		),
		ARRAY_A
	);

	$primary_ids = [];
	$seen        = [];

	foreach ( (array) $candidates as $row ) {
		$email = strtolower( $row['contact_email'] );
		if ( isset( $seen[ $email ] ) ) {
			continue;
		}
		$seen[ $email ]  = true;
		$primary_ids[]   = (int) $row['id'];
	}

	foreach ( array_chunk( $primary_ids, 500 ) as $chunk ) {
		$ids = implode( ',', array_map( 'absint', $chunk ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "UPDATE {$stats} SET is_primary = 1 WHERE id IN ({$ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	// Listings that lost the primary tie-break are not failures — they are simply
	// not the listing this contact hears about. Tracked separately so the run
	// summary's "skipped" stays meaningful.
	$run['phase']       = 'push';
	$run['offset']      = 0;
	$run['non_primary'] = max( 0, count( (array) $rows ) - count( $primary_ids ) );
	update_option( HBL_LVS_OPT_RUN, $run, false );
}

/**
 * @param string $quarter Quarter key.
 * @return string Option name holding that quarter's subcategory aggregates.
 */
function hbl_lvs_aggregate_option( string $quarter ): string {
	return 'hbl_lvs_agg_' . strtolower( $quarter );
}

/**
 * PUSH — write the custom fields onto each primary contact, one batch per call.
 *
 * @param array $run Run state.
 */
function hbl_lvs_phase_push( array $run ): void {
	global $wpdb;

	$quarter = (string) $run['quarter'];
	$stats   = hbl_lvs_table( 'stats' );
	$batch   = (int) apply_filters( 'hbl_lvs_push_batch', 20 );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$stats}
			 WHERE period_key = %s AND is_primary = 1 AND pushed = 0
			 ORDER BY id ASC LIMIT %d",
			$quarter,
			$batch
		),
		ARRAY_A
	);

	if ( empty( $rows ) ) {
		hbl_lvs_finish_rollup( $run );
		return;
	}

	$aggregates = get_option( hbl_lvs_aggregate_option( $quarter ), [] );
	$pushed     = 0;
	$skipped    = 0;

	foreach ( $rows as $row ) {
		$ok = hbl_lvs_push_row( $row, is_array( $aggregates ) ? $aggregates : [], $quarter );

		if ( $ok ) {
			++$pushed;
		} else {
			++$skipped;
		}

		// Mark as handled either way — a contact that cannot be resolved will not
		// become resolvable on a retry within the same run, and leaving the row
		// unpushed would loop the phase forever.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $stats, [ 'pushed' => $ok ? 1 : 2 ], [ 'id' => (int) $row['id'] ], [ '%d' ], [ '%d' ] );
	}

	$run['pushed']  = (int) $run['pushed'] + $pushed;
	$run['skipped'] = (int) $run['skipped'] + $skipped;
	update_option( HBL_LVS_OPT_RUN, $run, false );
}

/**
 * Computes and writes the field set for one listing/contact.
 *
 * @param array  $row        Row from the stats table.
 * @param array  $aggregates term_id => ['n'=>int,'avg'=>float]
 * @param string $quarter    Quarter key.
 * @return bool True when the values reached a contact.
 */
function hbl_lvs_push_row( array $row, array $aggregates, string $quarter ): bool {
	$email = (string) $row['contact_email'];
	if ( ! $email || ! is_email( $email ) ) {
		return false;
	}

	$contact = hbl_lvs_get_contact( $email, (int) $row['listing_id'] );
	if ( ! $contact ) {
		return false;
	}

	if ( ! function_exists( 'hbl_crm_bridge_set_custom_field' ) ) {
		return false;
	}

	$values = hbl_lvs_build_field_values( $row, $aggregates, $quarter );

	foreach ( $values as $slug => $value ) {
		hbl_crm_bridge_set_custom_field( $contact, (string) $slug, (string) $value );
	}

	// Timeline note, so the quarterly update is visible on the contact record and
	// not only as field values.
	if ( apply_filters( 'hbl_lvs_log_activity', true ) && function_exists( 'hbl_crm_bridge_log_activity' ) ) {
		hbl_crm_bridge_log_activity(
			$contact,
			__( 'Quarterly listing views updated', 'hbl-lvs' ),
			sprintf(
				/* translators: 1: views, 2: period, 3: category average, 4: pool size, 5: category name */
				__( '%1$s views in %2$s. Subcategory average %3$s across %4$s listings in "%5$s".', 'hbl-lvs' ),
				$values['listing-views-last-quarter'],
				$values['views-quarter-label'],
				$values['category-avg-views-last-quarter'],
				$values['category-comparison-count'],
				$values['category-comparison-name']
			)
		);
	}

	return true;
}

/**
 * Builds the exact field set that would be written for a stats row.
 *
 * Kept separate from the writing so the admin screens and the CSV export can
 * display precisely what was (or would be) sent, rather than re-deriving it and
 * drifting out of step with the real thing.
 *
 * @param array  $row        Row from the stats table.
 * @param array  $aggregates term_id => ['n'=>int,'avg'=>float]
 * @param string $quarter    Quarter key.
 * @return array<string,string> slug => value
 */
function hbl_lvs_build_field_values( array $row, array $aggregates, string $quarter ): array {
	$views      = (int) $row['views'];
	$comparison = hbl_lvs_choose_comparison( (string) $row['term_path'], $aggregates );
	$avg        = (float) $comparison['avg'];

	// Percentage and band are precomputed: merge tags cannot do arithmetic or
	// conditionals, so anything the email needs to say must already be a string.
	if ( $avg > 0 ) {
		$delta   = ( ( $views - $avg ) / $avg );
		$percent = sprintf( '%+d%%', (int) round( $delta * 100 ) );

		$threshold = (float) apply_filters( 'hbl_lvs_band_threshold', 0.10 );
		if ( $delta >= $threshold ) {
			$band = __( 'Above average', 'hbl-lvs' );
		} elseif ( $delta <= -$threshold ) {
			$band = __( 'Below average', 'hbl-lvs' );
		} else {
			$band = __( 'Average', 'hbl-lvs' );
		}
	} else {
		$percent = '';
		$band    = __( 'No comparison', 'hbl-lvs' );
	}

	$values = [
		'listing-views-last-quarter'      => (string) $views,
		'views-quarter-label'             => hbl_lvs_period_label( $quarter ),
		'category-avg-views-last-quarter' => (string) (int) round( $avg ),
		'category-comparison-name'        => (string) $comparison['name'],
		'category-comparison-count'       => (string) (int) $comparison['n'],
		'views-vs-category-percent'       => $percent,
		'views-performance-band'          => $band,
		'listing-plan-tier'               => ucfirst( (string) $row['tier'] ),
	];

	return apply_filters( 'hbl_lvs_field_values', $values, $row, [ 'quarter' => $quarter, 'comparison' => $comparison ] );
}

/**
 * Picks the comparison pool for a listing.
 *
 * Walks from the listing's own subcategory upward and stops at the first level
 * holding at least the minimum number of listings, falling back to the site-wide
 * pool. Two reasons for the minimum: an average over two or three listings is
 * statistical noise, and in a very small pool the "average" effectively discloses
 * a specific competitor's own view count.
 *
 * @param string $term_path  Comma-separated ancestry, root → leaf.
 * @param array  $aggregates term_id => ['n'=>int,'avg'=>float]
 * @return array{term_id:int,name:string,n:int,avg:float}
 */
function hbl_lvs_choose_comparison( string $term_path, array $aggregates ): array {
	$min = (int) apply_filters( 'hbl_lvs_min_comparison_pool', 5 );
	$min = max( 1, $min );

	$path = array_filter( array_map( 'intval', explode( ',', $term_path ) ) );

	// Leaf first, then each ancestor.
	foreach ( array_reverse( $path ) as $term_id ) {
		if ( isset( $aggregates[ $term_id ] ) && (int) $aggregates[ $term_id ]['n'] >= $min ) {
			$term = get_term( $term_id, ATBDP_CATEGORY );

			return [
				'term_id' => $term_id,
				'name'    => ( $term && ! is_wp_error( $term ) )
					? hbl_lvs_plain_text( $term->name )
					: __( 'All listings', 'hbl-lvs' ),
				'n'       => (int) $aggregates[ $term_id ]['n'],
				'avg'     => (float) $aggregates[ $term_id ]['avg'],
			];
		}
	}

	return [
		'term_id' => 0,
		'name'    => __( 'All listings', 'hbl-lvs' ),
		'n'       => isset( $aggregates[0] ) ? (int) $aggregates[0]['n'] : 0,
		'avg'     => isset( $aggregates[0] ) ? (float) $aggregates[0]['avg'] : 0.0,
	];
}

/**
 * Decodes a stored term/post title into plain text fit for an email.
 *
 * Directorist category names arrive HTML-encoded ("Plumbers &amp; Gas Fitters" —
 * imported data in particular). Written to a custom field verbatim, that reaches
 * the reader as literal "&amp;" in plain-text parts and subject lines, and can be
 * double-encoded to "&amp;amp;" once the email builder escapes it again. Decode
 * once at the boundary, matching what the CRM Bridge already does for plan names.
 *
 * @param string $text
 * @return string
 */
function hbl_lvs_plain_text( string $text ): string {
	return trim( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
}

/**
 * Resolves the DoubleScale contact for an email.
 *
 * Existing contacts only by default. A scheduled statistics job silently
 * creating hundreds of new CRM records would be a surprising side effect, so
 * creation is opt-in via the `hbl_lvs_create_missing_contacts` filter (the CRM
 * Bridge's own bulk sync remains the intended way to populate contacts).
 *
 * @param string $email
 * @param int    $listing_id
 * @return \DoubleScale\Modules\Contacts\Models\ContactModel|null
 */
function hbl_lvs_get_contact( string $email, int $listing_id ) {
	if ( ! class_exists( \DoubleScale\Modules\Contacts\Models\ContactModel::class ) ) {
		return null;
	}

	try {
		$contact = \DoubleScale\Modules\Contacts\Models\ContactModel::where( 'email', $email )->first();
		if ( $contact ) {
			return $contact;
		}
	} catch ( \Throwable $e ) {
		return null;
	}

	if ( ! apply_filters( 'hbl_lvs_create_missing_contacts', false ) ) {
		return null;
	}

	if ( function_exists( 'hbl_crm_bridge_get_contact_for_listing' ) ) {
		return hbl_crm_bridge_get_contact_for_listing( $listing_id );
	}

	return null;
}

/**
 * Completes the run and records the quarter as done.
 *
 * @param array $run Run state.
 */
function hbl_lvs_finish_rollup( array $run ): void {
	$quarter = (string) $run['quarter'];

	// Only bank the quarter as "done" once it has actually ended. Running a
	// preview against the quarter currently in progress must not satisfy the
	// scheduler — otherwise, when that quarter closes for real, the automatic
	// rollup would see it as already handled and skip it, permanently.
	if ( hbl_lvs_quarter_has_ended( $quarter ) ) {
		update_option( HBL_LVS_OPT_LAST_ROLLUP, $quarter, false );
	}

	delete_option( HBL_LVS_OPT_RUN );

	$summary = [
		'quarter'     => $quarter,
		'source'      => (string) $run['source'],
		'collected'   => (int) $run['collected'],
		'pushed'      => (int) $run['pushed'],
		'skipped'     => (int) $run['skipped'],
		'non_primary' => isset( $run['non_primary'] ) ? (int) $run['non_primary'] : 0,
		'complete'    => hbl_lvs_period_is_complete( $quarter ),
		'preview'     => ! hbl_lvs_quarter_has_ended( $quarter ),
		'finished_at' => gmdate( 'Y-m-d H:i:s' ),
	];

	update_option( 'hbl_lvs_last_summary', $summary, false );

	do_action( 'hbl_lvs_rollup_complete', $quarter, $summary );
}

// ══════════════════════════════════════════════════════════════════════════════
// RETENTION
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Trims event rows past the retention window, in bounded chunks so a large
 * backlog cannot blow the cron's execution time.
 *
 * @return int Rows deleted.
 */
function hbl_lvs_prune_events(): int {
	global $wpdb;

	$months = (int) apply_filters( 'hbl_lvs_retention_months', 24 );
	if ( $months <= 0 ) {
		return 0;
	}

	$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months' ) );
	$events = hbl_lvs_table( 'events' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return (int) $wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"DELETE FROM {$events} WHERE viewed_at < %s LIMIT 5000",
			$cutoff
		)
	);
}

// ══════════════════════════════════════════════════════════════════════════════
// DOUBLESCALE CUSTOM FIELD DEFINITIONS
// ══════════════════════════════════════════════════════════════════════════════

/**
 * The field set this plugin publishes.
 *
 * @return array<string,array{name:string,type:string}>
 */
function hbl_lvs_field_definitions(): array {
	return [
		'listing-views-last-quarter'      => [ 'name' => 'Listing Views – Last Quarter', 'type' => 'number' ],
		'views-quarter-label'             => [ 'name' => 'Views Period', 'type' => 'text' ],
		'category-avg-views-last-quarter' => [ 'name' => 'Subcategory Avg Views – Last Quarter', 'type' => 'number' ],
		'category-comparison-name'        => [ 'name' => 'Subcategory Compared', 'type' => 'text' ],
		'category-comparison-count'       => [ 'name' => 'Listings In Subcategory', 'type' => 'number' ],
		'views-vs-category-percent'       => [ 'name' => 'Views vs Subcategory %', 'type' => 'text' ],
		'views-performance-band'          => [ 'name' => 'Views Performance Band', 'type' => 'text' ],
		'listing-plan-tier'               => [ 'name' => 'Listing Plan Tier', 'type' => 'text' ],
	];
}

/**
 * Creates any missing custom field definitions in DoubleScale.
 *
 * hbl_crm_bridge_set_custom_field() no-ops when a slug has no definition, so the
 * fields must exist before the first push. Merge tags are registered from the
 * definitions table on load, so newly created fields appear in the email editor
 * from the next page load onward.
 *
 * @return array{created:int,existing:int,error:string}
 */
function hbl_lvs_ensure_custom_fields(): array {
	$result = [ 'created' => 0, 'existing' => 0, 'error' => '' ];

	if ( ! class_exists( \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::class ) ) {
		$result['error'] = __( 'DoubleScale Pro custom fields are not available.', 'hbl-lvs' );
		return $result;
	}

	try {
		$group_id = hbl_lvs_ensure_field_group();

		foreach ( hbl_lvs_field_definitions() as $slug => $definition ) {
			if ( \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::get_id( $slug ) ) {
				++$result['existing'];
				continue;
			}

			\DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::create(
				[
					'name'     => $definition['name'],
					'slug'     => $slug,
					'type'     => $definition['type'],
					'group_id' => $group_id,
					'scope'    => 'contact',
				]
			);

			++$result['created'];
		}
	} catch ( \Throwable $e ) {
		$result['error'] = $e->getMessage();
	}

	return $result;
}

/**
 * Creates the field definitions once, then stops trying.
 *
 * Retries on each admin load until it actually succeeds, so an install where
 * DoubleScale Pro was activated after this plugin still self-heals rather than
 * silently never publishing anything.
 */
function hbl_lvs_maybe_ensure_custom_fields(): void {
	if ( get_option( 'hbl_lvs_fields_ready' ) ) {
		return;
	}

	if ( ! class_exists( \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::class ) ) {
		return;
	}

	$result = hbl_lvs_ensure_custom_fields();

	if ( ! $result['error'] ) {
		update_option( 'hbl_lvs_fields_ready', 1, false );
	}
}

/**
 * Finds or creates the contact-scoped group the fields live in.
 *
 * @return int Group ID (0 is accepted by DoubleScale as "ungrouped").
 */
function hbl_lvs_ensure_field_group(): int {
	if ( ! class_exists( \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldsGroupModel::class ) ) {
		return 0;
	}

	try {
		$group = \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldsGroupModel::where( 'scope', 'contact' )
			->where( 'name', 'Listing Performance' )
			->first();

		if ( $group ) {
			return (int) $group->id;
		}

		$group = \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldsGroupModel::create(
			[
				'name'  => 'Listing Performance',
				'scope' => 'contact',
			]
		);

		return $group ? (int) $group->id : 0;
	} catch ( \Throwable $e ) {
		return 0;
	}
}

// ══════════════════════════════════════════════════════════════════════════════
// ADMIN — STANDALONE SCREEN
//
// Follows the HBL admin tools design system (stat cards, numbered step cards,
// badge pills, table cards, teal/orange buttons) shared with CRM Bridge and
// Place ID Manager. Styles live in assets/css/admin.css and load only here.
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Registers the standalone "Listing Views" menu.
 *
 * Its own top-level entry with its own icon — this is a Directorist reporting
 * tool in its own right, not a sub-page of the CRM plumbing it happens to
 * publish through.
 *
 * Position 32 places it immediately below CRM Bridge (31) and well above
 * Appearance (60), keeping the HBL tools together in sidebar order without
 * nesting this one inside any of them.
 */
function hbl_lvs_add_admin_page(): void {
	global $hbl_lvs_page_hook;

	$hbl_lvs_page_hook = add_menu_page(
		__( 'Listing Views', 'hbl-lvs' ),
		__( 'Listing Views', 'hbl-lvs' ),
		'manage_options',
		'hbl-lvs',
		'hbl_lvs_render_admin_page',
		'dashicons-visibility',
		32
	);
}

/**
 * Loads the stylesheet on this screen only.
 *
 * @param string $hook Current admin page hook suffix.
 */
function hbl_lvs_enqueue_admin_assets( string $hook ): void {
	global $hbl_lvs_page_hook;

	if ( $hook !== $hbl_lvs_page_hook ) {
		return;
	}

	wp_enqueue_style(
		'hbl-lvs-admin',
		plugin_dir_url( __FILE__ ) . 'assets/css/admin.css',
		[],
		HBL_LVS_VERSION
	);
}

/**
 * Builds a URL back into this screen.
 *
 * @param array $args Extra query args.
 * @return string
 */
function hbl_lvs_admin_url( array $args = [] ): string {
	return add_query_arg(
		array_merge( [ 'page' => 'hbl-lvs' ], $args ),
		admin_url( 'admin.php' )
	);
}

/**
 * The tab currently being viewed.
 *
 * @return string
 */
function hbl_lvs_current_tab(): string {
	$tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
	$valid = [ 'overview', 'tags', 'results', 'subcategories', 'tools' ];

	return in_array( $tab, $valid, true ) ? $tab : 'overview';
}

/**
 * Quarters that have computed results, newest first.
 *
 * @return array<int,string>
 */
function hbl_lvs_available_quarters(): array {
	global $wpdb;

	$stats = hbl_lvs_table( 'stats' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$found = $wpdb->get_col( "SELECT DISTINCT period_key FROM {$stats} ORDER BY period_key DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$found = array_map( 'strval', (array) $found );

	// Always offer the quarter that would be reported next, even before it runs.
	$due = hbl_lvs_next_rollup_target();
	if ( ! in_array( $due, $found, true ) ) {
		array_unshift( $found, $due );
	}

	return $found;
}

/**
 * The quarter selected in the UI, defaulting to the most recent with results.
 *
 * @return string
 */
function hbl_lvs_selected_quarter(): string {
	$quarters = hbl_lvs_available_quarters();

	if ( isset( $_GET['quarter'] ) ) {
		$requested = sanitize_text_field( wp_unslash( $_GET['quarter'] ) );
		if ( in_array( $requested, $quarters, true ) ) {
			return $requested;
		}
	}

	return $quarters ? $quarters[0] : hbl_lvs_current_quarter_key();
}

/**
 * Page chrome: heading, headline stats, tab bar, then the active tab.
 */
function hbl_lvs_render_admin_page(): void {
	global $wpdb;

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$tab    = hbl_lvs_current_tab();
	$notice = isset( $_GET['hbl_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['hbl_notice'] ) ) : '';

	$events  = hbl_lvs_table( 'events' );
	$current = hbl_lvs_current_quarter_key();
	$due     = hbl_lvs_next_rollup_target();
	$started = get_option( HBL_LVS_OPT_LOGGER_START );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$event_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$defined = hbl_lvs_defined_field_count();
	$total   = count( hbl_lvs_field_definitions() );

	$last_roll = get_option( HBL_LVS_OPT_LAST_ROLLUP );
	$run       = get_option( HBL_LVS_OPT_RUN );

	if ( ! $started ) {
		$log_badge = [ 'hbl-badge-danger', __( 'Not Started', 'hbl-lvs' ) ];
	} elseif ( $event_count > 0 ) {
		$log_badge = [ 'hbl-badge-on', __( 'Recording', 'hbl-lvs' ) ];
	} else {
		$log_badge = [ 'hbl-badge-warn', __( 'No Views Yet', 'hbl-lvs' ) ];
	}

	$tabs = [
		'overview'      => __( 'Overview', 'hbl-lvs' ),
		'tags'          => __( 'Merge Tags', 'hbl-lvs' ),
		'results'       => __( 'Results', 'hbl-lvs' ),
		'subcategories' => __( 'Subcategories', 'hbl-lvs' ),
		'tools'         => __( 'Tools', 'hbl-lvs' ),
	];
	?>
	<div class="wrap hbl-lvs-wrap">
		<h1>
			<svg width="26" height="26" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
				<path d="M1.5 12S5 5 12 5s10.5 7 10.5 7-3.5 7-10.5 7S1.5 12 1.5 12z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				<circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
			</svg>
			<?php esc_html_e( 'Listing Views', 'hbl-lvs' ); ?>
		</h1>
		<p class="description">
			<?php esc_html_e( 'Records how many times each Directorist listing was viewed, compares it against the average for the same subcategory, and publishes both onto the listing owner\'s contact record once per quarter — ready to use as merge tags in any email.', 'hbl-lvs' ); ?>
		</p>

		<?php if ( $notice ) : ?>
			<div class="notice <?php echo 0 === strpos( $notice, 'error:' ) ? 'notice-error' : 'notice-success'; ?> is-dismissible">
				<p><?php echo esc_html( str_replace( 'error:', '', $notice ) ); ?></p>
			</div>
		<?php endif; ?>

		<div class="hbl-lvs-stats">
			<div class="hbl-lvs-stat">
				<div class="hbl-lvs-stat-value"><?php echo esc_html( number_format_i18n( $event_count ) ); ?></div>
				<div class="hbl-lvs-stat-label"><?php esc_html_e( 'Views Logged', 'hbl-lvs' ); ?></div>
			</div>
			<div class="hbl-lvs-stat">
				<div class="hbl-lvs-stat-value">
					<span class="hbl-badge <?php echo esc_attr( $log_badge[0] ); ?>"><span class="hbl-badge-dot"></span> <?php echo esc_html( $log_badge[1] ); ?></span>
				</div>
				<div class="hbl-lvs-stat-label"><?php esc_html_e( 'View Logger', 'hbl-lvs' ); ?></div>
			</div>
			<div class="hbl-lvs-stat">
				<div class="hbl-lvs-stat-value"><?php echo esc_html( $due ); ?></div>
				<div class="hbl-lvs-stat-label"><?php esc_html_e( 'Next Report Covers', 'hbl-lvs' ); ?></div>
				<div class="hbl-lvs-stat-sub"><?php echo esc_html( hbl_lvs_period_label( $due, false ) ); ?></div>
			</div>
			<div class="hbl-lvs-stat">
				<div class="hbl-lvs-stat-value"><?php echo esc_html( $defined . ' / ' . $total ); ?></div>
				<div class="hbl-lvs-stat-label"><?php esc_html_e( 'Merge Tag Fields Ready', 'hbl-lvs' ); ?></div>
			</div>
			<div class="hbl-lvs-stat">
				<div class="hbl-lvs-stat-value">
					<?php if ( is_array( $run ) && ! empty( $run['quarter'] ) ) : ?>
						<span class="hbl-badge hbl-badge-warn"><span class="hbl-badge-dot"></span> <?php esc_html_e( 'Running', 'hbl-lvs' ); ?></span>
					<?php elseif ( $last_roll ) : ?>
						<?php echo esc_html( $last_roll ); ?>
					<?php else : ?>
						<span class="hbl-badge hbl-badge-off"><span class="hbl-badge-dot"></span> <?php esc_html_e( 'Never', 'hbl-lvs' ); ?></span>
					<?php endif; ?>
				</div>
				<div class="hbl-lvs-stat-label"><?php esc_html_e( 'Last Rollup', 'hbl-lvs' ); ?></div>
			</div>
		</div>

		<nav class="hbl-lvs-tabs">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<a href="<?php echo esc_url( hbl_lvs_admin_url( [ 'tab' => $slug ] ) ); ?>"
				   class="hbl-lvs-tab <?php echo $tab === $slug ? 'is-active' : ''; ?>">
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<?php
		switch ( $tab ) {
			case 'tags':
				hbl_lvs_tab_tags();
				break;
			case 'results':
				hbl_lvs_tab_results();
				break;
			case 'subcategories':
				hbl_lvs_tab_subcategories();
				break;
			case 'tools':
				hbl_lvs_tab_tools();
				break;
			default:
				hbl_lvs_tab_overview();
		}
		?>

		<script>
			function hblLvsCopy(text, el) {
				navigator.clipboard.writeText(text).then(function () {
					var old = el.textContent;
					el.textContent = '<?php echo esc_js( __( 'Copied', 'hbl-lvs' ) ); ?>';
					setTimeout(function () { el.textContent = old; }, 1200);
				});
			}
		</script>
	</div>
	<?php
}

/**
 * How many of this plugin's custom fields currently exist in DoubleScale.
 *
 * @return int
 */
function hbl_lvs_defined_field_count(): int {
	if ( ! class_exists( \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::class ) ) {
		return 0;
	}

	$count = 0;
	foreach ( array_keys( hbl_lvs_field_definitions() ) as $slug ) {
		if ( \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::get_id( $slug ) ) {
			++$count;
		}
	}

	return $count;
}

/**
 * Opens a numbered step card.
 *
 * @param int    $number
 * @param string $title
 * @param string $sub
 * @param string $badge_class
 * @param string $badge_label
 */
function hbl_lvs_step_open( int $number, string $title, string $sub = '', string $badge_class = '', string $badge_label = '' ): void {
	?>
	<div class="hbl-lvs-step">
		<div class="hbl-lvs-step-header">
			<div class="hbl-lvs-step-number"><?php echo esc_html( (string) $number ); ?></div>
			<div>
				<h2><?php echo esc_html( $title ); ?></h2>
				<?php if ( $sub ) : ?>
					<p class="hbl-lvs-step-sub"><?php echo esc_html( $sub ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( $badge_label ) : ?>
				<span class="hbl-badge <?php echo esc_attr( $badge_class ); ?>"><span class="hbl-badge-dot"></span> <?php echo esc_html( $badge_label ); ?></span>
			<?php endif; ?>
		</div>
	<?php
}

/**
 * Closes a step card.
 */
function hbl_lvs_step_close(): void {
	echo '</div>';
}

/**
 * Renders a small nonce-protected action form.
 *
 * @param string $task
 * @param string $label
 * @param string $class
 */
function hbl_lvs_action_button( string $task, string $label, string $class = 'button' ): void {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'hbl_lvs_admin' ); ?>
		<input type="hidden" name="action" value="hbl_lvs_admin_action">
		<input type="hidden" name="task" value="<?php echo esc_attr( $task ); ?>">
		<button class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
	</form>
	<?php
}

// ── Tab: Overview ─────────────────────────────────────────────────────────────

/**
 * Health, schedule and the last run.
 */
function hbl_lvs_tab_overview(): void {
	global $wpdb;

	$events  = hbl_lvs_table( 'events' );
	$current = hbl_lvs_current_quarter_key();
	$due     = hbl_lvs_next_rollup_target();
	$run     = get_option( HBL_LVS_OPT_RUN );
	$summary = get_option( 'hbl_lvs_last_summary' );
	$started = get_option( HBL_LVS_OPT_LOGGER_START );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$events_30d = (int) $wpdb->get_var(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM {$events} WHERE viewed_at >= %s",
			gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) )
		)
	);

	$next_cron = wp_next_scheduled( HBL_LVS_CRON_DAILY );

	// ── Run in progress ───────────────────────────────────────────────────────
	if ( is_array( $run ) && ! empty( $run['quarter'] ) ) {
		hbl_lvs_step_open(
			1,
			__( 'Rollup in progress', 'hbl-lvs' ),
			sprintf(
				/* translators: 1: quarter, 2: phase */
				__( 'Quarter %1$s — currently in the "%2$s" phase.', 'hbl-lvs' ),
				$run['quarter'],
				$run['phase']
			),
			'hbl-badge-warn',
			__( 'Running', 'hbl-lvs' )
		);
		?>
		<p class="description">
			<?php
			printf(
				/* translators: 1: collected, 2: pushed */
				esc_html__( '%1$d listings measured, %2$d contacts updated so far. This continues in the background; refresh to follow along.', 'hbl-lvs' ),
				(int) $run['collected'],
				(int) $run['pushed']
			);
			?>
		</p>
		<div class="hbl-lvs-actions">
			<?php hbl_lvs_action_button( 'cancel_run', __( 'Cancel run', 'hbl-lvs' ), 'button button-danger' ); ?>
		</div>
		<?php
		hbl_lvs_step_close();
	}

	// ── Health ────────────────────────────────────────────────────────────────
	$checks  = hbl_lvs_health_checks();
	$failing = count(
		array_filter(
			$checks,
			static function ( $c ) {
				return ! $c['ok'];
			}
		)
	);

	hbl_lvs_step_open(
		1,
		__( 'System health', 'hbl-lvs' ),
		__( 'Everything that has to be working for the quarterly update to reach your contacts.', 'hbl-lvs' ),
		$failing ? 'hbl-badge-danger' : 'hbl-badge-on',
		$failing
			? sprintf( /* translators: %d: count */ _n( '%d Issue', '%d Issues', $failing, 'hbl-lvs' ), $failing )
			: __( 'All Good', 'hbl-lvs' )
	);
	?>
	<div class="hbl-lvs-health">
		<?php foreach ( $checks as $check ) : ?>
			<div class="hbl-lvs-health-item <?php echo $check['ok'] ? 'hbl-lvs-health-ok' : 'hbl-lvs-health-bad'; ?>">
				<div class="hbl-lvs-health-icon"><?php echo $check['ok'] ? '&#10003;' : '!'; ?></div>
				<div>
					<strong><?php echo esc_html( $check['label'] ); ?></strong>
					<span><?php echo esc_html( $check['detail'] ); ?></span>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
	hbl_lvs_step_close();

	// ── Schedule ──────────────────────────────────────────────────────────────
	hbl_lvs_step_open(
		2,
		__( 'Schedule', 'hbl-lvs' ),
		__( 'Views are recorded continuously. Reporting happens once per quarter, automatically.', 'hbl-lvs' )
	);
	?>
	<table class="hbl-lvs-defs">
		<tbody>
			<tr>
				<th><?php esc_html_e( 'Logging since', 'hbl-lvs' ); ?></th>
				<td>
					<?php
					echo $started
						? esc_html( gmdate( 'j M Y H:i', strtotime( $started . ' UTC' ) ) . ' UTC' )
						: '<span class="hbl-lvs-muted">' . esc_html__( 'not started', 'hbl-lvs' ) . '</span>';
					?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Views in the last 30 days', 'hbl-lvs' ); ?></th>
				<td><?php echo esc_html( number_format_i18n( $events_30d ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Current quarter', 'hbl-lvs' ); ?></th>
				<td><?php echo esc_html( $current . ' — ' . hbl_lvs_period_label( $current ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Next report covers', 'hbl-lvs' ); ?></th>
				<td>
					<?php echo esc_html( $due . ' — ' . hbl_lvs_period_label( $due, false ) ); ?>
					<?php if ( ! hbl_lvs_period_is_complete( $due ) ) : ?>
						<br><span class="hbl-lvs-muted"><?php esc_html_e( 'Partial: view logging began part-way through this quarter, so it is labelled with its true date range rather than presented as a whole quarter.', 'hbl-lvs' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Next scheduled check', 'hbl-lvs' ); ?></th>
				<td>
					<?php
					echo $next_cron
						? esc_html( gmdate( 'j M H:i', $next_cron ) . ' UTC' ) . ' <span class="hbl-lvs-muted">(' . esc_html( human_time_diff( time(), $next_cron ) ) . ')</span>'
						: '<span class="hbl-lvs-muted">' . esc_html__( 'not scheduled', 'hbl-lvs' ) . '</span>';
					?>
				</td>
			</tr>
		</tbody>
	</table>
	<?php
	hbl_lvs_step_close();

	// ── Last run ──────────────────────────────────────────────────────────────
	if ( is_array( $summary ) ) {
		$is_preview = ! empty( $summary['preview'] );

		hbl_lvs_step_open(
			3,
			__( 'Last run', 'hbl-lvs' ),
			sprintf(
				/* translators: %s: quarter */
				__( 'Results for %s.', 'hbl-lvs' ),
				$summary['quarter']
			),
			$is_preview ? 'hbl-badge-warn' : 'hbl-badge-on',
			$is_preview ? __( 'Preview', 'hbl-lvs' ) : __( 'Scheduled Run', 'hbl-lvs' )
		);
		?>
		<table class="hbl-lvs-defs">
			<tbody>
				<tr><th><?php esc_html_e( 'Listings measured', 'hbl-lvs' ); ?></th><td><?php echo esc_html( number_format_i18n( $summary['collected'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Contacts updated', 'hbl-lvs' ); ?></th><td><?php echo esc_html( number_format_i18n( $summary['pushed'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Skipped (no matching contact)', 'hbl-lvs' ); ?></th><td><?php echo esc_html( number_format_i18n( $summary['skipped'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Data source', 'hbl-lvs' ); ?></th><td><?php echo esc_html( 'events' === $summary['source'] ? __( 'Logged view events', 'hbl-lvs' ) : __( 'Snapshot difference (fallback)', 'hbl-lvs' ) ); ?></td></tr>
				<?php if ( $is_preview ) : ?>
					<tr>
						<th><?php esc_html_e( 'Note', 'hbl-lvs' ); ?></th>
						<td class="hbl-lvs-muted"><?php esc_html_e( 'This was a preview of a quarter still in progress. It does not count as the scheduled run — the real one still happens when the quarter ends.', 'hbl-lvs' ); ?></td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
		hbl_lvs_step_close();
	}
}

/**
 * The health checks shown on the Overview tab.
 *
 * @return array<int,array{ok:bool,label:string,detail:string}>
 */
function hbl_lvs_health_checks(): array {
	global $wpdb;

	$checks = [];

	$missing = [];
	foreach ( [ 'events', 'snapshots', 'stats' ] as $t ) {
		$name = hbl_lvs_table( $t );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) ) {
			$missing[] = $t;
		}
	}
	$checks[] = [
		'ok'     => empty( $missing ),
		'label'  => __( 'Database tables', 'hbl-lvs' ),
		'detail' => $missing
			? sprintf( /* translators: %s: list */ __( 'Missing: %s. Deactivate and reactivate the plugin.', 'hbl-lvs' ), implode( ', ', $missing ) )
			: __( 'All three tables present.', 'hbl-lvs' ),
	];

	$started  = get_option( HBL_LVS_OPT_LOGGER_START );
	$checks[] = [
		'ok'     => (bool) $started,
		'label'  => __( 'View logger', 'hbl-lvs' ),
		'detail' => $started
			? __( 'Recording timestamped views.', 'hbl-lvs' )
			: __( 'Never started. Reactivate the plugin.', 'hbl-lvs' ),
	];

	// Directorist's JS tracking is frequently absent on sites whose listing
	// pages come from a page builder, so server-side tracking is what actually
	// keeps the numbers alive. Flag it loudly when it is off.
	$server   = hbl_lvs_server_tracking_enabled();
	$checks[] = [
		'ok'     => $server,
		'label'  => __( 'Server-side tracking', 'hbl-lvs' ),
		'detail' => $server
			? __( 'On. Views are recorded regardless of how listing pages are rendered.', 'hbl-lvs' )
			: __( 'Off. Views are only recorded if Directorist\'s own JavaScript fires, which many themes prevent.', 'hbl-lvs' ),
	];

	$ds       = class_exists( \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::class );
	$checks[] = [
		'ok'     => $ds,
		'label'  => __( 'DoubleScale Pro', 'hbl-lvs' ),
		'detail' => $ds ? __( 'Custom fields available.', 'hbl-lvs' ) : __( 'Not active. Values cannot be published.', 'hbl-lvs' ),
	];

	$bridge   = function_exists( 'hbl_crm_bridge_set_custom_field' );
	$checks[] = [
		'ok'     => $bridge,
		'label'  => __( 'CRM Bridge', 'hbl-lvs' ),
		'detail' => $bridge ? __( 'Contact writer available.', 'hbl-lvs' ) : __( 'Not active. Values cannot be published.', 'hbl-lvs' ),
	];

	$defined  = hbl_lvs_defined_field_count();
	$total    = count( hbl_lvs_field_definitions() );
	$checks[] = [
		'ok'     => $defined === $total,
		'label'  => __( 'Merge tag fields', 'hbl-lvs' ),
		'detail' => sprintf(
			/* translators: 1: defined, 2: total */
			__( '%1$d of %2$d defined in DoubleScale.', 'hbl-lvs' ),
			$defined,
			$total
		),
	];

	$cron     = (bool) wp_next_scheduled( HBL_LVS_CRON_DAILY );
	$checks[] = [
		'ok'     => $cron,
		'label'  => __( 'Daily schedule', 'hbl-lvs' ),
		'detail' => $cron
			? __( 'Registered. Checks daily whether a quarter is due.', 'hbl-lvs' )
			: __( 'Not scheduled. Reactivate the plugin.', 'hbl-lvs' ),
	];

	$wp_cron_off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	$checks[]    = [
		'ok'     => ! $wp_cron_off,
		'label'  => __( 'WP-Cron', 'hbl-lvs' ),
		'detail' => $wp_cron_off
			? __( 'DISABLE_WP_CRON is set. Confirm a server cron hits wp-cron.php.', 'hbl-lvs' )
			: __( 'Enabled.', 'hbl-lvs' ),
	];

	return $checks;
}

// ── Tab: Merge Tags ───────────────────────────────────────────────────────────

/**
 * Every field this plugin publishes, with its merge tag and live status.
 */
function hbl_lvs_tab_tags(): void {
	$ds      = class_exists( \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::class );
	$defined = hbl_lvs_defined_field_count();
	$total   = count( hbl_lvs_field_definitions() );

	$examples = [
		'listing-views-last-quarter'      => '184',
		'views-quarter-label'             => 'Apr–Jun 2026',
		'category-avg-views-last-quarter' => '112',
		'category-comparison-name'        => 'Plumbers & Gas Fitters',
		'category-comparison-count'       => '23',
		'views-vs-category-percent'       => '+64%',
		'views-performance-band'          => 'Above average',
		'listing-plan-tier'               => 'Gold',
	];

	hbl_lvs_step_open(
		1,
		__( 'Contact fields', 'hbl-lvs' ),
		__( 'Created automatically — you never add these by hand.', 'hbl-lvs' ),
		$defined === $total ? 'hbl-badge-on' : 'hbl-badge-warn',
		sprintf( '%d / %d', $defined, $total )
	);
	?>
	<p class="description">
		<?php esc_html_e( 'These custom fields are created in DoubleScale on the first admin page load after activation, with scope "contact" in a group called Listing Performance. If any are missing, use the repair button below. Do not rename the slugs — values are written by slug, so a rename silently stops the update. Renaming the label is safe.', 'hbl-lvs' ); ?>
	</p>

	<div class="hbl-lvs-actions" style="margin-bottom:18px">
		<?php hbl_lvs_action_button( 'create_fields', __( 'Create / repair fields', 'hbl-lvs' ), 'button button-primary' ); ?>
	</div>

	<div class="hbl-lvs-table-scroll">
		<table class="hbl-lvs-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Field', 'hbl-lvs' ); ?></th>
					<th><?php esc_html_e( 'Merge tag', 'hbl-lvs' ); ?></th>
					<th><?php esc_html_e( 'Example', 'hbl-lvs' ); ?></th>
					<th><?php esc_html_e( 'Status', 'hbl-lvs' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( hbl_lvs_field_definitions() as $slug => $definition ) : ?>
				<?php
				$tag      = '{{contact:contact_field:' . $slug . '}}';
				$is_there = $ds && \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::get_id( $slug );
				?>
				<tr>
					<td data-label="<?php esc_attr_e( 'Field', 'hbl-lvs' ); ?>">
						<strong><?php echo esc_html( $definition['name'] ); ?></strong><br>
						<span class="hbl-lvs-muted"><?php echo esc_html( $slug . ' · ' . $definition['type'] ); ?></span>
					</td>
					<td data-label="<?php esc_attr_e( 'Merge tag', 'hbl-lvs' ); ?>">
						<span class="hbl-lvs-tag"><?php echo esc_html( $tag ); ?></span>
						<a class="hbl-lvs-copy" href="#" onclick="hblLvsCopy('<?php echo esc_js( $tag ); ?>', this); return false;"><?php esc_html_e( 'Copy', 'hbl-lvs' ); ?></a>
					</td>
					<td data-label="<?php esc_attr_e( 'Example', 'hbl-lvs' ); ?>" class="hbl-lvs-muted"><?php echo esc_html( $examples[ $slug ] ?? '' ); ?></td>
					<td data-label="<?php esc_attr_e( 'Status', 'hbl-lvs' ); ?>">
						<?php if ( $is_there ) : ?>
							<span class="hbl-badge hbl-badge-on"><span class="hbl-badge-dot"></span> <?php esc_html_e( 'Ready', 'hbl-lvs' ); ?></span>
						<?php else : ?>
							<span class="hbl-badge hbl-badge-danger"><span class="hbl-badge-dot"></span> <?php esc_html_e( 'Missing', 'hbl-lvs' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
	hbl_lvs_step_close();

	hbl_lvs_step_open(
		2,
		__( 'Ready-made email copy', 'hbl-lvs' ),
		__( 'Paste straight into a DoubleScale email as a starting point.', 'hbl-lvs' )
	);

	$sample = "Hi {{contact:first_name}},\n\n"
		. "Your listing had {{contact:contact_field:listing-views-last-quarter}} views in {{contact:contact_field:views-quarter-label}}.\n\n"
		. "The average for {{contact:contact_field:category-comparison-name}} was {{contact:contact_field:category-avg-views-last-quarter}} views "
		. "across {{contact:contact_field:category-comparison-count}} listings — so you're running at "
		. "{{contact:contact_field:views-vs-category-percent}} versus similar businesses.\n\n"
		. "Performance: {{contact:contact_field:views-performance-band}}\n"
		. "Your plan: {{contact:contact_field:listing-plan-tier}}";
	?>
	<textarea class="hbl-lvs-sample" rows="10" readonly onclick="this.select()"><?php echo esc_textarea( $sample ); ?></textarea>
	<p class="description" style="margin-top:14px">
		<?php esc_html_e( 'To choose who receives it, filter contacts on Views Performance Band (Above average / Average / Below average / No comparison) together with Listing Plan Tier. Use those fields rather than doing arithmetic on the raw numbers — merge tags cannot calculate, which is why the percentage and the band are worked out in advance.', 'hbl-lvs' ); ?>
	</p>
	<?php
	hbl_lvs_step_close();
}

// ── Tab: Results ──────────────────────────────────────────────────────────────

/**
 * Per-listing results for a quarter, showing exactly the values that were (or
 * would be) written to each contact.
 */
function hbl_lvs_tab_results(): void {
	global $wpdb;

	$stats    = hbl_lvs_table( 'stats' );
	$quarter  = hbl_lvs_selected_quarter();
	$quarters = hbl_lvs_available_quarters();
	$agg      = get_option( hbl_lvs_aggregate_option( $quarter ), [] );
	$agg      = is_array( $agg ) ? $agg : [];

	$tier   = isset( $_GET['tier'] ) ? sanitize_key( wp_unslash( $_GET['tier'] ) ) : '';
	$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

	$per_page = 50;
	$offset   = ( $paged - 1 ) * $per_page;

	$where  = [ 's.period_key = %s' ];
	$params = [ $quarter ];

	if ( in_array( $tier, [ 'gold', 'silver', 'bronze' ], true ) ) {
		$where[]  = 's.tier = %s';
		$params[] = $tier;
	}

	switch ( $status ) {
		case 'written':
			$where[] = 's.is_primary = 1 AND s.pushed = 1';
			break;
		case 'nocontact':
			$where[] = 's.is_primary = 1 AND s.pushed = 2';
			break;
		case 'primary':
			$where[] = 's.is_primary = 1';
			break;
		case 'secondary':
			$where[] = 's.is_primary = 0';
			break;
	}

	if ( '' !== $search ) {
		$like     = '%' . $wpdb->esc_like( $search ) . '%';
		$where[]  = '(s.contact_email LIKE %s OR p.post_title LIKE %s)';
		$params[] = $like;
		$params[] = $like;
	}

	$where_sql = implode( ' AND ', $where );
	$from_sql  = "FROM {$stats} s LEFT JOIN {$wpdb->posts} p ON p.ID = s.listing_id WHERE {$where_sql}";

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from_sql}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$page_params   = $params;
	$page_params[] = $per_page;
	$page_params[] = $offset;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT s.*, p.post_title {$from_sql} ORDER BY s.views DESC, s.listing_id ASC LIMIT %d OFFSET %d",
			$page_params
		),
		ARRAY_A
	);

	hbl_lvs_step_open(
		1,
		__( 'Listing results', 'hbl-lvs' ),
		__( 'Exactly the values written to each contact — same code path, so this cannot drift from what was sent.', 'hbl-lvs' ),
		'hbl-badge-off',
		sprintf( /* translators: %s: count */ _n( '%s listing', '%s listings', $total, 'hbl-lvs' ), number_format_i18n( $total ) )
	);
	?>
	<form method="get" class="hbl-lvs-toolbar">
		<input type="hidden" name="page" value="hbl-lvs">
		<input type="hidden" name="tab" value="results">

		<div class="hbl-lvs-field">
			<label for="hbl-lvs-quarter"><?php esc_html_e( 'Quarter', 'hbl-lvs' ); ?></label>
			<select id="hbl-lvs-quarter" name="quarter" class="hbl-lvs-input">
				<?php foreach ( $quarters as $q ) : ?>
					<option value="<?php echo esc_attr( $q ); ?>" <?php selected( $q, $quarter ); ?>>
						<?php echo esc_html( $q . ' — ' . hbl_lvs_period_label( $q ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="hbl-lvs-field">
			<label for="hbl-lvs-tier"><?php esc_html_e( 'Plan tier', 'hbl-lvs' ); ?></label>
			<select id="hbl-lvs-tier" name="tier" class="hbl-lvs-input">
				<option value=""><?php esc_html_e( 'All tiers', 'hbl-lvs' ); ?></option>
				<?php foreach ( [ 'gold', 'silver', 'bronze' ] as $t ) : ?>
					<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $t, $tier ); ?>><?php echo esc_html( ucfirst( $t ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="hbl-lvs-field">
			<label for="hbl-lvs-status"><?php esc_html_e( 'Status', 'hbl-lvs' ); ?></label>
			<select id="hbl-lvs-status" name="status" class="hbl-lvs-input">
				<option value=""><?php esc_html_e( 'All listings', 'hbl-lvs' ); ?></option>
				<option value="primary" <?php selected( 'primary', $status ); ?>><?php esc_html_e( 'Primary only', 'hbl-lvs' ); ?></option>
				<option value="written" <?php selected( 'written', $status ); ?>><?php esc_html_e( 'Written to contact', 'hbl-lvs' ); ?></option>
				<option value="nocontact" <?php selected( 'nocontact', $status ); ?>><?php esc_html_e( 'No contact found', 'hbl-lvs' ); ?></option>
				<option value="secondary" <?php selected( 'secondary', $status ); ?>><?php esc_html_e( 'Not primary', 'hbl-lvs' ); ?></option>
			</select>
		</div>

		<div class="hbl-lvs-field">
			<label for="hbl-lvs-search"><?php esc_html_e( 'Search', 'hbl-lvs' ); ?></label>
			<input id="hbl-lvs-search" type="search" name="s" class="hbl-lvs-input" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Listing or email', 'hbl-lvs' ); ?>">
		</div>

		<div class="hbl-lvs-actions">
			<button class="button button-primary"><?php esc_html_e( 'Filter', 'hbl-lvs' ); ?></button>
			<a class="button" href="<?php echo esc_url( hbl_lvs_admin_url( [ 'tab' => 'results' ] ) ); ?>"><?php esc_html_e( 'Reset', 'hbl-lvs' ); ?></a>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=hbl_lvs_export&quarter=' . rawurlencode( $quarter ) ), 'hbl_lvs_export' ) ); ?>"><?php esc_html_e( 'Export CSV', 'hbl-lvs' ); ?></a>
		</div>
	</form>

	<div class="hbl-lvs-table-scroll">
		<table class="hbl-lvs-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Listing', 'hbl-lvs' ); ?></th>
					<th><?php esc_html_e( 'Views', 'hbl-lvs' ); ?></th>
					<th><?php esc_html_e( 'Tier', 'hbl-lvs' ); ?></th>
					<th><?php esc_html_e( 'Compared against', 'hbl-lvs' ); ?></th>
					<th><?php esc_html_e( 'Pool avg', 'hbl-lvs' ); ?></th>
					<th><?php esc_html_e( 'vs avg', 'hbl-lvs' ); ?></th>
					<th><?php esc_html_e( 'Band', 'hbl-lvs' ); ?></th>
					<th><?php esc_html_e( 'Contact', 'hbl-lvs' ); ?></th>
					<th><?php esc_html_e( 'Status', 'hbl-lvs' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="9" class="hbl-lvs-empty"><?php esc_html_e( 'No results yet. Run a rollup from the Tools tab.', 'hbl-lvs' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$values   = hbl_lvs_build_field_values( $row, $agg, $quarter );
					$band     = $values['views-performance-band'];
					$band_cls = '';
					if ( __( 'Above average', 'hbl-lvs' ) === $band ) {
						$band_cls = 'hbl-lvs-up';
					} elseif ( __( 'Below average', 'hbl-lvs' ) === $band ) {
						$band_cls = 'hbl-lvs-down';
					}
					?>
					<tr>
						<td data-label="<?php esc_attr_e( 'Listing', 'hbl-lvs' ); ?>">
							<a href="<?php echo esc_url( (string) get_edit_post_link( (int) $row['listing_id'] ) ); ?>">
								<?php echo esc_html( $row['post_title'] ? $row['post_title'] : '#' . $row['listing_id'] ); ?>
							</a>
						</td>
						<td data-label="<?php esc_attr_e( 'Views', 'hbl-lvs' ); ?>" class="hbl-lvs-num"><?php echo esc_html( number_format_i18n( (int) $row['views'] ) ); ?></td>
						<td data-label="<?php esc_attr_e( 'Tier', 'hbl-lvs' ); ?>"><?php echo esc_html( ucfirst( (string) $row['tier'] ) ); ?></td>
						<td data-label="<?php esc_attr_e( 'Compared against', 'hbl-lvs' ); ?>">
							<?php echo esc_html( $values['category-comparison-name'] ); ?>
							<span class="hbl-lvs-muted">(<?php echo esc_html( $values['category-comparison-count'] ); ?>)</span>
						</td>
						<td data-label="<?php esc_attr_e( 'Pool avg', 'hbl-lvs' ); ?>"><?php echo esc_html( $values['category-avg-views-last-quarter'] ); ?></td>
						<td data-label="<?php esc_attr_e( 'vs avg', 'hbl-lvs' ); ?>" class="<?php echo esc_attr( $band_cls ); ?>"><?php echo esc_html( $values['views-vs-category-percent'] ); ?></td>
						<td data-label="<?php esc_attr_e( 'Band', 'hbl-lvs' ); ?>" class="<?php echo esc_attr( $band_cls ); ?>"><?php echo esc_html( $band ); ?></td>
						<td data-label="<?php esc_attr_e( 'Contact', 'hbl-lvs' ); ?>"><?php echo esc_html( $row['contact_email'] ? $row['contact_email'] : '—' ); ?></td>
						<td data-label="<?php esc_attr_e( 'Status', 'hbl-lvs' ); ?>">
							<?php
							if ( ! (int) $row['is_primary'] ) {
								echo '<span class="hbl-badge hbl-badge-off"><span class="hbl-badge-dot"></span> ' . esc_html__( 'Not primary', 'hbl-lvs' ) . '</span>';
							} elseif ( 1 === (int) $row['pushed'] ) {
								echo '<span class="hbl-badge hbl-badge-on"><span class="hbl-badge-dot"></span> ' . esc_html__( 'Written', 'hbl-lvs' ) . '</span>';
							} elseif ( 2 === (int) $row['pushed'] ) {
								echo '<span class="hbl-badge hbl-badge-warn"><span class="hbl-badge-dot"></span> ' . esc_html__( 'No contact', 'hbl-lvs' ) . '</span>';
							} else {
								echo '<span class="hbl-badge hbl-badge-off"><span class="hbl-badge-dot"></span> ' . esc_html__( 'Pending', 'hbl-lvs' ) . '</span>';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
	</div>

	<?php
	$pages = (int) ceil( $total / $per_page );
	if ( $pages > 1 ) {
		echo '<div class="hbl-lvs-pagination">';
		echo wp_kses_post(
			paginate_links(
				[
					'base'      => hbl_lvs_admin_url(
						[
							'tab'     => 'results',
							'quarter' => $quarter,
							'tier'    => $tier,
							'status'  => $status,
							's'       => $search,
							'paged'   => '%#%',
						]
					),
					'format'    => '',
					'current'   => $paged,
					'total'     => $pages,
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
				]
			)
		);
		echo '</div>';
	}

	hbl_lvs_step_close();
}

// ── Tab: Subcategories ────────────────────────────────────────────────────────

/**
 * The comparison pools, so it is visible which subcategories are big enough to
 * average meaningfully and which fall back to a parent.
 */
function hbl_lvs_tab_subcategories(): void {
	$quarter  = hbl_lvs_selected_quarter();
	$quarters = hbl_lvs_available_quarters();
	$agg      = get_option( hbl_lvs_aggregate_option( $quarter ), [] );
	$agg      = is_array( $agg ) ? $agg : [];
	$min      = (int) apply_filters( 'hbl_lvs_min_comparison_pool', 5 );

	// Biggest pools first — those are the comparisons that actually get used.
	uasort(
		$agg,
		static function ( $a, $b ) {
			return (int) $b['n'] <=> (int) $a['n'];
		}
	);

	$usable = count(
		array_filter(
			$agg,
			static function ( $d ) use ( $min ) {
				return (int) $d['n'] >= $min;
			}
		)
	);

	hbl_lvs_step_open(
		1,
		__( 'Comparison pools', 'hbl-lvs' ),
		__( 'How each listing gets its "average for similar businesses" figure.', 'hbl-lvs' ),
		'hbl-badge-off',
		sprintf( /* translators: 1: usable, 2: total */ __( '%1$d of %2$d usable', 'hbl-lvs' ), $usable, count( $agg ) )
	);
	?>
	<form method="get" class="hbl-lvs-toolbar">
		<input type="hidden" name="page" value="hbl-lvs">
		<input type="hidden" name="tab" value="subcategories">
		<div class="hbl-lvs-field">
			<label for="hbl-lvs-sub-quarter"><?php esc_html_e( 'Quarter', 'hbl-lvs' ); ?></label>
			<select id="hbl-lvs-sub-quarter" name="quarter" class="hbl-lvs-input" onchange="this.form.submit()">
				<?php foreach ( $quarters as $q ) : ?>
					<option value="<?php echo esc_attr( $q ); ?>" <?php selected( $q, $quarter ); ?>><?php echo esc_html( $q ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
	</form>

	<p class="description">
		<?php
		printf(
			/* translators: %d: minimum pool size */
			esc_html__( 'A listing is compared against the deepest category it belongs to that holds at least %d listings. Smaller pools climb to the parent category, then to all listings. Two reasons for the floor: an average over two or three listings is noise, and in a tiny pool the "average" effectively discloses a specific competitor\'s own numbers.', 'hbl-lvs' ),
			$min
		);
		?>
	</p>

	<div class="hbl-lvs-table-scroll">
		<table class="hbl-lvs-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Category', 'hbl-lvs' ); ?></th>
					<th><?php esc_html_e( 'Listings in pool', 'hbl-lvs' ); ?></th>
					<th><?php esc_html_e( 'Average views', 'hbl-lvs' ); ?></th>
					<th><?php esc_html_e( 'Usable as a comparison', 'hbl-lvs' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $agg ) ) : ?>
				<tr><td colspan="4" class="hbl-lvs-empty"><?php esc_html_e( 'No aggregates yet. Run a rollup from the Tools tab.', 'hbl-lvs' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $agg as $term_id => $data ) : ?>
					<?php
					$term_id = (int) $term_id;
					if ( $term_id ) {
						$term = get_term( $term_id, ATBDP_CATEGORY );
						$name = ( $term && ! is_wp_error( $term ) ) ? hbl_lvs_plain_text( $term->name ) : sprintf( '#%d', $term_id );
					} else {
						$name = __( 'All listings (site-wide fallback)', 'hbl-lvs' );
					}
					$is_usable = (int) $data['n'] >= $min;
					?>
					<tr>
						<td data-label="<?php esc_attr_e( 'Category', 'hbl-lvs' ); ?>"><strong><?php echo esc_html( $name ); ?></strong></td>
						<td data-label="<?php esc_attr_e( 'Listings in pool', 'hbl-lvs' ); ?>" class="hbl-lvs-num"><?php echo esc_html( number_format_i18n( (int) $data['n'] ) ); ?></td>
						<td data-label="<?php esc_attr_e( 'Average views', 'hbl-lvs' ); ?>"><?php echo esc_html( number_format_i18n( round( (float) $data['avg'], 1 ), 1 ) ); ?></td>
						<td data-label="<?php esc_attr_e( 'Usable', 'hbl-lvs' ); ?>">
							<?php if ( $is_usable ) : ?>
								<span class="hbl-badge hbl-badge-on"><span class="hbl-badge-dot"></span> <?php esc_html_e( 'Yes', 'hbl-lvs' ); ?></span>
							<?php else : ?>
								<span class="hbl-badge hbl-badge-off"><span class="hbl-badge-dot"></span> <?php esc_html_e( 'Climbs to parent', 'hbl-lvs' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
	</div>
	<?php
	hbl_lvs_step_close();
}

// ── Tab: Tools ────────────────────────────────────────────────────────────────

/**
 * Manual actions, diagnostics, and the reset.
 */
function hbl_lvs_tab_tools(): void {
	global $wpdb;

	$current = hbl_lvs_current_quarter_key();
	$due     = hbl_lvs_next_rollup_target();

	hbl_lvs_step_open(
		1,
		__( 'Run a rollup', 'hbl-lvs' ),
		__( 'Normally automatic — this is for checking output or re-running after a fix.', 'hbl-lvs' )
	);
	?>
	<p class="description">
		<?php
		printf(
			/* translators: 1: due quarter, 2: current quarter */
			esc_html__( '%1$s runs on its own once the quarter has ended. Entering %2$s instead produces a preview of the quarter still in progress — useful for checking the numbers, and it will not stop the real run happening later.', 'hbl-lvs' ),
			esc_html( $due ),
			esc_html( $current )
		);
		?>
	</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hbl-lvs-toolbar">
		<?php wp_nonce_field( 'hbl_lvs_admin' ); ?>
		<input type="hidden" name="action" value="hbl_lvs_admin_action">
		<input type="hidden" name="task" value="run_rollup">
		<div class="hbl-lvs-field">
			<label for="hbl-lvs-run-quarter"><?php esc_html_e( 'Quarter', 'hbl-lvs' ); ?></label>
			<input id="hbl-lvs-run-quarter" type="text" name="quarter" class="hbl-lvs-input" value="<?php echo esc_attr( $due ); ?>">
		</div>
		<div class="hbl-lvs-actions">
			<button class="button button-primary"><?php esc_html_e( 'Run rollup', 'hbl-lvs' ); ?></button>
		</div>
	</form>
	<?php
	hbl_lvs_step_close();

	hbl_lvs_step_open(
		2,
		__( 'Maintenance', 'hbl-lvs' ),
		__( 'All of these run automatically on the daily schedule; the buttons just do it now.', 'hbl-lvs' )
	);
	?>
	<div class="hbl-lvs-actions">
		<?php hbl_lvs_action_button( 'create_fields', __( 'Create / repair DoubleScale fields', 'hbl-lvs' ), 'button' ); ?>
		<?php hbl_lvs_action_button( 'snapshot', __( 'Capture snapshot now', 'hbl-lvs' ), 'button' ); ?>
		<?php hbl_lvs_action_button( 'prune', __( 'Prune old events', 'hbl-lvs' ), 'button' ); ?>
	</div>
	<?php
	hbl_lvs_step_close();

	$server_on = hbl_lvs_server_tracking_enabled();

	hbl_lvs_step_open(
		3,
		__( 'How views are counted', 'hbl-lvs' ),
		__( 'Two independent sources, de-duplicated into one figure.', 'hbl-lvs' ),
		$server_on ? 'hbl-badge-on' : 'hbl-badge-warn',
		$server_on ? __( 'Server-side On', 'hbl-lvs' ) : __( 'Server-side Off', 'hbl-lvs' )
	);
	?>
	<p class="description">
		<?php esc_html_e( 'Directorist counts a view using JavaScript that only runs when its own single-listing template rendered the page. If your listing pages come from a page builder or a custom theme template, that script never loads and Directorist records nothing at all — which is invisible until a quarterly report comes back empty.', 'hbl-lvs' ); ?>
	</p>
	<p class="description">
		<?php esc_html_e( 'Server-side tracking records the view as the page is served instead, so it works whatever renders the page. Both sources write through the same de-duplication key, so running them together never double-counts. Leave this on unless you have specifically confirmed Directorist\'s own tracking works on your listing pages.', 'hbl-lvs' ); ?>
	</p>
	<div class="hbl-lvs-actions">
		<?php
		hbl_lvs_action_button(
			'toggle_server_tracking',
			$server_on ? __( 'Turn server-side tracking off', 'hbl-lvs' ) : __( 'Turn server-side tracking on', 'hbl-lvs' ),
			$server_on ? 'button' : 'button button-primary'
		);
		?>
	</div>
	<?php
	hbl_lvs_step_close();

	hbl_lvs_step_open( 4, __( 'Diagnostics', 'hbl-lvs' ), __( 'Current configuration and storage.', 'hbl-lvs' ) );
	?>
	<table class="hbl-lvs-defs">
		<tbody>
			<?php
			foreach ( [ 'events', 'snapshots', 'stats' ] as $t ) {
				$name = hbl_lvs_table( $t );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				printf(
					'<tr><th>%s</th><td>%s rows</td></tr>',
					esc_html( $name ),
					esc_html( number_format_i18n( $count ) )
				);
			}
			?>
			<tr><th><?php esc_html_e( 'Plugin version', 'hbl-lvs' ); ?></th><td><?php echo esc_html( HBL_LVS_VERSION ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Schema version', 'hbl-lvs' ); ?></th><td><?php echo esc_html( (string) get_option( HBL_LVS_OPT_DB_VERSION ) ); ?></td></tr>
			<tr>
				<th><?php esc_html_e( 'Server-side tracking', 'hbl-lvs' ); ?></th>
				<td>
					<?php
					echo hbl_lvs_server_tracking_enabled()
						? esc_html__( 'On — views recorded however the page is rendered', 'hbl-lvs' )
						: esc_html__( 'Off — relies on Directorist\'s JavaScript only', 'hbl-lvs' );
					?>
				</td>
			</tr>
			<tr><th><?php esc_html_e( 'De-duplication window', 'hbl-lvs' ); ?></th><td><?php echo esc_html( (int) apply_filters( 'hbl_lvs_dedupe_window', 1800 ) / 60 . ' ' . __( 'minutes per visitor, per listing', 'hbl-lvs' ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Minimum comparison pool', 'hbl-lvs' ); ?></th><td><?php echo esc_html( (int) apply_filters( 'hbl_lvs_min_comparison_pool', 5 ) . ' ' . __( 'listings', 'hbl-lvs' ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Above / below average threshold', 'hbl-lvs' ); ?></th><td><?php echo esc_html( round( (float) apply_filters( 'hbl_lvs_band_threshold', 0.10 ) * 100 ) . '%' ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Event retention', 'hbl-lvs' ); ?></th><td><?php echo esc_html( (int) apply_filters( 'hbl_lvs_retention_months', 24 ) . ' ' . __( 'months', 'hbl-lvs' ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Creates missing contacts', 'hbl-lvs' ); ?></th><td><?php echo apply_filters( 'hbl_lvs_create_missing_contacts', false ) ? esc_html__( 'Yes', 'hbl-lvs' ) : esc_html__( 'No — existing contacts are updated, none are created', 'hbl-lvs' ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Site timezone', 'hbl-lvs' ); ?></th><td><?php echo esc_html( wp_timezone_string() ); ?> <span class="hbl-lvs-muted"><?php esc_html_e( '(quarter boundaries follow this)', 'hbl-lvs' ); ?></span></td></tr>
		</tbody>
	</table>
	<?php
	hbl_lvs_step_close();
	?>
	<div class="hbl-lvs-danger">
		<h2><?php esc_html_e( 'Delete all view data', 'hbl-lvs' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Removes every logged view, snapshot and computed result, then takes a fresh baseline. View history cannot be reconstructed afterwards — Directorist never stored it, which is the whole reason this plugin exists. Intended for clearing test data on a staging site.', 'hbl-lvs' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			  onsubmit="return confirm('<?php echo esc_js( __( 'This permanently deletes all logged view history and cannot be undone. Continue?', 'hbl-lvs' ) ); ?>');">
			<?php wp_nonce_field( 'hbl_lvs_admin' ); ?>
			<input type="hidden" name="action" value="hbl_lvs_admin_action">
			<input type="hidden" name="task" value="reset_all">
			<button class="button button-danger"><?php esc_html_e( 'Delete all view data', 'hbl-lvs' ); ?></button>
		</form>
	</div>
	<?php
}

// ── Admin actions ─────────────────────────────────────────────────────────────

/**
 * Handles every button on the screen.
 */
function hbl_lvs_handle_admin_action(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'hbl-lvs' ) );
	}

	check_admin_referer( 'hbl_lvs_admin' );

	$task   = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
	$notice = '';
	$tab    = 'overview';

	switch ( $task ) {
		case 'create_fields':
			$tab = 'tags';
			$res = hbl_lvs_ensure_custom_fields();
			if ( $res['error'] ) {
				$notice = 'error:' . $res['error'];
			} else {
				update_option( 'hbl_lvs_fields_ready', 1, false );
				$notice = sprintf(
					/* translators: 1: created, 2: existing */
					__( '%1$d field(s) created, %2$d already existed.', 'hbl-lvs' ),
					$res['created'],
					$res['existing']
				);
			}
			break;

		case 'snapshot':
			$tab    = 'tools';
			$rows   = hbl_lvs_capture_snapshot( hbl_lvs_current_quarter_key() );
			$notice = sprintf(
				/* translators: %d: rows */
				__( 'Snapshot captured for %d listing(s).', 'hbl-lvs' ),
				$rows
			);
			break;

		case 'prune':
			$tab    = 'tools';
			$rows   = hbl_lvs_prune_events();
			$notice = sprintf(
				/* translators: %d: rows */
				__( '%d old event row(s) removed.', 'hbl-lvs' ),
				$rows
			);
			break;

		case 'run_rollup':
			$tab     = 'results';
			$quarter = isset( $_POST['quarter'] ) ? sanitize_text_field( wp_unslash( $_POST['quarter'] ) ) : '';
			$quarter = $quarter ? $quarter : hbl_lvs_next_rollup_target();
			hbl_lvs_start_rollup( $quarter );
			$notice = sprintf(
				/* translators: %s: quarter */
				__( 'Rollup started for %s. It runs in the background; refresh in a moment.', 'hbl-lvs' ),
				$quarter
			);
			break;

		case 'cancel_run':
			delete_option( HBL_LVS_OPT_RUN );
			$notice = __( 'In-progress run cancelled.', 'hbl-lvs' );
			break;

		case 'toggle_server_tracking':
			$tab = 'tools';
			$now = hbl_lvs_server_tracking_enabled() ? '0' : '1';
			update_option( 'hbl_lvs_server_tracking', $now, false );
			$notice = '1' === $now
				? __( 'Server-side view tracking enabled.', 'hbl-lvs' )
				: __( 'Server-side view tracking disabled — views will only be recorded if Directorist\'s own JavaScript fires.', 'hbl-lvs' );
			break;

		case 'reset_all':
			$tab    = 'tools';
			$notice = hbl_lvs_reset_all();
			break;
	}

	wp_safe_redirect(
		hbl_lvs_admin_url(
			[
				'tab'        => $tab,
				'hbl_notice' => rawurlencode( $notice ),
			]
		)
	);
	exit;
}

/**
 * Wipes all collected data and re-baselines. Staging use.
 *
 * @return string Notice text.
 */
function hbl_lvs_reset_all(): string {
	global $wpdb;

	foreach ( hbl_lvs_available_quarters() as $q ) {
		delete_option( hbl_lvs_aggregate_option( $q ) );
	}

	foreach ( [ 'events', 'snapshots', 'stats' ] as $t ) {
		$name = hbl_lvs_table( $t );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "TRUNCATE TABLE {$name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	delete_option( HBL_LVS_OPT_RUN );
	delete_option( HBL_LVS_OPT_LAST_ROLLUP );
	delete_option( 'hbl_lvs_last_summary' );
	delete_option( HBL_LVS_OPT_LAST_SNAPSHOT );

	hbl_lvs_capture_snapshot( hbl_lvs_current_quarter_key() );

	return __( 'All view data deleted and a fresh baseline snapshot taken.', 'hbl-lvs' );
}

/**
 * Streams the selected quarter's results as CSV, including the exact field
 * values that were written to each contact.
 */
function hbl_lvs_handle_export(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'hbl-lvs' ) );
	}

	check_admin_referer( 'hbl_lvs_export' );

	global $wpdb;

	$quarter = isset( $_GET['quarter'] ) ? sanitize_text_field( wp_unslash( $_GET['quarter'] ) ) : hbl_lvs_current_quarter_key();
	$stats   = hbl_lvs_table( 'stats' );
	$agg     = get_option( hbl_lvs_aggregate_option( $quarter ), [] );
	$agg     = is_array( $agg ) ? $agg : [];

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT s.*, p.post_title FROM {$stats} s
			 LEFT JOIN {$wpdb->posts} p ON p.ID = s.listing_id
			 WHERE s.period_key = %s ORDER BY s.views DESC",
			$quarter
		),
		ARRAY_A
	);

	// Discard anything already buffered (a stray BOM or notice from another
	// plugin would otherwise corrupt the first cell of the download).
	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=listing-views-' . $quarter . '.csv' );

	$out = fopen( 'php://output', 'w' );

	fputcsv(
		$out,
		[
			'listing_id',
			'listing',
			'quarter',
			'views',
			'tier',
			'subcategory',
			'pool_size',
			'pool_average',
			'vs_average',
			'band',
			'period_label',
			'contact_email',
			'is_primary',
			'push_status',
		]
	);

	foreach ( $rows as $row ) {
		$values = hbl_lvs_build_field_values( $row, $agg, $quarter );

		$push = 'pending';
		if ( ! (int) $row['is_primary'] ) {
			$push = 'not primary';
		} elseif ( 1 === (int) $row['pushed'] ) {
			$push = 'written';
		} elseif ( 2 === (int) $row['pushed'] ) {
			$push = 'no contact';
		}

		fputcsv(
			$out,
			[
				$row['listing_id'],
				$row['post_title'],
				$quarter,
				$values['listing-views-last-quarter'],
				$values['listing-plan-tier'],
				$values['category-comparison-name'],
				$values['category-comparison-count'],
				$values['category-avg-views-last-quarter'],
				$values['views-vs-category-percent'],
				$values['views-performance-band'],
				$values['views-quarter-label'],
				$row['contact_email'],
				(int) $row['is_primary'] ? 'yes' : 'no',
				$push,
			]
		);
	}

	fclose( $out );
	exit;
}
