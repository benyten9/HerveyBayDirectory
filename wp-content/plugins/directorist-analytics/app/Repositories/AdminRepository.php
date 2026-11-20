<?php
/**
 * Admin Repository
 *
 * Handles site-wide admin analytics data access.
 *
 * @package DirectoristAnalytics
 * @subpackage Repositories
 * @since 1.0.0
 */

namespace DirectoristAnalytics\App\Repositories;

defined( 'ABSPATH' ) || exit;

use DirectoristAnalytics\App\Models\View;
use DirectoristAnalytics\App\Models\DailyStat;
use DirectoristAnalytics\App\Models\Search;

/**
 * Admin Repository Class
 *
 * @since 1.0.0
 */
class AdminRepository {

	/**
	 * Get top listings by metric
	 *
	 * @since 1.0.0
	 * @param string $metric     Metric to sort by (views/clicks/searches).
	 * @param int    $limit      Limit results.
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array
	 */
	public function get_top_listings( $metric = 'views', $limit = 10, $start_date = null, $end_date = null ) {
		global $wpdb;
		$stats_table = DailyStat::get_table_name();

		// Validate metric
		$metric_map = array(
			'views'   => 'views',
			'clicks'  => 'clicks_total',
			'searches' => 'search_appearances',
		);

		if ( ! isset( $metric_map[ $metric ] ) ) {
			$metric = 'views';
		}

		$metric_column = $metric_map[ $metric ];

		// Build date filter
		$date_filter = '';
		if ( $start_date && $end_date ) {
			$date_filter = $wpdb->prepare(
				' AND ds.stat_date BETWEEN %s AND %s',
				sanitize_text_field( $start_date ),
				sanitize_text_field( $end_date )
			);
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					p.ID as listing_id,
					p.post_title as name,
					COALESCE(SUM(ds.views), 0) as views,
					COALESCE(SUM(ds.clicks_total), 0) as clicks
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->prefix}{$stats_table} ds ON p.ID = ds.listing_id
				WHERE p.post_type = %s 
				AND p.post_status = 'publish'
				{$date_filter}
				GROUP BY p.ID, p.post_title
				HAVING views > 0 AND {$metric_column} > 0
				ORDER BY {$metric_column} DESC
				LIMIT %d",
				'at_biz_dir',
				absint( $limit )
			)
		);

		if ( ! $results ) {
			return array();
		}

		return array_map( array( $this, 'transform_top_listing' ), $results );
	}

	/**
	 * Get listings table with pagination
	 *
	 * @since 1.0.0
	 * @param int         $page       Page number.
	 * @param int         $per_page   Items per page.
	 * @param string      $orderby    Column to sort by.
	 * @param string      $order      Sort direction (ASC/DESC).
	 * @param string|null $start_date Start date.
	 * @param string|null $end_date   End date.
	 * @return array
	 */
	public function get_listings_table( $page = 1, $per_page = 10, $orderby = 'views', $order = 'DESC', $start_date = null, $end_date = null ) {
		global $wpdb;
		$stats_table = DailyStat::get_table_name();

		// Validate orderby
		$orderby_map = array(
			'name'    => 'p.post_title',
			'views'   => 'views',
			'clicks'  => 'clicks',
			'searches' => 'searches',
		);

		if ( ! isset( $orderby_map[ $orderby ] ) ) {
			$orderby = 'views';
		}

		$orderby_column = $orderby_map[ $orderby ];

		// Validate order
		$order = strtoupper( $order );
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}

		// Build date filter
		$date_filter = '';
		if ( $start_date && $end_date ) {
			$date_filter = $wpdb->prepare(
				' AND ds.stat_date BETWEEN %s AND %s',
				sanitize_text_field( $start_date ),
				sanitize_text_field( $end_date )
			);
		}

		// Get total count of listings with views > 0 in date range
		$total_count_query = "SELECT COUNT(*)
		FROM (
			SELECT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->prefix}{$stats_table} ds ON p.ID = ds.listing_id
			WHERE p.post_type = %s 
			AND p.post_status = 'publish'
			{$date_filter}
			GROUP BY p.ID
			HAVING SUM(ds.views) > 0
		) as filtered_listings";

		$total_count = $wpdb->get_var(
		$wpdb->prepare( $total_count_query, 'at_biz_dir' )
		);

		if ( ! $total_count ) {
			return array(
				'data'       => array(),
				'pagination' => array(
					'total_items'  => 0,
					'total_pages'  => 0,
					'current_page' => $page,
					'per_page'     => $per_page,
				),
			);
		}

		// Calculate offset and limit
		$offset = ( $page - 1 ) * $per_page;

		// Build LIMIT clause
		$limit_clause = '';
		if ( $per_page > 0 ) {
			$limit_clause = $wpdb->prepare( 'LIMIT %d OFFSET %d', $per_page, $offset );
		}

		// Get paginated results
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					p.ID as listing_id,
					p.post_title as name,
					COALESCE(SUM(ds.views), 0) as views,
					COALESCE(SUM(ds.clicks_total), 0) as clicks,
					COALESCE(SUM(ds.search_appearances), 0) as searches
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->prefix}{$stats_table} ds ON p.ID = ds.listing_id
				WHERE p.post_type = %s 
				AND p.post_status = 'publish'
				{$date_filter}
				GROUP BY p.ID, p.post_title
				HAVING views > 0
				ORDER BY {$orderby_column} {$order}
				{$limit_clause}",
				'at_biz_dir'
			)
		);

		if ( ! $results ) {
			return array(
				'data'       => array(),
				'pagination' => array(
					'total_items'  => $total_count,
					'total_pages'  => $per_page > 0 ? ceil( $total_count / $per_page ) : 1,
					'current_page' => $page,
					'per_page'     => $per_page,
				),
			);
		}

		return array(
			'data'       => array_map( array( $this, 'transform_listing_table_row' ), $results ),
			'pagination' => array(
				'total_items'  => absint( $total_count ),
				'total_pages'  => $per_page > 0 ? ceil( $total_count / $per_page ) : 1,
				'current_page' => absint( $page ),
				'per_page'     => $per_page,
			),
		);
	}

	/**
	 * Transform listing table row
	 *
	 * @since 1.0.0
	 * @param object $item Listing data.
	 * @return array
	 */
	private function transform_listing_table_row( $item ) {
		$listing_id = absint( $item->listing_id );
		
		// Get listing URL
		$url = get_permalink( $listing_id );
		
		// Get preview image (priority order)
		$image_id = 0;
		
		// 1. Try Directorist preview image first (custom field)
		$image_id = get_post_meta( $listing_id, '_listing_prv_img', true );
		
		// 2. Fallback to WordPress featured image
		if ( ! $image_id ) {
			$image_id = get_post_thumbnail_id( $listing_id );
		}
		
		// 3. Fallback to first gallery image
		if ( ! $image_id ) {
			$gallery = get_post_meta( $listing_id, '_listing_img', true );
			if ( ! empty( $gallery ) && is_array( $gallery ) ) {
				$image_id = absint( $gallery[0] );
			}
		}
		
		// Get image URL (thumbnail size: 150x150)
		$image = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
		
		return array(
			'listing_id' => $listing_id,
			'name'       => sanitize_text_field( $item->name ),
			'url'        => esc_url( $url ),
			'image'      => $image ? esc_url( $image ) : '',
			'views'      => absint( $item->views ),
			'clicks'     => absint( $item->clicks ),
			'searches'   => absint( $item->searches ),
		);
	}

	/**
	 * Extract domain from URL
	 *
	 * @since 1.0.0
	 * @param string $url Full URL.
	 * @return string
	 */
	private function extract_domain( $url ) {
		$parsed = wp_parse_url( $url );
		$host = $parsed['host'] ?? '';
		
		// Remove www. prefix
		return preg_replace( '/^www\./', '', $host );
	}

	/**
	 * Get global traffic sources
	 *
	 * @since 1.0.0
	 * @param int $limit Limit results.
	 * @return array
	 */
	public function get_global_traffic_sources( $limit = 10 ) {
		global $wpdb;
		$views_table = View::get_table_name();

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					referrer_url as source,
					COUNT(*) as visitors,
					ROUND(COUNT(*) * 100.0 / (
						SELECT COUNT(*) 
						FROM {$wpdb->prefix}{$views_table} 
						WHERE referrer_url IS NOT NULL
					), 1) as percentage
				FROM {$wpdb->prefix}{$views_table}
				WHERE referrer_url IS NOT NULL 
				AND referrer_url != ''
				GROUP BY referrer_url
				ORDER BY visitors DESC
				LIMIT %d",
				absint( $limit )
			)
		);

		if ( ! $results ) {
			return array();
		}

		return array_map( array( $this, 'transform_traffic_source' ), $results );
	}

	/**
	 * Get global tech stats
	 *
	 * Returns aggregated browser, OS, device, or screen resolution statistics.
	 *
	 * @since 1.0.0
	 * @param string      $type       Type of stats (browsers/os/devices/resolutions).
	 * @param string|null $start_date Start date (YYYY-MM-DD).
	 * @param string|null $end_date   End date (YYYY-MM-DD).
	 * @return array
	 */
	public function get_global_tech_stats( $type = 'browsers', $start_date = null, $end_date = null ) {
		global $wpdb;
		$tech_table = 'directorist_analytics_tech_stats_daily';

		// Validate and map type to column
		$type_map = array(
			'browsers'    => 'browser',
			'os'          => 'os',
			'devices'     => 'device_type',
			'resolutions' => 'screen_resolution',
		);

		if ( ! isset( $type_map[ $type ] ) ) {
			$type = 'browsers';
		}

		$column = $type_map[ $type ];

		// Build date filter
		$date_filter = '';
		if ( $start_date && $end_date ) {
			$date_filter = $wpdb->prepare(
				' AND stat_date BETWEEN %s AND %s',
				$start_date,
				$end_date
			);
		}

		// Query aggregated tech stats table
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					{$column} as name,
					SUM(count) as visits,
					ROUND(SUM(count) * 100.0 / (
						SELECT SUM(count) 
						FROM {$wpdb->prefix}{$tech_table} 
						WHERE {$column} IS NOT NULL
						AND TRIM({$column}) != ''
						{$date_filter}
					), 1) as share
				FROM {$wpdb->prefix}{$tech_table}
				WHERE {$column} IS NOT NULL
				AND TRIM({$column}) != ''
				{$date_filter}
				GROUP BY {$column}
				ORDER BY visits DESC
				LIMIT %d",
				15
			)
		);

		if ( ! $results ) {
			return array();
		}

		$transformed = array_map( array( $this, 'transform_tech_stat' ), $results );
		return array_values( array_filter( $transformed ) );
	}

	/**
	 * Get contact form usage chart data
	 *
	 * @since 1.0.0
	 * @param int    $limit      Limit results.
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array
	 */
	public function get_contact_form_usage_chart( $limit = 5, $start_date = null, $end_date = null ) {
		global $wpdb;
		$stats_table = DailyStat::get_table_name();

		$date_filter = '';
		if ( $start_date && $end_date ) {
			$date_filter = $wpdb->prepare(
				' AND ds.stat_date BETWEEN %s AND %s',
				sanitize_text_field( $start_date ),
				sanitize_text_field( $end_date )
			);
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					p.ID as listing_id,
					p.post_title as name,
					COALESCE(SUM(ds.clicks_contact_form), 0) as contact_uses
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->prefix}{$stats_table} ds ON p.ID = ds.listing_id {$date_filter}
				WHERE p.post_type = %s 
				AND p.post_status = 'publish'
				GROUP BY p.ID, p.post_title
				HAVING contact_uses > 0
				ORDER BY contact_uses DESC
				LIMIT %d",
				'at_biz_dir',
				absint( $limit )
			)
		);

		if ( ! $results ) {
			return array();
		}

		// Format for chart
		return array(
			'categories' => array_map(
				function ( $item ) {
					return sanitize_text_field( $item->name );
				},
				$results
			),
			'series'     => array(
				array(
					'name' => __( 'Contact Uses', 'directorist-analytics' ),
					'data' => array_map(
						function ( $item ) {
							return absint( $item->contact_uses );
						},
						$results
					),
				),
			),
		);
	}

	/**
	 * Get contact form usage table (for modal)
	 *
	 * @since 1.0.0
	 * @param int         $page       Page number.
	 * @param int         $per_page   Items per page.
	 * @param string|null $start_date Start date.
	 * @param string|null $end_date   End date.
	 * @return array
	 */
	public function get_contact_form_usage_table( $page = 1, $per_page = 10, $start_date = null, $end_date = null ) {
		global $wpdb;
		$stats_table = DailyStat::get_table_name();

		// Build date filter
		$date_filter = '';
		if ( $start_date && $end_date ) {
			$date_filter = $wpdb->prepare(
				' AND ds.stat_date BETWEEN %s AND %s',
				$start_date,
				$end_date
			);
		}

		// Get total count
		$total_count = $wpdb->get_var(
			"SELECT COUNT(DISTINCT ds.listing_id)
			FROM {$wpdb->prefix}{$stats_table} ds
			WHERE ds.clicks_contact_form > 0 {$date_filter}"
		);

		if ( ! $total_count ) {
			return array(
				'data'       => array(),
				'pagination' => array(
					'total_items'  => 0,
					'total_pages'  => 0,
					'current_page' => $page,
					'per_page'     => $per_page,
				),
			);
		}

		// Calculate offset and limit
		$offset = ( $page - 1 ) * $per_page;

		// Build LIMIT clause
		$limit_clause = '';
		if ( $per_page > 0 ) {
			$limit_clause = $wpdb->prepare( 'LIMIT %d OFFSET %d', $per_page, $offset );
		}

		// Get paginated results
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					p.ID as listing_id,
					p.post_title as name,
					COALESCE(SUM(ds.clicks_contact_form), 0) as contact_uses
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->prefix}{$stats_table} ds ON p.ID = ds.listing_id {$date_filter}
				WHERE p.post_type = %s 
				AND p.post_status = 'publish'
				GROUP BY p.ID, p.post_title
				HAVING contact_uses > 0
				ORDER BY contact_uses DESC
				{$limit_clause}",
				'at_biz_dir'
			)
		);

		if ( ! $results ) {
			return array(
				'data'       => array(),
				'pagination' => array(
					'total_items'  => $total_count,
					'total_pages'  => $per_page > 0 ? ceil( $total_count / $per_page ) : 1,
					'current_page' => $page,
					'per_page'     => $per_page,
				),
			);
		}

		// Transform results
		$table_data = array();

		foreach ( $results as $item ) {
			$listing_id = absint( $item->listing_id );
			
			// Get listing URL
			$url = get_permalink( $listing_id );
			
			// Get preview image (priority order: Directorist preview > WordPress featured > Gallery first image)
			$image_id = get_post_meta( $listing_id, '_listing_prv_img', true );
			
			if ( ! $image_id ) {
				$image_id = get_post_thumbnail_id( $listing_id );
			}
			
			if ( ! $image_id ) {
				$gallery = get_post_meta( $listing_id, '_listing_img', true );
				if ( is_array( $gallery ) && ! empty( $gallery ) ) {
					$image_id = absint( $gallery[0] );
				}
			}
			
			// Get image URL
			$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
			
			$table_data[] = array(
				'name'        => sanitize_text_field( $item->name ),
				'contactUses' => absint( $item->contact_uses ),
				'url'         => esc_url( $url ),
				'image'       => $image_url ? esc_url( $image_url ) : '',
			);
		}

		return array(
			'data'       => $table_data,
			'pagination' => array(
				'total_items'  => absint( $total_count ),
				'total_pages'  => $per_page > 0 ? ceil( $total_count / $per_page ) : 1,
				'current_page' => absint( $page ),
				'per_page'     => $per_page,
			),
		);
	}

	/**
	 * Get top listing IDs by search appearances
	 *
	 * @since 1.0.0
	 * @param int         $limit      Number of listings.
	 * @param string|null $start_date Start date.
	 * @param string|null $end_date   End date.
	 * @return array
	 */
	public function get_top_listing_ids_by_search( $limit = 5, $start_date = null, $end_date = null ) {
		global $wpdb;
		$stats_table = DailyStat::get_table_name();  // ✅ USE DAILY_STATS, NOT SEARCHES

		$date_filter = '';
		if ( $start_date && $end_date ) {
			$date_filter = $wpdb->prepare(
				' AND stat_date BETWEEN %s AND %s',
				$start_date,
				$end_date
			);
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					listing_id, 
					SUM(search_appearances) as total
				FROM {$wpdb->prefix}{$stats_table}
				WHERE search_appearances > 0 {$date_filter}
				GROUP BY listing_id
				ORDER BY total DESC
				LIMIT %d",
				absint( $limit )
			)
		);

		if ( ! $results ) {
			return array();
		}

		return array_map(
			function( $item ) {
				return absint( $item->listing_id );
			},
			$results
		);
	}

	public function get_search_appearances_chart( $listing_ids, $start_date, $end_date ) {
		global $wpdb;
		$stats_table = DailyStat::get_table_name();
	
		if ( empty( $listing_ids ) || ! is_array( $listing_ids ) ) {
			return array();
		}
	
		$listing_ids = array_map( 'absint', $listing_ids );
		
		// Generate all dates in range
		$dates = array();
		$current = new \DateTime( $start_date );
		$end = new \DateTime( $end_date );
		
		while ( $current <= $end ) {
			$dates[] = $current->format( 'Y-m-d' );
			$current->modify( '+1 day' );
		}
	
		$chart_data = array();
	
		foreach ( $listing_ids as $listing_id ) {
			$listing = get_post( $listing_id );
			if ( ! $listing ) {
				continue;
			}
	
			// Get all daily search appearances for this listing
			$daily_data = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT 
						stat_date as date,
						search_appearances as count
					FROM {$wpdb->prefix}{$stats_table}
					WHERE listing_id = %d 
					AND stat_date BETWEEN %s AND %s
					ORDER BY stat_date ASC",
					$listing_id,
					$start_date,
					$end_date
				)
			);
	
			// Convert to associative array for easy lookup
			$data_by_date = array();
			if ( $daily_data ) {
				foreach ( $daily_data as $point ) {
					$data_by_date[ $point->date ] = absint( $point->count );
				}
			}
	
			// Build complete data array with zeros for missing dates
			$data_points = array();
			foreach ( $dates as $date ) {
				$data_points[] = isset( $data_by_date[ $date ] ) ? $data_by_date[ $date ] : 0;
			}
	
			// Include listing (even if all zeros - shows in chart)
			$chart_data[] = array(
				'name' => sanitize_text_field( $listing->post_title ),
				'data' => $data_points,
			);
		}
	
		return $chart_data;
	}

	/**
	 * Get search appearances table data (for modal)
	 *
	 * @since 1.0.0
	 * @param int         $page       Page number.
	 * @param int         $per_page   Items per page.
	 * @param string      $start_date Start date.
	 * @param string      $end_date   End date.
	 * @return array
	 */
	public function get_search_appearances_table( $page = 1, $per_page = 10, $start_date = null, $end_date = null ) {
		global $wpdb;
		$stats_table = DailyStat::get_table_name();

		// Build date filter
		$date_filter = '';
		if ( $start_date && $end_date ) {
			$date_filter = $wpdb->prepare(
				' AND stat_date BETWEEN %s AND %s',
				$start_date,
				$end_date
			);
		}

		// Get total count
		$total_count = $wpdb->get_var(
			"SELECT COUNT(DISTINCT ds.listing_id)
			FROM {$wpdb->prefix}{$stats_table} ds
			WHERE search_appearances > 0 {$date_filter}"
		);

		if ( ! $total_count ) {
			return array(
				'data'       => array(),
				'pagination' => array(
					'total_items'  => 0,
					'total_pages'  => 0,
					'current_page' => $page,
					'per_page'     => $per_page,
				),
			);
		}

		// Calculate offset and limit
		$offset = ( $page - 1 ) * $per_page;

		// Build LIMIT clause
		$limit_clause = '';
		if ( $per_page > 0 ) {
			// Normal pagination
			$limit_clause = $wpdb->prepare( 'LIMIT %d OFFSET %d', $per_page, $offset );
		} else {
			// per_page = -1 or 0 means "all items"
			$limit_clause = '';
		}

		// Get listings
		$listing_ids = $wpdb->get_col(
			"SELECT DISTINCT ds.listing_id
			FROM {$wpdb->prefix}{$stats_table} ds
			WHERE search_appearances > 0 {$date_filter}
			ORDER BY ds.listing_id ASC
			{$limit_clause}"
		);

		if ( empty( $listing_ids ) ) {
			return array(
				'data'       => array(),
				'pagination' => array(
					'total_items'  => $total_count,
					'total_pages'  => $per_page > 0 ? ceil( $total_count / $per_page ) : 1,
					'current_page' => $page,
					'per_page'     => $per_page,
				),
			);
		}

		// Month mapping
		$month_map = array(
			'01' => 'jan',
			'02' => 'feb',
			'03' => 'mar',
			'04' => 'apr',
			'05' => 'may',
			'06' => 'jun',
			'07' => 'jul',
			'08' => 'aug',
			'09' => 'sep',
			'10' => 'oct',
			'11' => 'nov',
			'12' => 'dec',
		);

		// Calculate which months are in the date range
		$start = new \DateTime( $start_date );
		$end   = new \DateTime( $end_date );
		
		$months_in_range = array();
		$current = clone $start;
		
		while ( $current <= $end ) {
			$month_num = $current->format( 'm' );
			if ( isset( $month_map[ $month_num ] ) ) {
				$months_in_range[ $month_num ] = $month_map[ $month_num ];
			}
			$current->modify( 'first day of next month' );
		}

		$table_data = array();

		foreach ( $listing_ids as $listing_id ) {
			$listing = get_post( $listing_id );
			if ( ! $listing ) {
				continue;
			}
		
			// Get listing URL and image
			$url = get_permalink( $listing_id );
			
			// Get preview image (priority order)
			$image_id = get_post_meta( $listing_id, '_listing_prv_img', true );
			
			if ( ! $image_id ) {
				$image_id = get_post_thumbnail_id( $listing_id );
			}
			
			if ( ! $image_id ) {
				$gallery = get_post_meta( $listing_id, '_listing_img', true );
				if ( ! empty( $gallery ) && is_array( $gallery ) ) {
					$image_id = absint( $gallery[0] );
				}
			}
			
			$image = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
		
			// Get monthly totals for this listing
			$monthly_data = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT 
						DATE_FORMAT(stat_date, '%%m') as month,
						SUM(search_appearances) as total
					FROM {$wpdb->prefix}{$stats_table}
					WHERE listing_id = %d {$date_filter}
					GROUP BY month
					ORDER BY month ASC",
					$listing_id
				)
			);
		
			// Build flat object with month keys (only months in range)
			$row = array(
				'name'  => sanitize_text_field( $listing->post_title ),
				'url'   => esc_url( $url ),
				'image' => $image ? esc_url( $image ) : '',
			);
		
			$grand_total = 0;
		
			// Initialize only months in date range to 0
			foreach ( $months_in_range as $month_key ) {
				$row[ $month_key ] = 0;
			}
		
			// Fill in actual data
			if ( $monthly_data ) {
				foreach ( $monthly_data as $month_row ) {
					$month_num = $month_row->month;
					if ( isset( $months_in_range[ $month_num ] ) ) {
						$month_key = $months_in_range[ $month_num ];
						$row[ $month_key ] = absint( $month_row->total );
						$grand_total += absint( $month_row->total );
					}
				}
			}
		
			$row['total'] = $grand_total;
		
			$table_data[] = $row;
		}

		return array(
			'data'       => $table_data,
			'pagination' => array(
				'total_items'  => absint( $total_count ),
				'total_pages'  => $per_page > 0 ? ceil( $total_count / $per_page ) : 1,
				'current_page' => absint( $page ),
				'per_page'     => $per_page,  // Don't use absint (keeps -1)
			),
		);
	}

	/**
	 * Transform top listing data
	 *
	 * @since 1.0.0
	 * @param object $item Listing data.
	 * @return array
	 */
	private function transform_top_listing( $item ) {
		$listing_id = absint( $item->listing_id );
		
		// Get listing URL
		$url = get_permalink( $listing_id );
		
		// Get preview image (priority order)
		$image_id = 0;
		
		// 1. Try Directorist preview image first
		$image_id = get_post_meta( $listing_id, '_listing_prv_img', true );
		
		// 2. Fallback to WordPress featured image
		if ( ! $image_id ) {
			$image_id = get_post_thumbnail_id( $listing_id );
		}
		
		// 3. Fallback to first gallery image
		if ( ! $image_id ) {
			$gallery = get_post_meta( $listing_id, '_listing_img', true );
			if ( ! empty( $gallery ) && is_array( $gallery ) ) {
				$image_id = absint( $gallery[0] );
			}
		}
		
		// Get image URL (thumbnail size: 150x150)
		$image = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
		
		return array(
			'listing_id' => $listing_id,
			'name'       => sanitize_text_field( $item->name ),
			'url'        => esc_url( $url ),
			'image'      => $image ? esc_url( $image ) : '',
			'views'      => absint( $item->views ),
			'clicks'     => absint( $item->clicks ),
		);
	}

	/**
	 * Transform traffic source data
	 *
	 * @since 1.0.0
	 * @param object $item Traffic source data.
	 * @return array
	 */
	private function transform_traffic_source( $item ) {
		return array(
			'source'     => esc_url( $item->source ),
			'visitors'   => absint( $item->visitors ),
			'percentage' => floatval( $item->percentage ),
		);
	}

	/**
	 * Transform tech stat data
	 *
	 * @since 1.0.0
	 * @param object $item Tech stat data.
	 * @return array
	 */
	private function transform_tech_stat( $item ) {
		$name = sanitize_text_field( $item->name );
		
		// Skip if name is empty after sanitization
		if ( empty( $name ) || trim( $name ) === '' ) {
			return null;
		}
		
		return array(
			'name'   => $name,
			'visits' => absint( $item->visits ),
			'share'  => floatval( $item->share ),
		);
	}

	/**
	 * Transform performance data
	 *
	 * @since 1.0.0
	 * @param object $item Performance data.
	 * @return array
	 */
	private function transform_performance_data( $item ) {
		return array(
			'listing_id'         => absint( $item->listing_id ),
			'listing_name'       => sanitize_text_field( $item->listing_name ),
			'total_views'        => absint( $item->total_views ),
			'total_clicks'       => absint( $item->total_clicks ),
			'search_appearances' => absint( $item->search_appearances ),
		);
	}

	public function get_top_countries_global( $limit = 10, $start_date = null, $end_date = null ) {
		global $wpdb;
		$country_table = 'directorist_analytics_country_stats_daily';
	
		// Build date filter
		$date_filter = '';
		if ( $start_date && $end_date ) {
			$date_filter = $wpdb->prepare(
				' AND stat_date BETWEEN %s AND %s',
				$start_date,
				$end_date
			);
		}
	
		// Query aggregated country stats
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					country_code,
					country_name,
					SUM(count) as visitors,
					ROUND(SUM(count) * 100.0 / (
						SELECT SUM(count)
						FROM {$wpdb->prefix}{$country_table}
						WHERE country_code IS NOT NULL
						{$date_filter}
					), 1) as share
				FROM {$wpdb->prefix}{$country_table}
				WHERE country_code IS NOT NULL
				{$date_filter}
				GROUP BY country_code, country_name
				ORDER BY visitors DESC
				LIMIT %d",
				absint( $limit )
			)
		);
	
		if ( ! $results ) {
			return array();
		}
	
		return array_map(
			function ( $item ) {
				return array(
					'country'  => sanitize_text_field( $item->country_name ),
					'code'     => sanitize_text_field( $item->country_code ),
					'visitors' => absint( $item->visitors ),
					'share'    => floatval( $item->share ),
				);
			},
			$results
		);
	}

	public function get_countries_table( $page = 1, $per_page = 10, $start_date = null, $end_date = null ) {
		global $wpdb;
		$country_table = 'directorist_analytics_country_stats_daily';
	
		$page     = absint( $page );
		$per_page = intval( $per_page );
		$offset   = ( $page - 1 ) * abs( $per_page );
	
		// Build date filter
		$date_filter = '';
		if ( $start_date && $end_date ) {
			$date_filter = $wpdb->prepare(
				' AND stat_date BETWEEN %s AND %s',
				$start_date,
				$end_date
			);
		}
	
		// Get total count
		$total_count = absint(
			$wpdb->get_var(
				"SELECT COUNT(DISTINCT country_code)
				FROM {$wpdb->prefix}{$country_table}
				WHERE country_code IS NOT NULL
				{$date_filter}"
			)
		);
	
		if ( ! $total_count ) {
			return array(
				'data'       => array(),
				'pagination' => array(
					'total_items'  => 0,
					'total_pages'  => 0,
					'current_page' => $page,
					'per_page'     => $per_page,
				),
			);
		}
	
		// Build LIMIT clause
		$limit_clause = '';
		if ( $per_page !== -1 && $per_page > 0 ) {
			$limit_clause = $wpdb->prepare( ' LIMIT %d OFFSET %d', abs( $per_page ), $offset );
		}
	
		// Get results
		$results = $wpdb->get_results(
			"SELECT 
				country_code,
				country_name,
				SUM(count) as visitors,
				ROUND(SUM(count) * 100.0 / (
					SELECT SUM(count)
					FROM {$wpdb->prefix}{$country_table}
					WHERE country_code IS NOT NULL
					{$date_filter}
				), 1) as share
			FROM {$wpdb->prefix}{$country_table}
			WHERE country_code IS NOT NULL
			{$date_filter}
			GROUP BY country_code, country_name
			ORDER BY visitors DESC
			{$limit_clause}"
		);
	
		if ( ! $results ) {
			return array(
				'data'       => array(),
				'pagination' => array(
					'total_items'  => $total_count,
					'total_pages'  => $per_page > 0 ? ceil( $total_count / $per_page ) : 1,
					'current_page' => $page,
					'per_page'     => $per_page,
				),
			);
		}
	
		// Transform results
		$table_data = array_map(
			function ( $item ) {
				return array(
					'country'  => sanitize_text_field( $item->country_name ),
					'code'     => sanitize_text_field( $item->country_code ),
					'visitors' => absint( $item->visitors ),
					'share'    => floatval( $item->share ),
				);
			},
			$results
		);
	
		return array(
			'data'       => $table_data,
			'pagination' => array(
				'total_items'  => absint( $total_count ),
				'total_pages'  => $per_page > 0 ? ceil( $total_count / $per_page ) : 1,
				'current_page' => absint( $page ),
				'per_page'     => $per_page,
			),
		);
	}

	public function get_top_search_engines( $limit = 4, $start_date = null, $end_date = null ) {
		global $wpdb;
		$search_engine_table = 'directorist_analytics_search_engine_stats_daily';
	
		// Build date filter
		$date_filter = '';
		if ( $start_date && $end_date ) {
			$date_filter = $wpdb->prepare(
				' AND stat_date BETWEEN %s AND %s',
				$start_date,
				$end_date
			);
		}
	
		// Get top N engines
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					search_engine as name,
					SUM(count) as visitors
				FROM {$wpdb->prefix}{$search_engine_table}
				WHERE search_engine IS NOT NULL
				{$date_filter}
				GROUP BY search_engine
				ORDER BY visitors DESC
				LIMIT %d",
				absint( $limit )
			)
		);
	
		if ( ! $results ) {
			return array();
		}
	
		// Get total for percentage
		$total = absint(
			$wpdb->get_var(
				"SELECT SUM(count)
				FROM {$wpdb->prefix}{$search_engine_table}
				WHERE search_engine IS NOT NULL
				{$date_filter}"
			)
		);
	
		// Get sum of top N
		$top_sum = array_sum( array_column( $results, 'visitors' ) );
	
		// Transform top N results
		$engines = array_map(
			function ( $item ) use ( $total ) {
				return array(
					'name'     => ucfirst( strtolower( sanitize_text_field( $item->name ) ) ),
					'visitors' => absint( $item->visitors ),
					'share'    => $total > 0 ? round( ( absint( $item->visitors ) / $total ) * 100, 1 ) : 0,
				);
			},
			$results
		);
	
		// Add "Other" category if there are more engines
		if ( $total > $top_sum ) {
			$engines[] = array(
				'name'     => 'Other',
				'visitors' => $total - $top_sum,
				'share'    => $total > 0 ? round( ( ( $total - $top_sum ) / $total ) * 100, 1 ) : 0,
			);
		}
	
		return $engines;
	}

	/**
	 * Get top referral sources globally
	 *
	 * @since 1.0.0
	 * @param int         $limit      Limit results.
	 * @param string|null $start_date Start date.
	 * @param string|null $end_date   End date.
	 * @return array
	 */
	public function get_top_referral_sources( $limit = 10, $start_date = null, $end_date = null ) {
		global $wpdb;
		$referral_table = 'directorist_analytics_referral_stats_daily';
	
		// Build date filter
		$date_filter = '';
		if ( $start_date && $end_date ) {
			$date_filter = $wpdb->prepare(
				' AND stat_date BETWEEN %s AND %s',
				$start_date,
				$end_date
			);
		}
	
		// Query aggregated referral stats
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					referrer_domain as source,
					referrer_type,
					SUM(count) as visitors,
					ROUND(SUM(count) * 100.0 / (
						SELECT SUM(count)
						FROM {$wpdb->prefix}{$referral_table}
						WHERE referrer_domain IS NOT NULL
						AND referrer_domain != ''
						{$date_filter}
					), 1) as share
				FROM {$wpdb->prefix}{$referral_table}
				WHERE referrer_domain IS NOT NULL
				AND referrer_domain != ''
				{$date_filter}
				GROUP BY referrer_domain, referrer_type
				ORDER BY visitors DESC
				LIMIT %d",
				absint( $limit )
			)
		);
	
		if ( ! $results ) {
			return array();
		}
	
		return array_map(
			function ( $item ) {
				return array(
					'source'   => sanitize_text_field( $item->source ),
					'visitors' => absint( $item->visitors ),
					'share'    => floatval( $item->share ),
				);
			},
			$results
		);
	}

	/**
	 * Get referral sources table (for modal)
	 *
	 * @since 1.0.0
	 * @param int         $page       Page number.
	 * @param int         $per_page   Items per page.
	 * @param string|null $start_date Start date.
	 * @param string|null $end_date   End date.
	 * @return array
	 */
	public function get_referral_sources_table( $page = 1, $per_page = 10, $start_date = null, $end_date = null ) {
		global $wpdb;
		$referral_table = 'directorist_analytics_referral_stats_daily';
	
		$page     = absint( $page );
		$per_page = intval( $per_page );
		$offset   = ( $page - 1 ) * abs( $per_page );
	
		// Build date filter
		$date_filter = '';
		if ( $start_date && $end_date ) {
			$date_filter = $wpdb->prepare(
				' AND stat_date BETWEEN %s AND %s',
				$start_date,
				$end_date
			);
		}
	
		// Get total count
		$total_count = absint(
			$wpdb->get_var(
				"SELECT COUNT(DISTINCT referrer_domain)
				FROM {$wpdb->prefix}{$referral_table}
				WHERE referrer_domain IS NOT NULL
				AND referrer_domain != ''
				{$date_filter}"
			)
		);
	
		if ( ! $total_count ) {
			return array(
				'data'       => array(),
				'pagination' => array(
					'total_items'  => 0,
					'total_pages'  => 0,
					'current_page' => $page,
					'per_page'     => $per_page,
				),
			);
		}
	
		// Build LIMIT clause
		$limit_clause = '';
		if ( $per_page !== -1 && $per_page > 0 ) {
			$limit_clause = $wpdb->prepare( ' LIMIT %d OFFSET %d', abs( $per_page ), $offset );
		}
	
		// Get results
		$results = $wpdb->get_results(
			"SELECT 
				referrer_domain as source,
				referrer_type,
				SUM(count) as visitors,
				ROUND(SUM(count) * 100.0 / (
					SELECT SUM(count)
					FROM {$wpdb->prefix}{$referral_table}
					WHERE referrer_domain IS NOT NULL
					AND referrer_domain != ''
					{$date_filter}
				), 1) as percentage
			FROM {$wpdb->prefix}{$referral_table}
			WHERE referrer_domain IS NOT NULL
			AND referrer_domain != ''
			{$date_filter}
			GROUP BY referrer_domain, referrer_type
			ORDER BY visitors DESC
			{$limit_clause}"
		);
	
		if ( ! $results ) {
			return array(
				'data'       => array(),
				'pagination' => array(
					'total_items'  => $total_count,
					'total_pages'  => $per_page > 0 ? ceil( $total_count / $per_page ) : 1,
					'current_page' => $page,
					'per_page'     => $per_page,
				),
			);
		}
	
		// Transform results
		$table_data = array_map(
			function ( $item ) {
				return array(
					'source'     => sanitize_text_field( $item->source ),
					'visitors'   => absint( $item->visitors ),
					'percentage' => floatval( $item->percentage ),
				);
			},
			$results
		);
	
		return array(
			'data'       => $table_data,
			'pagination' => array(
				'total_items'  => absint( $total_count ),
				'total_pages'  => $per_page > 0 ? ceil( $total_count / $per_page ) : 1,
				'current_page' => absint( $page ),
				'per_page'     => $per_page,
			),
		);
	}
}