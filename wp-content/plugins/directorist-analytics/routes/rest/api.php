<?php
/**
 * REST API Routes
 *
 * Defines all REST API endpoints for Directorist Analytics.
 *
 * @package DirectoristAnalytics
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

use DirectoristAnalytics\App\Http\Controllers\TrackingController;
use DirectoristAnalytics\App\Http\Controllers\AnalyticsController;
use DirectoristAnalytics\App\Http\Controllers\SettingsController;
use DirectoristAnalytics\App\Http\Controllers\ExportController;
use DirectoristAnalytics\WpMVC\Routing\Route;

// Public tracking routes (no authentication required)
Route::group(
	'track',
	function () {
		Route::post( 'click', array( TrackingController::class, 'track_click' ) );
		Route::post( 'search', array( TrackingController::class, 'track_search' ) );
		Route::post( 'resolution', array( TrackingController::class, 'update_resolution' ) );
	}
);

// Analytics data routes (admin-only)
Route::group(
	'analytics',
	function () {
		Route::get( 'overview', array( AnalyticsController::class, 'get_overview' ), array( 'admin' ) );
		Route::get( 'listing/{id}', array( AnalyticsController::class, 'get_listing_analytics' ), array( 'admin' ) );
		Route::get( 'authors', array( AnalyticsController::class, 'get_authors' ), array( 'admin' ) );
		Route::get( 'author/{id}', array( AnalyticsController::class, 'get_author_details' ), array( 'admin' ) );
		Route::get( 'settings', array( SettingsController::class, 'get_settings' ), array( 'admin' ) );
		Route::post( 'settings', array( SettingsController::class, 'update_settings' ), array( 'admin' ) );
		Route::post( 'settings/delete-logs', array( SettingsController::class, 'delete_raw_logs' ), array( 'admin' ) );
	}
);

// Admin-specific analytics routes
Route::group(
	'analytics/admin',
	function () {
		Route::get( 'top-listings', array( AnalyticsController::class, 'get_top_listings' ), array( 'admin' ) );
		Route::get( 'listings-table', array( AnalyticsController::class, 'get_listings_table' ), array( 'admin' ) );

		Route::get( 'traffic-sources', array( AnalyticsController::class, 'get_traffic_sources' ), array( 'admin' ) );
		Route::get( 'tech-stats', array( AnalyticsController::class, 'get_tech_stats' ), array( 'admin' ) );

		Route::get( 'contact-form-usage', array( AnalyticsController::class, 'get_contact_form_usage' ), array( 'admin' ) );
		Route::get( 'contact-form-table', array( AnalyticsController::class, 'get_contact_form_table' ), array( 'admin' ) );

		Route::get( 'search-chart', array( AnalyticsController::class, 'get_search_chart' ), array( 'admin' ) );
		Route::get( 'search-table', array( AnalyticsController::class, 'get_search_table' ), array( 'admin' ) );

		Route::get( 'top-countries', array( AnalyticsController::class, 'get_top_countries' ), array( 'admin' ) );
		Route::get( 'countries-table', array( AnalyticsController::class, 'get_countries_table' ), array( 'admin' ) );

		Route::get( 'top-search-engines', array( AnalyticsController::class, 'get_top_search_engines' ), array( 'admin' ) );

		Route::get( 'top-referral-sources', array( AnalyticsController::class, 'get_top_referral_sources' ), array( 'admin' ) );
		Route::get( 'referral-sources-table', array( AnalyticsController::class, 'get_referral_sources_table' ), array( 'admin' ) );
	},
	array( 'admin' )
);

// Export routes (admin-only) - NEW FEATURE
Route::group(
	'export',
	function () {
		Route::get( 'site-wide', array( ExportController::class, 'export_site_wide' ), array( 'admin' ) );
		Route::get( 'per-listing', array( ExportController::class, 'export_per_listing' ), array( 'admin' ) );
		Route::get( 'per-author', array( ExportController::class, 'export_per_author' ), array( 'admin' ) );
	}
);