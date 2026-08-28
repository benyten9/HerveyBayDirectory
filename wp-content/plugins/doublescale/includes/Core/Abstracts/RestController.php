<?php
/**
 * REST Controller
 *
 * This class extend `WP_REST_Controller` in order to include /batch endpoint
 * for all endpoints in Plugin REST Api.
 *
 * It's required to follow "Controller Classes" guide before extending this class:
 * <https://developer.wordpress.org/rest-api/extending-the-rest-api/controller-classes/>
 *
 * @class   RestController
 * @package DoubleScale\Pro
 * @subpackage RestApi
 * @see     https://developer.wordpress.org/rest-api/extending-the-rest-api/controller-classes/
 */

namespace DoubleScale\Core\Abstracts;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\ModuleManager;
use WP_Error;
use WP_REST_Controller;

/**
 * Abstract Rest Controller Class
 *
 * @extends  WP_REST_Controller
 * @since 1.0.0
 */
abstract class RestController extends WP_REST_Controller {

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'doublescale/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = '';

	/**
	 * Returns the http error status
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Error $wp_error Wordress Error.
	 *
	 * @return int Returns the http status recored in the specified $wp_error
	 */
	public function get_error_status( $wp_error ) {
		$error_code = $wp_error->get_error_code();
		$mappings   = array(
			'not_found'       => 404,
			'not_allowed'     => 401,
			'module_disabled' => 404,
		);
		$http_code  = isset( $mappings[ $error_code ] ) ? $mappings[ $error_code ] : 400;

		return $http_code;
	}

	/**
	 * REST guard: module disabled → 404 {@see WP_Error} with code module_disabled.
	 *
	 * @param string $slug Module slug (e.g. campaigns, smtp).
	 * @return WP_Error|null Null when the module is enabled.
	 */
	protected function require_module( string $slug ): ?WP_Error {
		if ( ! ModuleManager::isEnabled( $slug ) ) {
			return new WP_Error(
				'module_disabled',
				sprintf(
					/* translators: %s: module slug */
					__( 'The "%s" module is disabled.', 'doublescale' ),
					$slug
				),
				array( 'status' => 404 )
			);
		}
		return null;
	}

	/**
	 * Apply a request's sort parameters to a list query.
	 *
	 * Sorting has to happen in SQL rather than in the browser: the client only
	 * ever holds one page of rows, so sorting client-side reorders that page
	 * instead of the whole result set.
	 *
	 * `orderby` is matched against an explicit allow-list of columns because the
	 * value reaches the ORDER BY clause. Anything unrecognised falls back to
	 * $default_column, so a stale saved preference (e.g. a column that has since
	 * been removed) degrades to the default order instead of erroring.
	 *
	 * @since 1.0.0
	 *
	 * @param object           $query           Eloquent query builder.
	 * @param \WP_REST_Request $request         Request object.
	 * @param string[]         $allowed_columns Sortable column names.
	 * @param string           $default_column  Column used when none is requested.
	 * @param string           $default_order   Direction used when none is requested.
	 *
	 * @return object The query, for chaining.
	 */
	protected function apply_sorting( $query, $request, array $allowed_columns, $default_column = 'created_at', $default_order = 'desc' ) {
		$orderby = (string) $request->get_param( 'orderby' );
		$order   = strtolower( (string) $request->get_param( 'order' ) );

		if ( ! in_array( $orderby, $allowed_columns, true ) ) {
			$orderby = $default_column;
		}

		if ( ! in_array( $order, array( 'asc', 'desc' ), true ) ) {
			$order = $default_order;
		}

		$query->orderBy( $orderby, $order );

		// Guarantee a deterministic order: rows sharing the same sort value (a
		// duplicated name, or many records created in the same second) would
		// otherwise be free to shuffle between pages and appear duplicated or
		// missing as the user pages through.
		if ( 'id' !== $orderby ) {
			$query->orderBy( 'id', 'desc' );
		}

		return $query;
	}

	/**
	 * Shared REST argument schema for sortable list endpoints.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $allowed_columns Sortable column names.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_sorting_collection_params( array $allowed_columns ) {
		return array(
			'orderby' => array(
				'description'       => __( 'Column to sort the collection by.', 'doublescale' ),
				'type'              => 'string',
				'enum'              => $allowed_columns,
				'sanitize_callback' => 'sanitize_key',
			),
			'order'   => array(
				'description'       => __( 'Sort direction.', 'doublescale' ),
				'type'              => 'string',
				'enum'              => array( 'asc', 'desc' ),
				'default'           => 'desc',
				'sanitize_callback' => 'sanitize_key',
			),
		);
	}
}
