<?php
/**
 * Backward compatibility for older doublescale-pro booking Stripe gateways.
 *
 * Free 1.2.0 unified payment handling under DoubleScale\Core\Payment\GatewayManager
 * and removed the booking-specific PaymentGateway base class. Pro releases before
 * 1.1.0 still ship StripeBookingGateway extending that class. Loading these shims
 * during plugin bootstrap (not only when the Booking module boots) prevents fatals
 * when users update the free plugin before updating Pro.
 *
 * @package DoubleScale\Compat
 */

namespace DoubleScale\Compat;

defined( 'ABSPATH' ) || exit;

final class LegacyProBookingPayment {

	/**
	 * @var bool
	 */
	private static $loaded = false;

	/**
	 * Load deprecated booking payment gateway classes once.
	 *
	 * Safe to call multiple times.
	 */
	public static function ensure_loaded(): void {
		if ( self::$loaded ) {
			return;
		}

		$booking_root = DOUBLESCALE_PLUGIN_DIR . 'includes/Modules/Booking/';

		require_once $booking_root . 'PaymentGateway/PaymentGateway.php';
		require_once $booking_root . 'Managers/PaymentGatewaysManager.php';

		self::$loaded = true;
	}
}
