<?php
/**
 * Reject a payment when the charged currency does not match the document.
 *
 * Modelled on the WooCommerce store-currency check. Without FX conversion a
 * mismatch would record money in the wrong unit.
 *
 * @package DoubleScale\Pro\Modules\Pro\Payment
 */

namespace DoubleScale\Pro\Modules\Pro\Payment;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * PaymentCurrency guard.
 */
final class PaymentCurrency {

	/**
	 * @param string $expected Document / invoice currency.
	 * @param string $actual   Currency the gateway charged or is configured for.
	 * @param string $label    Gateway label for the error message.
	 * @return WP_Error|null
	 */
	public static function guard( $expected, $actual, $label ) {
		$expected = strtoupper( trim( (string) $expected ) );
		$actual   = strtoupper( trim( (string) $actual ) );

		if ( '' === $expected || '' === $actual || $expected === $actual ) {
			return null;
		}

		return new WP_Error(
			'currency_mismatch',
			sprintf(
				/* translators: 1: document currency, 2: gateway name, 3: gateway currency */
				__( 'Document currency (%1$s) does not match the %2$s currency (%3$s).', 'doublescale' ),
				$expected,
				$label,
				$actual
			),
			array( 'status' => 400 )
		);
	}
}
