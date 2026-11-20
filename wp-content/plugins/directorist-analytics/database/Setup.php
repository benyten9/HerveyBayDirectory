<?php
/**
 * Database Setup
 *
 * Creates all required database tables for analytics tracking.
 *
 * @package DirectoristAnalytics
 * @subpackage Database
 * @since 1.0.0
 */

namespace DirectoristAnalytics\Database;

defined( 'ABSPATH' ) || exit;

use DirectoristAnalytics\WpMVC\Database\Schema\Schema;
use DirectoristAnalytics\WpMVC\Database\Schema\Blueprint;

/**
 * Database Setup Class
 *
 * Handles creation of all analytics tables using WpMVC Schema Builder.
 *
 * @since 1.0.0
 */
class Setup {

	/**
	 * Execute database setup
	 *
	 * Creates all required tables for the analytics system.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function execute() {
		$prefix = 'directorist_analytics_';
	
		$this->create_activities_table( $prefix );
		$this->create_views_table( $prefix );
		$this->create_clicks_table( $prefix );
		$this->create_searches_table( $prefix );
		$this->create_daily_stats_table( $prefix );
		$this->create_tech_stats_daily_table( $prefix );
		$this->create_country_stats_daily_table( $prefix );
		$this->create_search_engine_stats_daily_table( $prefix );
		$this->create_referral_stats_daily_table( $prefix );
	}

	/**
	 * Create activities table
	 *
	 * Stores recent activities (listing created, review submitted, user registered).
	 *
	 * @since 1.0.0
	 * @param string $prefix Table prefix.
	 * @return void
	 */
	private function create_activities_table( $prefix ) {
		Schema::create(
			"{$prefix}activities",
			function ( Blueprint $table ) {
				$table->big_increments( 'id' );
				$table->string( 'activity_type', 50 );
				$table->unsigned_big_integer( 'object_id' );
				$table->unsigned_big_integer( 'user_id' )->nullable();
				$table->string( 'title', 255 )->nullable();
				$table->timestamp( 'created_at' );

				$table->index( 'activity_type' );
				$table->index( 'created_at' );
			}
		);
	}

	/**
	 * Create views table
	 *
	 * Stores detailed listing view tracking with visitor metadata.
	 *
	 * @since 1.0.0
	 * @param string $prefix Table prefix.
	 * @return void
	 */
	private function create_views_table( $prefix ) {
		Schema::create(
			"{$prefix}views",
			function ( Blueprint $table ) {
				$table->big_increments( 'id' );
				$table->unsigned_big_integer( 'listing_id' );
				$table->string( 'session_id', 64 );
				$table->string( 'ip_address', 45 )->nullable();
				$table->string( 'country_code', 2 )->nullable();
				$table->string( 'country_name', 100 )->nullable();
				$table->text( 'referrer_url' )->nullable();
				$table->string( 'referrer_type', 50 )->nullable();
				$table->string( 'search_engine', 50 )->nullable();
				$table->text( 'user_agent' )->nullable();
				$table->string( 'browser', 50 )->nullable();
				$table->string( 'browser_version', 20 )->nullable();
				$table->string( 'os', 50 )->nullable();
				$table->string( 'os_version', 20 )->nullable();
				$table->string( 'device_type', 20 )->nullable();
				$table->string( 'screen_resolution', 20 )->nullable();
				$table->timestamp( 'created_at' );

				$table->index( 'listing_id' );
				$table->index( 'session_id' );
				$table->index( 'created_at' );
				$table->index( 'country_code' );
				$table->index( 'referrer_type' );
				$table->index( [ 'listing_id', 'created_at' ], 'listing_created_at' );
			}
		);
	}

	/**
	 * Create clicks table
	 *
	 * Stores click events with session linking to views table.
	 *
	 * @since 1.0.0
	 * @param string $prefix Table prefix.
	 * @return void
	 */
	private function create_clicks_table( $prefix ) {
		Schema::create(
			"{$prefix}clicks",
			function ( Blueprint $table ) {
				$table->big_increments( 'id' );
				$table->unsigned_big_integer( 'listing_id' );
				$table->string( 'click_type', 50 );
				$table->string( 'session_id', 64 );
				$table->timestamp( 'created_at' );

				$table->index( 'listing_id' );
				$table->index( 'session_id' );
				$table->index( 'click_type' );
				$table->index( 'created_at' );
			}
		);
	}

	/**
	 * Create searches table
	 *
	 * Stores search appearance tracking (when listings appear in results).
	 *
	 * @since 1.0.0
	 * @param string $prefix Table prefix.
	 * @return void
	 */
	private function create_searches_table( $prefix ) {
		Schema::create(
			"{$prefix}searches",
			function ( Blueprint $table ) {
				$table->big_increments( 'id' );
				$table->unsigned_big_integer( 'listing_id' );
				$table->string( 'search_query', 255 )->nullable();
				$table->text( 'search_filters' )->nullable();
				$table->integer( 'position' )->nullable();
				$table->integer( 'total_results' )->nullable();
				$table->tiny_integer( 'clicked' )->default( 0 )->comment( 'value: 0/1' );
				$table->timestamp( 'created_at' );

				$table->index( 'listing_id' );
				$table->index( 'created_at' );
				$table->index( 'search_query' );
			}
		);
	}

	/**
	 * Create daily stats table
	 *
	 * Stores pre-aggregated daily statistics for performance optimization.
	 * Updated in real-time via UPSERT on each tracking event.
	 *
	 * @since 1.0.0
	 * @param string $prefix Table prefix.
	 * @return void
	 */
	private function create_daily_stats_table( $prefix ) {
		Schema::create(
			"{$prefix}daily_stats",
			function ( Blueprint $table ) {
				$table->big_increments( 'id' );
				$table->unsigned_big_integer( 'listing_id' );
				$table->string( 'stat_date', 10 ); // Stores as 'YYYY-MM-DD'
				$table->unsigned_integer( 'views' )->default( 0 );
				$table->unsigned_integer( 'unique_views' )->default( 0 );
				$table->unsigned_integer( 'clicks_total' )->default( 0 );
				$table->unsigned_integer( 'clicks_contact_form' )->default( 0 );
				$table->unsigned_integer( 'clicks_website' )->default( 0 );
				$table->unsigned_integer( 'clicks_phone' )->default( 0 );
				$table->unsigned_integer( 'clicks_email' )->default( 0 );
				$table->unsigned_integer( 'search_appearances' )->default( 0 );
				$table->timestamps();

				$table->unique( [ 'listing_id', 'stat_date' ], 'listing_date' );
				$table->index( 'stat_date' );
				$table->index( 'listing_id' );
			}
		);
	}

	/**
	 * Create tech stats daily table
	 *
	 * Stores aggregated daily statistics for browser, OS, device, and screen resolution.
	 * Privacy-friendly: No personal data, only counts.
	 *
	 * @since 1.0.0
	 * @param string $prefix Table prefix.
	 * @return void
	 */
	private function create_tech_stats_daily_table( $prefix ) {
		Schema::create(
			"{$prefix}tech_stats_daily",
			function ( Blueprint $table ) {
				$table->big_increments( 'id' );
				$table->string( 'stat_date', 10 ); // YYYY-MM-DD
				$table->string( 'browser', 50 )->nullable();
				$table->string( 'os', 50 )->nullable();
				$table->string( 'device_type', 20 )->nullable();
				$table->string( 'screen_resolution', 20 )->nullable();
				$table->unsigned_integer( 'count' )->default( 0 );
				$table->timestamps();

				// Unique constraint: one row per date + tech combination
				$table->unique( [ 'stat_date', 'browser', 'os', 'device_type', 'screen_resolution' ], 'tech_stats_unique' );
				$table->index( 'stat_date' );
				$table->index( 'browser' );
				$table->index( 'os' );
				$table->index( 'device_type' );
			}
		);
	}

	/**
	 * Create country stats daily table
	 *
	 * Stores aggregated daily statistics for visitor countries.
	 * Privacy-friendly: No IP addresses, only country-level data.
	 *
	 * @since 1.0.0
	 * @param string $prefix Table prefix.
	 * @return void
	 */
	private function create_country_stats_daily_table( $prefix ) {
		Schema::create(
			"{$prefix}country_stats_daily",
			function ( Blueprint $table ) {
				$table->big_increments( 'id' );
				$table->string( 'stat_date', 10 ); // YYYY-MM-DD
				$table->string( 'country_code', 2 )->nullable();
				$table->string( 'country_name', 100 )->nullable();
				$table->unsigned_integer( 'count' )->default( 0 );
				$table->timestamps();

				// Unique constraint: one row per date + country
				$table->unique( [ 'stat_date', 'country_code' ], 'country_stats_unique' );
				$table->index( 'stat_date' );
				$table->index( 'country_code' );
			}
		);
	}

	/**
	 * Create search engine stats daily table
	 *
	 * Stores aggregated daily statistics for traffic from search engines.
	 *
	 * @since 1.0.0
	 * @param string $prefix Table prefix.
	 * @return void
	 */
	private function create_search_engine_stats_daily_table( $prefix ) {
		Schema::create(
			"{$prefix}search_engine_stats_daily",
			function ( Blueprint $table ) {
				$table->big_increments( 'id' );
				$table->string( 'stat_date', 10 ); // YYYY-MM-DD
				$table->string( 'search_engine', 50 )->nullable();
				$table->unsigned_integer( 'count' )->default( 0 );
				$table->timestamps();

				// Unique constraint: one row per date + search engine
				$table->unique( [ 'stat_date', 'search_engine' ], 'search_engine_stats_unique' );
				$table->index( 'stat_date' );
				$table->index( 'search_engine' );
			}
		);
	}

	/**
	 * Create referral stats daily table
	 *
	 * Stores aggregated daily statistics for referral traffic.
	 * Privacy-friendly: Only stores domain, not full URLs.
	 *
	 * @since 1.0.0
	 * @param string $prefix Table prefix.
	 * @return void
	 */
	private function create_referral_stats_daily_table( $prefix ) {
		Schema::create(
			"{$prefix}referral_stats_daily",
			function ( Blueprint $table ) {
				$table->big_increments( 'id' );
				$table->string( 'stat_date', 10 ); // YYYY-MM-DD
				$table->string( 'referrer_domain', 255 )->nullable();
				$table->string( 'referrer_type', 50 )->nullable(); // direct, search_engine, social, referral
				$table->unsigned_integer( 'count' )->default( 0 );
				$table->timestamps();

				// Unique constraint: one row per date + referrer + type
				$table->unique( [ 'stat_date', 'referrer_domain', 'referrer_type' ], 'referral_stats_unique' );
				$table->index( 'stat_date' );
				$table->index( 'referrer_domain' );
				$table->index( 'referrer_type' );
			}
		);
	}
}