<?php
/**
 * Booking module REST base: all booking-scoped routes live under {@see self::NAMESPACE}.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Abstracts;

use WP_REST_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract REST controller for booking integrations and related endpoints.
 */
abstract class REST_Controller extends WP_REST_Controller {

	/**
	 * REST API namespace (matches client {@see \NAMESPACE} in booking constants).
	 *
	 * @var string
	 */
	protected $namespace = 'doublescale/v1/booking';
}
