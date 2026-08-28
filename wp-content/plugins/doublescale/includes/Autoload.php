<?php
/**
 * PSR-4 autoload fallback for DoubleScale\* (free plugin).
 *
 * Prefer Composer autoload. Mapping: DoubleScale\Foo\Bar -> includes/Foo/Bar.php
 *
 * SMTP providers use WordPress-style filenames under mixed-case directories, e.g.
 * DoubleScale\Modules\Smtp\Providers\SendLayer\SendLayer
 *   -> includes/Modules/Smtp/Providers/sendlayer/class-sendlayer.php
 * Lowercasing every directory segment breaks on Linux (modules/ vs Modules/).
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
		// Leaf becomes "class-<leaf>.php" with underscores → hyphens.
		$segments = explode( '\\', $relative );
		$leaf     = array_pop( $segments );
		if ( null === $leaf || '' === $leaf ) {
			return;
		}

		$leaf_file = 'class-' . strtolower( str_replace( '_', '-', $leaf ) ) . '.php';
		$candidates = array();

		// Preserve Modules/Smtp/Providers casing; lowercase provider package dirs
		// (sendlayer, mailgun, rest, …) which are lowercase on disk.
		$dir             = '';
		$lowercase_rest  = false;
		foreach ( $segments as $segment ) {
			if ( $lowercase_rest ) {
				$dir .= strtolower( str_replace( '_', '-', $segment ) ) . '/';
			} else {
				$dir .= str_replace( '_', '-', $segment ) . '/';
				if ( 'Providers' === $segment ) {
					$lowercase_rest = true;
				}
			}
		}
		$candidates[] = __DIR__ . '/' . $dir . $leaf_file;

		// Legacy: lowercase every segment (case-insensitive filesystems only).
		$dir = '';
		foreach ( $segments as $segment ) {
			$dir .= strtolower( str_replace( '_', '-', $segment ) ) . '/';
		}
		$candidates[] = __DIR__ . '/' . $dir . $leaf_file;

		foreach ( array_unique( $candidates ) as $candidate ) {
			if ( is_file( $candidate ) ) {
				require_once $candidate;
				return;
			}
		}
	}
);
