<?php
/**
 * Post PHP-Scoper fixes (generated files, not handled by patchers).
 *
 * @package DoubleScale
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );

$scoper_autoload = $root . '/dependencies/build/vendor/scoper-autoload.php';

if ( ! is_file( $scoper_autoload ) ) {
	exit( 0 );
}

$c = file_get_contents( $scoper_autoload );
// PHP-Scoper 0.17 can emit invalid PHP for this exposed GuzzleHttp\Psr7 stub.
$c = str_replace( 'function Psr7\\try_fopen()', 'function try_fopen()', $c );
file_put_contents( $scoper_autoload, $c );

// PHP-Scoper excludes `*.dist` filenames; Dompdf needs `installed-fonts.dist.json`.
$font_src = $root . '/dependencies/vendor/dompdf/dompdf/lib/fonts';
$font_dst = $root . '/dependencies/build/vendor/dompdf/dompdf/lib/fonts';
if ( is_dir( $font_src ) && is_dir( $font_dst ) ) {
	foreach ( glob( $font_src . '/*' ) ?: array() as $file ) {
		if ( ! is_file( $file ) ) {
			continue;
		}
		$target = $font_dst . '/' . basename( $file );
		if ( ! is_file( $target ) ) {
			copy( $file, $target );
		}
	}
}

// Dompdf builds some class names at runtime; prefix them for the scoped build.
$factory = $root . '/dependencies/build/vendor/dompdf/dompdf/src/Frame/Factory.php';
if ( is_file( $factory ) ) {
	$factory_contents = file_get_contents( $factory );
	$factory_contents = str_replace(
		array(
			'"Dompdf\\\\FrameDecorator\\\\{$decorator}"',
			'"Dompdf\\\\FrameReflower\\\\{$reflower}"',
			"'\\\\Dompdf\\\\Positioner\\\\' . \$type",
		),
		array(
			'"DoubleScale\\\\Vendor\\\\Dompdf\\\\FrameDecorator\\\\{$decorator}"',
			'"DoubleScale\\\\Vendor\\\\Dompdf\\\\FrameReflower\\\\{$reflower}"',
			"'\\\\DoubleScale\\\\Vendor\\\\Dompdf\\\\Positioner\\\\' . \$type",
		),
		$factory_contents
	);
	file_put_contents( $factory, $factory_contents );
}

// php-font-lib: scoper breaks relative class names and leaves hardcoded FontLib\ prefixes.
$font_lib = $root . '/dependencies/build/vendor/phenx/php-font-lib/src/FontLib';
if ( is_dir( $font_lib ) ) {
	$font_php = $font_lib . '/Font.php';
	if ( is_file( $font_php ) ) {
		$font_contents = file_get_contents( $font_php );
		$font_contents = str_replace(
			array(
				'"DoubleScale\\\\Vendor\\\\TrueType\\\\File"',
				'"DoubleScale\\\\Vendor\\\\OpenType\\\\File"',
				'"DoubleScale\\\\Vendor\\\\WOFF\\\\File"',
				'"DoubleScale\\\\Vendor\\\\TrueType\\\\Collection"',
				'"DoubleScale\\\\Vendor\\\\EOT\\\\File"',
			),
			array(
				'"TrueType\\\\File"',
				'"OpenType\\\\File"',
				'"WOFF\\\\File"',
				'"TrueType\\\\Collection"',
				'"EOT\\\\File"',
			),
			$font_contents
		);
		file_put_contents( $font_php, $font_contents );
	}

	$font_files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $font_lib, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $font_files as $font_file ) {
		if ( ! $font_file->isFile() || 'php' !== $font_file->getExtension() ) {
			continue;
		}
		$font_file_contents = file_get_contents( $font_file->getPathname() );
		$patched              = str_replace(
			'"FontLib\\\\',
			'"DoubleScale\\\\Vendor\\\\FontLib\\\\',
			$font_file_contents
		);
		if ( $patched !== $font_file_contents ) {
			file_put_contents( $font_file->getPathname(), $patched );
		}
	}

	$tt_file = $font_lib . '/TrueType/File.php';
	if ( is_file( $tt_file ) ) {
		$tt_contents = file_get_contents( $tt_file );
		$tt_contents = str_replace(
			'return $class_parts[1];',
			'return $class_parts[ \\count( $class_parts ) - 2 ];',
			$tt_contents
		);
		file_put_contents( $tt_file, $tt_contents );
	}
}

exit( 0 );
