<?php
/**
 * Stripe currency helpers.
 *
 * Lifted unchanged from `Modules/Booking/PaymentGateways/Stripe/Utils.php` —
 * pure functions with no booking knowledge, so they belong with the global
 * integration where every consumer can reach them.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Stripe;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\Currencies;

class Utils {

	/**
	 * Currencies Stripe charges in their major unit (no fractional part).
	 *
	 * UGX is intentionally absent — Stripe lists it as a special case where the
	 * charge amount is multiplied by 100 even though the currency has no decimal
	 * digits, so it behaves like a normal two-decimal currency for amount math.
	 * https://stripe.com/docs/currencies#zero-decimal
	 */
	const ZERO_DECIMAL_CURRENCIES = array(
		'BIF',
		'CLP',
		'DJF',
		'GNF',
		'JPY',
		'KMF',
		'KRW',
		'MGA',
		'PYG',
		'RWF',
		'VND',
		'VUV',
		'XAF',
		'XOF',
		'XPF',
	);

	public static function to_stripe_amount( $value, string $currency ): int {
		$code = strtoupper( $currency );
		// UGX is in Currencies::ZERO_DECIMAL but Stripe still multiplies by 100.
		if ( Currencies::zero_decimal( $code ) && 'UGX' !== $code ) {
			return (int) $value;
		}
		return (int) round( ( (float) $value ) * 100 );
	}

	public static function from_stripe_amount( $value, string $currency ) {
		$code = strtoupper( $currency );
		if ( Currencies::zero_decimal( $code ) && 'UGX' !== $code ) {
			return (int) $value;
		}
		return ( (float) $value ) / 100;
	}
}
