<?php
/**
 * One-shot: delete duplicate includes (same path as free), collapse DoubleScale\Pro\ → DoubleScale\Pro\,
 * fix aliases-map values.
 *
 * Usage: php tools/dedupe-pro-migrate.php [path/to/same_paths.txt]
 * Default list file: /tmp/same_paths.txt (relative paths under includes/, one per line).
 *
 * @package DoubleScale
 */

$root = dirname( __DIR__ );
$includes = $root . '/includes';
$list_file = isset( $argv[1] ) && $argv[1] !== ''
	? $argv[1]
	: '/tmp/same_paths.txt';

$skip_delete = array(
	'Site/Updater.php',
	'Core/Deprecated/aliases-map.php',
	'Database/ProInstall.php',
);

if ( ! is_readable( $list_file ) ) {
	fwrite( STDERR, "Missing list file: {$list_file}\n" );
	exit( 1 );
}

$lines = array_filter( array_map( 'trim', file( $list_file ) ) );
$deleted = 0;
foreach ( $lines as $rel ) {
	if ( in_array( $rel, $skip_delete, true ) ) {
		continue;
	}
	$path = $includes . '/' . $rel;
	if ( is_file( $path ) ) {
		if ( ! unlink( $path ) ) {
			fwrite( STDERR, "Failed to delete {$path}\n" );
			exit( 1 );
		}
		++$deleted;
	}
}
echo "Deleted {$deleted} duplicate files.\n";

$alias_map = $includes . '/Core/Deprecated/aliases-map.php';
if ( is_file( $alias_map ) ) {
	$raw = file_get_contents( $alias_map );
	$raw = preg_replace_callback(
		"/(=>\\s*')([^']+)(')/",
		static function ( $m ) {
			$v = str_replace( 'DoubleScale\\Pro\\Pro\\', 'DoubleScale\\Pro\\', $m[2] );

			return $m[1] . $v . $m[3];
		},
		$raw
	);
	file_put_contents( $alias_map, $raw );
	echo "Updated alias map values.\n";
}

$replacements = array(
	'namespace DoubleScale\Pro\\Pro;' => 'namespace DoubleScale;',
	'namespace DoubleScale\Pro\\Pro\\' => 'namespace DoubleScale\Pro\\',
	'use DoubleScale\Pro\\Pro\\' => 'use DoubleScale\Pro\\',
	'\\DoubleScale\Pro\\Pro\\' => '\\DoubleScale\Pro\\',
	"'DoubleScale\\Pro\\\\Pro\\\\" => "'DoubleScale\\Pro\\\\",
	'"DoubleScale\Pro\\\\Pro\\\\' => '"DoubleScale\Pro\\\\',
);

$rii = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $includes, RecursiveDirectoryIterator::SKIP_DOTS )
);
foreach ( $rii as $file ) {
	if ( $file->getExtension() !== 'php' ) {
		continue;
	}
	$p = $file->getPathname();
	$c = file_get_contents( $p );
	$n = $c;
	foreach ( $replacements as $from => $to ) {
		$n = str_replace( $from, $to, $n );
	}
	if ( $n !== $c ) {
		file_put_contents( $p, $n );
	}
}

$phpunit = $root . '/phpunit';
if ( is_dir( $phpunit ) ) {
	$rii2 = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $phpunit, RecursiveDirectoryIterator::SKIP_DOTS )
	);
	foreach ( $rii2 as $file ) {
		if ( $file->getExtension() !== 'php' ) {
			continue;
		}
		$p = $file->getPathname();
		$c = file_get_contents( $p );
		$n = $c;
		foreach ( $replacements as $from => $to ) {
			$n = str_replace( $from, $to, $n );
		}
		if ( $n !== $c ) {
			file_put_contents( $p, $n );
		}
	}
}

echo "Namespace rewrite complete under includes/ and phpunit/.\n";

$old_install = $includes . '/Database/Install.php';
if ( is_file( $old_install ) ) {
	unlink( $old_install );
	echo "Removed legacy includes/Database/Install.php (use ProInstall).\n";
}

echo "Done.\n";
