<?php
/**
 * Google per-calendar accounts + calendars entity route.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Booking\Integrations\Google\Rest;

use DoubleScale\Modules\Booking\Integration\Rest\REST_Account_Controller as Abstract_REST_Account_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Rest Integration Account Controller
 */
class REST_Account_Controller extends Abstract_REST_Account_Controller {

	/**
	 * Remote entity routes.
	 *
	 * @var array<string, array{callback: string}>
	 */
	protected $entities = array(
		'calendars' => array(
			'callback' => 'fetch_calendars',
		),
	);
}
