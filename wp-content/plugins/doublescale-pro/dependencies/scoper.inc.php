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
 * WPEloquent ships bundled Illuminate — those namespaces must never be prefixed
 * or you split contracts vs implementations (PHP 8.1+ fatals).
 *
 * @package DoubleScale\Pro
 */

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

return [
	'prefix'                  => 'DoubleScale\\Pro\\Vendor',
	'expose-global-constants' => true,
	'expose-global-classes'   => true,
	'expose-global-functions' => true,
	'expose-namespaces'       => array(
		'SendGrid',
		'Postmark',
		'Brevo',
	),
	// NOTE: Stripe is *prefixed* (DoubleScale\Pro\Vendor\Stripe\*), not exposed.
	// Exposing it tries to create global `Stripe\*` aliases, but
	// `Stripe\Exception\ExceptionInterface` is declared inside a conditional
	// `if (interface_exists(...))` block which PHP-Scoper cannot emit a
	// trailing class_alias() for. The result is a hard re-declaration fatal at
	// boot. Code under includes/ that uses `\Stripe\StripeClient` must be
	// updated to use `\DoubleScale\Pro\Vendor\Stripe\StripeClient` (or the
	// `use Stripe\StripeClient as DoubleScale...\StripeClient` pattern).
	'exclude-namespaces'      => array(
		'Composer',
		'GuzzleHttp',
		'Psr\\Http',
		'Psr\\Log',
		'Illuminate',
		'WPEloquent',
		'Carbon',
		'League',
		'Javanile',
		// Doctrine: optional cache adapters in some SDKs; partial prefix breaks implements clauses.
		'Doctrine',
	),
	'exclude-files'           => array(
		__DIR__ . '/composer.json',
		__DIR__ . '/composer.lock',
		// javanile/php-imap2 ships its `imap_*` polyfills as GLOBAL functions in
		// a namespace-less bootstrap.php (so a no-ext-imap server can call
		// `\imap_rfc822_write_address()` etc. that the library's own src/ code
		// invokes). PHP-Scoper otherwise wraps the file in the prefix namespace
		// (`DoubleScale\Pro\Vendor`), which redefines those functions as
		// `DoubleScale\Pro\Vendor\imap_*` and leaves the global ones undefined —
		// a fatal "Call to undefined function imap_rfc822_write_address()" on
		// every inbound IMAP poll. Excluding the file copies it verbatim so the
		// polyfills stay global. Its `use Javanile\Imap2\*` references still
		// resolve, because the `Javanile` namespace is excluded above (those
		// classes stay unprefixed).
		__DIR__ . '/vendor/javanile/php-imap2/bootstrap.php',
	),
	'finders'                 => array(
		Finder::create()
			->files()
			->ignoreVCS( true )
			->notName( '/.*\\.md|.*\\.dist|Makefile|composer\\.json|composer\\.lock/' )
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
		 * Fix javanile/php-imap2's broken `rfc822WriteHeaders` polyfill.
		 *
		 * The library's pure-PHP fallback for `imap_rfc822_write_address()` is a
		 * no-op stub (`return $string;`) that ignores the host and personal-name
		 * arguments, so on a no-ext-imap server an inbound From of
		 * `Name <local@host>` collapses to just `local` — breaking contact
		 * resolution and ticket creation. Replace it with a correct RFC 822
		 * address builder. (This is the durable companion to excluding
		 * bootstrap.php above: that keeps the polyfill global; this makes it
		 * actually correct.)
		 */
		static function ( string $file_path, string $prefix, string $contents ): string {
			if ( ! str_ends_with( str_replace( '\\', '/', $file_path ), 'javanile/php-imap2/src/Polyfill.php' ) ) {
				return $contents;
			}

			$broken = "public static function rfc822WriteHeaders(\$string)\n    {\n        return \$string;\n    }";

			$fixed = <<<'PHP'
public static function rfc822WriteHeaders($mailbox, $hostname = '', $personal = '')
    {
        // Back-compat: a single argument is treated as a pre-formatted address.
        if ('' === (string) $hostname && '' === (string) $personal) {
            return (string) $mailbox;
        }
        $address = '' !== (string) $hostname
            ? (string) $mailbox . '@' . (string) $hostname
            : (string) $mailbox;
        $personal = (string) $personal;
        if ('' === $personal) {
            return $address;
        }
        if (\preg_match('/[()<>@,;:\\\\".\[\]]/', $personal)) {
            $personal = '"' . \str_replace(array('\\\\', '"'), array('\\\\\\\\', '\\"'), $personal) . '"';
        }
        return $personal . ' <' . $address . '>';
    }
PHP;

			return str_replace( $broken, $fixed, $contents );
		},
		/**
		 * Prefix dynamic class strings used by Brevo / SendGrid clients.
		 */
		static function ( string $file_path, string $prefix, string $contents ): string {
			$p = str_replace( '\\', '\\\\', $prefix );

			$contents = str_replace(
				'\'\\\\Brevo\\\\Client\\\\Model\\\\',
				'\'' . $p . '\\\\Brevo\\\\Client\\\\Model\\\\',
				$contents
			);

			$contents = str_replace(
				'\'\\\\SendGrid\\\\Mail\\\\',
				'\'' . $p . '\\\\SendGrid\\\\Mail\\\\',
				$contents
			);

			return $contents;
		},
	),
];
