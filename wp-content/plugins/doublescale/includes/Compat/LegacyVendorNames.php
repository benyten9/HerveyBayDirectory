<?php
/**
 * Keep an older DoubleScale Pro working after DoubleScale prefixed its vendor libraries.
 *
 * DoubleScale ships Illuminate (through WPEloquent), Carbon and Doctrine under
 * DoubleScale\Vendor\…, so they can't clash with another plugin's own copy
 * (MailerPress ships Laravel 12 under the same names, which fatals the site).
 * A Pro released before that change still asks for Illuminate\…, WPEloquent\…,
 * Carbon\… and Doctrine\…: this gives it DoubleScale's prefixed class under the
 * old name, so its models, queries and collections keep working.
 *
 * It only answers while such a Pro is active, and only for a name nothing else
 * has loaded: it runs after every other autoloader, so another plugin's own
 * Illuminate keeps its classes. A Pro that uses the prefixed names says so with
 * DOUBLESCALE_PRO_SCOPED_VENDOR, and this stays out of the way.
 *
 * @package DoubleScale\Compat
 */

namespace DoubleScale\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * LegacyVendorNames.
 */
final class LegacyVendorNames {

	/** The namespaces DoubleScale now ships under DoubleScale\Vendor\. */
	const NAMESPACES = array( 'Illuminate\\', 'WPEloquent\\', 'Carbon\\', 'Doctrine\\' );

	/** Where they live now. */
	const PREFIX = 'DoubleScale\\Vendor\\';

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
	 * Alias an old vendor name to DoubleScale's prefixed class.
	 *
	 * @param string $class Requested class, interface or trait.
	 * @return void
	 */
	public static function load( $class ) {
		if ( ! self::legacy_pro_active() ) {
			return;
		}
		foreach ( self::NAMESPACES as $namespace ) {
			if ( 0 !== strpos( $class, $namespace ) ) {
				continue;
			}
			$target = self::PREFIX . $class;
			if ( class_exists( $target ) || interface_exists( $target ) || trait_exists( $target ) ) {
				class_alias( $target, $class );
			}
			return;
		}
	}

	/**
	 * Whether the active Pro still uses the unprefixed names.
	 *
	 * @return bool
	 */
	public static function legacy_pro_active() {
		return defined( 'DOUBLESCALE_PRO_VERSION' ) && ! defined( 'DOUBLESCALE_PRO_SCOPED_VENDOR' );
	}
}
