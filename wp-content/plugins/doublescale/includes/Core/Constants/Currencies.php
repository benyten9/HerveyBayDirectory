<?php
/**
 * Canonical ISO-4217 currency list for sales documents and settings.
 *
 * PHP is the source of truth. The TypeScript module at
 * `src/shared/constants/currencies.ts` must stay in lockstep (see Test H).
 *
 * There is no FX conversion here on purpose — mixed-currency totals stay as
 * per-code maps rather than a blended scalar.
 *
 * @since 1.0.0
 * @package DoubleScale\Core\Constants
 */

namespace DoubleScale\Core\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * Currencies class.
 */
final class Currencies {

	/**
	 * Supported ISO 4217 codes (settings picker + document picker).
	 *
	 * @var string[]
	 */
	const CODES = array(
		'USD',
		'EUR',
		'GBP',
		'JPY',
		'AUD',
		'CAD',
		'CHF',
		'CNY',
		'SEK',
		'NZD',
		'INR',
		'BRL',
		'RUB',
		'ZAR',
		'MXN',
		'SGD',
		'HKD',
		'NGN',
		'NOK',
		'KRW',
		'TRY',
		'DKK',
		'PLN',
		'THB',
		'IDR',
		'HUF',
		'CZK',
		'ILS',
		'CLP',
		'PHP',
		'AED',
		'COP',
		'SAR',
		'MYR',
		'RON',
		'EGP',
	);

	/**
	 * ISO currencies with no minor units.
	 *
	 * UGX is included (PayPal/Mollie/Square/Razorpay). Stripe is the special
	 * case that still multiplies UGX by 100 — that exception lives in Stripe\Utils.
	 *
	 * @var string[]
	 */
	const ZERO_DECIMAL = array(
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
		'UGX',
		'VND',
		'VUV',
		'XAF',
		'XOF',
		'XPF',
	);

	/**
	 * @var array<string, string>
	 */
	private const SYMBOLS = array(
		'USD' => '$',
		'EUR' => '€',
		'GBP' => '£',
		'JPY' => '¥',
		'AUD' => 'A$',
		'CAD' => 'C$',
		'CHF' => 'CHF',
		'CNY' => '¥',
		'SEK' => 'kr',
		'NZD' => 'NZ$',
		'INR' => '₹',
		'BRL' => 'R$',
		'RUB' => '₽',
		'ZAR' => 'R',
		'MXN' => 'MX$',
		'SGD' => 'S$',
		'HKD' => 'HK$',
		'NGN' => '₦',
		'NOK' => 'kr',
		'KRW' => '₩',
		'TRY' => '₺',
		'DKK' => 'kr',
		'PLN' => 'zł',
		'THB' => '฿',
		'IDR' => 'Rp',
		'HUF' => 'Ft',
		'CZK' => 'Kč',
		'ILS' => '₪',
		'CLP' => 'CLP$',
		'PHP' => '₱',
		'AED' => 'د.إ',
		'COP' => 'COL$',
		'SAR' => '﷼',
		'MYR' => 'RM',
		'RON' => 'lei',
		'EGP' => 'E£',
	);

	/**
	 * @var array<string, string>
	 */
	private const LABELS = array(
		'USD' => 'USD - US Dollar',
		'EUR' => 'EUR - Euro',
		'GBP' => 'GBP - British Pound',
		'JPY' => 'JPY - Japanese Yen',
		'AUD' => 'AUD - Australian Dollar',
		'CAD' => 'CAD - Canadian Dollar',
		'CHF' => 'CHF - Swiss Franc',
		'CNY' => 'CNY - Chinese Yuan',
		'SEK' => 'SEK - Swedish Krona',
		'NZD' => 'NZD - New Zealand Dollar',
		'INR' => 'INR - Indian Rupee',
		'BRL' => 'BRL - Brazilian Real',
		'RUB' => 'RUB - Russian Ruble',
		'ZAR' => 'ZAR - South African Rand',
		'MXN' => 'MXN - Mexican Peso',
		'SGD' => 'SGD - Singapore Dollar',
		'HKD' => 'HKD - Hong Kong Dollar',
		'NGN' => 'NGN - Nigerian Naira',
		'NOK' => 'NOK - Norwegian Krone',
		'KRW' => 'KRW - South Korean Won',
		'TRY' => 'TRY - Turkish Lira',
		'DKK' => 'DKK - Danish Krone',
		'PLN' => 'PLN - Polish Zloty',
		'THB' => 'THB - Thai Baht',
		'IDR' => 'IDR - Indonesian Rupiah',
		'HUF' => 'HUF - Hungarian Forint',
		'CZK' => 'CZK - Czech Koruna',
		'ILS' => 'ILS - Israeli Shekel',
		'CLP' => 'CLP - Chilean Peso',
		'PHP' => 'PHP - Philippine Peso',
		'AED' => 'AED - UAE Dirham',
		'COP' => 'COP - Colombian Peso',
		'SAR' => 'SAR - Saudi Riyal',
		'MYR' => 'MYR - Malaysian Ringgit',
		'RON' => 'RON - Romanian Leu',
		'EGP' => 'EGP - Egyptian Pound',
	);

	/**
	 * Uppercase+trim. Empty input becomes null so NULL flows through as inherit.
	 *
	 * @param mixed $currency Raw code.
	 * @return string|null
	 */
	public static function normalize( $currency ) {
		if ( null === $currency ) {
			return null;
		}
		$code = strtoupper( trim( (string) $currency ) );
		return '' === $code ? null : $code;
	}

	/**
	 * @param mixed $currency Raw code.
	 * @return bool
	 */
	public static function is_valid( $currency ) {
		$code = self::normalize( $currency );
		return null !== $code && in_array( $code, self::CODES, true );
	}

	/**
	 * @param mixed $currency Raw code.
	 * @return string
	 */
	public static function symbol( $currency ) {
		$code = self::normalize( $currency );
		if ( null === $code ) {
			return '';
		}
		return self::SYMBOLS[ $code ] ?? $code;
	}

	/**
	 * @param mixed $currency Raw code.
	 * @return string
	 */
	public static function label( $currency ) {
		$code = self::normalize( $currency );
		if ( null === $code ) {
			return '';
		}
		return self::LABELS[ $code ] ?? $code;
	}

	/**
	 * @param mixed $currency Raw code.
	 * @return bool
	 */
	public static function zero_decimal( $currency ) {
		$code = self::normalize( $currency );
		return null !== $code && in_array( $code, self::ZERO_DECIMAL, true );
	}

	/**
	 * Raw stored column: empty/null → null (inherit). Does not resolve to global.
	 *
	 * @param mixed $stored Raw column value.
	 * @return string|null
	 */
	public static function stored_or_null( $stored ) {
		return self::normalize( $stored );
	}
}
