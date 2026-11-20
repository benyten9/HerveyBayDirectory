<?php
/**
 * Let Pro run on a DoubleScale released before it prefixed its vendor libraries.
 *
 * Pro asks for Illuminate, WPEloquent, Carbon and Doctrine under DoubleScale\Vendor\…,
 * the names DoubleScale ships them under now. An older DoubleScale ships them
 * unprefixed (Illuminate\…): this gives Pro that class under the prefixed name,
 * so Pro and that DoubleScale share one class and their models, queries and
 * collections fit together.
 *
 * A current DoubleScale defines DOUBLESCALE_SCOPED_VENDOR in its vendor
 * autoloader and provides the prefixed classes itself; then this does nothing.
 *
 * @package DoubleScale\Pro\Compat
 */

namespace DoubleScale\Pro\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * ScopedVendorNames.
 */
final class ScopedVendorNames {

	/** Where a current DoubleScale ships these namespaces. */
	const PREFIX = 'DoubleScale\\Vendor\\';

	/** The namespaces an older DoubleScale ships unprefixed. */
	const NAMESPACES = array( 'Illuminate\\', 'WPEloquent\\', 'Carbon\\', 'Doctrine\\' );

	/**
	 * Register the autoloader once, after every other one.
	 *
	 * @return void
	 */
	public static function register() {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Alias a prefixed vendor name to an older DoubleScale's unprefixed class.
	 *
	 * @param string $class Requested class, interface or trait.
	 * @return void
	 */
	public static function load( $class ) {
		if ( defined( 'DOUBLESCALE_SCOPED_VENDOR' ) || 0 !== strpos( $class, self::PREFIX ) ) {
			return;
		}
		$legacy = substr( $class, strlen( self::PREFIX ) );
		foreach ( self::NAMESPACES as $namespace ) {
			if ( 0 !== strpos( $legacy, $namespace ) ) {
				continue;
			}
			if ( class_exists( $legacy ) || interface_exists( $legacy ) || trait_exists( $legacy ) ) {
				class_alias( $legacy, $class );
			}
			return;
		}
	}
}
