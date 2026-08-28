<?php
/**
 * Payment mode slugs Pro registers (free ≥ 1.3.8 defines matching constants).
 *
 * Older free `PaymentMode` may lack SQUARE / MOLLIE / RAZORPAY / AUTHORIZE_NET.
 * Reading those class constants fatals — Pro call sites must use this helper
 * (or string literals) until free is updated.
 *
 * @package DoubleScale\Pro\Compat
 */

namespace DoubleScale\Pro\Compat;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Constants\PaymentMode;

/**
 * PaymentModeSlugs helper.
 */
final class PaymentModeSlugs {

	public const WOOCOMMERCE   = 'woocommerce';
	public const SURECART      = 'surecart';
	public const SQUARE        = 'square';
	public const MOLLIE        = 'mollie';
	public const RAZORPAY      = 'razorpay';
	public const AUTHORIZE_NET = 'authorize_net';

	/**
	 * Prefer free PaymentMode::{NAME} when defined; otherwise the Pro fallback.
	 *
	 * @param string $name     Constant name on PaymentMode (e.g. 'SQUARE').
	 * @param string $fallback Slug to use when free is outdated.
	 * @return string
	 */
	public static function get( string $name, string $fallback ): string {
		$fqn = PaymentMode::class . '::' . $name;
		if ( defined( $fqn ) ) {
			return (string) constant( $fqn );
		}
		return $fallback;
	}

	/**
	 * @return string
	 */
	public static function woocommerce(): string {
		return self::get( 'WOOCOMMERCE', self::WOOCOMMERCE );
	}

	/**
	 * @return string
	 */
	public static function surecart(): string {
		return self::get( 'SURECART', self::SURECART );
	}

	/**
	 * @return string
	 */
	public static function square(): string {
		return self::get( 'SQUARE', self::SQUARE );
	}

	/**
	 * @return string
	 */
	public static function mollie(): string {
		return self::get( 'MOLLIE', self::MOLLIE );
	}

	/**
	 * @return string
	 */
	public static function razorpay(): string {
		return self::get( 'RAZORPAY', self::RAZORPAY );
	}

	/**
	 * @return string
	 */
	public static function authorize_net(): string {
		return self::get( 'AUTHORIZE_NET', self::AUTHORIZE_NET );
	}
}
