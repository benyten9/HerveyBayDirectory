<?php
/**
 * Registers Zoom integration REST controllers.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Booking\Integrations\Zoom\Rest;

use DoubleScale\Modules\Booking\Integration\Rest\REST_API as Abstract_REST_API;

defined( 'ABSPATH' ) || exit;

/**
 * @property \DoubleScale\Pro\Modules\Booking\Integrations\Zoom\Integration $integration
 */
class REST_API extends Abstract_REST_API {

	/**
	 * @var array<string, class-string>
	 */
	protected static $classes = array(
		'account_controller'     => REST_Account_Controller::class,
		'integration_controller' => REST_Integration_Controller::class,
	);
}
