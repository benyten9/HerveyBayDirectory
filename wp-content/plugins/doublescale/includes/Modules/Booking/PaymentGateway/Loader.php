<?php
/**
 * Payment gateway abstract layer loader (free tier).
 *
 * Initializes the unified GatewayManager singleton so concrete gateways
 * registered via the pro Loader can find a manager to register against.
 *
 * @package DoubleScale\Modules\Booking\PaymentGateway
 */

namespace DoubleScale\Modules\Booking\PaymentGateway;

use DoubleScale\Compat\LegacyProBookingPayment;
use DoubleScale\Core\Payment\GatewayManager;

defined( 'ABSPATH' ) || exit;

final class Loader {

	/**
	 * Boot the payment gateway abstract layer.
	 */
	public static function register(): void {
		GatewayManager::instance();
		require_once __DIR__ . '/ReturnHandler.php';
		LegacyProBookingPayment::ensure_loaded();
	}
}
