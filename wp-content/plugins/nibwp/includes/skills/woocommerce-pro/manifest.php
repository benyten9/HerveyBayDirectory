<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Skill Manifest — WooCommerce Pro.
 *
 * The Free plugin already reads and writes the shop: products, variations,
 * orders, refunds, coupons, customers, categories, reviews, stock, reports.
 * This skill is the half nobody can do through those abilities — the store
 * itself. Taxes are rows in wc_tax_rates, not a post type. Shipping is zones
 * with methods hung off them. Payment gateways keep their configuration in a
 * per-gateway settings option. Global attributes are taxonomies that have to
 * exist before a variable product can have variations. A thousand-row catalogue
 * arrives as a CSV, in batches, or not at all.
 *
 * Three hub abilities rather than sixty tools, because an MCP client degrades
 * badly past a couple of hundred: nibwp/wcpro-store, nibwp/wcpro-catalog,
 * nibwp/wcpro-import. Each carries an `action` enum.
 */

return [
    'id'             => 'woocommerce-pro',
    'name'           => 'WooCommerce Pro',
    'tagline'        => 'Set up and run the whole store — taxes, shipping zones, payment gateways, webhooks, global attributes, variations, and bulk CSV import/export',
    'description'    => 'The Free WooCommerce abilities cover the catalogue and the order book. This skill covers the store around them: general settings and selling locations, tax classes and tax rates, shipping zones and the methods inside them, payment gateways, webhooks, global product attributes and their terms, generating every variation of a variable product, cross-sells and upsells, downloadable files, product images from a URL, tags, bulk price changes, scheduled sales, product duplication, and bulk CSV import and export with a dry run that reports every bad row before anything is written.',
    'vendor'         => 'NIBWP',
    'version'        => '1.0.0',
    'category'       => 'ecommerce',
    'premium'        => true,
    'price'          => 49,
    'required_plans' => ['pro', 'agency', 'lifetime'],
    'requires'       => ['woocommerce'],
    'entitlements'   => ['skill:woocommerce', 'skill:woocommerce-pro', 'integration:woocommerce'],
    // No integration_files: the WooCommerce ability files ship in the Free build
    // and are always loaded, so there is nothing here for a standalone skill
    // licence to unlock.
    'features'       => [
        'Configure a brand-new store end to end — address, currency, where you sell, where you ship',
        'Tax classes and tax rates by country, state, postcode and city, with priorities and compound rates',
        'Shipping zones with flat rate, free shipping and local pickup, priced per zone',
        'Turn payment gateways on or off and fill in their settings',
        'Webhooks to any URL, so the store can talk to the rest of your stack',
        'Global product attributes and their terms — the prerequisite for real variable products',
        'Generate every variation of a variable product in one call instead of clicking a grid',
        'Cross-sells, upsells, grouped children and external product links',
        'Downloadable files, download limits and expiry',
        'Product images pulled straight from a URL into the media library',
        'Product tags, created and tidied in bulk',
        'Change prices across a filtered set of products by percentage or fixed amount',
        'Schedule a sale for a date range and clear it again afterwards',
        'Duplicate a product, variations and all',
        'Import a product CSV with a dry run first that names every bad row',
        'Resume a large import in batches instead of one request that times out',
        'Export products, orders or customers to CSV and get the download URL',
        'Every notification email — subject, heading, recipient, extra content — and a report of the ones switched off',
        'Send yourself a test email to check the styling and whether mail leaves the server at all',
        'Search the customer base by lifetime spend, order count, last order date and registration date',
        'The whole picture for one customer — orders, lifetime value, average order, addresses, notes',
        'Find guest orders that share an email with an account, and attach them to it',
        'Segment the base into named groups and get counts and ids, not a dump of every record',
        'Revenue over a period against the period before it, resolved in the store\'s own timezone',
        'Every figure says whether it came from WooCommerce analytics tables or from the order book, and cross-checks the two on request',
        'Top sellers by units or by money, low stock and out of stock, coupon performance, refund rate, new customers over time',
        'One store health call for the handful of numbers an owner actually acts on',
    ],
    // Part 2 appends to this list. Order matters only in that lib/ loads first.
    'ability_files' => [
        'abilities/store.php',
        'abilities/catalog.php',
        'abilities/import.php',
        'abilities/templates.php',
        'abilities/emails.php',
        'abilities/customers.php',
        'abilities/analytics.php',
    ],
    'instructions_file' => 'authoring/SKILL.md',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>',

    // ─── v2 routing contract ──────────────────────────────────────────────
    // The boundary against the Free WooCommerce abilities, in the words a model
    // can reason about. `triggers` below are a fast path; this sentence decides.
    'use_when' => 'The user wants the STORE configured or operated at a level the Free WooCommerce abilities do not reach: tax classes and rates, shipping zones and methods, payment gateways, webhooks, general/selling-location settings, global product attributes and their terms, generating variations, cross-sells/upsells/grouped/external links, downloadable files, product tags, bulk price changes, scheduled sales, product duplication, CSV import/export, the store templates, the notification emails (what they say, which are switched off, a test send), the customer base as a base (search by spend/recency, a whole profile, order notes, guest-order linking, segments), or the store figures WITH their provenance (revenue against the previous period resolved in the site timezone, refund rate, top sellers, low stock, coupon performance, customer acquisition, a store health summary). Single products, variations, orders, refunds, coupons, customers, categories, reviews and stock are already covered by the Free wc-* abilities — use those and do NOT route here for them.',

    'triggers' => [
        '/(?i)\b(?:tax (?:rate|class|settings)|shipping zones?|shipping methods?|payment gateways?)\b/',
        '/(?i)\b(?:set ?up|configure|finish setting ?up)\b[^.\n]{0,40}\b(?:woo ?commerce|store|shop|checkout)\b/',
        '/(?i)\b(?:woo ?commerce|product|store)\b[^.\n]{0,40}\b(?:webhooks?|global attributes?|attribute terms?)\b/',
        '/(?i)\b(?:generate|create|build)\b[^.\n]{0,30}\b(?:all )?variations?\b/',
        '/(?i)\b(?:import|export)\b[^.\n]{0,40}\b(?:products?|orders?|customers?)\b[^.\n]{0,20}\b(?:csv|file|spreadsheet)?\b/',
        '/(?i)\b(?:csv)\b[^.\n]{0,40}\b(?:import|export|products?|catalogu?e)\b/',
        '/(?i)\b(?:bulk)\b[^.\n]{0,30}\b(?:price|prices|pricing|sale)\b/',
        '/(?i)\b(?:cross-?sells?|up-?sells?|grouped product|external product|downloadable files?)\b/',
        '/(?i)\b(?:schedule|clear)\b[^.\n]{0,20}\b(?:a )?sale\b/',
        '/(?i)\b(?:duplicate)\b[^.\n]{0,20}\b(?:product|variable product)\b/',
        '/(?i)\b(?:order|customer|woo ?commerce|store|shop)\b[^.\n]{0,30}\b(?:e-?mails?|notifications?)\b/',
        '/(?i)\b(?:e-?mail|notification)\b[^.\n]{0,30}\b(?:not (?:being )?(?:sent|received)|disabled|switched off|template|subject|heading|test)\b/',
        '/(?i)\b(?:customers?|clients?|buyers?)\b[^.\n]{0,40}\b(?:who spent|lifetime value|ltv|segments?|repeat|lapsed|churn|top spenders?|order count)\b/',
        '/(?i)\b(?:guest (?:orders?|checkout)|link(?:ing)? (?:the )?(?:guest )?orders?)\b/',
        '/(?i)\b(?:revenue|sales|takings|turnover)\b[^.\n]{0,40}\b(?:last (?:week|month|year|\d+ days?)|this (?:week|month|year)|compared|vs\.?|against|year over year)\b/',
        '/(?i)\b(?:store health|how(?:\x27s| is) (?:the|my) (?:shop|store) doing|best ?sell(?:er|ing)|top ?sell(?:er|ing)|refund rate|conversion of coupons?)\b/',
        '/(?i)\b(?:low stock|out of stock|stock ?outs?|running out)\b/',
        '/(?i)\b(?:new customers?|customer acquisition|signups?|sign-?ups?)\b[^.\n]{0,30}\b(?:per|over|by|each)\b[^.\n]{0,20}\b(?:day|week|month|period)\b/',
        '/(?i)\b(?:analytics)\b[^.\n]{0,30}\b(?:stale|behind|lag(?:ging)?|not matching|wrong|out of date)\b/',
    ],
    'commands' => [
        '/wc-store'    => ['description' => 'Store setup: general settings, taxes, shipping zones, payment gateways, webhooks.'],
        '/wc-catalog'  => ['description' => 'Deep catalogue work: attributes, variations, links, downloads, tags, bulk pricing, sales.'],
        '/wc-import'   => ['description' => 'Import a product CSV (dry run first) or export products / orders / customers.'],
        '/wc-emails'   => ['description' => 'The notification emails: what is switched off, what each one says, and a test send.'],
        '/wc-customers' => ['description' => 'The customer base: search by spend and recency, whole profiles, segments, guest-order linking.'],
        '/wc-analytics' => ['description' => 'The figures, each one saying where it came from: revenue against the previous period, refund rate, top sellers, low stock, coupons, new customers, store health.'],
        '/wc-status'   => ['description' => 'Report what the store has configured and what is still missing.'],
    ],
    'mandatory_routing' => [
        'preflight_required' => true,
        'preflight_ability'  => 'nibwp/skill-preflight',
        'pipeline' => [
            [
                'ability'       => 'nibwp/skill-preflight',
                'args_template' => ['skill_id' => 'woocommerce-pro'],
                'why'           => 'Resolve the base country, currency, tax posture and uploads folder once, and mint the _preflight_token the CSV import commit requires.',
            ],
            [
                'ability'       => 'nibwp/load-skill-playbook',
                'args_template' => ['skill_id' => 'woocommerce-pro'],
                'why'           => 'Read authoring/SKILL.md: the action catalogue, the order a real store has to be built in, and the traps (tax rates are rows, variation attributes must be marked used-for-variations, a CSV is dry-run first).',
            ],
            [
                'ability'       => 'nibwp/wcpro-store',
                'args_template' => ['action' => 'store_status'],
                'why'           => 'Read what is already configured before changing anything. Every later step is decided by this answer, and it writes nothing.',
            ],
            [
                'ability'       => 'nibwp/wcpro-store',
                'args_template' => ['action' => '{update_general|create_tax_rate|create_shipping_zone|enable_payment_gateway|create_webhook|…}'],
                'why'           => 'Configure the store in this order: general settings → tax settings → tax classes → tax rates → shipping zones → shipping methods → payment gateways → webhooks. A tax rate assigned to a class that does not exist yet is silently ignored.',
            ],
            [
                'ability'       => 'nibwp/wcpro-catalog',
                'args_template' => ['action' => '{create_attribute|assign_attributes|generate_variations|…}'],
                'why'           => 'Attributes before products, assignment before variations. generate_variations refuses an attribute that is not marked used-for-variations, because such a variation is unbuyable and WooCommerce says nothing about it.',
            ],
            [
                'ability'       => 'nibwp/wcpro-import',
                'args_template' => ['action' => 'validate_csv'],
                'why'           => 'Dry run first. It reports every bad row and writes nothing. An import committed without it is how a catalogue ends up half-applied.',
            ],
            [
                'ability'       => 'nibwp/wcpro-import',
                'args_template' => ['action' => 'import_csv', '_preflight_token' => '{from_preflight.token}'],
                'why'           => 'Commit the import once validate_csv came back clean. Pass limit + offset for a large file and follow next_offset until done.',
            ],
        ],
        'forbidden_actions' => [
            'Reaching for nibwp/execute-php to write tax rates, shipping zones, gateway settings or webhooks — every one of those has an action here',
            'Writing option rows like woocommerce_calc_taxes or woocommerce_default_country directly instead of through wcpro-store update_general / update_tax_settings',
            'Creating a tax rate before the tax class it names exists',
            'Calling generate_variations before assign_attributes marked the attributes used-for-variations',
            'Committing import_csv before validate_csv returned no row problems',
            'Passing an absolute path or a URL where a CSV path is expected — the path is relative to the uploads directory and nothing outside it is readable',
            'Duplicating what the Free abilities already do: use nibwp/wc-create-product, wc-update-product, wc-create-variation, wc-list-orders, wc-create-refund and the rest for single-record work',
            'Quoting a figure from nibwp/wcpro-analytics without the "source" it came with — analytics_tables can lag behind the order book, and a number without its provenance is the one this hub must never be read as producing',
            'Resolving a period yourself and passing dates computed from the server clock: pass period:"last_30_days" and let the hub resolve it in the site timezone, or pass period:"custom" with plain dates',
        ],
        'before_answering' => 'Read the store before you change it: nibwp/wcpro-store { action:"store_status" } costs one call and tells you what is missing. Then work in order — taxes, shipping, payments, webhooks — because the later settings reference the earlier ones by slug and a missing reference fails silently. For anything about one product, one order, one coupon or one customer, the Free wc-* abilities own it; do not route here.',
    ],
    'preflight_questions' => [
        [
            'key'       => 'wcpro_base_country',
            'prompt'    => 'Which country does the store sell from? (ISO code, e.g. NL or US:CA)',
            'detect'    => 'get_option(woocommerce_default_country)',
            'type'      => 'string',
            'required'  => false,
            'cache_key' => 'wcpro_base_country',
        ],
        [
            'key'       => 'wcpro_currency',
            'prompt'    => 'Which currency? (ISO code, e.g. EUR)',
            'detect'    => 'get_option(woocommerce_currency)',
            'type'      => 'string',
            'required'  => false,
            'cache_key' => 'wcpro_currency',
        ],
        [
            'key'       => 'wcpro_prices_include_tax',
            'prompt'    => 'Are the prices you enter inclusive of tax?',
            'detect'    => 'get_option(woocommerce_prices_include_tax)',
            'choices'   => ['yes', 'no'],
            'type'      => 'enum',
            'required'  => false,
            'cache_key' => 'wcpro_prices_include_tax',
        ],
        [
            'key'       => 'wcpro_csv_path',
            'prompt'    => 'Path of the CSV to import, relative to the uploads directory (e.g. imports/products.csv).',
            'type'      => 'string',
            'required'  => false,
            'cache'     => false,
        ],
    ],
];
