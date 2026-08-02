<?php
/**
 * Normalize WooCommerce checkout field payloads for abandoned-cart storage.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\AbandonedCart;

defined( 'ABSPATH' ) || exit;

/**
 * CheckoutFieldResolver — block checkout uses hyphenated keys; CRM uses billing_phone.
 */
final class CheckoutFieldResolver {

	/**
	 * Map block-checkout hyphen keys onto classic WooCommerce underscore keys.
	 *
	 * @param array<string, mixed> $fields Raw checkout fields.
	 * @return array<string, mixed>
	 */
	public static function normalize( array $fields ): array {
		$aliases = array(
			'billing-phone'  => 'billing_phone',
			'shipping-phone' => 'shipping_phone',
		);

		foreach ( $aliases as $from => $to ) {
			if ( ! empty( $fields[ $from ] ) && empty( $fields[ $to ] ) ) {
				$fields[ $to ] = $fields[ $from ];
			}
		}

		return $fields;
	}

	/**
	 * Prefer billing phone, then shipping (matches WC customer import behaviour).
	 *
	 * @param array<string, mixed> $fields Checkout fields.
	 */
	public static function resolve_phone( array $fields ): string {
		$fields = self::normalize( $fields );

		$billing = trim( (string) ( $fields['billing_phone'] ?? '' ) );
		if ( '' !== $billing ) {
			return self::sanitize_phone( $billing );
		}

		$shipping = trim( (string) ( $fields['shipping_phone'] ?? '' ) );
		if ( '' !== $shipping ) {
			return self::sanitize_phone( $shipping );
		}

		return '';
	}

	/**
	 * Strip formatting while keeping a leading + for international numbers.
	 */
	public static function sanitize_phone( string $phone ): string {
		return preg_replace( '/[^\d+]/', '', $phone ) ?? '';
	}
}
