<?php
/**
 * Legacy payment gateways manager (deprecated).
 *
 * @package DoubleScale\Modules\Booking\Managers
 */

namespace DoubleScale\Modules\Booking\Managers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\PaymentGateway\PaymentGateway;

/**
 * PaymentGatewaysManager class.
 *
 * @deprecated 1.2.0 Use DoubleScale\Core\Payment\GatewayManager instead.
 */
final class PaymentGatewaysManager extends \DoubleScale\Modules\Booking\Abstracts\Manager {

	use \DoubleScale\Modules\Booking\Traits\Singleton;

	/**
	 * Register a payment gateway.
	 *
	 * @param PaymentGateway $payment_gateway Payment gateway instance.
	 * @return void
	 */
	public function register_payment_gateway( PaymentGateway $payment_gateway ) {
		parent::register(
			$payment_gateway,
			PaymentGateway::class,
			'slug',
			array(
				'name'        => 'name',
				'description' => 'description',
				'settings'    => 'get_settings',
				'fields'      => 'get_fields',
				'enabled'     => 'is_enabled',
			)
		);
	}
}
