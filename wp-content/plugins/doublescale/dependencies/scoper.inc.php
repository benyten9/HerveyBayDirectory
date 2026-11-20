<?php
/**
 * PHP-Scoper: prefixes `dependencies/vendor` (SMTP-style single tree).
 *
 * Prerequisite: run `cd dependencies && composer install` so `dependencies/vendor/` exists locally
 * (that directory is not committed; only `dependencies/build/` is shipped in git).
 *
 * Run from plugin root: `composer scope:vendor` (writes `dependencies/build/`).
 * Runtime loads only `dependencies/build/vendor/scoper-autoload.php` when present
 * so Guzzle / SendGrid / etc. do not clash with other plugins.
 *
 * WPEloquent's bundled Illuminate, and the Carbon / Doctrine it uses, are prefixed
 * too (DoubleScale\Vendor\Illuminate\…): other plugins ship their own Laravel
 * (e.g. MailerPress ships illuminate/* 12 with psr/container 2), and two copies
 * under one class name fatal the site. Prefix the whole set together, never a
 * part of it, or contracts and implementations split.
 *
 * @package DoubleScale
 */

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

return [
	'prefix'                  => 'DoubleScale\\Vendor',
	'expose-global-constants' => true,
	'expose-global-classes'   => true,
	// Keep this on: the scoped Illuminate code calls its own helpers (value(),
	// collect()…) unqualified and lands on these global aliases, so turning it
	// off takes the site down. tools/prune-scoper-function-aliases.php drops the
	// aliases that point at nothing (request 132: UpdraftPlus backups died on
	// our litespeed_finish_request()).
	'expose-global-functions' => true,
	'expose-namespaces'       => array(),
	'exclude-namespaces'      => array(
		'Composer',
		'GuzzleHttp',
		'Psr\\Http',
		'Psr\\Log',
		'League',
		'Javanile',
	),
	'exclude-files'           => array(
		__DIR__ . '/composer.json',
		__DIR__ . '/composer.lock',
	),
	'finders'                 => array(
		Finder::create()
			->files()
			->ignoreVCS( true )
			->notName( '/.*\\.md|.*\\.dist$|Makefile|composer\\.json|composer\\.lock/' )
			->exclude(
				array(
					'doc',
					'test',
					'test_old',
					'tests',
					'Tests',
					'vendor-bin',
				)
			)
			->in( __DIR__ . '/vendor' ),
		Finder::create()->append(
			array(
				__DIR__ . '/composer.json',
				__DIR__ . '/composer.lock',
			)
		),
	),
	'patchers'                => array(
		/**
		 * Prefix dynamic class strings used at runtime by bundled libraries.
		 */
		static function ( string $file_path, string $prefix, string $contents ): string {
			$p = str_replace( '\\', '\\\\', $prefix );

			// Dompdf builds decorator/reflower/positioner class names at runtime.
			$contents = str_replace(
				'"Dompdf\\\\FrameDecorator\\\\$decorator"',
				'"' . $p . '\\\\Dompdf\\\\FrameDecorator\\\\$decorator"',
				$contents
			);
			$contents = str_replace(
				'"Dompdf\\\\FrameReflower\\\\$reflower"',
				'"' . $p . '\\\\Dompdf\\\\FrameReflower\\\\$reflower"',
				$contents
			);
			$contents = str_replace(
				"'\\Dompdf\\Positioner\\' . \$type",
				"'\\" . $p . "\\Dompdf\\Positioner\\' . \$type",
				$contents
			);
			$contents = str_replace(
				"'\\Dompdf\\Positioner\\'.\$type",
				"'\\" . $p . "\\Dompdf\\Positioner\\'.\$type",
				$contents
			);

			// php-font-lib: keep relative class names; scope hardcoded FontLib\ prefixes.
			$contents = str_replace(
				array(
					'"' . $p . '\\\\TrueType\\\\File"',
					'"' . $p . '\\\\OpenType\\\\File"',
					'"' . $p . '\\\\WOFF\\\\File"',
					'"' . $p . '\\\\TrueType\\\\Collection"',
					'"' . $p . '\\\\EOT\\\\File"',
				),
				array(
					'"TrueType\\\\File"',
					'"OpenType\\\\File"',
					'"WOFF\\\\File"',
					'"TrueType\\\\Collection"',
					'"EOT\\\\File"',
				),
				$contents
			);
			$contents = str_replace( '"FontLib\\\\', '"' . $p . '\\\\FontLib\\\\', $contents );
			$contents = str_replace(
				'return $class_parts[1];',
				'return $class_parts[ \\count( $class_parts ) - 2 ];',
				$contents
			);

			return $contents;
		},
	),
];
