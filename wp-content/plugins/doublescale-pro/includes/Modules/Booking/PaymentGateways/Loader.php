<?php
/**
 * Booking payment gateways loader.
 *
 * Stripe credentials live in the global Stripe integration; this loader
 * registers the unified gateway and booking-specific handlers.
 *
 * @package DoubleScale\Pro\Modules\Booking\PaymentGateways
 */

namespace DoubleScale\Pro\Modules\Booking\PaymentGateways;

use DoubleScale\Pro\Modules\Pro\Payment\StripeGateway;

defined( 'ABSPATH' ) || exit;

final class Loader {

	public static function register(): void {
		StripeGateway::instance();
		BookingStripeHandler::instance();
	}
}
