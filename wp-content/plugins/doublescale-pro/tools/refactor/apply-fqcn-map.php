<?php
/**
 * Replace old FQCN substrings with new ones from class-map.generated.php for a module.
 *
 * Usage: php tools/refactor/apply-fqcn-map.php campaigns
 *
 * @package DoubleScale\Pro\Pro
 */
declare(strict_types=1);

if ($argc < 2) {
	fwrite( STDERR, "Usage: php apply-fqcn-map.php <module_slug>\n" );
	exit( 1 );
}

$module = $argv[1];
$root   = dirname( __DIR__, 2 );
$map    = require $root . '/tools/refactor/class-map.generated.php';

$repl = array();
foreach ( $map as $old => $info ) {
	if ( ( $info['module'] ?? '' ) !== $module ) {
		continue;
	}
	$repl[ $old ] = $info['new'];
}
uksort(
	$repl,
	static function ( $a, $b ) {
		return strlen( $b ) <=> strlen( $a );
	}
);

$files = 0;

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS )
);
foreach ( $iterator as $file ) {
	if ( ! $file->isFile() || $file->getExtension() !== 'php' ) {
		continue;
	}
	$path = $file->getPathname();
	if ( str_contains( $path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR ) ) {
		continue;
	}
	if ( str_contains( $path, DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR ) ) {
		continue;
	}

	$contents = file_get_contents( $path );
	if ( false === $contents ) {
		continue;
	}
	$original = $contents;
	foreach ( $repl as $old => $new ) {
		$contents = str_replace( $old, $new, $contents );
	}
	if ( $contents !== $original ) {
		file_put_contents( $path, $contents );
		++$files;
	}
}

fwrite( STDOUT, "apply-fqcn-map: module={$module} files_updated={$files}\n" );
