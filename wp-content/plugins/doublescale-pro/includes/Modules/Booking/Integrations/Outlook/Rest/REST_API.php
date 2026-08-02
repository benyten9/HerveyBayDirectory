<?php
/**
 * Registers Outlook integration REST controllers.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Booking\Integrations\Outlook\Rest;

use DoubleScale\Modules\Booking\Integration\Rest\REST_API as Abstract_REST_API;

defined( 'ABSPATH' ) || exit;

/**
 * @property \DoubleScale\Pro\Modules\Booking\Integrations\Outlook\Integration $integration
 */
class REST_API extends Abstract_REST_API {

	/**
	 * @var array<string, class-string>
	 */
	protected static $classes = array(
		'integration_controller' => REST_Integration_Controller::class,
		'account_controller'     => REST_Account_Controller::class,
	);
}
