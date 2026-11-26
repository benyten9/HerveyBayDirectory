<?php
/**
 * Export Controller
 *
 * Handles REST API endpoints for data export functionality.
 *
 * @package DirectoristAnalytics
 * @subpackage Controllers
 * @since 1.0.0
 */

namespace DirectoristAnalytics\App\Http\Controllers;

defined( 'ABSPATH' ) || exit;

use DirectoristAnalytics\App\Repositories\ExportRepository;
use DirectoristAnalytics\WpMVC\Routing\Response;
use DirectoristAnalytics\WpMVC\RequestValidator\Validator;
use WP_REST_Request;
use Exception;

/**
 * Export Controller Class
 *
 * @since 1.0.0
 */
class ExportController extends Controller {

	/**
	 * Export repository
	 *
	 * @var ExportRepository
	 */
	private $export_repository;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->export_repository = new ExportRepository();
	}

	/**
     * Export site-wide aggregated data
     *
     * @since 1.0.0
     * @param Validator       $validator       Validator instance.
     * @param WP_REST_Request $wp_rest_request Request object.
     * @return Response
     */
    public function export_site_wide( Validator $validator, WP_REST_Request $wp_rest_request ) {
        // Validate request parameters
        $validator->validate(
            array(
                'start_date' => 'required|string',
                'end_date'   => 'required|string',
                'metrics'    => 'required|string',
                'interval'   => 'string',
            )
        );

        try {
            // Extract parameters
            $start_date = sanitize_text_field( $wp_rest_request->get_param( 'start_date' ) );
            $end_date   = sanitize_text_field( $wp_rest_request->get_param( 'end_date' ) );
            $metrics    = sanitize_text_field( $wp_rest_request->get_param( 'metrics' ) );
            $interval   = sanitize_text_field( $wp_rest_request->get_param( 'interval' ) ?: 'daily' );

            // Additional validation
            $validation_error = $this->validate_export_params( $start_date, $end_date, $metrics, $interval );
            if ( $validation_error ) {
                return Response::send( $validation_error, 400 );
            }

            // Parse metrics string to array
            $metrics_array = array_map( 'trim', explode( ',', $metrics ) );

            // Get data from repository
            $data = $this->export_repository->get_site_wide_data( $start_date, $end_date, $interval, $metrics_array );

            // Build response
            return Response::send(
                array(
                    'scope'      => 'site-wide',
                    'date_range' => array(
                        'start' => $start_date,
                        'end'   => $end_date,
                    ),
                    'interval'   => $interval,
                    'data'       => $data,
                ),
                200
            );
        } catch ( Exception $e ) {
            return Response::send(
                array(
                    'error'   => true,
                    'message' => esc_html__( 'Error generating export data', 'directorist-advanced-review' ),
                ),
                500
            );
        }
    }

    /**
     * Validate export parameters
     *
     * @since 1.0.0
     * @param string $start_date Start date.
     * @param string $end_date   End date.
     * @param string $metrics    Metrics string.
     * @param string $interval   Interval.
     * @return array|null Error array or null if valid.
     */
    private function validate_export_params( $start_date, $end_date, $metrics, $interval ) {
        // Validate date range
        if ( strtotime( $start_date ) > strtotime( $end_date ) ) {
            return array(
                'error'   => true,
                'code'    => 'INVALID_DATE_RANGE',
                'message' => esc_html__( 'Start date must be before or equal to end date', 'directorist-advanced-review' ),
            );
        }

        // Check if start date is in future
        if ( strtotime( $start_date ) > current_time( 'timestamp' ) ) {
            return array(
                'error'   => true,
                'code'    => 'FUTURE_DATE',
                'message' => esc_html__( 'Start date cannot be in the future', 'directorist-advanced-review' ),
            );
        }

        // Check date range max (2 years)
        $date_diff = ( strtotime( $end_date ) - strtotime( $start_date ) ) / DAY_IN_SECONDS;
        if ( $date_diff > 730 ) {
            return array(
                'error'   => true,
                'code'    => 'DATE_RANGE_TOO_LARGE',
                'message' => esc_html__( 'Date range cannot exceed 2 years', 'directorist-advanced-review' ),
            );
        }

        // Validate metrics
        $metrics_array = array_map( 'trim', explode( ',', $metrics ) );
        if ( empty( $metrics_array ) ) {
            return array(
                'error'   => true,
                'code'    => 'NO_METRICS',
                'message' => esc_html__( 'At least one metric must be selected', 'directorist-advanced-review' ),
            );
        }

        // Validate metric keys (Part 4: all 11 metrics supported)
        $allowed_metrics = array(
            'listingViews',
            'clicks',
            'websiteClicks',
            'searchAppearanceData',
            'contactFormUsesData',
            'topCountries',
            'browserType',
            'operatingSystems',
            'screenResolutions',
            'searchEngines',
            'referrals',
        );
        $invalid_metrics = array_diff( $metrics_array, $allowed_metrics );
        if ( ! empty( $invalid_metrics ) ) {
            return array(
                'error'   => true,
                'code'    => 'INVALID_METRICS',
                'message' => sprintf(
                    /* translators: %s: invalid metric names */
                    esc_html__( 'Invalid metrics: %s', 'directorist-advanced-review' ),
                    implode( ', ', $invalid_metrics )
                ),
            );
        }

        // Validate interval (Part 3: support all 4 intervals)
        $allowed_intervals = array( 'daily', 'weekly', 'monthly', 'quarterly' );
        if ( ! in_array( $interval, $allowed_intervals, true ) ) {
            return array(
                'error'   => true,
                'code'    => 'INVALID_INTERVAL',
                'message' => sprintf(
                    /* translators: %s: comma-separated list of allowed intervals */
                    esc_html__( 'Invalid interval. Allowed: %s', 'directorist-advanced-review' ),
                    implode( ', ', $allowed_intervals )
                ),
            );
        }

        return null; // All validations passed
    }

	/**
     * Export per-listing data
     *
     * @since 1.0.0
     * @param Validator       $validator       Validator instance.
     * @param WP_REST_Request $wp_rest_request Request object.
     * @return Response
     */
    public function export_per_listing( Validator $validator, WP_REST_Request $wp_rest_request ) {
        // Validate request parameters (same as site-wide)
        $validator->validate(
            array(
                'start_date' => 'required|string',
                'end_date'   => 'required|string',
                'metrics'    => 'required|string',
                'interval'   => 'string',
            )
        );

        try {
            // Extract parameters
            $start_date = sanitize_text_field( $wp_rest_request->get_param( 'start_date' ) );
            $end_date   = sanitize_text_field( $wp_rest_request->get_param( 'end_date' ) );
            $metrics    = sanitize_text_field( $wp_rest_request->get_param( 'metrics' ) );
            $interval   = sanitize_text_field( $wp_rest_request->get_param( 'interval' ) ?: 'daily' );

            // Additional validation (reuse existing method)
            $validation_error = $this->validate_export_params( $start_date, $end_date, $metrics, $interval );
            if ( $validation_error ) {
                return Response::send( $validation_error, 400 );
            }

            // Parse metrics string to array
            $metrics_array = array_map( 'trim', explode( ',', $metrics ) );

            // Validate metrics for per-listing scope
            $per_listing_metrics = array( 'listingViews', 'clicks', 'websiteClicks', 'searchAppearanceData', 'contactFormUsesData' );
            $valid_metrics = array_intersect( $metrics_array, $per_listing_metrics );
            
            if ( empty( $valid_metrics ) ) {
                return Response::send(
                    array(
                        'error'   => true,
                        'code'    => 'INVALID_SCOPE_METRICS',
                        'message' => sprintf(
                            /* translators: %s: comma-separated list of valid metrics */
                            esc_html__( 'No valid metrics for per-listing scope. Valid metrics: %s', 'directorist-advanced-review' ),
                            implode( ', ', $per_listing_metrics )
                        ),
                    ),
                    400
                );
            }

            // Get data from repository
            $data = $this->export_repository->get_per_listing_data( $start_date, $end_date, $interval, $metrics_array );

            // Build response
            return Response::send(
                array(
                    'scope'          => 'per-listing',
                    'date_range'     => array(
                        'start' => $start_date,
                        'end'   => $end_date,
                    ),
                    'interval'       => $interval,
                    'total_listings' => count( $data ),
                    'listings'       => $data,
                ),
                200
            );
        } catch ( Exception $e ) {
            return Response::send(
                array(
                    'error'   => true,
                    'message' => esc_html__( 'Error generating per-listing export data', 'directorist-advanced-review' ),
                ),
                500
            );
        }
    }

	/**
     * Export per-author data
     *
     * @since 1.0.0
     * @param Validator       $validator       Validator instance.
     * @param WP_REST_Request $wp_rest_request Request object.
     * @return Response
     */
    public function export_per_author( Validator $validator, WP_REST_Request $wp_rest_request ) {
        // Validate request parameters (same as other exports)
        $validator->validate(
            array(
                'start_date' => 'required|string',
                'end_date'   => 'required|string',
                'metrics'    => 'required|string',
                'interval'   => 'string',
            )
        );

        try {
            // Extract parameters
            $start_date = sanitize_text_field( $wp_rest_request->get_param( 'start_date' ) );
            $end_date   = sanitize_text_field( $wp_rest_request->get_param( 'end_date' ) );
            $metrics    = sanitize_text_field( $wp_rest_request->get_param( 'metrics' ) );
            $interval   = sanitize_text_field( $wp_rest_request->get_param( 'interval' ) ?: 'daily' );

            // Additional validation (reuse existing method)
            $validation_error = $this->validate_export_params( $start_date, $end_date, $metrics, $interval );
            if ( $validation_error ) {
                return Response::send( $validation_error, 400 );
            }

            // Parse metrics string to array
            $metrics_array = array_map( 'trim', explode( ',', $metrics ) );

            // Validate metrics for per-author scope
            $per_author_metrics = array( 'listingViews', 'clicks', 'websiteClicks', 'searchAppearanceData', 'contactFormUsesData' );
            $valid_metrics = array_intersect( $metrics_array, $per_author_metrics );
            
            if ( empty( $valid_metrics ) ) {
                return Response::send(
                    array(
                        'error'   => true,
                        'code'    => 'INVALID_SCOPE_METRICS',
                        'message' => sprintf(
                            /* translators: %s: comma-separated list of valid metrics */
                            esc_html__( 'No valid metrics for per-author scope. Valid metrics: %s', 'directorist-advanced-review' ),
                            implode( ', ', $per_author_metrics )
                        ),
                    ),
                    400
                );
            }

            // Get data from repository
            $data = $this->export_repository->get_per_author_data( $start_date, $end_date, $interval, $metrics_array );

            // Build response
            return Response::send(
                array(
                    'scope'         => 'per-author',
                    'date_range'    => array(
                        'start' => $start_date,
                        'end'   => $end_date,
                    ),
                    'interval'      => $interval,
                    'total_authors' => count( $data ),
                    'authors'       => $data,
                ),
                200
            );
        } catch ( Exception $e ) {
            return Response::send(
                array(
                    'error'   => true,
                    'message' => esc_html__( 'Error generating per-author export data', 'directorist-advanced-review' ),
                ),
                500
            );
        }
    }
}