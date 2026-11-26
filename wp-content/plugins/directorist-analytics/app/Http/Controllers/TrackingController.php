<?php
/**
 * Tracking Controller
 *
 * Handles REST API endpoints for click and search tracking.
 *
 * @package DirectoristAnalytics
 * @subpackage Controllers
 * @since 1.0.0
 */

namespace DirectoristAnalytics\App\Http\Controllers;

defined( 'ABSPATH' ) || exit;

use DirectoristAnalytics\App\Services\TrackingService;
use DirectoristAnalytics\WpMVC\Routing\Response;
use DirectoristAnalytics\WpMVC\RequestValidator\Validator;
use DirectoristAnalytics\App\Models\View;
use WP_REST_Request;
use Exception;

/**
 * Tracking Controller Class
 *
 * @since 1.0.0
 */
class TrackingController {

	/**
	 * Tracking service instance
	 *
	 * @var TrackingService
	 */
	public $tracking_service;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 * @param TrackingService $tracking_service Tracking service instance.
	 */
	public function __construct( TrackingService $tracking_service ) {
		$this->tracking_service = $tracking_service;
	}

	/**
	 * Track click event
	 *
	 * @since 1.0.0
	 * @param Validator       $validator       Validator instance.
	 * @param WP_REST_Request $wp_rest_request Request object.
	 * @return Response
	 */
	public function track_click( Validator $validator, WP_REST_Request $wp_rest_request ) {
		$validator->validate(
			array(
				'listing_id' => 'required|numeric',
				'click_type' => 'required|string|accepted:contact_form,website,phone,email',
			)
		);

		try {
			$listing_id = absint( $wp_rest_request->get_param( 'listing_id' ) );
			$click_type = sanitize_text_field( $wp_rest_request->get_param( 'click_type' ) );

			$result = $this->tracking_service->track_click( $listing_id, $click_type );

			if ( ! $result ) {
				return Response::send(
					array(
						'message' => esc_html__( 'Failed to track click', 'directorist-advanced-review' ),
					),
					422
				);
			}

			return Response::send(
				array(
					'success' => true,
					'message' => esc_html__( 'Click tracked successfully', 'directorist-advanced-review' ),
				)
			);
		} catch ( Exception $e ) {
			return Response::send(
				array(
					'message' => esc_html__( 'Error tracking click', 'directorist-advanced-review' ),
				),
				500
			);
		}
	}

	/**
	 * Track search appearances
	 *
	 * @since 1.0.0
	 * @param Validator       $validator       Validator instance.
	 * @param WP_REST_Request $wp_rest_request Request object.
	 * @return Response
	 */
	public function track_search( Validator $validator, WP_REST_Request $wp_rest_request ) {
		$validator->validate(
			array(
				'listings'      => 'required|array',
				'search_query'  => 'string',
				'total_results' => 'numeric',
			)
		);

		try {
			$listings      = $wp_rest_request->get_param( 'listings' );
			$search_query  = sanitize_text_field( $wp_rest_request->get_param( 'search_query' ) ?? '' );
			$total_results = absint( $wp_rest_request->get_param( 'total_results' ) ?? 0 );

			$tracked_count = 0;

			foreach ( $listings as $listing_data ) {
				if ( ! isset( $listing_data['listing_id'] ) ) {
					continue;
				}

				$search_data = array(
					'query'         => $search_query,
					'filters'       => $wp_rest_request->get_param( 'filters' ) ?? array(),
					'position'      => absint( $listing_data['position'] ?? 0 ),
					'total_results' => $total_results,
				);

				$result = $this->tracking_service->track_search_appearance(
					absint( $listing_data['listing_id'] ),
					$search_data
				);

				if ( $result ) {
					$tracked_count++;
				}
			}

			return Response::send(
				array(
					'success'       => true,
					'tracked_count' => $tracked_count,
					'message'       => esc_html__( 'Search appearances tracked successfully', 'directorist-advanced-review' ),
				)
			);
		} catch ( Exception $e ) {
			return Response::send(
				array(
					'message' => esc_html__( 'Error tracking search appearances', 'directorist-advanced-review' ),
				),
				500
			);
		}
	}


	/**
	 * Update screen resolution for existing view
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $wp_rest_request Request object.
	 * @return Response
	 */
	public function update_resolution( WP_REST_Request $wp_rest_request ) {
		try {
			$session_id = $this->tracking_service->get_session_id();
			$resolution = sanitize_text_field( $wp_rest_request->get_param( 'resolution' ) );
	
			if ( ! $resolution ) {
				return Response::send(
					array( 'message' => esc_html__( 'Resolution required', 'directorist-advanced-review' ) ),
					400
				);
			}
	
			// Get the most recent view for this session
			$recent_view = View::query()
				->where( 'session_id', $session_id )
				->order_by( 'created_at', 'DESC' )
				->limit( 1 )
				->first();
	
			if ( ! $recent_view ) {
				return Response::send(
					array( 'message' => esc_html__( 'No recent view found', 'directorist-advanced-review' ) ),
					404
				);
			}
	
			// Update views table
			$updated = View::query()
				->where( 'session_id', $session_id )
				->order_by( 'created_at', 'DESC' )
				->limit( 1 )
				->update( array( 'screen_resolution' => $resolution ) );
	
			// Update aggregated tech stats with actual resolution
			if ( $updated ) {
				global $wpdb;
				$stats_service = new \DirectoristAnalytics\App\Services\StatsService();
				$stat_date = gmdate( 'Y-m-d', strtotime( $recent_view->created_at ) );
				$tech_table = $wpdb->prefix . 'directorist_analytics_tech_stats_daily';
				
				// Step 1: Decrement the empty resolution entry (move count)
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$tech_table}
						SET count = GREATEST(count - 1, 0),
							updated_at = NOW()
						WHERE stat_date = %s
						AND browser <=> %s
						AND os <=> %s
						AND device_type <=> %s
						AND (screen_resolution = '' OR screen_resolution IS NULL)
						AND count > 0",
						$stat_date,
						$recent_view->browser,
						$recent_view->os,
						$recent_view->device_type
					)
				);
				
				// Step 2: Increment (or create) the actual resolution entry
				$stats_service->upsert_tech_stat(
					$stat_date,
					$recent_view->browser,
					$recent_view->os,
					$recent_view->device_type,
					$resolution
				);
			}
	
			return Response::send(
				array(
					'success' => true,
					'updated' => $updated,
					'message' => esc_html__( 'Resolution updated', 'directorist-advanced-review' ),
				)
			);
		} catch ( Exception $e ) {
			return Response::send(
				array( 'message' => esc_html__( 'Error updating resolution', 'directorist-advanced-review' ) ),
				500
			);
		}
	}
}