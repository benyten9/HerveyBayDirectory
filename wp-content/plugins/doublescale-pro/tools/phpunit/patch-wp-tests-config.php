<?php
/**
 * Rewrite DB_* defines in wp-tests-config.php (WordPress core test config).
 * Called on every install-wp-tests.sh run so credentials stay in sync with CLI args.
 *
 * @package DoubleScale\Pro\Pro
 */

declare( strict_types=1 );

if ( $argc < 6 ) {
	fwrite( STDERR, "usage: php patch-wp-tests-config.php <wp-tests-config.php> <db-name> <db-user> <db-pass> <db-host>\n" );
	exit( 1 );
}

$file    = $argv[1];
$db_name = $argv[2];
$db_user = $argv[3];
$db_pass = $argv[4];
$db_host = $argv[5];

if ( ! is_file( $file ) ) {
	fwrite( STDERR, "patch-wp-tests-config: file not found: {$file}\n" );
	exit( 1 );
}

$contents = (string) file_get_contents( $file );
$eol      = str_contains( $contents, "\r\n" ) ? "\r\n" : "\n";
$lines    = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $contents ) );

$pairs = array(
	'DB_NAME'     => $db_name,
	'DB_USER'     => $db_user,
	'DB_PASSWORD' => $db_pass,
	'DB_HOST'     => $db_host,
);

$found = array_fill_keys( array_keys( $pairs ), false );
$out   = array();

foreach ( $lines as $line ) {
	$matched = false;
	foreach ( $pairs as $const => $value ) {
		if ( preg_match( '/^\s*define\s*\(\s*[\'"]' . preg_quote( $const, '/' ) . '[\'"]\s*,/', $line ) ) {
			$out[]            = "define( '" . $const . "', " . var_export( $value, true ) . ' );';
			$found[ $const ] = true;
			$matched          = true;
			break;
		}
	}
	if ( ! $matched ) {
		$out[] = $line;
	}
}

foreach ( $found as $const => $ok ) {
	if ( ! $ok ) {
		fwrite( STDERR, "patch-wp-tests-config: could not find define( '{$const}', ... ) in {$file}\n" );
		exit( 1 );
	}
}

$trailing_nl = str_ends_with( $contents, "\n" ) || str_ends_with( $contents, "\r\n" );
file_put_contents( $file, implode( $eol, $out ) . ( $trailing_nl ? $eol : '' ) );
echo "Updated DB_* in {$file}\n";
