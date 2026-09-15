<?php

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

return [
    // All prefixed classes will live under this root namespace.
    // Example: Sabberworm\CSS\Parser -> Perfmatters\Vendor\Sabberworm\CSS\Parser
    'prefix' => 'Perfmatters\\Vendor',

    // Scope third-party parser/runtime dependencies from Composer's vendor dir.
    // Also copy LICENSE files so MIT attribution survives prefixing.
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
            ->in(__DIR__ . '/vendor/thecodingmachine/safe')
            ->name(['*.php', 'LICENSE']),
    ],
];

