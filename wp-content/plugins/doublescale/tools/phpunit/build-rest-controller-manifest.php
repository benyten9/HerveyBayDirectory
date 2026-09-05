<?php
/**
 * Regenerate phpunit/data/rest-controller-classes.expected.json from
 * each module’s restControllers() (Core and every includes/Modules/{slug}/Module.php).
 *
 * Usage (from plugin root): php tools/phpunit/build-rest-controller-manifest.php
 *
 * @package DoubleScale
 */

$root = dirname( __DIR__, 2 );
require_once $root . '/phpunit/RestControllerManifestUtil.php';

$names = doublescale_collect_rest_controller_short_names( $root );
$fqcn  = doublescale_collect_rest_controller_fqcn_map( $root );

$out_dir = $root . '/phpunit/data';
if ( ! is_dir( $out_dir ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- CLI dev tool (not shipped); WP_Filesystem unavailable outside WP runtime.
	mkdir( $out_dir, 0777, true );
}

$out_file = $out_dir . '/rest-controller-classes.expected.json';

file_put_contents(
	$out_file,
	json_encode( $names, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
);

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI dev tool output; $out_file and count() are internal trusted values.
echo "Wrote {$out_file} (" . count( $names ) . " controllers)\n";

$map_file = $out_dir . '/rest-controller-fqcn-map.json';
file_put_contents(
	$map_file,
	json_encode( $fqcn, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
);

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI dev tool output; $map_file and count() are internal trusted values.
echo "Wrote {$map_file} (" . count( $fqcn ) . " FQCN entries)\n";
