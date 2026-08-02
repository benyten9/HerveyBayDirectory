<?php
/**
 * Print legacy MergeTags FQCN => new module MergeTags FQCN lines for aliases-map.php.
 *
 * @package DoubleScale\Pro\Pro
 */

declare( strict_types = 1 );

$plugin_root = dirname( __DIR__, 2 );

$roots = array(
	$plugin_root . '/includes/Modules/Contacts/MergeTags',
	$plugin_root . '/includes/Modules/Deals/MergeTags',
	$plugin_root . '/includes/Modules/Forms/MergeTags',
	$plugin_root . '/includes/Modules/Inbox/MergeTags',
	$plugin_root . '/includes/Modules/Automations/MergeTags',
);

$map = array();

foreach ( $roots as $dir ) {
	if ( ! is_dir( $dir ) ) {
		continue;
	}
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $it as $file ) {
		if ( strtolower( $file->getExtension() ) !== 'php' ) {
			continue;
		}
		$src = file_get_contents( $file->getPathname() );
		if ( false === $src ) {
			continue;
		}
		if ( ! preg_match( '/^namespace\s+([^;]+);/m', $src, $ns ) ) {
			continue;
		}
		if ( ! preg_match( '/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $src, $cls ) ) {
			continue;
		}
		$new_ns = trim( $ns[1] );
		$class    = $cls[1];
		if ( strpos( $new_ns, '\\MergeTags\\' ) === false ) {
			continue;
		}
		if ( ! preg_match( '/^DoubleScale\Pro\\\\Pro\\\\Modules\\\\([^\\\\]+)\\\\MergeTags\\\\(.+)$/', $new_ns, $m ) ) {
			continue;
		}
		$legacy_ns = 'DoubleScale\\Pro\\Pro\\MergeTags\\' . $m[2];
		$old_fqcn  = $legacy_ns . '\\' . $class;
		$new_fqcn  = $new_ns . '\\' . $class;
		if ( $old_fqcn === $new_fqcn ) {
			continue;
		}
		$map[ $old_fqcn ] = $new_fqcn;
	}
}

ksort( $map );

foreach ( $map as $o => $n ) {
	echo '  ' . var_export( $o, true ) . ' => ' . var_export( $n, true ) . ",\n";
}

fwrite( STDERR, '// emitted ' . count( $map ) . " entries\n" );
