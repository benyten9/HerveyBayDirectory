<?php

/**
 * Class RestAbandonedCartController
 * This class is responsible for handling the Automation Contact REST Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rest\Controllers;


defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Core\Utils\Utils;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Automations\Models\AbandonedCartModel;
use DoubleScale\Core\UserRoles\Permissions;

/**
 * RestAbandonedCartController class
 */
class RestAbandonedCartController extends RestController {

	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = 'abandoned-carts';

	/**
	 * Register the routes for the objects of the controller.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_items' ),
					'permission_callback' => array( $this, 'delete_items_permissions_check' ),
					'args'                => array(
						'ids' => array(
							'description' => __( 'The IDs of the items to delete.', 'doublescale'),
							'type'        => 'array',
							'items'       => array(
								'type' => 'integer',
							),
							'required'    => true,
						),
					),
				),
			)
		);

		// Analytics
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/analytics',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_analytics' ),
					'permission_callback' => array( $this, 'get_analytics_permissions_check' ),
					'args'                => array(
						'interval'   => array(
							'description' => __( 'Interval for the analytics.', 'doublescale'),
							'type'        => 'string',
							'enum'        => array( 'custom', 'today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_year', 'last_year' ),
							'required'    => false,
						),
						'start_date' => array(
							'description' => __( 'Start date for the analytics.', 'doublescale'),
							'type'        => 'string',
							'format'      => 'date',
						),
						'end_date'   => array(
							'description' => __( 'End date for the analytics.', 'doublescale'),
							'type'        => 'string',
							'format'      => 'date',
						),
					),
				),
			)
		);
	}

	/**
	 * Collection params
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'keyword'  => array(
				'description'       => __( 'Search keyword.', 'doublescale'),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'page'     => array(
				'description'       => __( 'Current page of the collection.', 'doublescale'),
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'description'       => __( 'Maximum number of items to be returned in result set.', 'doublescale'),
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Get items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		try {
			$per_page = $request->get_param( 'per_page' ) ?? 10;
			$page     = $request->get_param( 'page' ) ?? 1;
			$keyword  = $request->get_param( 'keyword' ) ?? '';

			if ( $keyword ) {
				$carts = AbandonedCartModel::where( 'email', 'like', '%' . $keyword . '%' )->with( 'contact' )->orderBy( 'created_at', 'desc' )
					->paginate( $per_page, array( '*' ), 'page', $page );
			} else {
				$carts = AbandonedCartModel::orderBy( 'created_at', 'desc' )->with( 'contact' )->paginate( $per_page, array( '*' ), 'page', $page );
			}

			return new WP_REST_Response( $carts, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}



	/**
	 * Delete items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function delete_items( $request ) {
		try {
			$ids = $request->get_param( 'ids' );
			if ( empty( $ids ) ) {
				return new WP_Error( 'error', __( 'No IDs provided.', 'doublescale'), array( 'status' => 400 ) );
			}

			AbandonedCartModel::destroy( $ids );

			return new WP_REST_Response( null, 204 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}



	/**
	 * Get analytics
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_analytics( $request ) {
		try {
			$interval   = $request->get_param( 'interval' ) ? $request->get_param( 'interval' ) : 'last_30_days';
			$start_date = $request->get_param( 'start_date' ) ? $request->get_param( 'start_date' ) : '';
			$end_date   = $request->get_param( 'end_date' ) ? $request->get_param( 'end_date' ) : '';

			if ( 'custom' !== $interval ) {
				$start_date = Utils::get_start_date( $interval, $start_date );
				$end_date   = Utils::get_end_date( $interval, $end_date );
			}

			$dates = Utils::get_dates_between_dates( $start_date, $end_date );
			$type  = $dates['type'] ?? 'hour';
			$carts = array();

			foreach ( $dates['dates'] as $date ) {
				switch ( $type ) {
					case 'hour':
						$range = self::created_at_range_for_bucket( 'hour', $date );
						$carts[ $date ] = AbandonedCartModel::whereBetween( 'created_at', $range )->count();
						break;
					case 'day':
						$range = self::created_at_range_for_bucket( 'day', $date );
						$carts[ $date ] = AbandonedCartModel::whereBetween( 'created_at', $range )->count();
						break;
					case 'month':
						$range = self::created_at_range_for_bucket( 'month', $date );
						$carts[ $date ] = AbandonedCartModel::whereBetween( 'created_at', $range )->count();
						break;
					case 'year':
						$range = self::created_at_range_for_bucket( 'year', $date );
						$carts[ $date ] = AbandonedCartModel::whereBetween( 'created_at', $range )->count();
						break;
				}
			}

			$revenue_grouped_by_date = array();

			foreach ( $dates['dates'] as $date ) {
				switch ( $dates['type'] ) {
					case 'hour':
						$range = self::created_at_range_for_bucket( 'hour', $date );
						$revenue_grouped_by_date[ $date ] = AbandonedCartModel::whereBetween( 'created_at', $range )->sum( 'total' );
						break;
					case 'day':
						$range = self::created_at_range_for_bucket( 'day', $date );
						$revenue_grouped_by_date[ $date ] = AbandonedCartModel::whereBetween( 'created_at', $range )->sum( 'total' );
						break;
					case 'month':
						$range = self::created_at_range_for_bucket( 'month', $date );
						$revenue_grouped_by_date[ $date ] = AbandonedCartModel::whereBetween( 'created_at', $range )->sum( 'total' );
						break;
					case 'year':
						$range = self::created_at_range_for_bucket( 'year', $date );
						$revenue_grouped_by_date[ $date ] = AbandonedCartModel::whereBetween( 'created_at', $range )->sum( 'total' );
						break;
				}
			}

			$total_cart    = AbandonedCartModel::count();
			$total_revenue = AbandonedCartModel::where( 'order_id', '>', 0 )->sum( 'total' );

			$analytics = array(
				'carts'   => $carts,
				'revenue' => $revenue_grouped_by_date,
				'data'    => $dates,
				'total'   => array(
					'carts'   => $total_cart,
					'revenue' => $total_revenue,
				),
			);
			return new WP_REST_Response( $analytics, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Created-at range for an analytics bucket.
	 *
	 * Day/month/year buckets must be anchored to the bucket date — bare
	 * whereDay/whereMonth/whereYear match that component in every period.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type Bucket type: hour, day, month, or year.
	 * @param string $date Bucket label from {@see Utils::get_dates_between_dates()}.
	 *
	 * @return array{0: string, 1: string} Inclusive start and end datetimes.
	 */
	public static function created_at_range_for_bucket( $type, $date ) {
		switch ( $type ) {
			case 'hour':
				return array( $date, gmdate( 'Y-m-d H:i:s', strtotime( $date . ' +1 hour' ) ) );
			case 'day':
				return array( $date . ' 00:00:00', $date . ' 23:59:59' );
			case 'month':
				$start = gmdate( 'Y-m-01', strtotime( $date ) );
				$end   = gmdate( 'Y-m-t', strtotime( $date ) );
				return array( $start . ' 00:00:00', $end . ' 23:59:59' );
			case 'year':
				$year = gmdate( 'Y', strtotime( $date ) );
				return array( $year . '-01-01 00:00:00', $year . '-12-31 23:59:59' );
			default:
				return array( $date . ' 00:00:00', $date . ' 23:59:59' );
		}
	}

	/**
	 * Get analytics permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function get_analytics_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Delete items permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function delete_items_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Get items permissions check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}
}
