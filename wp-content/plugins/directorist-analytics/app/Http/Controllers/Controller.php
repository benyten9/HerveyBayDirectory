<?php
/**
 * Base Controller
 *
 * Provides shared functionality for all controllers.
 *
 * @package DirectoristAnalytics
 * @subpackage Controllers
 * @since 1.0.0
 */

namespace DirectoristAnalytics\App\Http\Controllers;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;

/**
 * Base Controller Class
 *
 * @since 1.0.0
 */
abstract class Controller {

	/**
	 * Pagination helper
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $wp_rest_request Request object.
	 * @param int             $total_items     Total items count.
	 * @param int             $per_page        Items per page.
	 * @return array
	 */
	protected function pagination( WP_REST_Request $wp_rest_request, int $total_items, int $per_page = 20 ) {
		$current_page = $wp_rest_request->has_param( 'page' ) ? intval( $wp_rest_request->get_param( 'page' ) ) : 1;
		$per_page     = $wp_rest_request->has_param( 'per_page' ) ? intval( $wp_rest_request->get_param( 'per_page' ) ) : $per_page;
		$total_pages  = ceil( $total_items / $per_page );

		return array(
			'pagination' => array(
				'total_items'  => $total_items,
				'total_pages'  => $total_pages,
				'current_page' => $current_page,
				'per_page'     => $per_page,
			),
		);
	}

	/**
	 * Get date range from request or defaults
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $wp_rest_request Request object.
	 * @return array
	 */
	protected function get_date_range( WP_REST_Request $wp_rest_request ) {
		$start_date = $wp_rest_request->get_param( 'start_date' );
		$end_date   = $wp_rest_request->get_param( 'end_date' );

		// Default to last 30 days if not provided
		if ( ! $start_date || ! $end_date ) {
			$end_date   = current_time( 'Y-m-d' );
			$start_date = date( 'Y-m-d', strtotime( '-30 days' ) );
		}

		return array(
			'start_date' => sanitize_text_field( $start_date ),
			'end_date'   => sanitize_text_field( $end_date ),
		);
	}
}