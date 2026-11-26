<?php
/**
 * Analytics Controller
 *
 * Handles REST API endpoints for fetching analytics data.
 *
 * @package DirectoristAnalytics
 * @subpackage Controllers
 * @since 1.0.0
 */

namespace DirectoristAnalytics\App\Http\Controllers;

defined( 'ABSPATH' ) || exit;

use DirectoristAnalytics\App\Http\Controllers\Controller;
use DirectoristAnalytics\App\Repositories\OverviewRepository;
use DirectoristAnalytics\App\Repositories\AnalyticsRepository;
use DirectoristAnalytics\App\Repositories\AuthorRepository;
use DirectoristAnalytics\App\Repositories\AdminRepository;
use DirectoristAnalytics\WpMVC\Routing\Response;
use DirectoristAnalytics\WpMVC\RequestValidator\Validator;
use WP_REST_Request;
use Exception;

/**
 * Analytics Controller Class
 *
 * @since 1.0.0
 */
class AnalyticsController extends Controller {

	/**
	 * Overview repository
	 *
	 * @var OverviewRepository
	 */
	private $overview_repository;

	/**
	 * Analytics repository
	 *
	 * @var AnalyticsRepository
	 */
	private $analytics_repository;

	/**
	 * Author repository
	 *
	 * @var AuthorRepository
	 */
	private $author_repository;

	/**
	 * Admin repository
	 *
	 * @var AdminRepository
	 */
	private $admin_repository;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->overview_repository  = new OverviewRepository();
		$this->analytics_repository = new AnalyticsRepository();
		$this->author_repository    = new AuthorRepository();
		$this->admin_repository     = new AdminRepository();
	}

	/**
	 * Get overview statistics
	 *
	 * @since 1.0.0
	 * @param Validator       $validator       Validator instance.
	 * @param WP_REST_Request $wp_rest_request Request object.
	 * @return Response
	 */
	public function get_overview( Validator $validator, WP_REST_Request $wp_rest_request ) {
		$validator->validate(
			array(
				'start_date'     => 'string',
				'end_date'       => 'string',
				'activity_limit' => 'numeric|min:0|max:500'
			)
		);

		try {
			// Get date range (defaults to last 30 days)
			$date_range = $this->get_date_range( $wp_rest_request );

			// Get summary (filtered by date range)
			$summary = $this->overview_repository->get_overview_summary( 
				$date_range['start_date'], 
				$date_range['end_date'] 
			);

			// Get activity limit from request (null = all activities)
			$activity_limit = $wp_rest_request->get_param( 'activity_limit' );
			if ( $activity_limit !== null ) {
				$activity_limit = absint( $activity_limit );
			}

			// Get recent activities (filtered by date range)
			$activities = $this->overview_repository->get_recent_activities( 
				$activity_limit,  // Use parameter (not hardcoded 20)
				$date_range['start_date'], 
				$date_range['end_date'] 
			);

			// Get global trends chart data
			$chart_data = $this->overview_repository->get_global_trends_chart(
				$date_range['start_date'],
				$date_range['end_date']
			);

			return Response::send(
				array(
					'summary'    => $summary,
					'activities' => $activities,
					'chart_data' => $chart_data,  // NEW
					'date_range' => $date_range,
				)
			);
		} catch ( Exception $e ) {
			return Response::send(
				array(
					'message' => esc_html__( 'Error fetching overview data', 'directorist-advanced-review' ),
				),
				500
			);
		}
	}

	/**
	 * Get listing analytics
	 *
	 * @since 1.0.0
	 * @param Validator       $validator       Validator instance.
	 * @param WP_REST_Request $wp_rest_request Request object.
	 * @return Response
	 */
	public function get_listing_analytics( Validator $validator, WP_REST_Request $wp_rest_request ) {
		$validator->validate(
			array(
				'id'         => 'required|numeric',
				'start_date' => 'string',
				'end_date'   => 'string',
			)
		);

		try {
			$listing_id = absint( $wp_rest_request->get_param( 'id' ) );
			$date_range = $this->get_date_range( $wp_rest_request );

			// Get all analytics data for this listing
			$stats         = $this->analytics_repository->get_listing_stats( $listing_id, $date_range['start_date'], $date_range['end_date'] );
			$chart_data    = $this->analytics_repository->get_daily_chart_data( $listing_id, $date_range['start_date'], $date_range['end_date'] );
			$countries     = $this->analytics_repository->get_top_countries( $listing_id, 10 );
			$referrers     = $this->analytics_repository->get_top_referrers( $listing_id, 10 );
			$channels      = $this->analytics_repository->get_traffic_channels( $listing_id, $date_range['start_date'], $date_range['end_date'] );
			$browsers      = $this->analytics_repository->get_browser_stats( $listing_id );
			$os            = $this->analytics_repository->get_os_stats( $listing_id );
			$devices       = $this->analytics_repository->get_device_type_stats( $listing_id );
			$resolutions   = $this->analytics_repository->get_screen_resolution_stats( $listing_id );
			$clicks        = $this->analytics_repository->get_clicks_breakdown( $listing_id, $date_range['start_date'], $date_range['end_date'] );
			$search        = $this->analytics_repository->get_search_appearances( $listing_id, $date_range['start_date'], $date_range['end_date'] );

			return Response::send(
				array(
					'stats'      => $stats,
					'chart_data' => $chart_data,
					'traffic'    => array(
						'countries' => $countries,
						'referrers' => $referrers,
						'channels'  => $channels,
					),
					'tech'       => array(
						'browsers'    => $browsers,
						'os'          => $os,
						'devices'     => $devices,
						'resolutions' => $resolutions,
					),
					'clicks'     => $clicks,
					'search'     => $search,
					'date_range' => $date_range,
				)
			);
		} catch ( Exception $e ) {
			return Response::send(
				array(
					'message' => $e->getMessage(),
				),
				500
			);
		}
	}

	/**
	 * Get authors list
	 *
	 * @since 1.0.0
	 * @param Validator       $validator       Validator instance.
	 * @param WP_REST_Request $wp_rest_request Request object.
	 * @return Response
	 */
	public function get_authors( Validator $validator, WP_REST_Request $wp_rest_request ) {
		$validator->validate(
			array(
				'page'       => 'numeric|min:1',
				'per_page'   => 'numeric|min:1|max:100',
				'orderby'    => 'string|accepted:name,listingsCount,registrationDate,lastAccess,accessFrequency',
				'order'      => 'string|accepted:ASC,DESC',
				'start_date' => 'string',
				'end_date'   => 'string',
			)
		);
	
		try {
			$page       = absint( $wp_rest_request->get_param( 'page' ) ?: 1 );
			$per_page   = absint( $wp_rest_request->get_param( 'per_page' ) ?: 20 );
			$orderby    = sanitize_text_field( $wp_rest_request->get_param( 'orderby' ) ?: 'accessFrequency' );
			$order      = strtoupper( $wp_rest_request->get_param( 'order' ) ?: 'DESC' );
			// For authors, only use dates if explicitly provided (don't default to 30 days)
			$start_date = $wp_rest_request->get_param( 'start_date' );
			$end_date   = $wp_rest_request->get_param( 'end_date' );

			// Only use dates if BOTH are provided
			if ( $start_date && $end_date ) {
				$date_range = array(
					'start_date' => sanitize_text_field( $start_date ),
					'end_date'   => sanitize_text_field( $end_date ),
				);
				
				$authors = $this->author_repository->get_authors_list( 
					$page, 
					$per_page, 
					$orderby, 
					$order,
					$date_range['start_date'],
					$date_range['end_date']
				);
				$total_count = $this->author_repository->get_total_authors_count(
					$date_range['start_date'],
					$date_range['end_date']
				);
			} else {
				// No dates - show all authors
				$date_range = null;
				
				$authors = $this->author_repository->get_authors_list( 
					$page, 
					$per_page, 
					$orderby, 
					$order,
					null,
					null
				);
				$total_count = $this->author_repository->get_total_authors_count( null, null );
			}
			$pagination  = $this->pagination( $wp_rest_request, $total_count, $per_page );
	
			$response = array_merge(
				array( 'authors' => $authors ),
				$pagination
			);
			
			// Only include date_range if filtering by date
			if ( $date_range ) {
				$response['date_range'] = $date_range;
			}
			
			return Response::send( $response );
		} catch ( Exception $e ) {
			return Response::send(
				array(
					'message' => esc_html__( 'Error fetching authors', 'directorist-advanced-review' ),
				),
				500
			);
		}
	}

	/**
	 * Get author details
	 *
	 * @since 1.0.0
	 * @param Validator       $validator       Validator instance.
	 * @param WP_REST_Request $wp_rest_request Request object.
	 * @return Response
	 */
	public function get_author_details( Validator $validator, WP_REST_Request $wp_rest_request ) {
		$validator->validate(
			array(
				'id'         => 'required|numeric',
				'start_date' => 'string',
				'end_date'   => 'string',
			)
		);

		try {
			$user_id    = absint( $wp_rest_request->get_param( 'id' ) );
			$date_range = $this->get_date_range( $wp_rest_request );

			$author      = $this->author_repository->get_author_by_id( $user_id );
			$performance = $this->author_repository->get_author_performance( $user_id, $date_range['start_date'], $date_range['end_date'] );

			if ( ! $author ) {
				return Response::send(
					array(
						'message' => esc_html__( 'Author not found', 'directorist-advanced-review' ),
					),
					404
				);
			}

			return Response::send(
				array(
					'author'      => $author,
					'performance' => $performance,
					'date_range'  => $date_range,
				)
			);
		} catch ( Exception $e ) {
			return Response::send(
				array(
					'message' => $e->getMessage(),
				),
				500
			);
		}
	}

	/**
	 * Get top listings
	 *
	 * @since 1.0.0
	 * @param Validator       $validator       Validator instance.
	 * @param WP_REST_Request $wp_rest_request Request object.
	 * @return Response
	 */
	public function get_top_listings( Validator $validator, WP_REST_Request $wp_rest_request ) {
		$validator->validate(
			array(
				'metric'     => 'string|accepted:views,clicks,searches',
				'limit'      => 'numeric|min:1|max:50',
				'start_date' => 'string',
				'end_date'   => 'string',
			)
		);

		try {
			$metric     = sanitize_text_field( $wp_rest_request->get_param( 'metric' ) ?: 'views' );
			$limit      = absint( $wp_rest_request->get_param( 'limit' ) ?: 10 );
			$date_range = $this->get_date_range( $wp_rest_request );

			$listings = $this->admin_repository->get_top_listings( $metric, $limit, $date_range['start_date'], $date_range['end_date'] );

			return Response::send(
				array(
					'listings'   => $listings,
					'date_range' => $date_range,
				)
			);
		} catch ( Exception $e ) {
			return Response::send(
				array(
					'message' => esc_html__( 'Error fetching top listings', 'directorist-advanced-review' ),
				),
				500
			);
		}
	}

	/**
	 * Get listings table (for modal)
	 *
	 * @since 1.0.0
	 * @param Validator       $validator        Validator instance.
	 * @param WP_REST_Request $wp_rest_request Request object.
	 * @return WP_REST_Response
	 */
	public function get_listings_table( Validator $validator, WP_REST_Request $wp_rest_request ) {
		$validator->validate(
			array(
				'page'       => 'numeric|min:1',
				'per_page'   => 'numeric|min:-1|max:9999',
				'orderby'    => 'string|accepted:name,views,clicks,searches',
				'order'      => 'string|accepted:asc,desc,ASC,DESC',
				'start_date' => 'string',
				'end_date'   => 'string',
			)
		);

		try {
			$date_range = $this->get_date_range( $wp_rest_request );
			$page       = absint( $wp_rest_request->get_param( 'page' ) ?: 1 );
			$per_page   = intval( $wp_rest_request->get_param( 'per_page' ) ?: 10 );
			$orderby    = sanitize_text_field( $wp_rest_request->get_param( 'orderby' ) ?: 'views' );
			$order      = strtoupper( sanitize_text_field( $wp_rest_request->get_param( 'order' ) ?: 'DESC' ) );

			$result = $this->admin_repository->get_listings_table(
				$page,
				$per_page,
				$orderby,
				$order,
				$date_range['start_date'],
				$date_range['end_date']
			);

			return Response::send(
				array_merge(
					$result,
					array( 'date_range' => $date_range )
				)
			);
		} catch ( Exception $e ) {
			return Response::send(
				array(
					'message' => esc_html__( 'Error fetching listings table', 'directorist-advanced-review' ),
				),
				500
			);
		}
	}

	/**
	 * Get global traffic sources
	 *
	 * @since 1.0.0
	 * @param Validator       $validator       Validator instance.
	 * @param WP_REST_Request $wp_rest_request Request object.
	 * @return Response
	 */
	public function get_traffic_sources( Validator $validator, WP_REST_Request $wp_rest_request ) {
		$validator->validate(
			array(
				'limit' => 'numeric|min:1|max:50',
			)
		);

		try {
			$limit = absint( $wp_rest_request->get_param( 'limit' ) ?: 10 );

			$sources = $this->admin_repository->get_global_traffic_sources( $limit );

			return Response::send(
				array(
					'sources' => $sources,
				)
			);
		} catch ( Exception $e ) {
			return Response::send(
				array(
					'message' => esc_html__( 'Error fetching traffic sources', 'directorist-advanced-review' ),
				),
				500
			);
		}
	}

	/**
	 * Get global tech stats
	 *
	 * @since 1.0.0
	 * @param Validator       $validator       Validator instance.
	 * @param WP_REST_Request $wp_rest_request Request object.
	 * @return Response
	 */
	public function get_tech_stats( Validator $validator, WP_REST_Request $wp_rest_request ) {
		$validator->validate(
			array(
				'type'       => 'string|accepted:browsers,os,devices,resolutions',
				'start_date' => 'string',
				'end_date'   => 'string',
			)
		);
	
		try {
			$type       = sanitize_text_field( $wp_rest_request->get_param( 'type' ) ?: 'browsers' );
			$date_range = $this->get_date_range( $wp_rest_request );
	
			$stats = $this->admin_repository->get_global_tech_stats( 
				$type,
				$date_range['start_date'],
				$date_range['end_date']
			);
	
			return Response::send(
				array(
					'stats'      => $stats,
					'type'       => $type,
					'date_range' => $date_range,
				)
			);
		} catch ( Exception $e ) {
			return Response::send(
				array(
					'message' => esc_html__( 'Error fetching tech stats', 'directorist-advanced-review' ),
				),
				500
			);
		}
	}

	/**
	 * Get contact form usage chart
	 *
	 * @since 1.0.0
	 * @param Validator       $validator       Validator instance.
	 * @param WP_REST_Request $wp_rest_request Request object.
	 * @return Response
	 */
	public function get_contact_form_usage( Validator $validator, WP_REST_Request $wp_rest_request ) {
		$validator->validate(
			array(
				'limit'      => 'numeric|min:1|max:20',
				'start_date' => 'string',
				'end_date'   => 'string',
			)
		);

		try {
			$limit      = absint( $wp_rest_request->get_param( 'limit' ) ?: 5 );
			$date_range = $this->get_date_range( $wp_rest_request );

			$chart = $this->admin_repository->get_contact_form_usage_chart( $limit, $date_range['start_date'], $date_range['end_date'] );

			return Response::send(
				array(
					'chart'      => $chart,
					'date_range' => $date_range,
				)
			);
		} catch ( Exception $e ) {
			return Response::send(
				array(
					'message' => esc_html__( 'Error fetching contact form usage', 'directorist-advanced-review' ),
				),
				500
			);
		}
	}

	/**
	 * Get contact form usage table (for modal)
	 *
	 * @since 1.0.0
	 * @param Validator       $validator        Validator instance.
	 * @param WP_REST_Request $wp_rest_request Request object.
	 * @return WP_REST_Response
	 */
	public function get_contact_form_table( Validator $validator, WP_REST_Request $wp_rest_request ) {
		$validator->validate(
			array(
				'page'       => 'numeric|min:1',
				'per_page'   => 'numeric|min:-1|max:9999',
				'start_date' => 'string',
				'end_date'   => 'string',
			)
		);

		try {
			$date_range = $this->get_date_range( $wp_rest_request );
			$page       = absint( $wp_rest_request->get_param( 'page' ) ?: 1 );
			$per_page   = intval( $wp_rest_request->get_param( 'per_page' ) ?: 10 );

			$result = $this->admin_repository->get_contact_form_usage_table(
				$page,
				$per_page,
				$date_range['start_date'],
				$date_range['end_date']
			);

			return Response::send(
				array_merge(
					$result,
					array( 'date_range' => $date_range )
				)
			);
		} catch ( Exception $e ) {
			return Response::send(
				array(
					'message' => esc_html__( 'Error fetching contact form usage table', 'directorist-advanced-review' ),
				),
				500
			);
		}
	}

	/**
	 * Get search appearances chart
	 *
	 * @since 1.0.0
	 * @param Validator       $validator       Validator instance.
	 * @param WP_REST_Request $wp_rest_request Request object.
	 * @return Response
	 */
	public function get_search_chart( Validator $validator, WP_REST_Request $wp_rest_request ) {
		$validator->validate(
			array(
				'listing_ids' => 'string',  // Optional
				'limit'       => 'numeric|min:1|max:10',
				'start_date'  => 'string',  // Use date range
				'end_date'    => 'string',  // Use date range
			)
		);
	
		try {
			$date_range = $this->get_date_range( $wp_rest_request );  // Get date range
			$limit      = absint( $wp_rest_request->get_param( 'limit' ) ?: 5 );
	
			// Get listing IDs
			$listing_ids_param = $wp_rest_request->get_param( 'listing_ids' );
			
			if ( $listing_ids_param ) {
				// Use provided listing IDs
				$listing_ids = array_map( 'absint', explode( ',', sanitize_text_field( $listing_ids_param ) ) );
			} else {
				// Auto-fetch top listings by search appearances
				$listing_ids = $this->admin_repository->get_top_listing_ids_by_search( 
					$limit,
					$date_range['start_date'],
					$date_range['end_date']
				);
			}
	
			if ( empty( $listing_ids ) ) {
				return Response::send(
					array(
						'chart'      => array(),
						'date_range' => $date_range,
					)
				);
			}
	
			$chart = $this->admin_repository->get_search_appearances_chart( 
				$listing_ids, 
				$date_range['start_date'],
				$date_range['end_date']
			);
	
			return Response::send(
				array(
					'chart'      => $chart,
					'date_range' => $date_range,
				)
			);
		} catch ( Exception $e ) {
			return Response::send(
				array(
					'message' => esc_html__( 'Error fetching search appearances chart', 'directorist-advanced-review' ),
				),
				500
			);
		}
	}

		/**
	 * Get top countries globally
	 *
	 * @since 1.0.0
	 * @param Validator       $validator       Validator instance.
	 * @param WP_REST_Request $wp_rest_request Request object.
	 * @return Response
	 */
	public function get_top_countries( Validator $validator, WP_REST_Request $wp_rest_request ) {
		$validator->validate(
			array(
				'limit'      => 'numeric|min:1|max:50',
				'start_date' => 'string',
				'end_date'   => 'string',
			)
		);
	
		try {
			$limit      = absint( $wp_rest_request->get_param( 'limit' ) ?: 10 );
			$date_range = $this->get_date_range( $wp_rest_request );
	
			$countries = $this->admin_repository->get_top_countries_global( 
				$limit, 
				$date_range['start_date'], 
				$date_range['end_date'] 
			);
	
			return Response::send(
				array(
					'countries'  => $countries,
					'date_range' => $date_range,
				)
			);
		} catch ( Exception $e ) {
			return Response::send(
				array(
					'message' => esc_html__( 'Error fetching top countries', 'directorist-advanced-review' ),
				),
				500
			);
		}
	}

	/**
	 * Get countries table (for modal)
	 *
	 * @since 1.0.0
	 * @param Validator       $validator        Validator instance.
	 * @param WP_REST_Request $wp_rest_request Request object.
	 * @return WP_REST_Response
	 */
	public function get_countries_table( Validator $validator, WP_REST_Request $wp_rest_request ) {
		$validator->validate(
			array(
				'page'       => 'numeric|min:1',
				'per_page'   => 'numeric|min:-1|max:9999',
				'start_date' => 'string',
				'end_date'   => 'string',
			)
		);

		try {
			$date_range = $this->get_date_range( $wp_rest_request );
			$page       = absint( $wp_rest_request->get_param( 'page' ) ?: 1 );
			$per_page   = intval( $wp_rest_request->get_param( 'per_page' ) ?: 10 );

			$result = $this->admin_repository->get_countries_table(
				$page,
				$per_page,
				$date_range['start_date'],
				$date_range['end_date']
			);

			return Response::send( $result );
		} catch ( Exception $e ) {
			return Response::send(
				array(
					'message' => esc_html__( 'Error fetching countries table', 'directorist-advanced-review' ),
				),
				500
			);
		}
	}

	/**
	 * Get top search engines globally
	 *
	 * @since 1.0.0
	 * @param Validator       $validator       Validator instance.
	 * @param WP_REST_Request $wp_rest_request Request object.
	 * @return Response
	 */
	public function get_top_search_engines( Validator $validator, WP_REST_Request $wp_rest_request ) {
		$validator->validate(
			array(
				'limit'      => 'numeric|min:1|max:50',
				'start_date' => 'string',
				'end_date'   => 'string',
			)
		);
	
		try {
			$limit      = absint( $wp_rest_request->get_param( 'limit' ) ?: 4 );
			$date_range = $this->get_date_range( $wp_rest_request );
	
			$engines = $this->admin_repository->get_top_search_engines( 
				$limit,
				$date_range['start_date'],
				$date_range['end_date']
			);
	
			return Response::send(
				array(
					'engines'    => $engines,
					'date_range' => $date_range,
				)
			);
		} catch ( Exception $e ) {
			return Response::send(
				array(
					'message' => esc_html__( 'Error fetching top search engines', 'directorist-advanced-review' ),
				),
				500
			);
		}
	}

	/**
	 * Get top referral sources globally
	 *
	 * @since 1.0.0
	 * @param Validator       $validator       Validator instance.
	 * @param WP_REST_Request $wp_rest_request Request object.
	 * @return Response
	 */
	public function get_top_referral_sources( Validator $validator, WP_REST_Request $wp_rest_request ) {
		$validator->validate(
			array(
				'limit'      => 'numeric|min:1|max:50',
				'start_date' => 'string',
				'end_date'   => 'string',
			)
		);

		try {
			$limit      = absint( $wp_rest_request->get_param( 'limit' ) ?: 10 );
			$date_range = $this->get_date_range( $wp_rest_request );

			$sources = $this->admin_repository->get_top_referral_sources( 
				$limit,
				$date_range['start_date'],
				$date_range['end_date']
			);

			return Response::send(
				array(
					'sources'    => $sources,
					'date_range' => $date_range,
				)
			);
		} catch ( Exception $e ) {
			return Response::send(
				array(
					'message' => esc_html__( 'Error fetching top referral sources', 'directorist-advanced-review' ),
				),
				500
			);
		}
	}

	/**
	 * Get referral sources table (for modal)
	 *
	 * @since 1.0.0
	 * @param Validator       $validator        Validator instance.
	 * @param WP_REST_Request $wp_rest_request Request object.
	 * @return WP_REST_Response
	 */
	public function get_referral_sources_table( Validator $validator, WP_REST_Request $wp_rest_request ) {
		$validator->validate(
			array(
				'page'       => 'numeric|min:1',
				'per_page'   => 'numeric|min:-1|max:9999',
				'start_date' => 'string',
				'end_date'   => 'string',
			)
		);

		try {
			$date_range = $this->get_date_range( $wp_rest_request );
			$page       = absint( $wp_rest_request->get_param( 'page' ) ?: 1 );
			$per_page   = intval( $wp_rest_request->get_param( 'per_page' ) ?: 10 );

			$result = $this->admin_repository->get_referral_sources_table(
				$page,
				$per_page,
				$date_range['start_date'],
				$date_range['end_date']
			);

			return Response::send(
				array_merge(
					$result,
					array( 'date_range' => $date_range )
				)
			);
		} catch ( Exception $e ) {
			return Response::send(
				array(
					'message' => esc_html__( 'Error fetching referral sources table', 'directorist-advanced-review' ),
				),
				500
			);
		}
	}

	/**
	 * Get search appearances table (for modal)
	 *
	 * @since 1.0.0
	 * @param Validator       $validator        Validator instance.
	 * @param WP_REST_Request $wp_rest_request Request object.
	 * @return WP_REST_Response
	 */
	public function get_search_table( Validator $validator, WP_REST_Request $wp_rest_request ) {
		$validator->validate(
			array(
				'page'       => 'numeric|min:1',
				'per_page'   => 'numeric|min:-1|max:9999',
				'start_date' => 'string',
				'end_date'   => 'string',
			)
		);

		try {
			$date_range = $this->get_date_range( $wp_rest_request );
			$page       = absint( $wp_rest_request->get_param( 'page' ) ?: 1 );
			$per_page   = intval( $wp_rest_request->get_param( 'per_page' ) ?: 10 );

			$result = $this->admin_repository->get_search_appearances_table(
				$page,
				$per_page,
				$date_range['start_date'],
				$date_range['end_date']
			);

			return Response::send( $result );
		} catch ( Exception $e ) {
			return Response::send(
				array(
					'message' => esc_html__( 'Error fetching search appearances table', 'directorist-advanced-review' ),
				),
				500
			);
		}
	}
}