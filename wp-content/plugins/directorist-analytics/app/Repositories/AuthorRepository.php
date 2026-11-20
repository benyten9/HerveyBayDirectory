<?php
/**
 * Author Repository
 *
 * Handles author-specific analytics data access.
 *
 * @package DirectoristAnalytics
 * @subpackage Repositories
 * @since 1.0.0
 */

namespace DirectoristAnalytics\App\Repositories;

defined( 'ABSPATH' ) || exit;

use DirectoristAnalytics\App\Models\DailyStat;
use Exception;

/**
 * Author Repository Class
 *
 * @since 1.0.0
 */
class AuthorRepository {

		/**
	 * Get authors list with statistics
	 *
	 * @since 1.0.0
	 * @param int    $page     Current page number.
	 * @param int    $per_page Items per page.
	 * @param string $orderby  Order by field.
	 * @param string $order    Order direction (ASC/DESC).
	 * @return array
	 * @throws Exception If parameters are invalid.
	 */
	public function get_authors_list( $page = 1, $per_page = 20, $orderby = 'accessFrequency', $order = 'DESC', $start_date = null, $end_date = null ) {
		global $wpdb;
	
		$page     = absint( $page );
		$per_page = absint( $per_page );
		$offset   = ( $page - 1 ) * $per_page;
	
		// Validate orderby
		$allowed_orderby = array( 'name', 'listingsCount', 'registrationDate', 'lastAccess', 'accessFrequency' );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'accessFrequency';
		}
	
		// Validate order
		$order = strtoupper( $order );
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}
	
		// Map frontend fields to database columns
		$orderby_map = array(
			'name'             => 'u.display_name',
			'listingsCount'    => 'listingsCount',
			'registrationDate' => 'u.user_registered',
			'lastAccess'       => 'lastAccess',
			'accessFrequency'  => 'accessFrequency',
		);
	
		$orderby_column = $orderby_map[ $orderby ];
	
		// Build query with conditional date parameters
		if ( $start_date && $end_date ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT 
						u.ID as id,
						u.display_name as name,
						u.user_email as email,
						u.user_registered as registrationDate,
						(SELECT COUNT(*) 
						 FROM {$wpdb->posts} p 
						 WHERE p.post_author = u.ID 
						 AND p.post_type = %s 
						 AND p.post_status = 'publish') as listingsCount,
						(SELECT meta_value 
						 FROM {$wpdb->usermeta} 
						 WHERE user_id = u.ID 
						 AND meta_key = %s) as lastAccess,
						(SELECT meta_value 
						 FROM {$wpdb->usermeta} 
						 WHERE user_id = u.ID 
						 AND meta_key = %s) as accessFrequency
					FROM {$wpdb->users} u
					WHERE DATE(u.user_registered) BETWEEN %s AND %s
					AND EXISTS (
						SELECT 1 
						FROM {$wpdb->posts} p
						WHERE p.post_author = u.ID 
						AND p.post_type = %s
						AND p.post_status = 'publish'
					)
					ORDER BY {$orderby_column} {$order}
					LIMIT %d OFFSET %d",
					'at_biz_dir',
					'_directorist_last_login',
					'_directorist_login_count',
					$start_date,
					$end_date,
					'at_biz_dir',
					$per_page,
					$offset
				)
			);
		} else {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT 
						u.ID as id,
						u.display_name as name,
						u.user_email as email,
						u.user_registered as registrationDate,
						(SELECT COUNT(*) 
						 FROM {$wpdb->posts} p 
						 WHERE p.post_author = u.ID 
						 AND p.post_type = %s 
						 AND p.post_status = 'publish') as listingsCount,
						(SELECT meta_value 
						 FROM {$wpdb->usermeta} 
						 WHERE user_id = u.ID 
						 AND meta_key = %s) as lastAccess,
						(SELECT meta_value 
						 FROM {$wpdb->usermeta} 
						 WHERE user_id = u.ID 
						 AND meta_key = %s) as accessFrequency
					FROM {$wpdb->users} u
					WHERE EXISTS (
						SELECT 1 
						FROM {$wpdb->posts} 
						WHERE post_author = u.ID 
						AND post_type = %s
						AND post_status = 'publish'
					)
					ORDER BY {$orderby_column} {$order}
					LIMIT %d OFFSET %d",
					'at_biz_dir',
					'_directorist_last_login',
					'_directorist_login_count',
					'at_biz_dir',
					$per_page,
					$offset
				)
			);
		}
	
		if ( ! $results ) {
			return array();
		}
	
		return array_map( array( $this, 'transform_author_data' ), $results );
	}

	/**
	 * Get author by ID
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID.
	 * @return array|null
	 */
	public function get_author_by_id( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
					u.ID as id,
					u.display_name as name,
					u.user_email as email,
					u.user_registered as registrationDate,
					(SELECT COUNT(*) 
					 FROM {$wpdb->posts} p 
					 WHERE p.post_author = %d 
					 AND p.post_type = %s 
					 AND p.post_status = 'publish') as listingsCount,
					(SELECT meta_value 
					 FROM {$wpdb->usermeta} 
					 WHERE user_id = %d 
					 AND meta_key = %s) as lastAccess,
					(SELECT meta_value 
					 FROM {$wpdb->usermeta} 
					 WHERE user_id = %d 
					 AND meta_key = %s) as accessFrequency
				FROM {$wpdb->users} u
				WHERE u.ID = %d",
				$user_id,
				'at_biz_dir',
				$user_id,
				'_directorist_last_login',
				$user_id,
				'_directorist_login_count',
				$user_id
			)
		);

		return $result ? $this->transform_author_data( $result ) : null;
	}

	/**
	 * Get author performance statistics
	 *
	 * @since 1.0.0
	 * @param int    $user_id    User ID.
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array
	 */
	public function get_author_performance( $user_id, $start_date, $end_date ) {
		global $wpdb;
		$stats_table = DailyStat::get_table_name();

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					p.ID as listing_id,
					p.post_title as listing_name,
					COALESCE(SUM(ds.views), 0) as total_views,
					COALESCE(SUM(ds.clicks_total), 0) as total_clicks,
					COALESCE(SUM(ds.search_appearances), 0) as search_appearances
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->prefix}{$stats_table} ds 
					ON p.ID = ds.listing_id 
					AND ds.stat_date BETWEEN %s AND %s
				WHERE p.post_author = %d 
				AND p.post_type = %s 
				AND p.post_status = 'publish'
				GROUP BY p.ID, p.post_title
				ORDER BY total_views DESC",
				sanitize_text_field( $start_date ),
				sanitize_text_field( $end_date ),
				absint( $user_id ),
				'at_biz_dir'
			)
		);

		if ( ! $results ) {
			return array();
		}

		return array_map( array( $this, 'transform_performance_data' ), $results );
	}

	/**
	 * Get author listing IDs
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID.
	 * @return array
	 */
	public function get_author_listing_ids( $user_id ) {
		global $wpdb;

		$listing_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID 
				FROM {$wpdb->posts} 
				WHERE post_author = %d 
				AND post_type = %s 
				AND post_status = 'publish'",
				absint( $user_id ),
				'at_biz_dir'
			)
		);

		return array_map( 'absint', $listing_ids );
	}

	/**
	 * Get total authors count
	 *
	 * @since 1.0.0
	 * @return int
	 */
	public function get_total_authors_count( $start_date = null, $end_date = null ) {
		global $wpdb;
	
		if ( $start_date && $end_date ) {
			return absint(
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT u.ID)
						FROM {$wpdb->users} u
						WHERE DATE(u.user_registered) BETWEEN %s AND %s
						AND EXISTS (
							SELECT 1 
							FROM {$wpdb->posts} p
							WHERE p.post_author = u.ID 
							AND p.post_type = %s
							AND p.post_status = 'publish'
						)",
						$start_date,
						$end_date,
						'at_biz_dir'
					)
				)
			);
		} else {
			return absint(
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT u.ID)
						FROM {$wpdb->users} u
						INNER JOIN {$wpdb->posts} p ON p.post_author = u.ID
						WHERE p.post_type = %s
						AND p.post_status = 'publish'",
						'at_biz_dir'
					)
				)
			);
		}
	}

    /**
	 * Get most active authors by login count
	 *
	 * @since 1.0.0
	 * @param int $limit Limit results.
	 * @return array
	 */
	public function get_most_active_authors( $limit = 10 ) {
		return $this->get_authors_list( 1, absint( $limit ), 'accessFrequency', 'DESC' );
	}

	/**
	 * Transform author data for API response
	 *
	 * @since 1.0.0
	 * @param object $author Author object.
	 * @return array
	 */
	private function transform_author_data( $author ) {
		return array(
			'id'               => absint( $author->id ),
			'name'             => sanitize_text_field( $author->name ),
			'email'            => sanitize_email( $author->email ),
			'avatar'           => get_avatar_url( $author->id ),
			'listingsCount'    => absint( $author->listingsCount ),
			'registrationDate' => sanitize_text_field( $author->registrationDate ),
			'lastAccess'       => $author->lastAccess ? sanitize_text_field( $author->lastAccess ) : null,
			'accessFrequency'  => absint( $author->accessFrequency ),
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
}