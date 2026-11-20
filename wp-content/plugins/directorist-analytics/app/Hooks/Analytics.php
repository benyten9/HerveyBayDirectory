<?php
/**
 * Analytics Hooks
 *
 * Registers WordPress/Directorist hooks for automatic analytics tracking.
 *
 * @package DirectoristAnalytics
 * @subpackage Hooks
 * @since 1.0.0
 */

namespace DirectoristAnalytics\App\Hooks;

defined( 'ABSPATH' ) || exit;

use DirectoristAnalytics\App\Services\TrackingService;
use DirectoristAnalytics\App\Services\ActivityService;
use DirectoristAnalytics\App\Services\AuthorLoginService;

/**
 * Analytics Hooks Class
 *
 * @since 1.0.0
 */
class Analytics {

	/**
	 * Tracking service instance
	 *
	 * @var TrackingService
	 */
	private $tracking_service;

	/**
	 * Activity service instance
	 *
	 * @var ActivityService
	 */
	private $activity_service;

	/**
	 * Author login service instance
	 *
	 * @var AuthorLoginService
	 */
	private $author_login_service;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->tracking_service      = new TrackingService();
		$this->activity_service      = new ActivityService();
		$this->author_login_service  = new AuthorLoginService();

		$this->register_hooks();
	}

	/**
	 * Register all WordPress hooks
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_hooks() {
		// View tracking (hook into Directorist core's view counting)
		add_action( 'directorist_listing_views_count_updated', array( $this, 'track_detailed_view' ), 10, 1 );
	
		// Activity tracking
		add_action( 'atbdp_listing_published', array( $this, 'record_listing_published' ), 10, 1 );
		add_action( 'directorist_review_updated', array( $this, 'record_review_updated' ), 10, 2 );
		add_action( 'user_register', array( $this, 'record_user_registered' ), 10, 1 );
	
		// Author login tracking
		add_action( 'wp_login', array( $this, 'track_author_login' ), 10, 2 );
	
		// Search tracking (using Directorist hook)
		add_action( 'directorist_instant_search_completed', array( $this, 'track_search_results' ), 10, 2 );

		// Contact form tracking
		add_action( 'directorist_email_on_send_contact_message_to_admin', array( $this, 'track_contact_form_submission' ), 10, 1 );
	}

	/**
	 * Track contact form submission
	 *
	 * Triggered when a contact form is successfully sent.
	 *
	 * @since 1.0.0
	 * @param array $action_args Contact form data.
	 * @return void
	 */
	public function track_contact_form_submission( $action_args ) {
		if ( empty( $action_args['listing_id'] ) || empty( $action_args['is_sent'] ) ) {
			return;
		}

		// Only track if email was successfully sent
		if ( ! $action_args['is_sent'] ) {
			return;
		}

		$listing_id = absint( $action_args['listing_id'] );
		
		// Track the contact form click
		$this->tracking_service->track_click( $listing_id, 'contact_form' );
	}

	/**
	 * Track detailed view metadata
	 *
	 * Triggered when Directorist core counts a view.
	 *
	 * @since 1.0.0
	 * @param int $listing_id Listing ID.
	 * @return void
	 */
	public function track_detailed_view( $listing_id ) {
		$this->tracking_service->track_detailed_view( absint( $listing_id ) );
	}

	/**
	 * Record listing published activity
	 *
	 * @since 1.0.0
	 * @param int $listing_id Listing ID.
	 * @return void
	 */
	public function record_listing_published( $listing_id ) {
		$this->activity_service->record_listing_created( absint( $listing_id ) );
	}

	/**
	 * Record review updated activity
	 *
	 * @since 1.0.0
	 * @param int   $comment_id   Comment ID.
	 * @param array $comment_data Comment data.
	 * @return void
	 */
	public function record_review_updated( $comment_id, $comment_data ) {
		$comment = get_comment( absint( $comment_id ) );

		if ( ! $comment || 'review' !== $comment->comment_type ) {
			return;
		}

		$listing_id = absint( $comment->comment_post_ID );

		$this->activity_service->record_review_submitted( absint( $comment_id ), $listing_id );
	}

	/**
	 * Record user registered activity
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function record_user_registered( $user_id ) {
		$this->activity_service->record_user_registered( absint( $user_id ) );
	}

	/**
	 * Track author login
	 *
	 * @since 1.0.0
	 * @param string   $user_login Username.
	 * @param \WP_User $user       User object.
	 * @return void
	 */
	public function track_author_login( $user_login, $user ) {
		$this->author_login_service->track_login( $user_login, $user );
	}

	/**
	 * Track search results
	 *
	 * Hooks into Directorist instant search completion.
	 *
	 * @since 1.0.0
	 * @param object $listings Directorist_Listings object.
	 * @param array  $args     Search arguments.
	 * @return void
	 */
	public function track_search_results( $listings, $args ) {
		// Get listing IDs from the results
		$listing_ids = $listings->post_ids();
		
		if ( empty( $listing_ids ) ) {
			return; // No results to track
		}

		// Get search query
		$search_query = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';

		// Extract filters
		$filters = array();
		if ( ! empty( $_POST['in_cat'] ) ) {
			$filters['category'] = absint( $_POST['in_cat'] );
		}
		if ( ! empty( $_POST['in_loc'] ) ) {
			$filters['location'] = absint( $_POST['in_loc'] );
		}
		if ( ! empty( $_POST['price_range'] ) ) {
			$filters['price_range'] = sanitize_text_field( wp_unslash( $_POST['price_range'] ) );
		}

		// Get total results
		$total_results = isset( $listings->query_results->total ) ? absint( $listings->query_results->total ) : count( $listing_ids );

		// Track each listing's appearance in search
		foreach ( $listing_ids as $position => $listing_id ) {
			$search_data = array(
				'query'         => $search_query,
				'filters'       => $filters,
				'position'      => $position + 1, // 1-indexed
				'total_results' => $total_results,
			);

			$this->tracking_service->track_search_appearance( $listing_id, $search_data );
		}
	}

	/**
	 * Capture search response on shutdown
	 *
	 * This runs after wp_send_json() but before output is sent
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function capture_search_response_on_shutdown() {
		// Get the output buffer if it exists
		$levels = ob_get_level();
		
		if ( $levels > 0 ) {
			$output = ob_get_contents();
			
			if ( ! empty( $output ) && ! empty( $this->temp_search_data ) ) {
				// Decode JSON response
				$response = json_decode( $output, true );
				
				if ( is_array( $response ) && isset( $response['search_result'] ) ) {
					$this->process_search_response( $response );
				}
			}
		}
	}

	/**
	 * Process search response and track listing IDs
	 *
	 * @since 1.0.0
	 * @param array $response AJAX response array.
	 * @return void
	 */
	private function process_search_response( $response ) {
		if ( empty( $this->temp_search_data ) ) {
			return;
		}
		error_log( 'test' );
		$listing_ids = array();

		// Extract listing IDs from HTML response
		if ( isset( $response['search_result'] ) && ! empty( $response['search_result'] ) ) {
			$html = $response['search_result'];
			
			// Try multiple patterns to extract listing IDs
			$patterns = array(
				'/data-id=["\'](\d+)["\']/',           // data-id="123"
				'/data-post-id=["\'](\d+)["\']/',      // data-post-id="123"
				'/data-listing-id=["\'](\d+)["\']/',   // data-listing-id="123"
			);

			foreach ( $patterns as $pattern ) {
				preg_match_all( $pattern, $html, $matches );
				
				if ( ! empty( $matches[1] ) ) {
					$listing_ids = array_unique( array_map( 'absint', $matches[1] ) );
					break; // Stop after first successful pattern
				}
			}
		}

		// Track search appearances if we have listing IDs
		if ( ! empty( $listing_ids ) ) {
			$total_results = isset( $response['count'] ) ? absint( $response['count'] ) : count( $listing_ids );
			
			foreach ( $listing_ids as $position => $listing_id ) {
				$search_data = array(
					'query'         => $this->temp_search_data['query'],
					'filters'       => $this->temp_search_data['filters'],
					'position'      => $position + 1,
					'total_results' => $total_results,
				);

				$this->tracking_service->track_search_appearance( $listing_id, $search_data );
			}
		}

		// Clean up temp data
		unset( $this->temp_search_data );
	}

	/**
	 * Capture listing IDs from search response
	 *
	 * @since 1.0.0
	 * @param mixed $response Response data.
	 * @param int   $status_code HTTP status code.
	 * @return mixed
	 */
	public function capture_search_listing_ids( $response, $status_code ) {
		// Remove filter to avoid infinite loops
		remove_filter( 'wp_send_json_success', array( $this, 'capture_search_listing_ids' ), 10 );

		// Check if we have search data
		if ( empty( $this->temp_search_data ) ) {
			return $response;
		}

		// Try to extract listing IDs from response
		$listing_ids = array();

		// Check different response structures
		if ( isset( $response['count'] ) && $response['count'] > 0 ) {
			// Response has count, try to get IDs from global $wp_query or other sources
			// For now, we'll use a different approach
			
			// Since Directorist doesn't expose listing IDs directly in AJAX response,
			// we'll hook earlier to capture them from the Listings object
			// This is handled via output buffering approach below
		}

		// Alternative: Use output buffer to extract from HTML
		// This is more reliable but requires parsing
		if ( isset( $response['search_result'] ) && ! empty( $response['search_result'] ) ) {
			$html = $response['search_result'];
			
			// Extract listing IDs from HTML (looking for data-listing-id or similar)
			preg_match_all( '/data-post-id=["\'](\d+)["\']/', $html, $matches );
			
			if ( ! empty( $matches[1] ) ) {
				$listing_ids = array_map( 'absint', $matches[1] );
			}
		}

		// Track search appearances if we have listing IDs
		if ( ! empty( $listing_ids ) && ! empty( $this->temp_search_data ) ) {
			$total_results = isset( $response['count'] ) ? absint( $response['count'] ) : count( $listing_ids );
			
			foreach ( $listing_ids as $position => $listing_id ) {
				$search_data = array(
					'query'         => $this->temp_search_data['query'],
					'filters'       => $this->temp_search_data['filters'],
					'position'      => $position + 1,
					'total_results' => $total_results,
				);

				$this->tracking_service->track_search_appearance( $listing_id, $search_data );
			}
		}

		// Clean up temp data
		unset( $this->temp_search_data );

		return $response;
	}

	/**
	 * Temporary storage for search data
	 *
	 * @var array
	 */
	private $temp_search_data = array();
}