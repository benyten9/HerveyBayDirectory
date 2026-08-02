<?php
/**
 * PSR-4 autoload fallback for DoubleScale\* (free plugin).
 *
 * Prefer Composer autoload. Mapping: DoubleScale\Foo\Bar -> includes/Foo/Bar.php
 *
 * @package DoubleScale
 */

namespace DoubleScale;

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'DoubleScale\\';
		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$path     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_file( $path ) ) {
			require_once $path;
			return;
		}

		// Fallback: WordPress filename convention used under Modules/Smtp/.
		// Directory segments are lowercased; the leaf becomes "class-<leaf>.php"
		// with underscores converted to hyphens (e.g. Account_API -> class-account-api.php).
		$segments = explode( '\\', $relative );
		$leaf     = array_pop( $segments );
		$dir      = '';
		foreach ( $segments as $segment ) {
			$dir .= strtolower( str_replace( '_', '-', $segment ) ) . '/';
		}
		$wp_path = __DIR__ . '/' . $dir . 'class-' . strtolower( str_replace( '_', '-', $leaf ) ) . '.php';
		if ( is_file( $wp_path ) ) {
			require_once $wp_path;
		}
	}
);
