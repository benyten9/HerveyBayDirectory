<?php
/**
 * Analytics Repository
 *
 * Handles listing-specific analytics data access.
 *
 * @package DirectoristAnalytics
 * @subpackage Repositories
 * @since 1.0.0
 */

namespace DirectoristAnalytics\App\Repositories;

defined( 'ABSPATH' ) || exit;

use DirectoristAnalytics\App\Models\View;
use DirectoristAnalytics\App\Models\Click;
use DirectoristAnalytics\App\Models\Search;
use DirectoristAnalytics\App\Models\DailyStat;
use Exception;
use DirectoristAnalytics\App\Services\MetricDefinitions;

/**
 * Analytics Repository Class
 *
 * @since 1.0.0
 */
class AnalyticsRepository {

	/**
	 * Get listing statistics
	 *
	 * @since 1.0.0
	 * @param int    $listing_id Listing ID.
	 * @param string $start_date Start date (YYYY-MM-DD).
	 * @param string $end_date   End date (YYYY-MM-DD).
	 * @return array
	 * @throws Exception If dates are invalid.
	 */
	public function get_listing_stats( $listing_id, $start_date, $end_date ) {
		$listing_id = absint( $listing_id );

		if ( ! $this->validate_date( $start_date ) || ! $this->validate_date( $end_date ) ) {
			throw new Exception( esc_html__( 'Invalid date format. Use YYYY-MM-DD.', 'directorist-advanced-review' ), 400 );
		}

		$result = DailyStat::query()
			->select( MetricDefinitions::native_select() )
			->where( 'listing_id', $listing_id )
			->where_raw( "stat_date BETWEEN '{$start_date}' AND '{$end_date}'" )
			->first();

		if ( ! $result ) {
			return $this->get_empty_stats();
		}

		return array(
			'total_views'          => absint( $result->total_views ),
			'unique_views'         => absint( $result->unique_views ),
			'total_clicks'         => absint( $result->total_clicks ),
			'clicks_contact_form'  => absint( $result->clicks_contact_form ),
			'clicks_website'       => absint( $result->clicks_website ),
			'clicks_phone'         => absint( $result->clicks_phone ),
			'clicks_email'         => absint( $result->clicks_email ),
			'search_appearances'   => absint( $result->search_appearances ),
		);
	}

	/**
	 * Get daily chart data
	 *
	 * @since 1.0.0
	 * @param int    $listing_id Listing ID.
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array
	 */
	public function get_daily_chart_data( $listing_id, $start_date, $end_date ) {
		$listing_id = absint( $listing_id );

		$results = DailyStat::query()
			->select(
				array(
					'stat_date as date',
					'views',
					'unique_views',
					'clicks_total as clicks',
					'search_appearances',
				)
			)
			->where( 'listing_id', $listing_id )
			->where_raw( "stat_date BETWEEN '{$start_date}' AND '{$end_date}'" )
			->order_by( 'stat_date', 'ASC' )
			->get();

		if ( ! $results ) {
			return array();
		}

		return array_map( array( $this, 'transform_chart_data' ), $results );
	}

	/**
	 * Get top countries
	 *
	 * @since 1.0.0
	 * @param int $listing_id Listing ID.
	 * @param int $limit      Limit results.
	 * @return array
	 */
	public function get_top_countries( $listing_id, $limit = 10 ) {
		global $wpdb;
		$table = View::get_table_name();

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					country_code,
					country_name,
					COUNT(*) as visitors,
					ROUND(COUNT(*) * 100.0 / (
						SELECT COUNT(*) 
						FROM {$wpdb->prefix}{$table} 
						WHERE listing_id = %d 
						AND country_code IS NOT NULL
					), 1) as share
				FROM {$wpdb->prefix}{$table}
				WHERE listing_id = %d 
				AND country_code IS NOT NULL
				GROUP BY country_code, country_name
				ORDER BY visitors DESC
				LIMIT %d",
				$listing_id,
				$listing_id,
				absint( $limit )
			)
		);

		if ( ! $results ) {
			return array();
		}

		return array_map( array( $this, 'transform_country_data' ), $results );
	}

	/**
	 * Get top referrers
	 *
	 * @since 1.0.0
	 * @param int $listing_id Listing ID.
	 * @param int $limit      Limit results.
	 * @return array
	 */
	public function get_top_referrers( $listing_id, $limit = 10 ) {
		global $wpdb;
		$table = View::get_table_name();

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					referrer_url as source,
					COUNT(*) as visitors,
					ROUND(COUNT(*) * 100.0 / (
						SELECT COUNT(*) 
						FROM {$wpdb->prefix}{$table} 
						WHERE listing_id = %d
					), 1) as percentage
				FROM {$wpdb->prefix}{$table}
				WHERE listing_id = %d 
				AND referrer_url IS NOT NULL 
				AND referrer_url != ''
				GROUP BY referrer_url
				ORDER BY visitors DESC
				LIMIT %d",
				$listing_id,
				$listing_id,
				absint( $limit )
			)
		);

		if ( ! $results ) {
			return array();
		}

		return array_map( array( $this, 'transform_referrer_data' ), $results );
	}

	/**
	 * Get browser statistics
	 *
	 * @since 1.0.0
	 * @param int $listing_id Listing ID.
	 * @return array
	 */
	public function get_browser_stats( $listing_id ) {
		global $wpdb;
		$table = View::get_table_name();

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					browser,
					COUNT(*) as visits,
					ROUND(COUNT(*) * 100.0 / (
						SELECT COUNT(*) 
						FROM {$wpdb->prefix}{$table} 
						WHERE listing_id = %d
					), 1) as share
				FROM {$wpdb->prefix}{$table}
				WHERE listing_id = %d 
				AND browser IS NOT NULL
				GROUP BY browser
				ORDER BY visits DESC",
				$listing_id,
				$listing_id
			)
		);

		if ( ! $results ) {
			return array();
		}

		return array_map( array( $this, 'transform_tech_stat' ), $results );
	}

	/**
	 * Get OS statistics
	 *
	 * @since 1.0.0
	 * @param int $listing_id Listing ID.
	 * @return array
	 */
	public function get_os_stats( $listing_id ) {
		global $wpdb;
		$table = View::get_table_name();

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					os,
					COUNT(*) as visits,
					ROUND(COUNT(*) * 100.0 / (
						SELECT COUNT(*) 
						FROM {$wpdb->prefix}{$table} 
						WHERE listing_id = %d
					), 1) as share
				FROM {$wpdb->prefix}{$table}
				WHERE listing_id = %d 
				AND os IS NOT NULL
				GROUP BY os
				ORDER BY visits DESC",
				$listing_id,
				$listing_id
			)
		);

		if ( ! $results ) {
			return array();
		}

		return array_map( array( $this, 'transform_tech_stat' ), $results );
	}

	/**
	 * Get screen resolution statistics
	 *
	 * @since 1.0.0
	 * @param int $listing_id Listing ID.
	 * @return array
	 */
	public function get_screen_resolution_stats( $listing_id ) {
		global $wpdb;
		$table = View::get_table_name();

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					screen_resolution as resolution,
					COUNT(*) as visits,
					ROUND(COUNT(*) * 100.0 / (
						SELECT COUNT(*) 
						FROM {$wpdb->prefix}{$table} 
						WHERE listing_id = %d 
						AND screen_resolution IS NOT NULL 
						AND screen_resolution != ''
					), 1) as share
				FROM {$wpdb->prefix}{$table}
				WHERE listing_id = %d 
				AND screen_resolution IS NOT NULL 
				AND screen_resolution != ''
				GROUP BY screen_resolution
				ORDER BY visits DESC
				LIMIT 10",
				$listing_id,
				$listing_id
			)
		);

		if ( ! $results ) {
			return array();
		}

		return array_map( array( $this, 'transform_resolution_stat' ), $results );
	}

	/**
	 * Get device type statistics
	 *
	 * @since 1.0.0
	 * @param int $listing_id Listing ID.
	 * @return array
	 */
	public function get_device_type_stats( $listing_id ) {
		global $wpdb;
		$table = View::get_table_name();

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					device_type,
					COUNT(*) as visits,
					ROUND(COUNT(*) * 100.0 / (
						SELECT COUNT(*) 
						FROM {$wpdb->prefix}{$table} 
						WHERE listing_id = %d
					), 1) as share
				FROM {$wpdb->prefix}{$table}
				WHERE listing_id = %d 
				AND device_type IS NOT NULL
				GROUP BY device_type
				ORDER BY visits DESC",
				$listing_id,
				$listing_id
			)
		);

		if ( ! $results ) {
			return array();
		}

		return array_map( array( $this, 'transform_tech_stat' ), $results );
	}

	/**
	 * Get traffic channels breakdown
	 *
	 * @since 1.0.0
	 * @param int    $listing_id Listing ID.
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array
	 */
	public function get_traffic_channels( $listing_id, $start_date, $end_date ) {
		global $wpdb;
		$table = View::get_table_name();

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					COALESCE(referrer_type, 'direct') as channel,
					COUNT(*) as count
				FROM {$wpdb->prefix}{$table}
				WHERE listing_id = %d 
				AND created_at BETWEEN %s AND %s
				GROUP BY channel",
				absint( $listing_id ),
				sanitize_text_field( $start_date ),
				sanitize_text_field( $end_date )
			)
		);

		// Format as object { direct: 450, search_engine: 320, ... }
		$channels = array(
			'direct'        => 0,
			'search_engine' => 0,
			'social'        => 0,
			'referral'      => 0,
		);

		foreach ( $results as $result ) {
			$channels[ $result->channel ] = absint( $result->count );
		}

		return $channels;
	}

	/**
	 * Get clicks breakdown
	 *
	 * @since 1.0.0
	 * @param int    $listing_id Listing ID.
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array
	 */
	public function get_clicks_breakdown( $listing_id, $start_date, $end_date ) {
		$result = DailyStat::query()
			->select(
				array(
					'SUM(clicks_contact_form) as contact_form',
					'SUM(clicks_website) as website',
					'SUM(clicks_phone) as phone',
					'SUM(clicks_email) as email',
				)
			)
			->where( 'listing_id', absint( $listing_id ) )
			->where_raw( "stat_date BETWEEN '{$start_date}' AND '{$end_date}'" )
			->first();

		if ( ! $result ) {
			return array(
				'contact_form' => 0,
				'website'      => 0,
				'phone'        => 0,
				'email'        => 0,
			);
		}

		return array(
			'contact_form' => absint( $result->contact_form ),
			'website'      => absint( $result->website ),
			'phone'        => absint( $result->phone ),
			'email'        => absint( $result->email ),
		);
	}

	/**
	 * Get search appearances data
	 *
	 * @since 1.0.0
	 * @param int    $listing_id Listing ID.
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array
	 */
	public function get_search_appearances( $listing_id, $start_date, $end_date ) {
		$result = Search::query()
			->select(
				array(
					'COUNT(*) as total_appearances',
					'SUM(clicked) as total_clicks',
					'AVG(position) as avg_position',
				)
			)
			->where( 'listing_id', absint( $listing_id ) )
			->where_raw( "created_at BETWEEN '{$start_date}' AND '{$end_date}'" )
			->first();

		if ( ! $result || ! $result->total_appearances ) {
			return array(
				'total_appearances' => 0,
				'total_clicks'      => 0,
				'ctr'               => 0,
				'avg_position'      => 0,
			);
		}

		$total_appearances = absint( $result->total_appearances );
		$total_clicks      = absint( $result->total_clicks );
		$ctr               = $total_appearances > 0 ? round( ( $total_clicks / $total_appearances ) * 100, 2 ) : 0;

		return array(
			'total_appearances' => $total_appearances,
			'total_clicks'      => $total_clicks,
			'ctr'               => floatval( $ctr ),
			'avg_position'      => round( floatval( $result->avg_position ), 1 ),
		);
	}

	/**
	 * Transform chart data item
	 *
	 * @since 1.0.0
	 * @param object $item Data item.
	 * @return array
	 */
	private function transform_chart_data( $item ) {
		return array(
			'date'               => sanitize_text_field( $item->date ),
			'views'              => absint( $item->views ),
			'unique_views'       => absint( $item->unique_views ),
			'clicks'             => absint( $item->clicks ),
			'search_appearances' => absint( $item->search_appearances ),
		);
	}

	/**
	 * Transform country data
	 *
	 * @since 1.0.0
	 * @param object $item Country data.
	 * @return array
	 */
	private function transform_country_data( $item ) {
		return array(
			'country'      => sanitize_text_field( $item->country_name ),
			'code'         => sanitize_text_field( $item->country_code ),
			'visitors'     => absint( $item->visitors ),
			'share'        => floatval( $item->share ),
		);
	}

	/**
	 * Transform referrer data
	 *
	 * @since 1.0.0
	 * @param object $item Referrer data.
	 * @return array
	 */
	private function transform_referrer_data( $item ) {
		return array(
			'source'     => esc_url( $item->source ),
			'visitors'   => absint( $item->visitors ),
			'percentage' => floatval( $item->percentage ),
		);
	}

	/**
	 * Transform tech stat (browser/OS)
	 *
	 * @since 1.0.0
	 * @param object $item Tech stat data.
	 * @return array
	 */
	private function transform_tech_stat( $item ) {
		$name = isset( $item->browser ) ? $item->browser : ( isset( $item->os ) ? $item->os : $item->device_type );

		return array(
			'name'   => sanitize_text_field( $name ),
			'visits' => absint( $item->visits ),
			'share'  => floatval( $item->share ),
		);
	}

	/**
	 * Transform resolution stat
	 *
	 * @since 1.0.0
	 * @param object $item Resolution data.
	 * @return array
	 */
	private function transform_resolution_stat( $item ) {
		return array(
			'resolution' => sanitize_text_field( $item->resolution ),
			'visits'     => absint( $item->visits ),
			'share'      => floatval( $item->share ),
		);
	}

	/**
	 * Validate date format
	 *
	 * @since 1.0.0
	 * @param string $date Date string.
	 * @return bool
	 */
	private function validate_date( $date ) {
		$d = \DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}

	/**
	 * Get empty stats structure
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private function get_empty_stats() {
		return array(
			'total_views'         => 0,
			'unique_views'        => 0,
			'total_clicks'        => 0,
			'clicks_contact_form' => 0,
			'clicks_website'      => 0,
			'clicks_phone'        => 0,
			'clicks_email'        => 0,
			'search_appearances'  => 0,
		);
	}
}