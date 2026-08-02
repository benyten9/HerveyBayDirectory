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
}
