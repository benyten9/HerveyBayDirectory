<?php
/**
 * Overview Repository
 *
 * Handles data access for the Overview tab.
 *
 * @package DirectoristAnalytics
 * @subpackage Repositories
 * @since 1.0.0
 */

namespace DirectoristAnalytics\App\Repositories;

defined( 'ABSPATH' ) || exit;

use DirectoristAnalytics\App\Models\Activity;
use DirectoristAnalytics\App\Models\DailyStat;

/**
 * Overview Repository Class
 *
 * @since 1.0.0
 */
class OverviewRepository {

	/**
	 * Get total listings count (date-filtered)
	 *
	 * @since 1.0.0
	 * @param string|null $start_date Start date.
	 * @param string|null $end_date   End date.
	 * @return int
	 */
	public function get_total_listings( $start_date = null, $end_date = null ) {
		global $wpdb;
		
		$date_filter = '';
		if ( $start_date && $end_date ) {
			$date_filter = $wpdb->prepare(
				" AND post_date BETWEEN %s AND %s",
				$start_date . ' 00:00:00',
				$end_date . ' 23:59:59'
			);
		}
		
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) 
				FROM {$wpdb->posts} 
				WHERE post_type = %s 
				AND post_status = 'publish'
				{$date_filter}",
				'at_biz_dir'
			)
		);

		return absint( $count );
	}

	/**
	 * Get total reviews count (date-filtered)
	 *
	 * @since 1.0.0
	 * @param string|null $start_date Start date.
	 * @param string|null $end_date   End date.
	 * @return int
	 */
	public function get_total_reviews( $start_date = null, $end_date = null ) {
		global $wpdb;
		
		$date_filter = '';
		if ( $start_date && $end_date ) {
			$date_filter = $wpdb->prepare(
				" AND comment_date BETWEEN %s AND %s",
				$start_date . ' 00:00:00',
				$end_date . ' 23:59:59'
			);
		}

		$count = $wpdb->get_var(
			"SELECT COUNT(*) 
			FROM {$wpdb->comments} 
			WHERE comment_type = 'review' 
			AND comment_approved = '1'
			{$date_filter}"
		);

		return absint( $count );
	}

	/**
	 * Get total authors count
	 *
	 * @since 1.0.0
	 * @return int
	 */
	public function get_total_authors() {
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_author) 
				FROM {$wpdb->posts} 
				WHERE post_type = %s 
				AND post_status IN ('publish', 'pending', 'draft', 'private')",
				'at_biz_dir'
			)
		);

		return absint( $count );
	}

	/**
	 * Get total website clicks (date-filtered)
	 *
	 * @since 1.0.0
	 * @param string|null $start_date Start date.
	 * @param string|null $end_date   End date.
	 * @return int
	 */
	public function get_total_website_clicks( $start_date = null, $end_date = null ) {
		global $wpdb;  // ADD THIS
		
		$query = DailyStat::query()
			->select( [ 'SUM(clicks_website) as total' ] );
		
		if ( $start_date && $end_date ) {
			$query->where_raw( 
				$wpdb->prepare( 
					"stat_date BETWEEN %s AND %s", 
					$start_date, 
					$end_date 
				) 
			);
		}
		
		$result = $query->first();
	
		return $result ? absint( $result->total ) : 0;
	}

	/**
	 * Get recent activities
	 *
	 * @since 1.0.0
	 * @param int|null    $limit      Number of activities (0 or null = all).
	 * @param string|null $start_date Start date.
	 * @param string|null $end_date   End date.
	 * @return array
	 */
	public function get_recent_activities( $limit = null, $start_date = null, $end_date = null ) {
		global $wpdb;

		$query = Activity::query()
			->order_by_desc( 'created_at' );
		
		// Date filter
		if ( $start_date && $end_date ) {
			$query->where_raw( 
				$wpdb->prepare( 
					"created_at BETWEEN %s AND %s", 
					$start_date . ' 00:00:00', 
					$end_date . ' 23:59:59' 
				) 
			);
		}

		// Limit (if provided and > 0)
		if ( $limit && $limit > 0 ) {
			$query->limit( absint( $limit ) );
		}
		// If limit is null or 0, no LIMIT clause = return all
		
		$activities = $query->get();

		if ( ! $activities ) {
			return array();
		}

		return array_map( array( $this, 'transform_activity' ), $activities );
	}

	/**
	 * Transform activity for API response
	 *
	 * @since 1.0.0
	 * @param object $activity Activity object.
	 * @return array
	 */
	private function transform_activity( $activity ) {
		$type       = sanitize_key( $activity->activity_type );
		$object_id  = absint( $activity->object_id );
		$title      = '';
		$link       = '';

		switch ( $type ) {
			case 'listing_created':
				$listing = get_post( $object_id );
				$title   = $listing ? sanitize_text_field( $listing->post_title ) : __( 'Unknown Listing', 'directorist-analytics' );
				$link    = $listing ? get_permalink( $listing ) : '';
				break;

			case 'review_submitted':
				$comment = get_comment( $object_id );
				if ( $comment ) {
					// Get listing title (not reviewer name) for "New Review Submitted on [listing title]"
					$listing_id = absint( $comment->comment_post_ID );
					$listing    = get_post( $listing_id );
					$title      = $listing ? sanitize_text_field( $listing->post_title ) : __( 'Unknown Listing', 'directorist-analytics' );
					
					// Link to the listing page (review section)
					$link = $listing ? get_permalink( $listing ) . '#comment-' . $object_id : '';
				} else {
					$title = __( 'Unknown Review', 'directorist-analytics' );
					$link  = '';
				}
				break;

			case 'user_registered':
				$user = get_userdata( $object_id );
				if ( $user ) {
					// User display name only
					$title = sanitize_text_field( $user->display_name );
					
					// Link to author posts page
					$link = get_author_posts_url( $user->ID );
				} else {
					$title = __( 'Unknown User', 'directorist-analytics' );
					$link  = '';
				}
				break;

			default:
				$title = sanitize_text_field( $activity->title );
				$link  = '';
				break;
		}

		return array(
			'id'    => absint( $activity->id ),
			'type'  => $type,
			'title' => $title,  // Just the name
			'time'  => sprintf(
				/* translators: %s: Time difference */
				__( '%s ago', 'directorist-analytics' ),
				human_time_diff(
					strtotime( $activity->created_at ),
					current_time( 'timestamp' )
				)
			),
			'date'  => sanitize_text_field( $activity->created_at ),
			'link'  => esc_url( $link ),  // Just the URL
		);
	}

	/**
	 * Get overview summary (date-filtered)
	 *
	 * @since 1.0.0
	 * @param string|null $start_date Start date.
	 * @param string|null $end_date   End date.
	 * @return array
	 */
	public function get_overview_summary( $start_date = null, $end_date = null ) {
		return array(
			'total_listings'       => $this->get_total_listings( $start_date, $end_date ),
			'total_reviews'        => $this->get_total_reviews( $start_date, $end_date ),
			'total_authors'        => $this->get_total_authors(), // No date filter (lifetime)
			'total_website_clicks' => $this->get_total_website_clicks( $start_date, $end_date ),
		);
	}

	/**
	 * Get global daily trends chart
	 *
	 * @since 1.0.0
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array
	 */
	public function get_global_trends_chart( $start_date, $end_date ) {
		global $wpdb;
		$stats_table = DailyStat::get_table_name();

		// Get daily aggregated data
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					stat_date,
					SUM(views) as total_views,
					SUM(search_appearances) as total_searches
				FROM {$wpdb->prefix}{$stats_table}
				WHERE stat_date BETWEEN %s AND %s
				GROUP BY stat_date
				ORDER BY stat_date ASC",
				$start_date,
				$end_date
			)
		);

		// Create a map of date => data from database results
		$data_map = array();
		if ( $results ) {
			foreach ( $results as $row ) {
				// Normalize date format to ensure consistent matching
				$date_key = date( 'Y-m-d', strtotime( $row->stat_date ) );
				$data_map[ $date_key ] = array(
					'views'    => absint( $row->total_views ),
					'searches' => absint( $row->total_searches ),
				);
			}
		}

		// Generate all dates in the range
		$start = new \DateTime( $start_date );
		$end = new \DateTime( $end_date );
		$end->modify( '+1 day' ); // Include end date in range

		$dates = array();
		$views = array();
		$searches = array();

		$current = clone $start;
		while ( $current < $end ) {
			$date_str = $current->format( 'Y-m-d' );
			$date_label = $current->format( 'M d' ); // 'Nov 02'

			$dates[] = $date_label;
			$views[] = isset( $data_map[ $date_str ] ) ? $data_map[ $date_str ]['views'] : 0;
			$searches[] = isset( $data_map[ $date_str ] ) ? $data_map[ $date_str ]['searches'] : 0;

			$current->modify( '+1 day' );
		}

		return array(
			'categories' => $dates,
			'series'     => array(
				array(
					'name' => __( 'Views', 'directorist-analytics' ),
					'data' => $views,
				),
				array(
					'name' => __( 'Search Appearances', 'directorist-analytics' ),
					'data' => $searches,
				),
			),
		);
	}
}