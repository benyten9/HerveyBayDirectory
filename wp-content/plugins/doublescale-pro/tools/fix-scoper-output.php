<?php
/**
 * Post PHP-Scoper fixes (generated files, not handled by patchers).
 *
 * @package DoubleScale\Pro\Pro
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );

$scoper_autoload = $root . '/dependencies/build/vendor/scoper-autoload.php';

if ( ! is_file( $scoper_autoload ) ) {
	exit( 0 );
}

$c = file_get_contents( $scoper_autoload );
// PHP-Scoper 0.17 can emit invalid PHP for this exposed GuzzleHttp\Psr7 stub.
$c = str_replace( 'function Psr7\\try_fopen()', 'function try_fopen()', $c );
file_put_contents( $scoper_autoload, $c );

exit( 0 );
