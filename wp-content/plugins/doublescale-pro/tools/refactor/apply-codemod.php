<?php
/**
 * Rewrite references to Old FQCNs inside PHP files.
 *
 * Why regex over a proper PHP AST?
 *   - Every FQCN we rewrite is fully-qualified (e.g. `DoubleScale\Pro\Managers\DealManager`)
 *     and will never appear as a substring of anything else. These tokens
 *     are always adjacent to boundary characters (`\`, whitespace, `(`, `,`,
 *     `;`, `:`, quote). A word-boundary regex gives the right result and
 *     doesn't need nikic/php-parser at runtime.
 *   - The replacement is mechanical: `Old\Name` -> `New\Name`. Keeping it
 *     regex-based means the tool is self-contained (no composer dev install).
 *
 * Scope:
 *   - Only rewrite *references* in .php files. File renames happen in a
 *     separate pass via `git mv` driven by the same map (see
 *     `apply-move.php`). This script must run AFTER the move, on the new
 *     file contents, so `use`/`namespace` lines in the moved files get
 *     rewritten too.
 *
 *   - Filter selected modules via `--modules=contacts,deals` (comma-sep).
 *     Defaults to ALL modules. Useful for the Contacts pilot: we first
 *     move + rewrite Contacts only, verify, then move on.
 *
 * Usage:
 *     php tools/refactor/apply-codemod.php [--modules=contacts] [--dry-run]
 *
 * @package DoubleScale\Pro\Pro
 */

declare(strict_types=1);

$options  = getopt( '', array( 'modules::', 'dry-run' ) );
$dry_run  = isset( $options['dry-run'] );
$modules  = isset( $options['modules'] ) && '' !== $options['modules']
	? array_map( 'trim', explode( ',', (string) $options['modules'] ) )
	: null;

$plugin_root = dirname( __DIR__, 2 );
$map_file    = $plugin_root . '/tools/refactor/class-map.generated.php';

if ( ! is_file( $map_file ) ) {
	fwrite( STDERR, "Run build-class-map.php first. Missing: {$map_file}\n" );
	exit( 1 );
}

$full_map = require $map_file;

$active_map = array();
foreach ( $full_map as $old => $info ) {
	if ( null !== $modules && ! in_array( $info['module'], $modules, true ) ) {
		continue;
	}
	$active_map[ $old ] = $info['new'];
}

// Per-file `namespace` line fixup: when a moved file still declares its old
// namespace we rewrite the declaration to match its new path.
// Keyed by absolute file path (as it exists on disk now).
$ns_fixup = array();
foreach ( $full_map as $old => $info ) {
	if ( null !== $modules && ! in_array( $info['module'], $modules, true ) ) {
		continue;
	}
	$abs_new = realpath( $plugin_root . '/' . $info['new_path'] );
	if ( ! $abs_new ) {
		continue;
	}
	$old_ns = substr( $old, 0, strrpos( $old, '\\' ) );
	$new_ns = substr( $info['new'], 0, strrpos( $info['new'], '\\' ) );
	if ( $old_ns !== $new_ns ) {
		$ns_fixup[ $abs_new ] = array( $old_ns, $new_ns );
	}
}

// Sort by old-name length desc so longer FQCNs (e.g.
// DoubleScale\Pro\Foo\Bar\Baz) are replaced before overlapping shorter prefixes
// (DoubleScale\Pro\Foo\Bar). Not strictly required given our word-boundary pattern
// below, but makes collisions obvious if they ever slip in.
uksort(
	$active_map,
	static fn( $a, $b ) => strlen( $b ) <=> strlen( $a )
);

// Walk every PHP file under includes/ (plus the plugin entry) and rewrite.
$targets = array();
$rii     = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $plugin_root . '/includes', RecursiveDirectoryIterator::SKIP_DOTS )
);
foreach ( $rii as $file ) {
	if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
		$targets[] = $file->getPathname();
	}
}
$targets[] = $plugin_root . '/doublescale-pro.php';

$stats = array(
	'files_scanned' => 0,
	'files_changed' => 0,
	'replacements'  => 0,
);

foreach ( $targets as $path ) {
	$before = file_get_contents( $path );
	if ( false === $before ) {
		continue;
	}
	$stats['files_scanned']++;

	$after    = $before;
	$local_r  = 0;

	// 1. Rewrite class-like FQCNs (including `\\`-escaped strings used with
	//    `class_alias`, Illuminate model bindings, etc.). Uses backslash as
	//    the left boundary and a "not a word/backslash" char on the right.
	foreach ( $active_map as $old => $new ) {
		// Match optional leading backslash so `\Foo\Bar` and `Foo\Bar` both
		// land. Right boundary prevents partial matches like `FooBar` inside
		// `FooBarBaz`. We rewrite without adding a leading backslash - if
		// the source had one, `preg_replace` preserves it via the look-behind.
		$pattern = '/(?<![A-Za-z0-9_\\\\])' . preg_quote( $old, '/' ) . '(?![A-Za-z0-9_\\\\])/';
		$after   = preg_replace( $pattern, $new, $after, -1, $count );
		$local_r += (int) $count;
	}

	// 2. If this is a moved file whose namespace declaration needs fixing,
	//    rewrite ONLY the `namespace X;` line in it.
	$abs_path = realpath( $path );
	if ( $abs_path && isset( $ns_fixup[ $abs_path ] ) ) {
		list( $old_ns, $new_ns ) = $ns_fixup[ $abs_path ];
		$pattern  = '/^namespace\s+' . preg_quote( $old_ns, '/' ) . '(?=\s*;)/m';
		$after    = preg_replace( $pattern, 'namespace ' . $new_ns, $after, 1, $count );
		$local_r += (int) $count;
	}

	if ( $local_r > 0 ) {
		$stats['files_changed']++;
		$stats['replacements'] += $local_r;
		if ( ! $dry_run ) {
			file_put_contents( $path, $after );
		}
	}
}

fwrite(
	STDOUT,
	sprintf(
		"Codemod %s.\n  Scanned:      %d\n  Changed:      %d\n  Replacements: %d\n  Map size:     %d\n  Modules:      %s\n",
		$dry_run ? 'DRY-RUN complete' : 'applied',
		$stats['files_scanned'],
		$stats['files_changed'],
		$stats['replacements'],
		count( $active_map ),
		null === $modules ? 'ALL' : implode( ',', $modules )
	)
);
