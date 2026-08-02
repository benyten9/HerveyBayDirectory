<?php
/**
 * Plugin Name:  HBL – Directorist Expiry Handler
 * Plugin URI:
 * Description:  Intercepts Directorist's expiry mechanism for Silver/Gold
 *               listings. On expiry the listing stays publicly visible (published).
 *               At day 60 post-expiry it is automatically downgraded to Bronze:
 *               the pricing plan is swapped, and the premium-only fields
 *               (website URL, contact email, extra gallery images) are stripped.
 *               A QuillCRM automation trigger stub is included for wiring up later.
 * Version:      1.1.10
 * Requires PHP: 7.4
 * Author:       HBL
 * License:      GPL-2.0+
 * Text Domain:  hbl-expiry
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * QUICK-START FOR DEVELOPERS
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * 1. PLAN DETECTION
 *    By default the plugin finds Silver/Gold plans by matching plan post-titles
 *    that contain the words "silver" or "gold" (case-insensitive substring).
 *    If your titles differ, hard-code IDs via filters – e.g. in functions.php:
 *
 *      add_filter( 'hbl_expiry_premium_plan_ids', fn() => [ 42, 57 ] );
 *      add_filter( 'hbl_expiry_bronze_plan_id',   fn() => 11 );
 *
 * 2. DOWNGRADE THRESHOLD
 *    Defaults to 60 days (matching the "permanently delete" setting). Override:
 *
 *      add_filter( 'hbl_expiry_downgrade_days', fn() => 60 );
 *
 * 3. BRONZE IMAGE LIMIT
 *    Defaults to 1. Override:
 *
 *      add_filter( 'hbl_bronze_image_limit', fn() => 3 );
 *
 * 4. QUILLCRM INTEGRATION
 *    Hook into the stub action to make real API calls:
 *
 *      add_action( 'hbl_quillcrm_fire_tag',
 *          function( int $owner_id, string $tag, int $listing_id ) {
 *              // e.g. QuillCRM_Client::addTag( $owner_id, $tag );
 *          }, 10, 3 );
 *
 * 5. ENABLE LOGGING
 *    Logging is always written to the WordPress debug log. To activate it:
 *      - Set WP_DEBUG = true and WP_DEBUG_LOG = true in wp-config.php, OR
 *      - Define HBL_EXPIRY_DEBUG = true in wp-config.php to log independently
 *        of WP_DEBUG (useful on production where WP_DEBUG must stay off).
 *
 *    Log entries are prefixed with [HBL Expiry] and appear in wp-content/debug.log.
 *
 * 6. DEVELOPER HOOKS (all fired with relevant args)
 *    hbl_premium_listing_kept_visible    ( $listing_id, $plan_id )
 *    hbl_before_listing_downgrade        ( $listing_id, $original_plan_id, $expired_ts )
 *    hbl_expiry_should_downgrade (filter) ( $bool, $listing_id, $plan_id, $expired_ts )
 *    hbl_listing_downgraded_to_bronze    ( $listing_id, $original_plan_id, $bronze_plan_id )
 *    hbl_quillcrm_fire_tag               ( $owner_id, $tag, $listing_id )
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * HOW IT WORKS
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Directorist runs an internal cron (atbdp_cron, every 30 min) that:
 *   (a) Sets listings whose _expiry_date has passed → post_status = 'expired'
 *       and fires do_action( 'atbdp_listing_expired', $id ).
 *   (b) Deletes/trashes listings in 'expired' status once _deletion_date passes.
 *
 * This plugin hooks into step (a) to:
 *   – Detect Silver/Gold listings (via _fm_plans meta → pricing plan title).
 *   – Record the expiry timestamp in _hbl_expired_on.
 *   – Delete _deletion_date (prevents step (b) from ever acting).
 *   – Immediately restore post_status to 'publish'.
 *
 * A second filter (directorist_update_listings_expired_status_query_arguments)
 * excludes listings already carrying _hbl_expired_on from future expiry passes,
 * preventing the cron from re-expiring them.
 *
 * The downgrade sweep runs two ways:
 *   – WP-Cron fires hbl_expiry_downgrade_daily once per day.
 *   – A fallback runs on every front-end/admin page load, throttled to at most
 *     once per hour via a transient. This ensures the sweep runs automatically
 *     even on low-traffic sites where WP-Cron is unreliable.
 *
 * @package HBL_DirectoristExpiry
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ──────────────────────────────────────────────────────────────────

define( 'HBL_EXPIRY_VERSION', '1.1.10' );

/**
 * Post-meta key that stores the Unix timestamp when a Silver/Gold listing
 * was intercepted at expiry.  Presence of this key signals that the listing
 * is under HBL management.
 */
define( 'HBL_EXPIRY_META_ON', '_hbl_expired_on' );

/**
 * Post-meta key that stores the pricing-plan post ID (from _fm_plans) that
 * was active at the time of expiry.  Used for QuillCRM context and restore.
 */
define( 'HBL_EXPIRY_META_PLAN', '_hbl_original_plan' );

/** WP-Cron hook name for the daily downgrade sweep. */
define( 'HBL_EXPIRY_CRON_HOOK', 'hbl_expiry_downgrade_daily' );

/**
 * Transient key used to throttle the page-load fallback sweep to once per hour.
 * Prevents a DB query on every single request.
 */
define( 'HBL_EXPIRY_FALLBACK_LOCK', 'hbl_expiry_fallback_ran' );

// ── Plugin lifecycle hooks ─────────────────────────────────────────────────────

register_activation_hook( __FILE__, 'hbl_expiry_on_activate' );
register_deactivation_hook( __FILE__, 'hbl_expiry_on_deactivate' );
register_uninstall_hook( __FILE__, 'hbl_expiry_on_uninstall' );

// ── Bootstrap ──────────────────────────────────────────────────────────────────

add_action( 'plugins_loaded', 'hbl_expiry_boot', 0 );

/**
 * Wires up all WordPress/Directorist hooks.
 */
function hbl_expiry_boot(): void {

	if ( ! defined( 'ATBDP_POST_TYPE' ) ) {
		return;
	}

	// ① Intercept Directorist's expiry action for Silver/Gold listings.
	add_action( 'atbdp_listing_expired', 'hbl_expiry_on_listing_expired', 10, 1 );

	// ② Keep already-managed listings out of Directorist's expiry query.
	// NOTE: The filter name in Directorist core has a trailing space — this is intentional.
	add_filter(
		'directorist_update_listings_expired_status_query_arguments ',
		'hbl_expiry_exclude_managed_from_expiry_query'
	);

	// ③ WP-Cron daily sweep (belt-and-suspenders alongside on_activate).
	if ( ! wp_next_scheduled( HBL_EXPIRY_CRON_HOOK ) ) {
		wp_schedule_event( time(), 'daily', HBL_EXPIRY_CRON_HOOK );
	}
	add_action( HBL_EXPIRY_CRON_HOOK, 'hbl_expiry_run_downgrade_cron' );

	// ④ Fallback sweep: runs on every page load but throttled to once per hour
	//    via transient. Guarantees automatic downgrades even when WP-Cron stalls.
	add_action( 'wp', 'hbl_expiry_maybe_run_fallback_sweep' );
	add_action( 'admin_init', 'hbl_expiry_maybe_run_fallback_sweep' );

	// ⑤ One-time scan for listings that expired before this plugin was installed.
	if ( ! get_option( 'hbl_expiry_initial_scan_done' ) ) {
		add_action( 'init', 'hbl_expiry_initial_scan', 20 );
	}
}

// ══════════════════════════════════════════════════════════════════════════════
// LOGGING HELPER
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Writes a debug log entry prefixed with [HBL Expiry].
 *
 * Active when WP_DEBUG_LOG is true OR when HBL_EXPIRY_DEBUG is defined as true
 * in wp-config.php (allows production logging without enabling WP_DEBUG).
 *
 * @param string $message
 */
function hbl_expiry_log( string $message ): void {
	$debug_log     = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
	$explicit_flag = defined( 'HBL_EXPIRY_DEBUG' ) && HBL_EXPIRY_DEBUG;

	if ( ! $debug_log && ! $explicit_flag ) {
		return;
	}

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log( '[HBL Expiry] ' . $message );
}

// ══════════════════════════════════════════════════════════════════════════════
// PLAN HELPERS
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Returns the pricing-plan post ID stored on a listing, or 0.
 *
 * @param  int $listing_id
 * @return int Plan post ID, or 0 if none.
 */
function hbl_expiry_get_listing_plan_id( int $listing_id ): int {
	return (int) get_post_meta( $listing_id, '_fm_plans', true );
}

/**
 * Returns the post IDs of all "premium" (Silver / Gold) pricing plans.
 *
 * Resolution order:
 *  1. `hbl_expiry_premium_plan_ids` filter (allows hard-coding IDs).
 *  2. Auto-detection: any atbdp_pricing_plans post whose title contains
 *     "silver" or "gold" (case-insensitive substring match).
 *
 * @return int[]
 */
function hbl_expiry_get_premium_plan_ids(): array {
	static $cache = null;

	if ( null !== $cache ) {
		return $cache;
	}

	$overridden = apply_filters( 'hbl_expiry_premium_plan_ids', [] );
	if ( ! empty( $overridden ) ) {
		$cache = array_map( 'absint', (array) $overridden );
		return $cache;
	}

	$all     = get_posts( [
		'post_type'      => 'atbdp_pricing_plans',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'fields'         => 'ids',
		'no_found_rows'  => true,
	] );
	$premium = [];

	foreach ( $all as $plan_id ) {
		$title = strtolower( trim( get_the_title( (int) $plan_id ) ) );
		if ( false !== strpos( $title, 'silver' ) || false !== strpos( $title, 'gold' ) ) {
			$premium[] = (int) $plan_id;
		}
	}

	$cache = $premium;
	return $cache;
}

/**
 * Returns the Bronze pricing-plan post ID, or 0 if not found.
 *
 * @return int
 */
function hbl_expiry_get_bronze_plan_id(): int {
	static $cache = null;

	if ( null !== $cache ) {
		return $cache;
	}

	$overridden = (int) apply_filters( 'hbl_expiry_bronze_plan_id', 0 );
	if ( $overridden ) {
		$cache = $overridden;
		return $cache;
	}

	$all = get_posts( [
		'post_type'      => 'atbdp_pricing_plans',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'fields'         => 'ids',
		'no_found_rows'  => true,
	] );

	foreach ( $all as $plan_id ) {
		$title = strtolower( trim( get_the_title( (int) $plan_id ) ) );
		if ( false !== strpos( $title, 'bronze' ) ) {
			$cache = (int) $plan_id;
			return $cache;
		}
	}

	hbl_expiry_log( 'WARNING: No Bronze plan found by title. Check plan titles or use hbl_expiry_bronze_plan_id filter. Downgrade will be SKIPPED until this is resolved.' );
	$cache = 0;
	return $cache;
}

/**
 * Returns true when $plan_id belongs to a Silver or Gold plan.
 *
 * @param  int $plan_id
 * @return bool
 */
function hbl_expiry_is_premium_plan( int $plan_id ): bool {
	if ( ! $plan_id ) {
		return false;
	}
	return in_array( $plan_id, hbl_expiry_get_premium_plan_ids(), true );
}

// ══════════════════════════════════════════════════════════════════════════════
// ① INTERCEPT DIRECTORIST EXPIRY
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Callback for the `atbdp_listing_expired` action.
 *
 * For Silver/Gold listings:
 *  - Records the expiry moment in _hbl_expired_on.
 *  - Records the plan ID in _hbl_original_plan.
 *  - Removes _deletion_date so Directorist's cleanup cron never acts.
 *  - Restores post_status to 'publish'.
 *
 * @param int $listing_id
 */
function hbl_expiry_on_listing_expired( int $listing_id ): void {

	if ( get_post_type( $listing_id ) !== ATBDP_POST_TYPE ) {
		return;
	}

	$plan_id = hbl_expiry_get_listing_plan_id( $listing_id );

	$listing_title = get_the_title( $listing_id );

	hbl_expiry_log( sprintf(
		'atbdp_listing_expired fired for listing %d ("%s") — plan ID: %d',
		$listing_id,
		$listing_title,
		$plan_id
	) );

	if ( ! $plan_id ) {
		hbl_expiry_log( sprintf( 'Listing %d ("%s") has no _fm_plans meta — skipping HBL management.', $listing_id, $listing_title ) );
		return;
	}

	if ( ! hbl_expiry_is_premium_plan( $plan_id ) ) {
		hbl_expiry_log( sprintf( 'Listing %d ("%s") is plan %d (not Silver/Gold) — leaving Directorist to handle it.', $listing_id, $listing_title, $plan_id ) );
		return;
	}

	// Guard: already under HBL management (e.g. if the hook fires twice).
	if ( get_post_meta( $listing_id, HBL_EXPIRY_META_ON, true ) ) {
		hbl_expiry_log( sprintf( 'Listing %d ("%s") already has _hbl_expired_on — duplicate fire ignored.', $listing_id, $listing_title ) );
		return;
	}

	// ── Record expiry ─────────────────────────────────────────────────────────
	update_post_meta( $listing_id, HBL_EXPIRY_META_ON,   time() );
	update_post_meta( $listing_id, HBL_EXPIRY_META_PLAN, $plan_id );
	delete_post_meta( $listing_id, '_deletion_date' );
	// Set _never_expire so Directorist's cron query excludes this listing
	// on every subsequent run (belt-and-suspenders alongside the filter).
	update_post_meta( $listing_id, '_never_expire', 1 );

	hbl_expiry_log( sprintf(
		'Listing %d ("%s") plan %d intercepted at expiry — _deletion_date removed, restoring to publish.',
		$listing_id,
		$listing_title,
		$plan_id
	) );

	// ── Restore to publish ───────────────────────────────────────────────────
	remove_action( 'atbdp_listing_expired', 'hbl_expiry_on_listing_expired', 10 );

	wp_update_post( [
		'ID'          => $listing_id,
		'post_status' => 'publish',
	] );

	add_action( 'atbdp_listing_expired', 'hbl_expiry_on_listing_expired', 10, 1 );

	hbl_expiry_log( sprintf( 'Listing %d ("%s") restored to publish. Grace period starts now.', $listing_id, $listing_title ) );

	do_action( 'hbl_premium_listing_kept_visible', $listing_id, $plan_id );
}

// ══════════════════════════════════════════════════════════════════════════════
// ② EXCLUDE MANAGED LISTINGS FROM SUBSEQUENT DIRECTORIST EXPIRY PASSES
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Callback for `directorist_update_listings_expired_status_query_arguments`.
 *
 * @param  array $args
 * @return array
 */
function hbl_expiry_exclude_managed_from_expiry_query( array $args ): array {

	if ( ! isset( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
		$args['meta_query'] = [ 'relation' => 'AND' ];
	}

	$args['meta_query']['hbl_not_managed'] = [
		'key'     => HBL_EXPIRY_META_ON,
		'compare' => 'NOT EXISTS',
	];

	return $args;
}

// ══════════════════════════════════════════════════════════════════════════════
// ③ DOWNGRADE SWEEP – CRON + PAGE-LOAD FALLBACK
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Page-load fallback sweep.
 *
 * WP-Cron is only triggered by page visits and can miss its schedule on quiet
 * sites. This function runs on every front-end (`wp`) and admin (`admin_init`)
 * page load, but uses a 1-hour transient as a lock so it never queries the DB
 * more than once per hour.
 */
function hbl_expiry_maybe_run_fallback_sweep(): void {

	if ( get_transient( HBL_EXPIRY_FALLBACK_LOCK ) ) {
		return; // Already ran within the last hour.
	}

	// Set the lock BEFORE running so concurrent requests don't double-fire.
	set_transient( HBL_EXPIRY_FALLBACK_LOCK, 1, HOUR_IN_SECONDS );

	hbl_expiry_run_downgrade_cron();
}

/**
 * Core downgrade sweep — called by both WP-Cron and the page-load fallback.
 *
 * Queries for Silver/Gold listings whose _hbl_expired_on timestamp predates
 * the configured threshold (default: 60 days) and downgrades each one.
 */
function hbl_expiry_run_downgrade_cron(): void {

	$threshold_days = (int) apply_filters( 'hbl_expiry_downgrade_days', 60 );
	$cutoff_ts      = time() - ( $threshold_days * DAY_IN_SECONDS );

	$listings = get_posts( [
		'post_type'      => ATBDP_POST_TYPE,
		'post_status'    => [ 'publish', 'expired' ],
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => [
			'relation' => 'AND',
			[
				'key'     => HBL_EXPIRY_META_ON,
				'value'   => $cutoff_ts,
				'compare' => '<=',
				'type'    => 'NUMERIC',
			],
		],
	] );

	foreach ( $listings as $listing_id ) {
		hbl_expiry_downgrade_to_bronze( (int) $listing_id );
	}
}

// ══════════════════════════════════════════════════════════════════════════════
// DOWNGRADE LOGIC
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Performs the Bronze downgrade for a single listing.
 *
 * @param int $listing_id
 */
function hbl_expiry_downgrade_to_bronze( int $listing_id ): void {

	$bronze_plan_id   = hbl_expiry_get_bronze_plan_id();
	$original_plan_id = (int) get_post_meta( $listing_id, HBL_EXPIRY_META_PLAN, true );
	$expired_ts       = (int) get_post_meta( $listing_id, HBL_EXPIRY_META_ON,   true );
	$listing_title    = get_the_title( $listing_id );

	hbl_expiry_log( sprintf(
		'Attempting downgrade for listing %d ("%s") (original plan %d, expired %s UTC).',
		$listing_id,
		$listing_title,
		$original_plan_id,
		gmdate( 'Y-m-d H:i:s', $expired_ts )
	) );

	if ( ! $bronze_plan_id ) {
		hbl_expiry_log( sprintf(
			'ERROR: Cannot downgrade listing %d ("%s") — Bronze plan not found. Skipping. ' .
			'Fix: add a pricing plan with "Bronze" in the title, or set hbl_expiry_bronze_plan_id filter.',
			$listing_id,
			$listing_title
		) );
		return;
	}

	$proceed = (bool) apply_filters(
		'hbl_expiry_should_downgrade',
		true,
		$listing_id,
		$original_plan_id,
		$expired_ts
	);

	if ( ! $proceed ) {
		hbl_expiry_log( sprintf( 'Downgrade for listing %d ("%s") cancelled by hbl_expiry_should_downgrade filter.', $listing_id, $listing_title ) );
		return;
	}

	do_action( 'hbl_before_listing_downgrade', $listing_id, $original_plan_id, $expired_ts );

	// ── Step 1: Switch pricing plan to Bronze ─────────────────────────────────
	update_post_meta( $listing_id, '_fm_plans', $bronze_plan_id );
	hbl_expiry_log( sprintf( 'Listing %d ("%s"): _fm_plans updated from %d to Bronze plan %d.', $listing_id, $listing_title, $original_plan_id, $bronze_plan_id ) );

	// ── Step 2: Strip clickable website URL ───────────────────────────────────
	$old_website = get_post_meta( $listing_id, '_website', true );
	if ( $old_website ) {
		update_post_meta( $listing_id, '_hbl_stripped_website', $old_website );
		delete_post_meta( $listing_id, '_website' );
		hbl_expiry_log( sprintf( 'Listing %d ("%s"): _website stripped and archived.', $listing_id, $listing_title ) );
	}

	// ── Step 3: Strip clickable contact email ─────────────────────────────────
	$old_email = get_post_meta( $listing_id, '_email', true );
	if ( $old_email ) {
		update_post_meta( $listing_id, '_hbl_stripped_email', $old_email );
		delete_post_meta( $listing_id, '_email' );
		hbl_expiry_log( sprintf( 'Listing %d ("%s"): _email stripped and archived.', $listing_id, $listing_title ) );
	}

	// ── Step 4: Truncate gallery images to Bronze limit ───────────────────────
	hbl_expiry_strip_extra_images( $listing_id );

	// ── Step 5: Make listing never expire (Bronze behaviour) ──────────────────
	update_post_meta( $listing_id, '_never_expire', 1 );
	delete_post_meta( $listing_id, '_expiry_date' );
	delete_post_meta( $listing_id, '_deletion_date' );
	delete_post_meta( $listing_id, '_renewal_reminder_sent' );

	// ── Step 6: Ensure public visibility ──────────────────────────────────────
	if ( 'publish' !== get_post_status( $listing_id ) ) {
		wp_update_post( [
			'ID'          => $listing_id,
			'post_status' => 'publish',
		] );
	}

	// ── Step 7: QuillCRM automation trigger (stub) ────────────────────────────
	hbl_expiry_fire_quillcrm_tag( $listing_id, $original_plan_id );

	// ── Step 8: Clean up HBL tracking meta ────────────────────────────────────
	delete_post_meta( $listing_id, HBL_EXPIRY_META_ON );
	delete_post_meta( $listing_id, HBL_EXPIRY_META_PLAN );

	hbl_expiry_log( sprintf(
		'SUCCESS: Listing %d ("%s") fully downgraded to Bronze (plan %d). Original plan %d archived.',
		$listing_id,
		$listing_title,
		$bronze_plan_id,
		$original_plan_id
	) );

	do_action( 'hbl_listing_downgraded_to_bronze', $listing_id, $original_plan_id, $bronze_plan_id );
}

/**
 * Truncates a listing's gallery to the allowed Bronze image count.
 *
 * @param int $listing_id
 */
function hbl_expiry_strip_extra_images( int $listing_id ): void {

	/** @param int $limit  Defaults to 1. */
	$limit  = (int) apply_filters( 'hbl_bronze_image_limit', 1, $listing_id );
	$images = get_post_meta( $listing_id, '_listing_img', true );

	if ( ! is_array( $images ) ) {
		hbl_expiry_log( sprintf( 'Listing %d ("%s"): _listing_img is not an array — skipping image strip.', $listing_id, get_the_title( $listing_id ) ) );
		return;
	}

	hbl_expiry_log( sprintf( 'Listing %d ("%s"): %d gallery image(s) found, Bronze limit is %d.', $listing_id, get_the_title( $listing_id ), count( $images ), $limit ) );

	if ( count( $images ) <= $limit ) {
		return;
	}

	$kept   = array_slice( $images, 0, $limit );
	$pruned = array_slice( $images, $limit );

	update_post_meta( $listing_id, '_listing_img',         $kept );
	update_post_meta( $listing_id, '_hbl_stripped_images', $pruned );

	hbl_expiry_log( sprintf( 'Listing %d ("%s"): %d image(s) stripped and archived.', $listing_id, get_the_title( $listing_id ), count( $pruned ) ) );
}

// ══════════════════════════════════════════════════════════════════════════════
// QUILLCRM INTEGRATION STUB
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Placeholder: fire a QuillCRM tag / automation trigger on downgrade.
 *
 * @param int $listing_id
 * @param int $original_plan_id
 */
function hbl_expiry_fire_quillcrm_tag( int $listing_id, int $original_plan_id ): void {

	$owner_id = (int) get_post_field( 'post_author', $listing_id );

	$tag = (string) apply_filters(
		'hbl_quillcrm_downgrade_tag',
		'listing-downgraded-to-bronze',
		$listing_id,
		$owner_id
	);

	do_action( 'hbl_quillcrm_fire_tag', $owner_id, $tag, $listing_id );

	hbl_expiry_log( sprintf(
		'QuillCRM stub — tag "%s" queued for user %d (listing %d "%s", original plan %d).',
		$tag,
		$owner_id,
		$listing_id,
		get_the_title( $listing_id ),
		$original_plan_id
	) );
}

// ══════════════════════════════════════════════════════════════════════════════
// ONE-TIME INITIAL SCAN
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Runs once on the first page load after the plugin is activated.
 *
 * Finds Silver/Gold listings already in 'expired' status before this plugin
 * was installed and brings them under HBL management.
 */
function hbl_expiry_initial_scan(): void {

	if ( ! defined( 'ATBDP_POST_TYPE' ) ) {
		return;
	}

	$expired_listings = get_posts( [
		'post_type'      => ATBDP_POST_TYPE,
		'post_status'    => 'expired',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => [
			[
				'key'     => HBL_EXPIRY_META_ON,
				'compare' => 'NOT EXISTS',
			],
		],
	] );

	$claimed = 0;
	foreach ( $expired_listings as $listing_id ) {
		$plan_id = hbl_expiry_get_listing_plan_id( (int) $listing_id );

		if ( ! hbl_expiry_is_premium_plan( $plan_id ) ) {
			continue;
		}

		$expiry_date_str = get_post_meta( (int) $listing_id, '_expiry_date', true );
		$expired_ts      = $expiry_date_str ? (int) strtotime( $expiry_date_str ) : time();

		update_post_meta( (int) $listing_id, HBL_EXPIRY_META_ON,   $expired_ts );
		update_post_meta( (int) $listing_id, HBL_EXPIRY_META_PLAN, $plan_id );
		delete_post_meta( (int) $listing_id, '_deletion_date' );

		wp_update_post( [
			'ID'          => (int) $listing_id,
			'post_status' => 'publish',
		] );

		hbl_expiry_log( sprintf(
			'Initial scan: listing %d ("%s") (plan %d) brought under HBL management. Expiry base: %s UTC.',
			(int) $listing_id,
			get_the_title( (int) $listing_id ),
			$plan_id,
			gmdate( 'Y-m-d H:i:s', $expired_ts )
		) );

		$claimed++;
	}

	update_option( 'hbl_expiry_initial_scan_done', true, false );
}

// ══════════════════════════════════════════════════════════════════════════════
// LIFECYCLE CALLBACKS
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Plugin activation callback.
 */
function hbl_expiry_on_activate(): void {
	if ( ! wp_next_scheduled( HBL_EXPIRY_CRON_HOOK ) ) {
		wp_schedule_event( time(), 'daily', HBL_EXPIRY_CRON_HOOK );
	}
}

/**
 * Plugin deactivation callback.
 */
function hbl_expiry_on_deactivate(): void {
	$ts = wp_next_scheduled( HBL_EXPIRY_CRON_HOOK );
	if ( $ts ) {
		wp_unschedule_event( $ts, HBL_EXPIRY_CRON_HOOK );
	}
	delete_transient( HBL_EXPIRY_FALLBACK_LOCK );
}

/**
 * Plugin uninstall callback.
 */
function hbl_expiry_on_uninstall(): void {

	wp_clear_scheduled_hook( HBL_EXPIRY_CRON_HOOK );
	delete_option( 'hbl_expiry_initial_scan_done' );
	delete_transient( HBL_EXPIRY_FALLBACK_LOCK );

	/*
	 * Optional hard-clean: removes all HBL post-meta from the database.
	 * Uncomment ONLY if you are certain the archived field values are not needed.
	 *
	 * global $wpdb;
	 *
	 * $meta_keys = [
	 *     '_hbl_expired_on',
	 *     '_hbl_original_plan',
	 *     '_hbl_stripped_website',
	 *     '_hbl_stripped_email',
	 *     '_hbl_stripped_images',
	 * ];
	 *
	 * foreach ( $meta_keys as $key ) {
	 *     $wpdb->delete(
	 *         $wpdb->postmeta,
	 *         [ 'meta_key' => $key ],
	 *         [ '%s' ]
	 *     );
	 * }
	 */
}
