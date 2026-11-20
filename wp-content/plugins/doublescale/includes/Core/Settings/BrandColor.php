<?php
/**
 * The business's brand colour (Settings → Business, next to the logo).
 *
 * One colour for everything a client sees: the client portal and its login
 * page, and the support portal. Each derives its shades (tints, hover,
 * readable text) from it. Persisted as
 * `doublescale_settings['business']['business_brand_color']`; empty means the
 * default.
 *
 * @package DoubleScale\Core\Settings
 */

namespace DoubleScale\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * BrandColor.
 */
final class BrandColor {

	/** The colour when none is set. */
	public const DEFAULT = '#4B32B8';

	/** Key inside the `business` settings group. */
	public const KEY = 'business_brand_color';

	/**
	 * `#abc` / `#AABBCC` (with or without #) → `#AABBCC`; anything else → null.
	 *
	 * @param mixed $value Colour.
	 * @return string|null
	 */
	public static function sanitize( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$hex = ltrim( trim( $value ), '#' );
		if ( preg_match( '/^[0-9a-f]{3}$/i', $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		return preg_match( '/^[0-9a-f]{6}$/i', $hex ) ? '#' . strtoupper( $hex ) : null;
	}

	/**
	 * The brand colour, or the default.
	 *
	 * @return string `#RRGGBB`.
	 */
	public static function get(): string {
		$business = Settings::get( 'business', array() );
		$set      = is_array( $business ) && isset( $business[ self::KEY ] ) ? self::sanitize( $business[ self::KEY ] ) : null;

		return $set ?? self::DEFAULT;
	}

	/**
	 * Save a colour; an empty or invalid one goes back to the default.
	 *
	 * @param string $value `#RRGGBB`.
	 * @return string The colour now in use.
	 */
	public static function set( string $value ): string {
		$business = Settings::get( 'business', array() );
		$business = is_array( $business ) ? $business : array();
		$color    = self::sanitize( $value );
		if ( null === $color ) {
			unset( $business[ self::KEY ] );
		} else {
			$business[ self::KEY ] = $color;
		}
		Settings::update( 'business', $business );

		return self::get();
	}
}
