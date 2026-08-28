<?php
/**
 * Settings currency helpers that exist on free ≥ 1.3.8.
 *
 * Older free has Settings but not deal_currency() / document_currency().
 * Call sites in Pro must go through this wrapper (Settings methods cannot be
 * polyfilled onto an already-loaded class).
 *
 * @package DoubleScale\Pro\Compat
 */

namespace DoubleScale\Pro\Compat;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;

/**
 * SettingsCurrency wrapper.
 */
final class SettingsCurrency {

	/**
	 * @param string|null $stored_currency Stored column.
	 * @param mixed       $sent_at         Unused; kept for call-site parity.
	 * @return string
	 */
	public static function document_currency( $stored_currency, $sent_at = null ) {
		if ( method_exists( Settings::class, 'document_currency' ) ) {
			return Settings::document_currency( $stored_currency, $sent_at );
		}

		if ( ! empty( $stored_currency ) ) {
			return (string) $stored_currency;
		}

		return self::global();
	}

	/**
	 * @param string|null $stored_currency Stored column.
	 * @return string
	 */
	public static function deal_currency( $stored_currency ) {
		if ( method_exists( Settings::class, 'deal_currency' ) ) {
			return Settings::deal_currency( $stored_currency );
		}

		if ( ! empty( $stored_currency ) ) {
			return (string) $stored_currency;
		}

		return self::global();
	}

	/**
	 * @return string
	 */
	private static function global(): string {
		if ( method_exists( Settings::class, 'get_currency' ) ) {
			return (string) Settings::get_currency();
		}
		return 'USD';
	}
}
