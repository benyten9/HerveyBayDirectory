<?php
/**
 * Boots integration REST controllers (settings + per-calendar accounts).
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Integration\Rest;

use DoubleScale\Modules\Booking\Abstracts\Integration;
use DoubleScale\Modules\Booking\Integration\Rest\REST_Account_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * @property Integration $integration
 */
class REST_API {

	/**
	 * Active integration instance.
	 *
	 * @var Integration
	 */
	protected $integration;

	/**
	 * Controller class names keyed by role.
	 *
	 * @var array<string, class-string>
	 */
	protected static $classes = array();

	/**
	 * @param Integration $integration Host-calendar integration.
	 */
	public function __construct( Integration $integration ) {
		$this->integration = $integration;

		if ( ! empty( static::$classes['integration_controller'] ?? null ) ) {
			new static::$classes['integration_controller']( $this->integration );
		}

		if ( ! empty( static::$classes['account_controller'] ?? null ) ) {
			new static::$classes['account_controller']( $this->integration );
		} else {
			new REST_Account_Controller( $this->integration );
		}
	}
}
