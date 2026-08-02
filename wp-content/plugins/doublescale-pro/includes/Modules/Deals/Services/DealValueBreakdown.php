<?php
/**
 * Sum deal amounts grouped by the resolved deal currency.
 *
 * Deals may be frozen to different currencies (linked to a proposal/invoice) or
 * follow the global settings currency (unlinked). Adding e.g. BRL + USD into one
 * figure is meaningless, so every deal-value total must be kept per currency.
 *
 * @package DoubleScale\Pro\Modules\Deals\Services
 */

namespace DoubleScale\Pro\Modules\Deals\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;

/**
 * DealValueBreakdown helper.
 */
class DealValueBreakdown {

	/**
	 * Group an amount extracted from each deal by the deal's resolved currency.
	 *
	 * @param iterable $deals  Deal models (each exposing `value`/`weighted_value` + raw `currency`).
	 * @param string   $column Attribute to sum ('value' or 'weighted_value').
	 * @return array{total: float, by_currency: array<string, float>} Flat total kept for back-compat/percentages.
	 */
	public static function compute( $deals, string $column = 'value' ): array {
		$total       = 0.0;
		$by_currency = array();

		foreach ( $deals as $deal ) {
			$amount = (float) ( $deal->{$column} ?? 0 );
			if ( 0.0 === $amount ) {
				continue;
			}

			$stored   = isset( $deal->getAttributes()['currency'] ) ? $deal->getAttributes()['currency'] : null;
			$currency = Settings::deal_currency( $stored );

			$total += $amount;
			if ( ! isset( $by_currency[ $currency ] ) ) {
				$by_currency[ $currency ] = 0.0;
			}
			$by_currency[ $currency ] += $amount;
		}

		foreach ( $by_currency as $code => $value ) {
			$by_currency[ $code ] = round( (float) $value, 2 );
		}

		return array(
			'total'       => round( $total, 2 ),
			'by_currency' => $by_currency,
		);
	}

	/**
	 * Just the by-currency map (convenience).
	 *
	 * @param iterable $deals  Deal models.
	 * @param string   $column Attribute to sum.
	 * @return array<string, float>
	 */
	public static function by_currency( $deals, string $column = 'value' ): array {
		return self::compute( $deals, $column )['by_currency'];
	}
}
