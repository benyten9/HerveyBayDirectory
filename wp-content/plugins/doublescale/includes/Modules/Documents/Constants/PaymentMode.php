<?php
/**
 * Canonical invoice payment mode values.
 *
 * Offline modes are recorded manually by staff. Online gateway slugs (e.g.
 * stripe) route through GatewayManager.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * PaymentMode constants and normalization helpers.
 */
class PaymentMode {

	const BANK_TRANSFER = 'bank_transfer';
	const CASH          = 'cash';
	const CHECK         = 'check';
	const CREDIT_CARD   = 'credit_card';
	const CREDIT_NOTE   = 'credit_note';
	const STRIPE        = 'stripe';
	const PAYPAL        = 'paypal';
	const WOOCOMMERCE   = 'woocommerce';
	const SQUARE        = 'square';
	const MOLLIE        = 'mollie';
	const RAZORPAY      = 'razorpay';
	const AUTHORIZE_NET = 'authorize_net';
	const OTHER         = 'other';

	/**
	 * Modes recorded manually (offline).
	 *
	 * @return string[]
	 */
	public static function offline_modes(): array {
		return array(
			self::BANK_TRANSFER,
			self::CASH,
			self::CHECK,
			self::OTHER,
		);
	}

	/**
	 * Known online gateway slugs. Pro/modules register implementations.
	 *
	 * @return string[]
	 */
	public static function online_gateway_slugs(): array {
		$slugs = array( self::STRIPE, self::PAYPAL );

		/**
		 * Register additional online payment gateway slugs for invoices.
		 *
		 * @param string[] $slugs Gateway slugs.
		 */
		return array_values( array_unique( apply_filters( 'doublescale_sales_online_payment_gateway_slugs', $slugs ) ) );
	}

	/**
	 * All selectable modes on an invoice (offline + online gateways).
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array_values( array_unique( array_merge( self::offline_modes(), self::online_gateway_slugs() ) ) );
	}

	/**
	 * @param string $mode Mode slug.
	 * @return bool
	 */
	public static function is_offline( string $mode ): bool {
		$normalized = self::normalize( $mode );
		return null !== $normalized && in_array( $normalized, self::offline_modes(), true );
	}

	/**
	 * @param string $mode Mode slug.
	 * @return bool
	 */
	public static function is_online_gateway( string $mode ): bool {
		$normalized = self::normalize( $mode );
		return null !== $normalized && in_array( $normalized, self::online_gateway_slugs(), true );
	}

	/**
	 * @param mixed $modes Mode list.
	 * @return array{offline: string[], online: string[]}
	 */
	public static function split_modes( $modes ): array {
		$normalized = self::normalize_list( $modes );
		$offline    = array();
		$online     = array();

		foreach ( $normalized as $mode ) {
			if ( self::is_online_gateway( $mode ) ) {
				$online[] = $mode;
			} else {
				$offline[] = $mode;
			}
		}

		return array(
			'offline' => $offline,
			'online'  => $online,
		);
	}

	/**
	 * @param string $mode Raw mode value.
	 * @return string|null Canonical slug or null when empty/unknown.
	 */
	public static function normalize( string $mode ): ?string {
		$mode = strtolower( trim( $mode ) );
		$mode = str_replace( '-', '_', $mode );
		$mode = preg_replace( '/[^a-z0-9_]/', '', $mode );

		if ( '' === $mode ) {
			return null;
		}

		if ( in_array( $mode, self::all(), true ) ) {
			return $mode;
		}

		$legacy = array(
			'bank'            => self::BANK_TRANSFER,
			'stripe_checkout' => self::STRIPE,
			'credit_card'     => self::CREDIT_CARD,
		);

		return $legacy[ $mode ] ?? self::OTHER;
	}

	/**
	 * @param mixed $modes List of mode strings.
	 * @return string[]
	 */
	public static function normalize_list( $modes ): array {
		if ( ! is_array( $modes ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $modes as $mode ) {
			if ( ! is_string( $mode ) && ! is_numeric( $mode ) ) {
				continue;
			}
			$canonical = self::normalize( (string) $mode );
			if ( $canonical ) {
				$normalized[] = $canonical;
			}
		}

		return array_values( array_unique( $normalized ) );
	}
}
