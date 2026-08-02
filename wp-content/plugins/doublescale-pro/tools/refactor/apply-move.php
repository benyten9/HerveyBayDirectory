<?php
/**
 * Execute `git mv` for every file in the class map that belongs to the
 * selected module(s), creating destination directories on the fly.
 *
 * Does NOT rewrite file contents - that's apply-codemod.php's job, run
 * AFTER this script so `namespace` / `use` lines get fixed in the moved
 * files as well.
 *
 * Usage:
 *     php tools/refactor/apply-move.php --modules=contacts [--dry-run]
 *
 * @package DoubleScale\Pro\Pro
 */

declare(strict_types=1);

$options = getopt( '', array( 'modules::', 'dry-run' ) );
$dry_run = isset( $options['dry-run'] );
$modules = isset( $options['modules'] ) && '' !== $options['modules']
	? array_map( 'trim', explode( ',', (string) $options['modules'] ) )
	: null;

if ( null === $modules ) {
	fwrite( STDERR, "--modules is required. Example: --modules=contacts\n" );
	exit( 1 );
}

$plugin_root = dirname( __DIR__, 2 );
chdir( $plugin_root );

$map = require $plugin_root . '/tools/refactor/class-map.generated.php';

$moved        = 0;
$dirs_created = 0;

foreach ( $map as $old => $info ) {
	if ( ! in_array( $info['module'], $modules, true ) ) {
		continue;
	}
	$old_path = $info['old_path'];
	$new_path = $info['new_path'];

	if ( $old_path === $new_path ) {
		continue;
	}
	if ( ! is_file( $plugin_root . '/' . $old_path ) ) {
		fwrite( STDERR, "MISSING source: {$old_path}\n" );
		continue;
	}
	if ( is_file( $plugin_root . '/' . $new_path ) ) {
		fwrite( STDERR, "EXISTS target:  {$new_path}\n" );
		continue;
	}

	$dest_dir = dirname( $plugin_root . '/' . $new_path );
	if ( ! is_dir( $dest_dir ) ) {
		if ( ! $dry_run ) {
			if ( ! mkdir( $dest_dir, 0755, true ) && ! is_dir( $dest_dir ) ) {
				fwrite( STDERR, "Failed to mkdir {$dest_dir}\n" );
				continue;
			}
		}
		$dirs_created++;
	}

	$cmd = sprintf( 'git mv %s %s', escapeshellarg( $old_path ), escapeshellarg( $new_path ) );
	if ( $dry_run ) {
		echo $cmd . "\n";
	} else {
		exec( $cmd . ' 2>&1', $output, $rc );
		if ( 0 !== $rc ) {
			fwrite( STDERR, "git mv failed: {$old_path} -> {$new_path}\n" . implode( "\n", $output ) . "\n" );
			continue;
		}
	}
	$moved++;
}

fwrite(
	STDOUT,
	sprintf(
		"Move %s.\n  Moved:        %d files\n  Dirs created: %d\n  Modules:      %s\n",
		$dry_run ? 'DRY-RUN complete' : 'applied',
		$moved,
		$dirs_created,
		implode( ',', $modules )
	)
);
