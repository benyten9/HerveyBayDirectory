<?php
/**
 * Emit includes/Core/Deprecated/aliases-map.php from the class map, for
 * the selected module(s) only (default: all).
 *
 * Usage: php tools/refactor/emit-aliases-map.php [--modules=contacts,deals]
 *
 * Only classes flagged public=true get a shim - internals would clutter the
 * map without helping any third-party.
 *
 * @package DoubleScale\Pro\Pro
 */
declare(strict_types=1);

$options = getopt( '', array( 'modules::' ) );
$modules = isset( $options['modules'] ) && '' !== $options['modules']
	? array_map( 'trim', explode( ',', (string) $options['modules'] ) )
	: null;

// Both files guard with `defined('ABSPATH') || exit;`, so define a fake
// ABSPATH before including them. The constant never touches WP itself.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/cli-stub/' );
}

$plugin_root = dirname( __DIR__, 2 );
$map         = require $plugin_root . '/tools/refactor/class-map.generated.php';

$existing_file = $plugin_root . '/includes/Core/Deprecated/aliases-map.php';
$existing      = is_file( $existing_file ) ? (array) require $existing_file : array();

$selected = array();
foreach ( $map as $old => $info ) {
	if ( ! $info['public'] ) {
		continue;
	}
	if ( null !== $modules && ! in_array( $info['module'], $modules, true ) ) {
		continue;
	}
	$selected[ $old ] = $info['new'];
}

$merged = array_merge( $existing, $selected );
ksort( $merged );

$out  = "<?php\n";
$out .= "/**\n";
$out .= " * Generated list of OldFQCN => NewFQCN for `class_alias` shims.\n";
$out .= " *\n";
$out .= " * Edited via tools/refactor/emit-aliases-map.php. Every public-facing\n";
$out .= " * class that was relocated during the modular refactor lives here, so\n";
$out .= " * third-party extenders that `use DoubleScale\Pro\\Pro\\Managers\\DealManager` (or\n";
$out .= " * similar) keep resolving to the moved class instead of fatal-ing.\n";
$out .= " *\n";
$out .= " * Timestamp: " . gmdate( 'c' ) . "\n";
$out .= " * Entries:   " . count( $merged ) . "\n";
$out .= " *\n";
$out .= " * @package DoubleScale\Pro\\Pro\n";
$out .= " */\n\n";
$out .= "defined( 'ABSPATH' ) || exit;\n\n";
$out .= "return " . var_export( $merged, true ) . ";\n";

file_put_contents( $existing_file, $out );

fwrite(
	STDOUT,
	sprintf(
		"aliases-map.php regenerated.\n  Selected this run: %d\n  Total in map:      %d\n",
		count( $selected ),
		count( $merged )
	)
);
