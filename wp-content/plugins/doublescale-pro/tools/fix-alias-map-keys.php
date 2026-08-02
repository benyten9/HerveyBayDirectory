<?php
/**
 * Legacy alias map keys were prefixed with DoubleScale\Pro\ by the bulk migrator.
 * Old public FQCNs must stay DoubleScale\<Root>\… (not DoubleScale\Pro\<Root>\…)
 * unless the legacy name already lived under Modules\… (canonical lives in Pro Modules).
 *
 * Usage: php tools/fix-alias-map-keys.php
 */
declare( strict_types = 1 );

$path = dirname( __DIR__ ) . '/includes/Core/Deprecated/aliases-map.php';
$lines = file( $path, FILE_IGNORE_NEW_LINES );
if ( false === $lines ) {
	fwrite( STDERR, "Cannot read aliases-map\n" );
	exit( 1 );
}
$out = array();
foreach ( $lines as $line ) {
	$arrow_pos = strpos( $line, '=>' );
	if ( false !== $arrow_pos ) {
		$key_part   = substr( $line, 0, $arrow_pos );
		$value_part = substr( $line, $arrow_pos );
		$key_part   = preg_replace(
			"/'DoubleScale\\\\Pro\\\\(?!Modules\\\\)/",
			"'DoubleScale\\\\",
			$key_part,
			1
		);
		$line = $key_part . $value_part;
	}
	$out[] = $line;
}
file_put_contents( $path, implode( "\n", $out ) . "\n" );
