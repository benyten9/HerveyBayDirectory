<?php
/**
 * Stats Service
 *
 * Handles real-time updates to daily_stats table using UPSERT logic.
 *
 * @package DirectoristAnalytics
 * @subpackage Services
 * @since 1.0.0
 */

namespace DirectoristAnalytics\App\Services;

defined( 'ABSPATH' ) || exit;

use DirectoristAnalytics\App\Models\DailyStat;

/**
 * Stats Service Class
 *
 * @since 1.0.0
 */
class StatsService {

	/**
	 * Increment view count
	 *
	 * @since 1.0.0
	 * @param int  $listing_id Listing ID.
	 * @param bool $is_unique  Is this a unique view?
	 * @return bool
	 */
	public function increment_view_count( $listing_id, $is_unique = false ) {
		$increments = array( 'views' => 1 );

		if ( $is_unique ) {
			$increments['unique_views'] = 1;
		}

		return $this->upsert_daily_stat( absint( $listing_id ), current_time( 'Y-m-d' ), $increments );
	}

	/**
	 * Increment click count
	 *
	 * @since 1.0.0
	 * @param int    $listing_id Listing ID.
	 * @param string $click_type Click type (contact_form, website, phone, email).
	 * @return bool
	 */
	public function increment_click_count( $listing_id, $click_type ) {
		$column_map = array(
			'contact_form' => 'clicks_contact_form',
			'website'      => 'clicks_website',
			'phone'        => 'clicks_phone',
			'email'        => 'clicks_email',
		);

		if ( ! isset( $column_map[ $click_type ] ) ) {
			return false;
		}

		$increments = array(
			'clicks_total'            => 1,
			$column_map[ $click_type ] => 1,
		);

		return $this->upsert_daily_stat( absint( $listing_id ), current_time( 'Y-m-d' ), $increments );
	}

	/**
	 * Increment search appearance count
	 *
	 * @since 1.0.0
	 * @param int $listing_id Listing ID.
	 * @return bool
	 */
	public function increment_search_appearance( $listing_id ) {
		$increments = array( 'search_appearances' => 1 );

		return $this->upsert_daily_stat( absint( $listing_id ), current_time( 'Y-m-d' ), $increments );
	}

		/**
	 * UPSERT daily stat
	 *
	 * @since 1.0.0
	 * @param int    $listing_id Listing ID.
	 * @param string $stat_date  Date (YYYY-MM-DD).
	 * @param array  $increments Columns to increment.
	 * @return bool
	 */
	private function upsert_daily_stat( $listing_id, $stat_date, $increments ) {
		global $wpdb;

		$table = $wpdb->prefix . DailyStat::get_table_name();

		// Build INSERT columns and values with correct placeholders
		$columns      = array( 'listing_id', 'stat_date', 'created_at', 'updated_at' );
		$values       = array( $listing_id, $stat_date, current_time( 'mysql' ), current_time( 'mysql' ) );
		$placeholders = array( '%d', '%s', '%s', '%s' ); // listing_id is int, rest are strings

		foreach ( $increments as $column => $value ) {
			$columns[]      = $column;
			$values[]       = absint( $value );
			$placeholders[] = '%d'; // All increment values are integers
		}

		// Build UPDATE part
		$updates = array( 'updated_at = NOW()' );
		foreach ( $increments as $column => $value ) {
			$updates[] = "{$column} = {$column} + " . absint( $value );
		}

		// Build SQL
		$columns_sql      = implode( ', ', $columns );
		$placeholders_sql = implode( ', ', $placeholders );
		$updates_sql      = implode( ', ', $updates );

		$sql = "INSERT INTO {$table} ({$columns_sql})
				VALUES ({$placeholders_sql})
				ON DUPLICATE KEY UPDATE {$updates_sql}";

		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

		return false !== $result;
	}

	/**
	 * UPSERT tech stat
	 *
	 * Aggregates browser, OS, device, and screen resolution data.
	 *
	 * @since 1.0.0
	 * @param string $stat_date         Date (YYYY-MM-DD).
	 * @param string $browser           Browser name.
	 * @param string $os                Operating system.
	 * @param string $device_type       Device type (Desktop/Mobile/Tablet).
	 * @param string $screen_resolution Screen resolution.
	 * @return bool
	 */
	public function upsert_tech_stat( $stat_date, $browser, $os, $device_type, $screen_resolution ) {
		// Skip if all tech data is empty (bot or unknown)
		if ( empty( $browser ) && empty( $os ) && empty( $device_type ) && empty( $screen_resolution ) ) {
			return true;
		}
	
		global $wpdb;
	
		$table = $wpdb->prefix . 'directorist_analytics_tech_stats_daily';
	
		// Sanitize inputs
		$stat_date         = sanitize_text_field( $stat_date );
		$browser           = sanitize_text_field( $browser );
		$os                = sanitize_text_field( $os );
		$device_type       = sanitize_text_field( $device_type );
		$screen_resolution = sanitize_text_field( $screen_resolution );
	
		// Build SQL
		$sql = "INSERT INTO {$table} 
				(stat_date, browser, os, device_type, screen_resolution, count, created_at, updated_at)
				VALUES (%s, %s, %s, %s, %s, 1, NOW(), NOW())
				ON DUPLICATE KEY UPDATE 
				count = count + 1,
				updated_at = NOW()";
	
		$result = $wpdb->query(
			$wpdb->prepare(
				$sql,
				$stat_date,
				$browser,
				$os,
				$device_type,
				$screen_resolution
			)
		);
	
		return false !== $result;
	}

	/**
	 * UPSERT country stat
	 *
	 * Aggregates visitor country data.
	 *
	 * @since 1.0.0
	 * @param string $stat_date    Date (YYYY-MM-DD).
	 * @param string $country_code Country code (2 letters).
	 * @param string $country_name Country name.
	 * @return bool
	 */
	public function upsert_country_stat( $stat_date, $country_code, $country_name ) {
		global $wpdb;

		$table = $wpdb->prefix . 'directorist_analytics_country_stats_daily';

		// Sanitize inputs
		$stat_date    = sanitize_text_field( $stat_date );
		$country_code = sanitize_text_field( $country_code );
		$country_name = sanitize_text_field( $country_name );

		// Build SQL
		$sql = "INSERT INTO {$table} 
				(stat_date, country_code, country_name, count, created_at, updated_at)
				VALUES (%s, %s, %s, 1, NOW(), NOW())
				ON DUPLICATE KEY UPDATE 
				count = count + 1,
				updated_at = NOW()";

		$result = $wpdb->query(
			$wpdb->prepare(
				$sql,
				$stat_date,
				$country_code,
				$country_name
			)
		);

		return false !== $result;
	}

	/**
	 * UPSERT search engine stat
	 *
	 * Aggregates search engine referral data.
	 *
	 * @since 1.0.0
	 * @param string $stat_date     Date (YYYY-MM-DD).
	 * @param string $search_engine Search engine name.
	 * @return bool
	 */
	public function upsert_search_engine_stat( $stat_date, $search_engine ) {
		global $wpdb;

		$table = $wpdb->prefix . 'directorist_analytics_search_engine_stats_daily';

		// Sanitize inputs
		$stat_date     = sanitize_text_field( $stat_date );
		$search_engine = sanitize_text_field( $search_engine );

		// Build SQL
		$sql = "INSERT INTO {$table} 
				(stat_date, search_engine, count, created_at, updated_at)
				VALUES (%s, %s, 1, NOW(), NOW())
				ON DUPLICATE KEY UPDATE 
				count = count + 1,
				updated_at = NOW()";

		$result = $wpdb->query(
			$wpdb->prepare(
				$sql,
				$stat_date,
				$search_engine
			)
		);

		return false !== $result;
	}

	/**
	 * UPSERT referral stat
	 *
	 * Aggregates referral source data.
	 *
	 * @since 1.0.0
	 * @param string $stat_date       Date (YYYY-MM-DD).
	 * @param string $referrer_domain Referrer domain.
	 * @param string $referrer_type   Referrer type (direct/search_engine/social/referral).
	 * @return bool
	 */
	public function upsert_referral_stat( $stat_date, $referrer_domain, $referrer_type ) {
		global $wpdb;

		$table = $wpdb->prefix . 'directorist_analytics_referral_stats_daily';

		// Sanitize inputs
		$stat_date       = sanitize_text_field( $stat_date );
		$referrer_domain = sanitize_text_field( $referrer_domain );
		$referrer_type   = sanitize_text_field( $referrer_type );

		// Build SQL
		$sql = "INSERT INTO {$table} 
				(stat_date, referrer_domain, referrer_type, count, created_at, updated_at)
				VALUES (%s, %s, %s, 1, NOW(), NOW())
				ON DUPLICATE KEY UPDATE 
				count = count + 1,
				updated_at = NOW()";

		$result = $wpdb->query(
			$wpdb->prepare(
				$sql,
				$stat_date,
				$referrer_domain,
				$referrer_type
			)
		);

		return false !== $result;
	}
}