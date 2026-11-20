<?php

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

return [
    // All prefixed classes will live under this root namespace.
    // Example: Sabberworm\CSS\Parser -> Perfmatters\Vendor\Sabberworm\CSS\Parser
    // Example: WP_Async_Request -> Perfmatters\Vendor\WP_Async_Request
    'prefix' => 'Perfmatters\\Vendor',

    // wp-background-processing declares global classes. Prefix those so this
    // copy cannot satisfy another plugin's class_exists('WP_Async_Request').
    // Global functions and constants stay exposed, which is PHP-Scoper's default.
    'expose-global-classes' => false,

    // Scope third-party dependencies from Composer's vendor dir.
    // Also copy LICENSE files so attribution survives prefixing.
    // PHP-Scoper strips the shared vendor/ base, so both packages land
    // directly under vendor-prefixed/.
    'finders' => [
        Finder::create()
            ->files()
            ->in(__DIR__ . '/vendor/sabberworm/php-css-parser/src')
            ->name('*.php'),
        Finder::create()
            ->files()
            ->depth(0)
            ->in(__DIR__ . '/vendor/sabberworm/php-css-parser')
            ->name('LICENSE'),
        Finder::create()
            ->files()
            ->in(__DIR__ . '/vendor/deliciousbrains/wp-background-processing/classes')
            ->name('*.php'),
        Finder::create()
            ->files()
            ->depth(0)
            ->in(__DIR__ . '/vendor/deliciousbrains/wp-background-processing')
            ->name(['LICENSE', 'license.txt']),
    ],
];
