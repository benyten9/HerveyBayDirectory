<?php
/**
 * Sync modular layout from Pro into a DS Free checkout (manifest-driven).
 *
 * Defaults to dry-run (lists planned copies). Requires --execute to write files.
 * Optional --rewrite-namespace swaps DoubleScale\Pro\\Pro\\ → DoubleScale\Pro\\ (adjust in manifest).
 *
 * Usage:
 *     php tools/refactor/port-to-free.php --target=/abs/path/to/DoubleScale-Free
 *     php tools/refactor/port-to-free.php --target=/abs/path/to/DoubleScale-Free --execute
 *     php tools/refactor/port-to-free.php --target=... --execute --rewrite-namespace
 *
 * When the Free repository is outside this workspace, pass its root as --target.
 *
 * @package DoubleScale\Pro\Pro
 */

declare( strict_types=1 );

$longopts = array(
	'target:',
	'manifest:',
	'execute',
	'rewrite-namespace',
	'dry-run',
);

$opts = getopt( '', $longopts );

if ( empty( $opts['target'] ) ) {
	fwrite(
		STDERR,
		"--target required: absolute path to DS Free plugin root (directory containing composer.json / includes/).\n"
	);
	exit( 1 );
}

$plugin_root = dirname( __DIR__, 2 );
chdir( $plugin_root );

$manifest_path = isset( $opts['manifest'] ) && is_string( $opts['manifest'] )
	? $opts['manifest']
	: $plugin_root . '/tools/refactor/port-to-free-manifest.json';

if ( ! is_file( $manifest_path ) ) {
	fwrite( STDERR, "Manifest not found: {$manifest_path}\n" );
	exit( 1 );
}

$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
if ( ! is_array( $manifest ) ) {
	fwrite( STDERR, "Invalid manifest JSON.\n" );
	exit( 1 );
}

$modules    = isset( $manifest['modules'] ) && is_array( $manifest['modules'] ) ? $manifest['modules'] : array();
$core_files = isset( $manifest['core_files'] ) && is_array( $manifest['core_files'] ) ? $manifest['core_files'] : array();
$subdir     = isset( $manifest['target_root_subdir'] ) ? (string) $manifest['target_root_subdir'] : 'includes';

$ns_from = isset( $manifest['namespace_from'] ) ? (string) $manifest['namespace_from'] : '';
$ns_to   = isset( $manifest['namespace_to'] ) ? (string) $manifest['namespace_to'] : '';

$target_root = rtrim( (string) $opts['target'], '/\\' );
if ( ! is_dir( $target_root ) ) {
	fwrite( STDERR, "Target is not a directory: {$target_root}\n" );
	exit( 1 );
}

$execute          = isset( $opts['execute'] );
$rewrite_ns       = isset( $opts['rewrite-namespace'] );
$dry_run_explicit = isset( $opts['dry-run'] );

if ( $dry_run_explicit ) {
	$execute = false;
}

if ( ! $execute ) {
	fwrite( STDERR, "Dry-run mode (no files written). Pass --execute to copy.\n" );
}

$sources_to_copy = array();

foreach ( $modules as $slug ) {
	$slug = (string) $slug;
	$src  = $plugin_root . '/includes/Modules/' . $slug;
	if ( ! is_dir( $src ) ) {
		fwrite( STDERR, "MISSING module dir in Pro (skip): includes/Modules/{$slug}\n" );
		continue;
	}
	$sources_to_copy[] = array(
		'type' => 'dir',
		'from' => $src,
		'to'   => $target_root . '/' . $subdir . '/Modules/' . $slug,
	);
}

foreach ( $core_files as $rel ) {
	$rel = (string) $rel;
	$src = $plugin_root . '/' . $rel;
	if ( ! is_file( $src ) ) {
		fwrite( STDERR, "MISSING core file (skip): {$rel}\n" );
		continue;
	}
	$sources_to_copy[] = array(
		'type' => 'file',
		'from' => $src,
		'to'   => $target_root . '/' . $rel,
	);
}

if ( $sources_to_copy === array() ) {
	fwrite( STDERR, "Nothing to sync (empty manifest result).\n" );
	exit( 1 );
}

$files_copied = 0;
$bytes        = 0;

foreach ( $sources_to_copy as $item ) {
	if ( 'dir' === $item['type'] ) {
		if ( ! $execute ) {
			echo "DIR  {$item['from']} -> {$item['to']}\n";
		}
		port_to_free_copy_tree( $item['from'], $item['to'], $execute, $rewrite_ns, $ns_from, $ns_to, $files_copied, $bytes );
	} else {
		if ( ! $execute ) {
			echo "FILE {$item['from']} -> {$item['to']}\n";
		} else {
			port_to_free_ensure_dir( dirname( $item['to'] ) );
			port_to_free_copy_file( $item['from'], $item['to'], $rewrite_ns, $ns_from, $ns_to );
			++$files_copied;
			$bytes += filesize( $item['to'] );
		}
	}
}

if ( $execute ) {
	echo "Copied {$files_copied} files (~{$bytes} bytes).\n";
	exit( 0 );
}

echo "\nSummary: planned items above. See port-to-free-manifest.json to adjust modules/core_files.\n";

/**
 * @param-out int $files_copied
 * @param-out int $bytes
 */
function port_to_free_copy_tree(
	string $src,
	string $dst,
	bool $execute,
	bool $rewrite_ns,
	string $ns_from,
	string $ns_to,
	int &$files_copied,
	int &$bytes
): void {
	if ( ! is_dir( $src ) ) {
		return;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $src, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $iterator as $info ) {
		/** @var SplFileInfo $info */
		$sub = substr( $info->getPathname(), strlen( $src ) );
		$sub = str_replace( '\\', '/', $sub );
		$target_path = $dst . $sub;

		if ( $info->isDir() ) {
			if ( $execute ) {
				port_to_free_ensure_dir( $target_path );
			}
			continue;
		}

		if ( ! $execute ) {
			echo "FILE {$info->getPathname()} -> {$target_path}\n";
			continue;
		}

		port_to_free_ensure_dir( dirname( $target_path ) );
		port_to_free_copy_file( $info->getPathname(), $target_path, $rewrite_ns, $ns_from, $ns_to );
		++$files_copied;
		$bytes += filesize( $target_path );
	}
}

function port_to_free_ensure_dir( string $dir ): void {
	if ( is_dir( $dir ) ) {
		return;
	}
	if ( ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
		fwrite( STDERR, "Failed to mkdir {$dir}\n" );
		exit( 1 );
	}
}

function port_to_free_copy_file(
	string $from,
	string $to,
	bool $rewrite_ns,
	string $ns_from,
	string $ns_to
): void {
	$data = (string) file_get_contents( $from );
	if ( $rewrite_ns && '' !== $ns_from && '' !== $ns_to && substr( $from, -4 ) === '.php' ) {
		$data = str_replace( $ns_from, $ns_to, $data );
	}
	if ( file_put_contents( $to, $data ) === false ) {
		fwrite( STDERR, "Failed to write {$to}\n" );
		exit( 1 );
	}
}
