<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * WooCommerce Pro — nibwp/wcpro-store.
 *
 * The store itself, as opposed to the things in it. None of this is reachable
 * through the Free wc-* abilities, and none of it lives where an agent would
 * guess: tax rates are rows in two custom tables, shipping methods are option
 * rows keyed by an instance id, gateway settings are one serialised option per
 * gateway, and webhooks are a CRUD object with a topic that WooCommerce silently
 * blanks when it does not recognise it.
 *
 * One hub with an `action` enum rather than twenty-seven tools. Every action is
 * store configuration, so every action — read or write — asks for
 * manage_woocommerce: a gateway's settings hold API secrets and a webhook's
 * delivery URL is a credential of sorts, so "read" is not a lower bar here.
 */

require_once __DIR__ . '/../lib/guard.php';
require_once __DIR__ . '/../lib/sanitize.php';

/**
 * The action enum. Single source of truth — the registration and the test both
 * read this, and the switch in nibwp_wcpro_store_execute() must match it
 * exactly in both directions.
 *
 * @return array<int, string>
 */
function nibwp_wcpro_store_actions(): array
{
    return [
        // Read-only overview.
        'store_status',
        // General settings.
        'get_general',
        'update_general',
        // Taxes.
        'get_tax_settings',
        'update_tax_settings',
        'list_tax_classes',
        'create_tax_class',
        'delete_tax_class',
        'list_tax_rates',
        'create_tax_rate',
        'update_tax_rate',
        'delete_tax_rate',
        // Shipping.
        'list_shipping_zones',
        'create_shipping_zone',
        'update_shipping_zone',
        'delete_shipping_zone',
        'add_shipping_method',
        'update_shipping_method',
        'remove_shipping_method',
        // Payments.
        'list_payment_gateways',
        'enable_payment_gateway',
        'disable_payment_gateway',
        'configure_payment_gateway',
        // Webhooks.
        'list_webhooks',
        'create_webhook',
        'update_webhook',
        'delete_webhook',
    ];
}

nibwp_wcpro_register_hub(
    'nibwp/wcpro-store',
    __('WooCommerce Pro — store setup and configuration', 'nibwp'),
    'Set up and configure the WooCommerce store itself: general settings (address, currency, selling and shipping locations, coupons, tax toggle), tax settings, tax classes and tax rates, shipping zones and the methods inside them, payment gateways, and webhooks. Start with action:"store_status" — it writes nothing and reports what is configured and what is missing. This is store configuration, not catalogue work: single products, orders, coupons and customers are covered by the Free nibwp/wc-* abilities.',
    nibwp_wcpro_store_actions(),
    [
        'settings' => [
            'type' => 'object',
            'description' => 'For update_general / update_tax_settings: only the keys you want changed. Unmentioned settings are left alone.',
        ],
        'name' => ['type' => 'string', 'description' => 'Tax class name, shipping zone name, or webhook name.'],
        'slug' => ['type' => 'string', 'description' => 'Tax class slug. Defaults to a slug of the name.'],
        'tax_class' => ['type' => 'string', 'description' => 'Tax class slug a rate belongs to. "" or "standard" is the standard class.'],
        'rate_id' => ['type' => 'integer', 'description' => 'Tax rate id, for update_tax_rate / delete_tax_rate.'],
        'rate' => [
            'type' => 'object',
            'description' => 'Tax rate fields: country, state, postcode (array or ; separated), city (array or ; separated), rate (percent, e.g. "21.0000"), name, priority, compound, shipping, order, tax_class.',
        ],
        'zone_id' => ['type' => 'integer', 'description' => 'Shipping zone id. 0 is the "Locations not covered by your other zones" zone.'],
        'zone' => [
            'type' => 'object',
            'description' => 'Shipping zone fields: name, order, locations (array of {code, type} where type is country|state|postcode|continent).',
        ],
        'instance_id' => ['type' => 'integer', 'description' => 'Shipping method instance id inside a zone.'],
        'method_type' => ['type' => 'string', 'description' => 'Shipping method type to add, e.g. flat_rate, free_shipping, local_pickup.'],
        'method_settings' => ['type' => 'object', 'description' => 'Shipping method instance settings, e.g. {title:"Flat rate", cost:"4.95", tax_status:"taxable"}.'],
        'gateway_id' => ['type' => 'string', 'description' => 'Payment gateway id, e.g. bacs, cheque, cod, stripe.'],
        'gateway_settings' => ['type' => 'object', 'description' => 'Gateway settings to write, e.g. {title:"Bank transfer", description:"…"}.'],
        'webhook_id' => ['type' => 'integer', 'description' => 'Webhook id, for update_webhook / delete_webhook.'],
        'webhook' => [
            'type' => 'object',
            'description' => 'Webhook fields: name, topic (e.g. order.created), delivery_url, status (active|paused|disabled), secret, api_version.',
        ],
        'status' => ['type' => 'string', 'description' => 'Filter for list_webhooks: active, paused, disabled, or all.'],
        'per_page' => ['type' => 'integer', 'default' => 20],
        'page' => ['type' => 'integer', 'default' => 1],
        'force' => ['type' => 'boolean', 'default' => false, 'description' => 'Required to delete a tax class that still has rates, or a shipping zone that still has methods.'],
    ],
    'nibwp_wcpro_store_execute',
    implode("\n", [
        'Read before you write: action:"store_status" costs one call and reports what is configured and what is missing.',
        'Order matters, because the later settings reference the earlier ones by slug and a missing reference fails silently:',
        '  1. update_general — base country, currency, selling + shipping locations, coupons, taxes on/off.',
        '  2. update_tax_settings — prices inclusive or exclusive, what tax is based on, how it is displayed.',
        '  3. create_tax_class, then create_tax_rate per class. A rate naming a class that does not exist is stored against the standard class instead, with no warning.',
        '  4. create_shipping_zone, then add_shipping_method per zone. A zone with no method offers nothing at checkout.',
        '  5. enable_payment_gateway, then configure_payment_gateway. A store with no enabled gateway cannot take an order.',
        '  6. create_webhook last, so the first delivery describes a finished store.',
        'Traps: tax rates are ROWS in wc_tax_rates, not a post type — there is no post id to edit. Deleting a tax class deletes its rates too. An unrecognised webhook topic is blanked by WooCommerce rather than refused, so this ability refuses it for you.',
    ]),
);

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_store_execute(array $input): array|WP_Error
{
    $guard = nibwp_wcpro_guard($input, false);
    if (is_wp_error($guard)) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');

    // One bar for the whole hub. There is no object to ask about — this is the
    // store's own configuration — and the reads expose gateway credentials, so
    // reading is not a lower bar than writing here.
    $denied = nibwp_wcpro_require_manage('The store configuration');
    if ($denied !== null) {
        return $denied;
    }

    switch ($action) {
        case 'store_status':
            return nibwp_wcpro_store_status();

        case 'get_general':
            return nibwp_wcpro_ok($action, 'General store settings.', ['result' => nibwp_wcpro_read_general()]);

        case 'update_general':
            return nibwp_wcpro_update_general((array) ($input['settings'] ?? []));

        case 'get_tax_settings':
            return nibwp_wcpro_ok($action, 'Tax settings.', ['result' => nibwp_wcpro_read_tax_settings()]);

        case 'update_tax_settings':
            return nibwp_wcpro_update_tax_settings((array) ($input['settings'] ?? []));

        case 'list_tax_classes':
            return nibwp_wcpro_list_tax_classes();

        case 'create_tax_class':
            return nibwp_wcpro_create_tax_class($input);

        case 'delete_tax_class':
            return nibwp_wcpro_delete_tax_class($input);

        case 'list_tax_rates':
            return nibwp_wcpro_list_tax_rates($input);

        case 'create_tax_rate':
            return nibwp_wcpro_write_tax_rate($input, 0);

        case 'update_tax_rate':
            return nibwp_wcpro_write_tax_rate($input, (int) ($input['rate_id'] ?? 0));

        case 'delete_tax_rate':
            return nibwp_wcpro_delete_tax_rate($input);

        case 'list_shipping_zones':
            return nibwp_wcpro_list_shipping_zones();

        case 'create_shipping_zone':
            return nibwp_wcpro_write_shipping_zone($input, 0);

        case 'update_shipping_zone':
            return nibwp_wcpro_write_shipping_zone($input, (int) ($input['zone_id'] ?? -1));

        case 'delete_shipping_zone':
            return nibwp_wcpro_delete_shipping_zone($input);

        case 'add_shipping_method':
            return nibwp_wcpro_add_shipping_method($input);

        case 'update_shipping_method':
            return nibwp_wcpro_update_shipping_method($input);

        case 'remove_shipping_method':
            return nibwp_wcpro_remove_shipping_method($input);

        case 'list_payment_gateways':
            return nibwp_wcpro_list_payment_gateways();

        case 'enable_payment_gateway':
            return nibwp_wcpro_toggle_payment_gateway($input, true);

        case 'disable_payment_gateway':
            return nibwp_wcpro_toggle_payment_gateway($input, false);

        case 'configure_payment_gateway':
            return nibwp_wcpro_configure_payment_gateway($input);

        case 'list_webhooks':
            return nibwp_wcpro_list_webhooks($input);

        case 'create_webhook':
            return nibwp_wcpro_write_webhook($input, 0);

        case 'update_webhook':
            return nibwp_wcpro_write_webhook($input, (int) ($input['webhook_id'] ?? 0));

        case 'delete_webhook':
            return nibwp_wcpro_delete_webhook($input);
    }

    return nibwp_wcpro_unknown_action($action, nibwp_wcpro_store_actions());
}

/* ------------------------------------------------------------------ general */

/**
 * The general settings this skill reads and writes, mapped to the option rows
 * WooCommerce actually stores them in.
 *
 * @return array<string, string>
 */
function nibwp_wcpro_general_option_map(): array
{
    return [
        'address_1'                 => 'woocommerce_store_address',
        'address_2'                 => 'woocommerce_store_address_2',
        'city'                      => 'woocommerce_store_city',
        'postcode'                  => 'woocommerce_store_postcode',
        'base_country'              => 'woocommerce_default_country',
        'currency'                  => 'woocommerce_currency',
        'currency_position'         => 'woocommerce_currency_pos',
        'thousand_separator'        => 'woocommerce_price_thousand_sep',
        'decimal_separator'         => 'woocommerce_price_decimal_sep',
        'price_decimals'            => 'woocommerce_price_num_decimals',
        'selling_locations'         => 'woocommerce_allowed_countries',
        'selling_countries'         => 'woocommerce_specific_allowed_countries',
        'selling_except_countries'  => 'woocommerce_all_except_countries',
        'shipping_locations'        => 'woocommerce_ship_to_countries',
        'shipping_countries'        => 'woocommerce_specific_ship_to_countries',
        'default_customer_address'  => 'woocommerce_default_customer_address',
        'enable_coupons'            => 'woocommerce_enable_coupons',
        'enable_taxes'              => 'woocommerce_calc_taxes',
    ];
}

/** @return array<string, mixed> */
function nibwp_wcpro_read_general(): array
{
    $out = [];
    foreach (nibwp_wcpro_general_option_map() as $field => $option) {
        $out[$field] = get_option($option, '');
    }

    return $out;
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_update_general(array $settings): array|WP_Error
{
    if ($settings === []) {
        return nibwp_wcpro_err('nothing_to_do', 'Provide a "settings" object with the general settings to change.', 422);
    }

    $map = nibwp_wcpro_general_option_map();
    $unknown = array_diff(array_keys($settings), array_keys($map));
    if ($unknown !== []) {
        return nibwp_wcpro_err(
            'unknown_setting',
            sprintf('Not a general setting: %s. Known settings: %s.', implode(', ', $unknown), implode(', ', array_keys($map))),
            422,
        );
    }

    $values = [];
    foreach ($settings as $field => $raw) {
        $value = match ($field) {
            'base_country' => nibwp_wcpro_country($raw, 'base_country'),
            'currency' => nibwp_wcpro_currency($raw, 'currency'),
            'currency_position' => nibwp_wcpro_enum($raw, ['left', 'right', 'left_space', 'right_space'], 'currency_position'),
            'price_decimals' => (string) max(0, min(6, (int) $raw)),
            'selling_locations' => nibwp_wcpro_enum($raw, ['all', 'all_except', 'specific'], 'selling_locations'),
            'shipping_locations' => nibwp_wcpro_enum($raw, ['', 'all', 'specific', 'disabled'], 'shipping_locations', ''),
            'default_customer_address' => nibwp_wcpro_enum($raw, ['', 'base', 'geolocation', 'geolocation_ajax'], 'default_customer_address', ''),
            'enable_coupons', 'enable_taxes' => nibwp_wcpro_yesno($raw),
            'selling_countries', 'selling_except_countries', 'shipping_countries' => nibwp_wcpro_country_list($raw, (string) $field),
            default => nibwp_wcpro_text($raw, 200),
        };
        if (is_wp_error($value)) {
            return $value;
        }
        $values[$field] = $value;
    }

    $changed = [];
    foreach ($values as $field => $value) {
        $option = $map[$field];
        if (get_option($option, null) === $value) {
            continue;
        }
        update_option($option, $value);
        $changed[$field] = $value;
    }

    $notes = [];
    if (($values['selling_locations'] ?? '') === 'specific' && ($values['selling_countries'] ?? null) === []) {
        $notes[] = 'selling_locations is "specific" but selling_countries is empty — the store currently sells nowhere.';
    }
    if (($values['shipping_locations'] ?? '') === 'specific' && ($values['shipping_countries'] ?? null) === []) {
        $notes[] = 'shipping_locations is "specific" but shipping_countries is empty — checkout will offer no shipping.';
    }
    if (array_key_exists('enable_taxes', $values) && $values['enable_taxes'] === 'yes') {
        $notes[] = 'Taxes are on. Create the tax classes and rates next — an enabled tax system with no rates charges nothing.';
    }

    return nibwp_wcpro_ok(
        'update_general',
        $changed === [] ? 'Every setting already held that value; nothing was written.' : sprintf('Updated %d general setting(s).', count($changed)),
        ['result' => ['changed' => $changed, 'current' => nibwp_wcpro_read_general()], 'next_steps' => $notes],
    );
}

/**
 * A list of countries for the selling/shipping location settings, which
 * WooCommerce stores as an array of codes.
 *
 * @return array<int, string>|WP_Error
 */
function nibwp_wcpro_country_list(mixed $value, string $field): array|WP_Error
{
    $parts = is_array($value) ? $value : array_filter(array_map('trim', explode(',', (string) $value)));
    $out = [];
    foreach ($parts as $part) {
        $code = nibwp_wcpro_country($part, $field);
        if (is_wp_error($code)) {
            return $code;
        }
        $out[$code] = $code;
    }

    return array_values($out);
}

/* --------------------------------------------------------------------- tax */

/** @return array<string, string> */
function nibwp_wcpro_tax_option_map(): array
{
    return [
        'prices_include_tax'   => 'woocommerce_prices_include_tax',
        'tax_based_on'         => 'woocommerce_tax_based_on',
        'shipping_tax_class'   => 'woocommerce_shipping_tax_class',
        'round_at_subtotal'    => 'woocommerce_tax_round_at_subtotal',
        'display_in_shop'      => 'woocommerce_tax_display_shop',
        'display_in_cart'      => 'woocommerce_tax_display_cart',
        'price_display_suffix' => 'woocommerce_price_display_suffix',
        'total_display'        => 'woocommerce_tax_total_display',
    ];
}

/** @return array<string, mixed> */
function nibwp_wcpro_read_tax_settings(): array
{
    $out = ['taxes_enabled' => get_option('woocommerce_calc_taxes', 'no') === 'yes'];
    foreach (nibwp_wcpro_tax_option_map() as $field => $option) {
        $out[$field] = get_option($option, '');
    }

    return $out;
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_update_tax_settings(array $settings): array|WP_Error
{
    if ($settings === []) {
        return nibwp_wcpro_err('nothing_to_do', 'Provide a "settings" object with the tax settings to change.', 422);
    }

    $map = nibwp_wcpro_tax_option_map();
    $unknown = array_diff(array_keys($settings), array_keys($map));
    if ($unknown !== []) {
        return nibwp_wcpro_err(
            'unknown_setting',
            sprintf('Not a tax setting: %s. Known settings: %s.', implode(', ', $unknown), implode(', ', array_keys($map))),
            422,
        );
    }

    $classes = nibwp_wcpro_tax_class_slugs();
    $values = [];
    foreach ($settings as $field => $raw) {
        $value = match ($field) {
            'prices_include_tax' => nibwp_wcpro_yesno($raw),
            'round_at_subtotal' => nibwp_wcpro_yesno($raw),
            'tax_based_on' => nibwp_wcpro_enum($raw, ['shipping', 'billing', 'base'], 'tax_based_on'),
            'display_in_shop', 'display_in_cart' => nibwp_wcpro_enum($raw, ['incl', 'excl'], (string) $field),
            'total_display' => nibwp_wcpro_enum($raw, ['single', 'itemized'], 'total_display'),
            'shipping_tax_class' => nibwp_wcpro_enum($raw, array_merge(['inherit', 'standard', ''], $classes), 'shipping_tax_class', 'inherit'),
            default => nibwp_wcpro_text($raw, 200),
        };
        if (is_wp_error($value)) {
            return $value;
        }
        $values[$field] = $value;
    }

    $changed = [];
    foreach ($values as $field => $value) {
        $option = $map[$field];
        if (get_option($option, null) === $value) {
            continue;
        }
        update_option($option, $value);
        $changed[$field] = $value;
    }

    return nibwp_wcpro_ok(
        'update_tax_settings',
        $changed === [] ? 'Every tax setting already held that value; nothing was written.' : sprintf('Updated %d tax setting(s).', count($changed)),
        ['result' => ['changed' => $changed, 'current' => nibwp_wcpro_read_tax_settings()]],
    );
}

/** @return array<int, string> */
function nibwp_wcpro_tax_class_slugs(): array
{
    if (!class_exists('WC_Tax') || !method_exists('WC_Tax', 'get_tax_class_slugs')) {
        return [];
    }

    return array_map('strval', (array) WC_Tax::get_tax_class_slugs());
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_list_tax_classes(): array|WP_Error
{
    $denied = nibwp_wcpro_require_class('WC_Tax', 'Listing tax classes');
    if ($denied !== null) {
        return $denied;
    }

    if (!method_exists('WC_Tax', 'get_tax_rate_classes')) {
        return nibwp_wcpro_err('wc_api_missing', 'WC_Tax::get_tax_rate_classes() is not available on this site.', 409);
    }

    $items = [['slug' => 'standard', 'name' => 'Standard rate', 'rate_count' => count(nibwp_wcpro_tax_rates_for('')), 'built_in' => true]];
    foreach ((array) WC_Tax::get_tax_rate_classes() as $class) {
        $slug = (string) ($class->slug ?? '');
        $items[] = [
            'slug'       => $slug,
            'name'       => (string) ($class->name ?? $slug),
            'rate_count' => count(nibwp_wcpro_tax_rates_for($slug)),
            'built_in'   => false,
        ];
    }

    return nibwp_wcpro_ok('list_tax_classes', sprintf('%d tax class(es).', count($items)), ['items' => $items, 'count' => count($items)]);
}

/** @return array<int, object> */
function nibwp_wcpro_tax_rates_for(string $class): array
{
    if (!class_exists('WC_Tax') || !method_exists('WC_Tax', 'get_rates_for_tax_class')) {
        return [];
    }

    return array_values((array) WC_Tax::get_rates_for_tax_class($class));
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_create_tax_class(array $input): array|WP_Error
{
    $denied = nibwp_wcpro_require_class('WC_Tax', 'Creating a tax class');
    if ($denied !== null) {
        return $denied;
    }

    if (!method_exists('WC_Tax', 'create_tax_class')) {
        return nibwp_wcpro_err('wc_api_missing', 'WC_Tax::create_tax_class() is not available on this site.', 409);
    }

    $name = nibwp_wcpro_text($input['name'] ?? '', 100);
    if ($name === '') {
        return nibwp_wcpro_err('missing_name', 'Provide a "name" for the tax class, e.g. "Reduced rate".', 422);
    }
    $slug = sanitize_title((string) ($input['slug'] ?? $name));

    $created = WC_Tax::create_tax_class($name, $slug);
    if (is_wp_error($created)) {
        return $created;
    }

    return nibwp_wcpro_ok(
        'create_tax_class',
        sprintf('Tax class "%s" created.', $name),
        [
            'result' => ['name' => $name, 'slug' => (string) ($created['slug'] ?? $slug)],
            'next_steps' => ['Add rates to it with action:"create_tax_rate" and tax_class:"' . (string) ($created['slug'] ?? $slug) . '". A class with no rates charges nothing.'],
        ],
    );
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_delete_tax_class(array $input): array|WP_Error
{
    $denied = nibwp_wcpro_require_class('WC_Tax', 'Deleting a tax class');
    if ($denied !== null) {
        return $denied;
    }

    if (!method_exists('WC_Tax', 'delete_tax_class_by')) {
        return nibwp_wcpro_err('wc_api_missing', 'WC_Tax::delete_tax_class_by() is not available on this site.', 409);
    }

    $slug = sanitize_title((string) ($input['slug'] ?? $input['name'] ?? ''));
    if ($slug === '') {
        return nibwp_wcpro_err('missing_slug', 'Provide the "slug" of the tax class to delete.', 422);
    }
    if ($slug === 'standard') {
        return nibwp_wcpro_err('cannot_delete_standard', 'The standard rate is not a class that can be deleted — it is what a product with no tax class uses.', 409);
    }

    // Deleting a class deletes every rate in it, which is the kind of thing a
    // store owner discovers a week later. Say so, and make it deliberate.
    $rates = nibwp_wcpro_tax_rates_for($slug);
    if ($rates !== [] && !nibwp_wcpro_bool($input['force'] ?? false)) {
        return nibwp_wcpro_err(
            'tax_class_in_use',
            sprintf('Tax class "%s" still has %d rate(s), and deleting the class deletes them. Pass force:true to go ahead.', $slug, count($rates)),
            409,
            ['rate_count' => count($rates)],
        );
    }

    $deleted = WC_Tax::delete_tax_class_by('slug', $slug);
    if (is_wp_error($deleted)) {
        return $deleted;
    }
    if ($deleted !== true) {
        return nibwp_wcpro_err('not_found', sprintf('No tax class with slug "%s".', $slug), 404);
    }

    return nibwp_wcpro_ok('delete_tax_class', sprintf('Tax class "%s" deleted, along with %d rate(s).', $slug, count($rates)), [
        'result' => ['slug' => $slug, 'rates_deleted' => count($rates)],
    ]);
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_list_tax_rates(array $input): array|WP_Error
{
    $denied = nibwp_wcpro_require_class('WC_Tax', 'Listing tax rates');
    if ($denied !== null) {
        return $denied;
    }

    $class = sanitize_title((string) ($input['tax_class'] ?? ''));
    if ($class === 'standard') {
        $class = '';
    }

    $items = [];
    foreach (nibwp_wcpro_tax_rates_for($class) as $rate) {
        $items[] = [
            'rate_id'  => (int) ($rate->tax_rate_id ?? 0),
            'country'  => (string) ($rate->tax_rate_country ?? ''),
            'state'    => (string) ($rate->tax_rate_state ?? ''),
            'postcode' => array_values((array) ($rate->postcode ?? [])),
            'city'     => array_values((array) ($rate->city ?? [])),
            'rate'     => (string) ($rate->tax_rate ?? ''),
            'name'     => (string) ($rate->tax_rate_name ?? ''),
            'priority' => (int) ($rate->tax_rate_priority ?? 1),
            'compound' => (bool) ($rate->tax_rate_compound ?? false),
            'shipping' => (bool) ($rate->tax_rate_shipping ?? true),
            'order'    => (int) ($rate->tax_rate_order ?? 0),
            'tax_class' => (string) ($rate->tax_rate_class ?? ''),
        ];
    }

    return nibwp_wcpro_ok(
        'list_tax_rates',
        sprintf('%d rate(s) in the %s class.', count($items), $class === '' ? 'standard' : $class),
        ['items' => $items, 'count' => count($items)],
    );
}

/**
 * Create or update a tax rate.
 *
 * $rate_id 0 inserts. There is no post id here and no CRUD class: these are rows
 * in wp_woocommerce_tax_rates, with the postcode and city lists in a second
 * table, and WC_Tax's underscore-prefixed statics are the only supported way in.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_write_tax_rate(array $input, int $rate_id): array|WP_Error
{
    $denied = nibwp_wcpro_require_class('WC_Tax', 'Writing a tax rate');
    if ($denied !== null) {
        return $denied;
    }
    foreach (['_insert_tax_rate', '_update_tax_rate', '_update_tax_rate_postcodes', '_update_tax_rate_cities', '_get_tax_rate'] as $method) {
        if (!method_exists('WC_Tax', $method)) {
            return nibwp_wcpro_err('wc_api_missing', sprintf('WC_Tax::%s() is not available on this site.', $method), 409);
        }
    }

    $creating = $rate_id <= 0;
    $rate = (array) ($input['rate'] ?? []);
    if (!$creating) {
        $existing = WC_Tax::_get_tax_rate($rate_id);
        if (!$existing) {
            return nibwp_wcpro_err('not_found', sprintf('No tax rate with id %d.', $rate_id), 404);
        }
    }

    $row = [];

    if ($creating || array_key_exists('country', $rate)) {
        $country = nibwp_wcpro_country($rate['country'] ?? '*', 'rate.country');
        // "*" means every country, and it is a legitimate rate — a single VAT
        // line for a shop that charges the same everywhere.
        if (trim((string) ($rate['country'] ?? '')) === '*') {
            $country = '';
        } elseif (is_wp_error($country)) {
            return $country;
        }
        $parts = explode(':', (string) $country);
        $row['tax_rate_country'] = $parts[0] ?? '';
        if (isset($parts[1]) && !array_key_exists('state', $rate)) {
            $row['tax_rate_state'] = $parts[1];
        }
    }
    if (array_key_exists('state', $rate)) {
        $row['tax_rate_state'] = strtoupper(nibwp_wcpro_text($rate['state'], 20));
    }
    if ($creating || array_key_exists('rate', $rate)) {
        $percent = nibwp_wcpro_rate($rate['rate'] ?? null, 'rate.rate');
        if (is_wp_error($percent)) {
            return $percent;
        }
        $row['tax_rate'] = $percent;
    }
    if ($creating || array_key_exists('name', $rate)) {
        $row['tax_rate_name'] = nibwp_wcpro_text($rate['name'] ?? 'Tax', 200);
    }
    if (array_key_exists('priority', $rate)) {
        $row['tax_rate_priority'] = max(1, (int) $rate['priority']);
    }
    if (array_key_exists('compound', $rate)) {
        $row['tax_rate_compound'] = nibwp_wcpro_bool($rate['compound']) ? 1 : 0;
    }
    if (array_key_exists('shipping', $rate)) {
        $row['tax_rate_shipping'] = nibwp_wcpro_bool($rate['shipping'], true) ? 1 : 0;
    }
    if (array_key_exists('order', $rate)) {
        $row['tax_rate_order'] = max(0, (int) $rate['order']);
    }

    $class_given = $rate['tax_class'] ?? $input['tax_class'] ?? null;
    if ($creating || $class_given !== null) {
        $class = sanitize_title((string) ($class_given ?? ''));
        if ($class === 'standard') {
            $class = '';
        }
        // WC_Tax::format_tax_rate_class() silently rewrites an unknown class to
        // the standard one, so the rate would exist but apply to the wrong
        // products. Refuse instead.
        if ($class !== '' && !in_array($class, nibwp_wcpro_tax_class_slugs(), true)) {
            return nibwp_wcpro_err(
                'unknown_tax_class',
                sprintf('There is no tax class "%s". Create it first with action:"create_tax_class". Existing classes: standard, %s.', $class, implode(', ', nibwp_wcpro_tax_class_slugs()) ?: '(none)'),
                422,
            );
        }
        $row['tax_rate_class'] = $class;
    }

    if ($row === [] && !array_key_exists('postcode', $rate) && !array_key_exists('city', $rate)) {
        return nibwp_wcpro_err('nothing_to_do', 'Provide a "rate" object with at least one field to write.', 422);
    }

    if ($creating) {
        $rate_id = (int) WC_Tax::_insert_tax_rate($row);
        if ($rate_id <= 0) {
            return nibwp_wcpro_err('insert_failed', 'WooCommerce did not return a rate id, so the rate was not stored.', 500);
        }
    } elseif ($row !== []) {
        WC_Tax::_update_tax_rate($rate_id, $row);
    }

    if (array_key_exists('postcode', $rate)) {
        WC_Tax::_update_tax_rate_postcodes($rate_id, nibwp_wcpro_location_list($rate['postcode']));
    }
    if (array_key_exists('city', $rate)) {
        WC_Tax::_update_tax_rate_cities($rate_id, nibwp_wcpro_location_list($rate['city']));
    }

    return nibwp_wcpro_ok(
        $creating ? 'create_tax_rate' : 'update_tax_rate',
        sprintf('Tax rate %d %s.', $rate_id, $creating ? 'created' : 'updated'),
        [
            'result' => ['rate_id' => $rate_id] + $row,
            'next_steps' => get_option('woocommerce_calc_taxes', 'no') === 'yes'
                ? []
                : ['Taxes are switched off store-wide, so this rate will not be charged. Turn them on with action:"update_general", settings:{enable_taxes:true}.'],
        ],
    );
}

/**
 * A postcode or city list, in the shape WC_Tax's location writers want.
 *
 * @return array<int, string>
 */
function nibwp_wcpro_location_list(mixed $value): array
{
    $parts = is_array($value) ? $value : explode(';', (string) $value);
    $out = [];
    foreach ($parts as $part) {
        $clean = nibwp_wcpro_text($part, 40);
        if ($clean !== '') {
            $out[] = $clean;
        }
    }

    return $out;
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_delete_tax_rate(array $input): array|WP_Error
{
    $denied = nibwp_wcpro_require_class('WC_Tax', 'Deleting a tax rate');
    if ($denied !== null) {
        return $denied;
    }

    foreach (['_get_tax_rate', '_delete_tax_rate'] as $method) {
        if (!method_exists('WC_Tax', $method)) {
            return nibwp_wcpro_err('wc_api_missing', sprintf('WC_Tax::%s() is not available on this site.', $method), 409);
        }
    }

    $rate_id = (int) ($input['rate_id'] ?? 0);
    if ($rate_id <= 0) {
        return nibwp_wcpro_err('missing_id', 'Provide the "rate_id" of the tax rate to delete.', 422);
    }
    if (!WC_Tax::_get_tax_rate($rate_id)) {
        return nibwp_wcpro_err('not_found', sprintf('No tax rate with id %d.', $rate_id), 404);
    }

    WC_Tax::_delete_tax_rate($rate_id);

    return nibwp_wcpro_ok('delete_tax_rate', sprintf('Tax rate %d deleted.', $rate_id), ['result' => ['rate_id' => $rate_id]]);
}

/* ---------------------------------------------------------------- shipping */

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_list_shipping_zones(): array|WP_Error
{
    $denied = nibwp_wcpro_require_class('WC_Shipping_Zones', 'Listing shipping zones');
    if ($denied !== null) {
        return $denied;
    }

    $items = [];
    // get_zones() leaves out zone 0 — the catch-all every store has and nobody
    // remembers — so it is read separately and reported alongside the rest.
    foreach ((array) WC_Shipping_Zones::get_zones() as $zone) {
        $items[] = nibwp_wcpro_zone_summary((int) ($zone['zone_id'] ?? $zone['id'] ?? 0));
    }
    $items[] = nibwp_wcpro_zone_summary(0);

    return nibwp_wcpro_ok('list_shipping_zones', sprintf('%d shipping zone(s), including the catch-all zone 0.', count($items)), [
        'items' => array_values(array_filter($items)),
        'count' => count($items),
    ]);
}

/** @return array<string, mixed>|null */
function nibwp_wcpro_zone_summary(int $zone_id): ?array
{
    if (!class_exists('WC_Shipping_Zones')) {
        return null;
    }
    $zone = WC_Shipping_Zones::get_zone($zone_id);
    if (!$zone) {
        return null;
    }

    $methods = [];
    foreach ((array) $zone->get_shipping_methods(false) as $instance_id => $method) {
        $methods[] = [
            'instance_id' => (int) $instance_id,
            'type'        => (string) ($method->id ?? ''),
            'title'       => (string) (method_exists($method, 'get_title') ? $method->get_title() : ''),
            'enabled'     => ($method->enabled ?? 'yes') === 'yes',
            'settings'    => (array) ($method->instance_settings ?? []),
        ];
    }

    $locations = [];
    foreach ((array) $zone->get_zone_locations() as $location) {
        $locations[] = ['code' => (string) ($location->code ?? ''), 'type' => (string) ($location->type ?? '')];
    }

    return [
        'zone_id'   => $zone_id,
        'name'      => (string) $zone->get_zone_name(),
        'order'     => (int) $zone->get_zone_order(),
        'locations' => $locations,
        'methods'   => $methods,
    ];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_write_shipping_zone(array $input, int $zone_id): array|WP_Error
{
    $denied = nibwp_wcpro_require_class('WC_Shipping_Zone', 'Writing a shipping zone');
    if ($denied !== null) {
        return $denied;
    }

    $creating = $zone_id <= 0 && !array_key_exists('zone_id', $input);
    $fields = (array) ($input['zone'] ?? []);
    if ($creating && trim((string) ($fields['name'] ?? $input['name'] ?? '')) === '') {
        return nibwp_wcpro_err('missing_name', 'Provide a "name" for the zone, e.g. "Netherlands" or "Rest of EU".', 422);
    }

    $zone = $creating ? new WC_Shipping_Zone() : WC_Shipping_Zones::get_zone($zone_id);
    if (!$zone) {
        return nibwp_wcpro_err('not_found', sprintf('No shipping zone with id %d.', $zone_id), 404);
    }

    $name = $fields['name'] ?? $input['name'] ?? null;
    if ($name !== null) {
        $zone->set_zone_name(nibwp_wcpro_text($name, 100));
    }
    if (array_key_exists('order', $fields)) {
        $zone->set_zone_order(max(0, (int) $fields['order']));
    }
    if (array_key_exists('locations', $fields)) {
        $locations = nibwp_wcpro_zone_locations($fields['locations'], $zone);
        if (is_wp_error($locations)) {
            return $locations;
        }
        $zone->set_zone_locations($locations);
    }

    $saved_id = (int) $zone->save();
    if ($saved_id <= 0 && $creating) {
        return nibwp_wcpro_err('save_failed', 'WooCommerce did not return a zone id, so the zone was not stored.', 500);
    }
    $saved_id = $saved_id > 0 ? $saved_id : $zone_id;

    return nibwp_wcpro_ok(
        $creating ? 'create_shipping_zone' : 'update_shipping_zone',
        sprintf('Shipping zone %d %s.', $saved_id, $creating ? 'created' : 'updated'),
        [
            'result' => nibwp_wcpro_zone_summary($saved_id),
            'next_steps' => $creating
                ? ['A zone with no method offers nothing at checkout. Add one with action:"add_shipping_method", zone_id:' . $saved_id . ', method_type:"flat_rate".']
                : [],
        ],
    );
}

/**
 * Zone locations, in the {code, type} shape WC_Shipping_Zone stores.
 *
 * @return array<int, array<string, string>>|WP_Error
 */
function nibwp_wcpro_zone_locations(mixed $value, object $zone): array|WP_Error
{
    $out = [];
    foreach ((array) $value as $entry) {
        $entry = (array) $entry;
        $code = nibwp_wcpro_text($entry['code'] ?? '', 40);
        $type = nibwp_wcpro_enum($entry['type'] ?? 'country', ['country', 'state', 'postcode', 'continent'], 'locations[].type', 'country');
        if (is_wp_error($type)) {
            return $type;
        }
        if ($code === '') {
            return nibwp_wcpro_err('invalid_location', 'Every location needs a "code" — a country code, a state as "US:CA", a postcode, or a continent code.', 422);
        }
        if (method_exists($zone, 'is_valid_location_type') && !$zone->is_valid_location_type($type)) {
            return nibwp_wcpro_err('invalid_location', sprintf('WooCommerce does not accept "%s" as a zone location type here.', $type), 422);
        }
        $out[] = ['code' => $type === 'postcode' ? $code : strtoupper($code), 'type' => $type];
    }

    return $out;
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_delete_shipping_zone(array $input): array|WP_Error
{
    $denied = nibwp_wcpro_require_class('WC_Shipping_Zones', 'Deleting a shipping zone');
    if ($denied !== null) {
        return $denied;
    }

    $zone_id = (int) ($input['zone_id'] ?? 0);
    if ($zone_id <= 0) {
        return nibwp_wcpro_err('missing_id', 'Provide the "zone_id" to delete. Zone 0 is the catch-all and cannot be deleted.', 422);
    }
    $summary = nibwp_wcpro_zone_summary($zone_id);
    if ($summary === null) {
        return nibwp_wcpro_err('not_found', sprintf('No shipping zone with id %d.', $zone_id), 404);
    }
    if ($summary['methods'] !== [] && !nibwp_wcpro_bool($input['force'] ?? false)) {
        return nibwp_wcpro_err(
            'zone_in_use',
            sprintf('Zone %d still has %d shipping method(s). Pass force:true to delete the zone and its methods.', $zone_id, count($summary['methods'])),
            409,
        );
    }

    if (!WC_Shipping_Zones::delete_zone($zone_id)) {
        return nibwp_wcpro_err('delete_failed', sprintf('WooCommerce refused to delete zone %d.', $zone_id), 500);
    }

    return nibwp_wcpro_ok('delete_shipping_zone', sprintf('Shipping zone %d deleted.', $zone_id), ['result' => $summary]);
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_add_shipping_method(array $input): array|WP_Error
{
    $denied = nibwp_wcpro_require_class('WC_Shipping_Zones', 'Adding a shipping method');
    if ($denied !== null) {
        return $denied;
    }

    // Zone 0 is legitimate — it is where "everywhere else" gets its rate — so
    // the id is read with array_key_exists rather than truthiness.
    if (!array_key_exists('zone_id', $input)) {
        return nibwp_wcpro_err('missing_id', 'Provide the "zone_id" to add the method to. 0 is the catch-all zone.', 422);
    }
    $zone_id = (int) $input['zone_id'];
    $zone = WC_Shipping_Zones::get_zone($zone_id);
    if (!$zone) {
        return nibwp_wcpro_err('not_found', sprintf('No shipping zone with id %d.', $zone_id), 404);
    }

    $type = sanitize_key((string) ($input['method_type'] ?? ''));
    if ($type === '') {
        return nibwp_wcpro_err('missing_method_type', 'Provide a "method_type", e.g. flat_rate, free_shipping or local_pickup.', 422);
    }
    $available = nibwp_wcpro_shipping_method_types();
    if ($available !== [] && !in_array($type, $available, true)) {
        return nibwp_wcpro_err(
            'unknown_method_type',
            sprintf('"%s" is not a shipping method registered on this site. Available: %s.', $type, implode(', ', $available)),
            422,
        );
    }

    $instance_id = (int) $zone->add_shipping_method($type);
    if ($instance_id <= 0) {
        return nibwp_wcpro_err('add_failed', sprintf('WooCommerce did not add a "%s" method to zone %d.', $type, $zone_id), 500);
    }

    $settings = (array) ($input['method_settings'] ?? []);
    $applied = [];
    if ($settings !== []) {
        $result = nibwp_wcpro_apply_method_settings($instance_id, $settings);
        if (is_wp_error($result)) {
            return $result;
        }
        $applied = $result;
    }

    return nibwp_wcpro_ok(
        'add_shipping_method',
        sprintf('Added "%s" to zone %d as instance %d.', $type, $zone_id, $instance_id),
        ['result' => ['zone_id' => $zone_id, 'instance_id' => $instance_id, 'type' => $type, 'settings' => $applied]],
    );
}

/** @return array<int, string> */
function nibwp_wcpro_shipping_method_types(): array
{
    if (!function_exists('WC') || !method_exists(WC(), 'shipping')) {
        return [];
    }
    $shipping = WC()->shipping();
    if (!is_object($shipping) || !method_exists($shipping, 'get_shipping_methods')) {
        return [];
    }

    return array_values(array_map('strval', array_keys((array) $shipping->get_shipping_methods())));
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_update_shipping_method(array $input): array|WP_Error
{
    $denied = nibwp_wcpro_require_class('WC_Shipping_Zones', 'Updating a shipping method');
    if ($denied !== null) {
        return $denied;
    }

    $instance_id = (int) ($input['instance_id'] ?? 0);
    if ($instance_id <= 0) {
        return nibwp_wcpro_err('missing_id', 'Provide the "instance_id" of the method inside the zone. list_shipping_zones returns it.', 422);
    }
    $settings = (array) ($input['method_settings'] ?? []);
    if ($settings === []) {
        return nibwp_wcpro_err('nothing_to_do', 'Provide a "method_settings" object, e.g. {title:"Flat rate", cost:"4.95"}.', 422);
    }

    $applied = nibwp_wcpro_apply_method_settings($instance_id, $settings);
    if (is_wp_error($applied)) {
        return $applied;
    }

    return nibwp_wcpro_ok(
        'update_shipping_method',
        sprintf('Updated %d setting(s) on shipping method instance %d.', count($applied), $instance_id),
        ['result' => ['instance_id' => $instance_id, 'settings' => $applied]],
    );
}

/**
 * Write instance settings on one shipping method.
 *
 * A method's settings live in their own option row keyed by the instance id, not
 * on the zone, which is why a method can be "in" a zone and still offer nothing:
 * the cost is in the other row.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_apply_method_settings(int $instance_id, array $settings): array|WP_Error
{
    $method = WC_Shipping_Zones::get_shipping_method($instance_id);
    if (!$method) {
        return nibwp_wcpro_err('not_found', sprintf('No shipping method instance %d.', $instance_id), 404);
    }
    if (!method_exists($method, 'init_instance_settings') || !method_exists($method, 'get_instance_option_key')) {
        return nibwp_wcpro_err('wc_api_missing', 'This shipping method does not expose instance settings.', 409);
    }

    $method->init_instance_settings();
    $current = (array) $method->instance_settings;
    $fields = method_exists($method, 'get_instance_form_fields') ? (array) $method->get_instance_form_fields() : [];

    $applied = [];
    foreach ($settings as $key => $value) {
        $key = sanitize_key((string) $key);
        if ($key === '') {
            continue;
        }
        // An unknown key would be written and then ignored, which reads as a
        // successful change that did nothing. Say which keys exist instead.
        if ($fields !== [] && !array_key_exists($key, $fields) && $key !== 'enabled') {
            return nibwp_wcpro_err(
                'unknown_method_setting',
                sprintf('"%s" is not a setting of this method. Its settings are: %s.', $key, implode(', ', array_keys($fields))),
                422,
            );
        }
        $applied[$key] = is_scalar($value) ? (string) $value : $value;
    }

    if ($applied === []) {
        return nibwp_wcpro_err('nothing_to_do', 'No recognised settings to write.', 422);
    }

    update_option($method->get_instance_option_key(), array_merge($current, $applied), 'yes');

    return $applied;
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_remove_shipping_method(array $input): array|WP_Error
{
    $denied = nibwp_wcpro_require_class('WC_Shipping_Zones', 'Removing a shipping method');
    if ($denied !== null) {
        return $denied;
    }

    if (!array_key_exists('zone_id', $input)) {
        return nibwp_wcpro_err('missing_id', 'Provide the "zone_id" the method belongs to. 0 is the catch-all zone.', 422);
    }
    $zone_id = (int) $input['zone_id'];
    $instance_id = (int) ($input['instance_id'] ?? 0);
    if ($instance_id <= 0) {
        return nibwp_wcpro_err('missing_id', 'Provide the "instance_id" of the method to remove.', 422);
    }

    $zone = WC_Shipping_Zones::get_zone($zone_id);
    if (!$zone) {
        return nibwp_wcpro_err('not_found', sprintf('No shipping zone with id %d.', $zone_id), 404);
    }
    if (!method_exists($zone, 'delete_shipping_method')) {
        return nibwp_wcpro_err('wc_api_missing', 'WC_Shipping_Zone::delete_shipping_method() is not available on this site.', 409);
    }
    if (!$zone->delete_shipping_method($instance_id)) {
        return nibwp_wcpro_err('delete_failed', sprintf('Zone %d has no method instance %d.', $zone_id, $instance_id), 404);
    }

    return nibwp_wcpro_ok('remove_shipping_method', sprintf('Removed method instance %d from zone %d.', $instance_id, $zone_id), [
        'result' => nibwp_wcpro_zone_summary($zone_id),
    ]);
}

/* ---------------------------------------------------------------- payments */

/** @return array<string, WC_Payment_Gateway>|WP_Error */
function nibwp_wcpro_gateways(): array|WP_Error
{
    if (!function_exists('WC')) {
        return nibwp_wcpro_err('wc_not_active', 'WooCommerce is not loaded, so its payment gateways cannot be read.', 409);
    }
    $registry = WC()->payment_gateways();
    if (!is_object($registry) || !method_exists($registry, 'payment_gateways')) {
        return nibwp_wcpro_err('wc_api_missing', 'The WooCommerce payment gateway registry is not available on this site.', 409);
    }

    $out = [];
    foreach ((array) $registry->payment_gateways() as $gateway) {
        if (is_object($gateway) && isset($gateway->id)) {
            $out[(string) $gateway->id] = $gateway;
        }
    }

    return $out;
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_list_payment_gateways(): array|WP_Error
{
    $gateways = nibwp_wcpro_gateways();
    if (is_wp_error($gateways)) {
        return $gateways;
    }

    $items = [];
    foreach ($gateways as $id => $gateway) {
        $fields = method_exists($gateway, 'get_form_fields') ? (array) $gateway->get_form_fields() : [];
        $items[] = [
            'id'          => $id,
            'title'       => (string) ($gateway->title ?? ''),
            'method_title' => (string) ($gateway->method_title ?? ''),
            'enabled'     => ($gateway->enabled ?? 'no') === 'yes',
            'order'       => isset($gateway->settings['order']) ? (int) $gateway->settings['order'] : 0,
            // Names only. A gateway's settings hold live API keys, and a tool
            // response is not the place to hand them back out.
            'setting_keys' => array_values(array_diff(array_keys($fields), ['enabled'])),
        ];
    }

    $enabled = array_values(array_filter($items, static fn (array $item): bool => $item['enabled']));

    return nibwp_wcpro_ok(
        'list_payment_gateways',
        sprintf('%d gateway(s) installed, %d enabled.', count($items), count($enabled)),
        [
            'items' => $items,
            'count' => count($items),
            'next_steps' => $enabled === [] ? ['No gateway is enabled, so the store cannot take an order. Enable one with action:"enable_payment_gateway".'] : [],
        ],
    );
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_toggle_payment_gateway(array $input, bool $on): array|WP_Error
{
    $gateways = nibwp_wcpro_gateways();
    if (is_wp_error($gateways)) {
        return $gateways;
    }

    $id = sanitize_key((string) ($input['gateway_id'] ?? ''));
    if ($id === '' || !isset($gateways[$id])) {
        return nibwp_wcpro_err(
            'unknown_gateway',
            sprintf('No payment gateway "%s". Installed: %s.', $id, implode(', ', array_keys($gateways))),
            404,
        );
    }

    $gateway = $gateways[$id];
    if (!method_exists($gateway, 'update_option')) {
        return nibwp_wcpro_err('wc_api_missing', sprintf('Gateway "%s" does not expose a settings writer.', $id), 409);
    }
    $gateway->update_option('enabled', $on ? 'yes' : 'no');

    return nibwp_wcpro_ok(
        $on ? 'enable_payment_gateway' : 'disable_payment_gateway',
        sprintf('Payment gateway "%s" %s.', $id, $on ? 'enabled' : 'disabled'),
        ['result' => ['gateway_id' => $id, 'enabled' => $on]],
    );
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_configure_payment_gateway(array $input): array|WP_Error
{
    $gateways = nibwp_wcpro_gateways();
    if (is_wp_error($gateways)) {
        return $gateways;
    }

    $id = sanitize_key((string) ($input['gateway_id'] ?? ''));
    if ($id === '' || !isset($gateways[$id])) {
        return nibwp_wcpro_err(
            'unknown_gateway',
            sprintf('No payment gateway "%s". Installed: %s.', $id, implode(', ', array_keys($gateways))),
            404,
        );
    }
    $settings = (array) ($input['gateway_settings'] ?? []);
    if ($settings === []) {
        return nibwp_wcpro_err('nothing_to_do', 'Provide a "gateway_settings" object. action:"list_payment_gateways" returns the setting keys each gateway accepts.', 422);
    }

    $gateway = $gateways[$id];
    if (!method_exists($gateway, 'update_option')) {
        return nibwp_wcpro_err('wc_api_missing', sprintf('Gateway "%s" does not expose a settings writer.', $id), 409);
    }
    $fields = method_exists($gateway, 'get_form_fields') ? (array) $gateway->get_form_fields() : [];

    $written = [];
    foreach ($settings as $key => $value) {
        $key = (string) $key;
        if ($fields !== [] && !array_key_exists($key, $fields)) {
            return nibwp_wcpro_err(
                'unknown_gateway_setting',
                sprintf('"%s" is not a setting of gateway "%s". Its settings are: %s.', $key, $id, implode(', ', array_keys($fields))),
                422,
            );
        }
        $gateway->update_option($key, is_scalar($value) ? (string) $value : $value);
        $written[] = $key;
    }

    return nibwp_wcpro_ok(
        'configure_payment_gateway',
        sprintf('Wrote %d setting(s) on gateway "%s".', count($written), $id),
        // Keys, never values: some of these are API secrets.
        ['result' => ['gateway_id' => $id, 'settings_written' => $written]],
    );
}

/* ---------------------------------------------------------------- webhooks */

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_list_webhooks(array $input): array|WP_Error
{
    $denied = nibwp_wcpro_require_class('WC_Webhook', 'Listing webhooks');
    if ($denied !== null) {
        return $denied;
    }
    if (!class_exists('WC_Data_Store')) {
        return nibwp_wcpro_err('wc_api_missing', 'The WooCommerce data store layer is not available on this site.', 409);
    }

    $status = nibwp_wcpro_enum($input['status'] ?? 'all', ['all', 'active', 'paused', 'disabled'], 'status', 'all');
    if (is_wp_error($status)) {
        return $status;
    }
    $page = nibwp_wcpro_paginate($input, 20, 100);

    $store = WC_Data_Store::load('webhook');
    if (!method_exists($store, 'search_webhooks')) {
        return nibwp_wcpro_err('wc_api_missing', 'The webhook data store cannot be searched on this site.', 409);
    }
    $ids = (array) $store->search_webhooks([
        'limit'  => $page['per_page'],
        'offset' => $page['offset'],
        'status' => $status === 'all' ? '' : $status,
    ]);

    $items = [];
    foreach ($ids as $id) {
        $webhook = new WC_Webhook((int) $id);
        if ($webhook->get_id() <= 0) {
            continue;
        }
        $items[] = nibwp_wcpro_webhook_summary($webhook);
    }

    return nibwp_wcpro_ok('list_webhooks', sprintf('%d webhook(s).', count($items)), [
        'items' => $items,
        'count' => count($items),
    ]);
}

/** @return array<string, mixed> */
function nibwp_wcpro_webhook_summary(WC_Webhook $webhook): array
{
    return [
        'webhook_id'    => $webhook->get_id(),
        'name'          => (string) $webhook->get_name(),
        'status'        => (string) $webhook->get_status(),
        'topic'         => (string) $webhook->get_topic(),
        'delivery_url'  => (string) $webhook->get_delivery_url(),
        'api_version'   => (string) $webhook->get_api_version(),
        'failure_count' => (int) $webhook->get_failure_count(),
        // The secret signs the payload. It is a credential, so its presence is
        // reported and its value is not.
        'has_secret'    => trim((string) $webhook->get_secret()) !== '',
    ];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_write_webhook(array $input, int $webhook_id): array|WP_Error
{
    $denied = nibwp_wcpro_require_class('WC_Webhook', 'Writing a webhook');
    if ($denied !== null) {
        return $denied;
    }

    $creating = $webhook_id <= 0;
    $fields = (array) ($input['webhook'] ?? []);
    $webhook = new WC_Webhook($creating ? 0 : $webhook_id);
    if (!$creating && $webhook->get_id() <= 0) {
        return nibwp_wcpro_err('not_found', sprintf('No webhook with id %d.', $webhook_id), 404);
    }

    if ($creating && (trim((string) ($fields['topic'] ?? '')) === '' || trim((string) ($fields['delivery_url'] ?? '')) === '')) {
        return nibwp_wcpro_err('missing_fields', 'A new webhook needs at least a "topic" (e.g. order.created) and a "delivery_url".', 422);
    }

    if (array_key_exists('topic', $fields)) {
        $topic = strtolower(nibwp_wcpro_text($fields['topic'], 100));
        // WC_Webhook::set_topic() blanks an unrecognised topic instead of
        // complaining, and a webhook with no topic never fires. Refuse here so
        // the caller learns about it now rather than from silence.
        if (function_exists('wc_is_webhook_valid_topic') && !wc_is_webhook_valid_topic($topic)) {
            return nibwp_wcpro_err(
                'invalid_topic',
                sprintf('"%s" is not a webhook topic WooCommerce recognises. Use resource.event (coupon/customer/order/product . created|updated|deleted|restored) or action.<hook_name>.', $topic),
                422,
            );
        }
        $webhook->set_topic($topic);
    }
    if (array_key_exists('delivery_url', $fields)) {
        $url = nibwp_wcpro_url($fields['delivery_url'], 'webhook.delivery_url');
        if (is_wp_error($url)) {
            return $url;
        }
        $webhook->set_delivery_url($url);
    }
    if (array_key_exists('name', $fields)) {
        $webhook->set_name(nibwp_wcpro_text($fields['name'], 200));
    }
    if (array_key_exists('secret', $fields)) {
        $webhook->set_secret(nibwp_wcpro_text($fields['secret'], 200));
    }
    if (array_key_exists('api_version', $fields)) {
        $webhook->set_api_version(nibwp_wcpro_text($fields['api_version'], 20));
    }
    if (array_key_exists('status', $fields)) {
        $status = nibwp_wcpro_enum($fields['status'], ['active', 'paused', 'disabled'], 'webhook.status');
        if (is_wp_error($status)) {
            return $status;
        }
        $webhook->set_status($status);
    }
    if ($creating) {
        // Attribute the webhook to whoever created it, which is what the
        // deliveries are made as.
        $webhook->set_user_id(get_current_user_id());
        if (trim((string) $webhook->get_name()) === '') {
            $webhook->set_name(sprintf('Webhook: %s', (string) $webhook->get_topic()));
        }
    }

    $saved_id = (int) $webhook->save();
    if ($saved_id <= 0) {
        return nibwp_wcpro_err('save_failed', 'WooCommerce did not return a webhook id, so it was not stored.', 500);
    }

    return nibwp_wcpro_ok(
        $creating ? 'create_webhook' : 'update_webhook',
        sprintf('Webhook %d %s.', $saved_id, $creating ? 'created' : 'updated'),
        ['result' => nibwp_wcpro_webhook_summary(new WC_Webhook($saved_id))],
    );
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_delete_webhook(array $input): array|WP_Error
{
    $denied = nibwp_wcpro_require_class('WC_Webhook', 'Deleting a webhook');
    if ($denied !== null) {
        return $denied;
    }

    $webhook_id = (int) ($input['webhook_id'] ?? 0);
    if ($webhook_id <= 0) {
        return nibwp_wcpro_err('missing_id', 'Provide the "webhook_id" to delete.', 422);
    }
    $webhook = new WC_Webhook($webhook_id);
    if ($webhook->get_id() <= 0) {
        return nibwp_wcpro_err('not_found', sprintf('No webhook with id %d.', $webhook_id), 404);
    }
    $summary = nibwp_wcpro_webhook_summary($webhook);
    $webhook->delete(true);

    return nibwp_wcpro_ok('delete_webhook', sprintf('Webhook %d deleted.', $webhook_id), ['result' => $summary]);
}

/* ------------------------------------------------------------------ status */

/**
 * What is configured, and what is missing.
 *
 * The first call of any store build, and the only one that is worth making
 * before asking the user anything: half the questions answer themselves here.
 *
 * @return array<string, mixed>
 */
function nibwp_wcpro_store_status(): array
{
    $general = nibwp_wcpro_read_general();
    $taxes_on = get_option('woocommerce_calc_taxes', 'no') === 'yes';

    $zones = [];
    $methods = 0;
    if (class_exists('WC_Shipping_Zones')) {
        foreach ((array) WC_Shipping_Zones::get_zones() as $zone) {
            $summary = nibwp_wcpro_zone_summary((int) ($zone['zone_id'] ?? $zone['id'] ?? 0));
            if ($summary !== null) {
                $zones[] = $summary;
                $methods += count($summary['methods']);
            }
        }
        $catch_all = nibwp_wcpro_zone_summary(0);
        if ($catch_all !== null) {
            $methods += count($catch_all['methods']);
        }
    }

    $gateways = nibwp_wcpro_gateways();
    $enabled_gateways = [];
    if (!is_wp_error($gateways)) {
        foreach ($gateways as $id => $gateway) {
            if (($gateway->enabled ?? 'no') === 'yes') {
                $enabled_gateways[] = (string) $id;
            }
        }
    }

    $rate_count = count(nibwp_wcpro_tax_rates_for(''));
    foreach (nibwp_wcpro_tax_class_slugs() as $slug) {
        $rate_count += count(nibwp_wcpro_tax_rates_for($slug));
    }

    $products = wp_count_posts('product');
    $published = is_object($products) ? (int) ($products->publish ?? 0) : 0;

    $missing = [];
    if (trim((string) $general['base_country']) === '') {
        $missing[] = 'No base country — set it with update_general { base_country }. Tax and shipping both match against it.';
    }
    if (trim((string) $general['currency']) === '') {
        $missing[] = 'No currency — set it with update_general { currency }.';
    }
    if ($taxes_on && $rate_count === 0) {
        $missing[] = 'Taxes are on but there are no rates, so nothing is charged. Add one with create_tax_rate.';
    }
    if ($zones === [] && $methods === 0) {
        $missing[] = 'No shipping zone has a method, so checkout offers no shipping. create_shipping_zone then add_shipping_method.';
    }
    if ($enabled_gateways === []) {
        $missing[] = 'No payment gateway is enabled, so no order can be paid for. enable_payment_gateway { gateway_id }.';
    }
    if ($published === 0) {
        $missing[] = 'No published products. Create them with the Free nibwp/wc-create-product ability, or import a CSV with nibwp/wcpro-import.';
    }

    return nibwp_wcpro_ok(
        'store_status',
        $missing === []
            ? 'The store has an address, a currency, tax rates where needed, shipping and a payment gateway.'
            : sprintf('%d thing(s) still missing before this store can take an order.', count($missing)),
        [
            'result' => [
                'address' => [
                    'address_1' => (string) $general['address_1'],
                    'city'      => (string) $general['city'],
                    'postcode'  => (string) $general['postcode'],
                    'country'   => (string) $general['base_country'],
                ],
                'currency'           => (string) $general['currency'],
                'selling_locations'  => (string) $general['selling_locations'],
                'shipping_locations' => (string) $general['shipping_locations'],
                'coupons_enabled'    => $general['enable_coupons'] === 'yes',
                'taxes_enabled'      => $taxes_on,
                'tax_classes'        => array_merge(['standard'], nibwp_wcpro_tax_class_slugs()),
                'tax_rate_count'     => $rate_count,
                'shipping_zones'     => count($zones),
                'shipping_methods'   => $methods,
                'gateways_enabled'   => $enabled_gateways,
                'products_published' => $published,
            ],
            'next_steps' => $missing,
        ],
    );
}
