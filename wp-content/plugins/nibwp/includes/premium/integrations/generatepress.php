<?php

declare(strict_types=1);

/**
 * GeneratePress integration — NIBWP MCP abilities (Layer 1 adapter).
 *
 * Six domain-grouped abilities give an AI agent full read/write control of a
 * GeneratePress site: theme info/discovery, the main settings (layout, header,
 * nav, structure, branding), colors + Global Colors, typography (Font Manager +
 * dynamic typography), spacing, and — when GP Premium is active — Elements
 * (hooks, blocks, headers, layouts) with display/location conditions.
 *
 * Mechanism: in-process WP options + post meta. GeneratePress stores everything
 * in options (`generate_settings`, `generate_spacing_settings`, …) and, for
 * Premium Elements, the `gp_elements` custom post type. We read defaults from
 * GP's own `generate_get_defaults()` / `generate_spacing_get_defaults()` so the
 * shape always matches the installed version, merge patches non-destructively,
 * and flush GP's dynamic-CSS cache so changes take effect immediately.
 *
 * Detection: the active (or parent) theme is `generatepress`, or
 * `generate_get_defaults()` exists. Premium gates on GENERATE_PREMIUM_VERSION +
 * post_type_exists('gp_elements'). Verified against GeneratePress 3.x option keys.
 */

if (!defined('ABSPATH')) {
    exit();
}

/* ----------------------------------------------------------------------------
 * Shared helpers (file-local)
 * ------------------------------------------------------------------------- */

/** Is GeneratePress the active (or parent) theme? */
function nibwp_gp_available(): bool
{
    if (function_exists('generate_get_defaults')) {
        return true;
    }
    $theme = wp_get_theme();
    return $theme->get_template() === 'generatepress' || $theme->get_stylesheet() === 'generatepress';
}

/** Is GeneratePress Premium active? */
function nibwp_gp_premium(): bool
{
    return defined('GENERATE_PREMIUM_VERSION') || defined('GP_PREMIUM_VERSION') || function_exists('generate_premium_do_elements');
}

/** House WP_Error wrapper. */
function nibwp_gp_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error($code, $message, ['status' => $status]);
}

/** GeneratePress core defaults (shape matches the installed version). */
function nibwp_gp_defaults(): array
{
    return function_exists('generate_get_defaults') ? (array) generate_get_defaults() : [];
}

/** The merged `generate_settings` (saved values over defaults). */
function nibwp_gp_settings(): array
{
    $saved = get_option('generate_settings', []);
    $saved = is_array($saved) ? $saved : [];
    $defaults = nibwp_gp_defaults();
    return $defaults ? wp_parse_args($saved, $defaults) : $saved;
}

/**
 * Recursively sanitize an arbitrary settings value while preserving scalar type
 * (bool/int/float kept; strings → sanitize_text_field; arrays recurse). Keeps
 * hex colors, URLs and font names intact.
 *
 * @param mixed $v
 * @return mixed
 */
function nibwp_gp_sanitize($v)
{
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $val) {
            $out[is_string($k) ? sanitize_text_field($k) : $k] = nibwp_gp_sanitize($val);
        }
        return $out;
    }
    if (is_bool($v) || is_int($v) || is_float($v) || $v === null) {
        return $v;
    }
    return sanitize_text_field((string) $v);
}

/**
 * Merge a patch into an options array and persist. Returns the list of keys
 * that actually changed.
 *
 * @param array<string,mixed> $patch
 * @return string[]
 */
function nibwp_gp_update_option(string $option, array $patch): array
{
    $current = get_option($option, []);
    $current = is_array($current) ? $current : [];
    $changed = [];
    foreach ($patch as $key => $value) {
        $key   = (string) $key;
        $value = nibwp_gp_sanitize($value);
        if (!array_key_exists($key, $current) || $current[$key] !== $value) {
            $changed[] = $key;
        }
        $current[$key] = $value;
    }
    update_option($option, $current);
    nibwp_gp_flush_css();
    return $changed;
}

/** Force GeneratePress to regenerate its dynamic CSS after a settings change. */
function nibwp_gp_flush_css(): void
{
    delete_option('generate_dynamic_css_cached_version');
    delete_option('generate_dynamic_css_output');
    delete_transient('generate_dynamic_css');
    if (function_exists('generate_update_dynamic_css_cache')) {
        generate_update_dynamic_css_cache();
    }
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
}

/** Canonical GeneratePress hook locations available to Hook Elements. */
function nibwp_gp_hooks(): array
{
    return [
        'wp_head', 'wp_body_open', 'wp_footer',
        'generate_before_header', 'generate_inside_header', 'generate_after_header',
        'generate_inside_navigation', 'generate_after_primary_menu', 'generate_menu_bar_items',
        'generate_inside_mobile_menu', 'generate_inside_navigation_drawer',
        'generate_before_main_content', 'generate_before_content', 'generate_after_entry_header',
        'generate_after_content', 'generate_after_main_content',
        'generate_before_entry_title', 'generate_after_entry_title', 'generate_after_entry_content',
        'generate_before_right_sidebar_content', 'generate_after_right_sidebar_content',
        'generate_before_left_sidebar_content', 'generate_after_left_sidebar_content',
        'generate_before_footer', 'generate_inside_footer_widgets',
        'generate_footer', 'generate_after_footer',
        'generate_before_do_template_part', 'generate_after_do_template_part',
    ];
}

/** Filter the settings array to color-related keys (+ keep global_colors handled separately). */
function nibwp_gp_color_keys(array $settings): array
{
    $out = [];
    foreach ($settings as $k => $v) {
        if (is_string($k) && str_contains($k, 'color')) {
            $out[$k] = $v;
        }
    }
    return $out;
}

/* ----------------------------------------------------------------------------
 * 1) generatepress-info — discovery
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/generatepress-info', [
    'label'       => __('GeneratePress — Info', 'nibwp'),
    'description' => __('Detect GeneratePress + GP Premium, version, active modules, Elements support, Global Colors, and the available hook locations.', 'nibwp'),
    'category'    => 'generatepress',
    'input_schema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_gp_info_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function nibwp_gp_info_execute(array $input): array|WP_Error
{
    if (!nibwp_gp_available()) {
        return nibwp_gp_err('gp_inactive', 'GeneratePress is not the active theme on this site.', 404);
    }
    $theme = wp_get_theme();
    $settings = nibwp_gp_settings();
    $global = isset($settings['global_colors']) && is_array($settings['global_colors']) ? $settings['global_colors'] : [];

    $option_groups = [];
    foreach (['generate_settings', 'generate_spacing_settings', 'generate_secondary_nav_settings', 'generate_menu_plus_settings', 'generate_background_settings', 'generate_blog_settings', 'generate_woocommerce_settings', 'generate_copyright'] as $o) {
        if (get_option($o, null) !== null) {
            $option_groups[] = $o;
        }
    }

    $elements_cpt = post_type_exists('gp_elements');
    return [
        'gp_active'        => true,
        'gp_version'       => defined('GENERATE_VERSION') ? GENERATE_VERSION : '',
        'premium_active'   => nibwp_gp_premium(),
        'premium_version'  => defined('GENERATE_PREMIUM_VERSION') ? GENERATE_PREMIUM_VERSION : (defined('GP_PREMIUM_VERSION') ? GP_PREMIUM_VERSION : ''),
        'active_theme'     => $theme->get_stylesheet(),
        'is_child_theme'   => $theme->get_template() === 'generatepress' && $theme->get_stylesheet() !== 'generatepress',
        'option_groups'    => $option_groups,
        'global_colors'    => count($global),
        'use_dynamic_typography' => (bool) ($settings['use_dynamic_typography'] ?? false),
        'elements_supported' => $elements_cpt,
        'elements_count'   => $elements_cpt ? (int) wp_count_posts('gp_elements')->publish + (int) wp_count_posts('gp_elements')->draft : 0,
        'hooks'            => nibwp_gp_hooks(),
        'settings_key_count' => count($settings),
        'abilities'        => ['settings', 'colors', 'typography', 'spacing', 'elements'],
    ];
}

/* ----------------------------------------------------------------------------
 * 2) generatepress-settings — the main generate_settings option
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/generatepress-settings', [
    'label'       => __('GeneratePress — Settings', 'nibwp'),
    'description' => __('Read and write GeneratePress settings (layout, container, header, navigation, sidebars, footer, structure, branding). Actions: get, update, get_defaults, reset.', 'nibwp'),
    'category'    => 'generatepress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['get', 'update', 'get_defaults', 'reset']],
            'keys'     => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Limit get/reset to these setting keys.'],
            'settings' => ['type' => 'object', 'description' => 'For update: a map of setting key → value, merged into generate_settings.'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_gp_settings_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function nibwp_gp_settings_execute(array $input): array|WP_Error
{
    if (!nibwp_gp_available()) {
        return nibwp_gp_err('gp_inactive', 'GeneratePress is not the active theme on this site.', 404);
    }
    $action = (string) ($input['action'] ?? '');
    $keys   = array_map('strval', (array) ($input['keys'] ?? []));

    switch ($action) {
        case 'get':
            $settings = nibwp_gp_settings();
            if ($keys) {
                $settings = array_intersect_key($settings, array_flip($keys));
            }
            return ['settings' => $settings];

        case 'get_defaults':
            return ['defaults' => nibwp_gp_defaults()];

        case 'update':
            $patch = (array) ($input['settings'] ?? []);
            if ($patch === []) {
                return nibwp_gp_err('no_settings', 'Provide a non-empty "settings" object for update.');
            }
            $changed = nibwp_gp_update_option('generate_settings', $patch);
            return ['updated' => $changed, 'count' => count($changed)];

        case 'reset':
            $defaults = nibwp_gp_defaults();
            if ($defaults === []) {
                return nibwp_gp_err('no_defaults', 'GeneratePress defaults are unavailable.');
            }
            $reset = $keys ? array_intersect_key($defaults, array_flip($keys)) : $defaults;
            $changed = nibwp_gp_update_option('generate_settings', $reset);
            return ['reset' => array_keys($reset), 'count' => count($changed)];
    }
    return nibwp_gp_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 3) generatepress-colors — colors + Global Colors palette
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/generatepress-colors', [
    'label'       => __('GeneratePress — Colors', 'nibwp'),
    'description' => __('Read/write GeneratePress colors and the Global Colors palette. Actions: get, update, list_global, set_global, add_global, update_global, remove_global.', 'nibwp'),
    'category'    => 'generatepress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['get', 'update', 'list_global', 'set_global', 'add_global', 'update_global', 'remove_global']],
            'colors' => ['type' => 'object', 'description' => 'For update: map of color setting key → hex/rgba value.'],
            'global' => ['type' => 'array', 'description' => 'For set_global: the full palette [{name,slug,color}].'],
            'name'   => ['type' => 'string'],
            'slug'   => ['type' => 'string'],
            'color'  => ['type' => 'string'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_gp_colors_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function nibwp_gp_colors_execute(array $input): array|WP_Error
{
    if (!nibwp_gp_available()) {
        return nibwp_gp_err('gp_inactive', 'GeneratePress is not the active theme on this site.', 404);
    }
    $action = (string) ($input['action'] ?? '');
    $settings = nibwp_gp_settings();
    $palette = isset($settings['global_colors']) && is_array($settings['global_colors']) ? array_values($settings['global_colors']) : [];

    $save_palette = static function (array $p): array {
        $changed = nibwp_gp_update_option('generate_settings', ['global_colors' => array_values($p)]);
        return ['global_colors' => array_values($p), 'updated' => $changed];
    };

    switch ($action) {
        case 'get':
            return ['colors' => nibwp_gp_color_keys($settings), 'global_colors' => $palette];

        case 'update':
            $colors = (array) ($input['colors'] ?? []);
            if ($colors === []) {
                return nibwp_gp_err('no_colors', 'Provide a non-empty "colors" object.');
            }
            return ['updated' => nibwp_gp_update_option('generate_settings', $colors)];

        case 'list_global':
            return ['global_colors' => $palette];

        case 'set_global':
            return $save_palette(array_map(static fn($c) => [
                'name'  => sanitize_text_field((string) (((array) $c)['name'] ?? '')),
                'slug'  => sanitize_title((string) (((array) $c)['slug'] ?? (((array) $c)['name'] ?? ''))),
                'color' => sanitize_text_field((string) (((array) $c)['color'] ?? '')),
            ], (array) ($input['global'] ?? [])));

        case 'add_global':
            $slug = sanitize_title((string) ($input['slug'] ?? ($input['name'] ?? '')));
            if ($slug === '' || ($input['color'] ?? '') === '') {
                return nibwp_gp_err('bad_color', 'add_global needs name/slug + color.');
            }
            $palette = array_values(array_filter($palette, static fn($c) => (((array) $c)['slug'] ?? '') !== $slug));
            $palette[] = ['name' => sanitize_text_field((string) ($input['name'] ?? $slug)), 'slug' => $slug, 'color' => sanitize_text_field((string) $input['color'])];
            return $save_palette($palette);

        case 'update_global':
            $slug = sanitize_title((string) ($input['slug'] ?? ''));
            $found = false;
            foreach ($palette as &$c) {
                if ((((array) $c)['slug'] ?? '') === $slug) {
                    if (isset($input['color'])) { $c['color'] = sanitize_text_field((string) $input['color']); }
                    if (isset($input['name']))  { $c['name']  = sanitize_text_field((string) $input['name']); }
                    $found = true;
                }
            }
            unset($c);
            if (!$found) {
                return nibwp_gp_err('not_found', 'No global color with slug: ' . $slug);
            }
            return $save_palette($palette);

        case 'remove_global':
            $slug = sanitize_title((string) ($input['slug'] ?? ''));
            $palette = array_values(array_filter($palette, static fn($c) => (((array) $c)['slug'] ?? '') !== $slug));
            return $save_palette($palette);
    }
    return nibwp_gp_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 4) generatepress-typography — Font Manager + dynamic typography
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/generatepress-typography', [
    'label'       => __('GeneratePress — Typography', 'nibwp'),
    'description' => __('Read/write GeneratePress typography: the Font Manager, dynamic typography rules (per selector), and global typography flags. Actions: get, update, add_font, remove_font, set_rule, remove_rule.', 'nibwp'),
    'category'    => 'generatepress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'     => ['type' => 'string', 'enum' => ['get', 'update', 'add_font', 'remove_font', 'set_rule', 'remove_rule']],
            'font'       => ['type' => 'object', 'description' => 'For add_font: a Font Manager entry (fontFamily, googleFont, variants, …).'],
            'font_family'=> ['type' => 'string'],
            'rule'       => ['type' => 'object', 'description' => 'For set_rule: a typography rule keyed by its selector.'],
            'selector'   => ['type' => 'string'],
            'settings'   => ['type' => 'object', 'description' => 'For update: typography-related generate_settings keys (use_dynamic_typography, google_font_display, …).'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_gp_typography_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function nibwp_gp_typography_execute(array $input): array|WP_Error
{
    if (!nibwp_gp_available()) {
        return nibwp_gp_err('gp_inactive', 'GeneratePress is not the active theme on this site.', 404);
    }
    $action = (string) ($input['action'] ?? '');
    $settings = nibwp_gp_settings();
    $font_manager = isset($settings['font_manager']) && is_array($settings['font_manager']) ? array_values($settings['font_manager']) : [];
    $typography   = isset($settings['typography']) && is_array($settings['typography']) ? array_values($settings['typography']) : [];

    switch ($action) {
        case 'get':
            return [
                'font_manager'           => $font_manager,
                'typography'             => $typography,
                'use_dynamic_typography' => (bool) ($settings['use_dynamic_typography'] ?? false),
                'google_font_display'    => (string) ($settings['google_font_display'] ?? ''),
            ];

        case 'update':
            $patch = (array) ($input['settings'] ?? []);
            if ($patch === []) {
                return nibwp_gp_err('no_settings', 'Provide typography "settings" to update.');
            }
            return ['updated' => nibwp_gp_update_option('generate_settings', $patch)];

        case 'add_font':
            $font = (array) ($input['font'] ?? []);
            if (($font['fontFamily'] ?? '') === '') {
                return nibwp_gp_err('bad_font', 'add_font needs a font object with fontFamily.');
            }
            $font_manager = array_values(array_filter($font_manager, static fn($f) => (((array) $f)['fontFamily'] ?? '') !== $font['fontFamily']));
            $font_manager[] = nibwp_gp_sanitize($font);
            return ['updated' => nibwp_gp_update_option('generate_settings', ['font_manager' => $font_manager])];

        case 'remove_font':
            $fam = (string) ($input['font_family'] ?? '');
            $font_manager = array_values(array_filter($font_manager, static fn($f) => (((array) $f)['fontFamily'] ?? '') !== $fam));
            return ['updated' => nibwp_gp_update_option('generate_settings', ['font_manager' => $font_manager])];

        case 'set_rule':
            $rule = nibwp_gp_sanitize((array) ($input['rule'] ?? []));
            $selector = (string) (($rule['selector'] ?? $input['selector']) ?? '');
            if ($selector === '') {
                return nibwp_gp_err('bad_rule', 'set_rule needs a rule with a "selector".');
            }
            $rule['selector'] = $selector;
            $typography = array_values(array_filter($typography, static fn($r) => (((array) $r)['selector'] ?? '') !== $selector));
            $typography[] = $rule;
            return ['updated' => nibwp_gp_update_option('generate_settings', ['typography' => $typography, 'use_dynamic_typography' => true])];

        case 'remove_rule':
            $selector = (string) ($input['selector'] ?? '');
            $typography = array_values(array_filter($typography, static fn($r) => (((array) $r)['selector'] ?? '') !== $selector));
            return ['updated' => nibwp_gp_update_option('generate_settings', ['typography' => $typography])];
    }
    return nibwp_gp_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 5) generatepress-spacing — generate_spacing_settings
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/generatepress-spacing', [
    'label'       => __('GeneratePress — Spacing', 'nibwp'),
    'description' => __('Read/write GeneratePress spacing (padding/margins for header, content, widgets, footer, menus). Actions: get, update, get_defaults, reset.', 'nibwp'),
    'category'    => 'generatepress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['get', 'update', 'get_defaults', 'reset']],
            'spacing' => ['type' => 'object', 'description' => 'For update: map of spacing key → value.'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_gp_spacing_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function nibwp_gp_spacing_execute(array $input): array|WP_Error
{
    if (!nibwp_gp_available()) {
        return nibwp_gp_err('gp_inactive', 'GeneratePress is not the active theme on this site.', 404);
    }
    $action = (string) ($input['action'] ?? '');
    $defaults = function_exists('generate_spacing_get_defaults') ? (array) generate_spacing_get_defaults() : [];
    $saved = get_option('generate_spacing_settings', []);
    $saved = is_array($saved) ? $saved : [];
    $merged = $defaults ? wp_parse_args($saved, $defaults) : $saved;

    switch ($action) {
        case 'get':
            return ['spacing' => $merged];
        case 'get_defaults':
            return ['defaults' => $defaults];
        case 'update':
            $patch = (array) ($input['spacing'] ?? []);
            if ($patch === []) {
                return nibwp_gp_err('no_spacing', 'Provide a non-empty "spacing" object.');
            }
            return ['updated' => nibwp_gp_update_option('generate_spacing_settings', $patch)];
        case 'reset':
            if ($defaults === []) {
                return nibwp_gp_err('no_defaults', 'Spacing defaults unavailable (GP Premium Spacing module may be inactive).');
            }
            return ['reset' => array_keys($defaults), 'updated' => nibwp_gp_update_option('generate_spacing_settings', $defaults)];
    }
    return nibwp_gp_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 6) generatepress-elements — GP Premium Elements (gp_elements CPT)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/generatepress-elements', [
    'label'       => __('GeneratePress — Elements (Premium)', 'nibwp'),
    'description' => __('Manage GeneratePress Premium Elements: hooks, block elements, headers and layouts, with display/location conditions. Actions: list, get, hooks, create_hook, create_block, update, set_conditions, delete.', 'nibwp'),
    'category'    => 'generatepress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'    => ['type' => 'string', 'enum' => ['list', 'get', 'hooks', 'create_hook', 'create_block', 'update', 'set_conditions', 'delete']],
            'id'        => ['type' => 'integer'],
            'title'     => ['type' => 'string'],
            'content'   => ['type' => 'string', 'description' => 'The element body (HTML / block markup / shortcodes).'],
            'hook'      => ['type' => 'string', 'description' => 'Hook location for a hook element (see action=hooks).'],
            'priority'  => ['type' => 'integer', 'default' => 10],
            'status'    => ['type' => 'string', 'enum' => ['publish', 'draft'], 'default' => 'publish'],
            'execute_php' => ['type' => 'boolean', 'default' => false],
            'display'   => ['type' => 'array', 'description' => 'Display/location conditions (array of rule objects).'],
            'exclude'   => ['type' => 'array', 'description' => 'Exclude conditions.'],
            'users'     => ['type' => 'array', 'description' => 'User (role/login) conditions.'],
            'meta'      => ['type' => 'object', 'description' => 'Extra _generate_* meta to set verbatim (overrides). Use get/list to confirm exact keys on this GP Premium version.'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_gp_elements_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false, 'instructions' => 'GP Premium only. Run get/list first to confirm the exact _generate_* meta keys on the installed version; pass corrections via the "meta" param.']],
]);

function nibwp_gp_elements_execute(array $input): array|WP_Error
{
    if (!nibwp_gp_available()) {
        return nibwp_gp_err('gp_inactive', 'GeneratePress is not the active theme on this site.', 404);
    }
    $action = (string) ($input['action'] ?? '');

    if ($action === 'hooks') {
        return ['hooks' => nibwp_gp_hooks()];
    }
    if (!post_type_exists('gp_elements')) {
        return nibwp_gp_err('elements_unavailable', 'GeneratePress Premium Elements are not available — activate GP Premium and the Elements module.', 404);
    }

    // Read all _generate_* meta for an element (self-describing).
    $dump = static function (int $id): array {
        $post = get_post($id);
        if (!$post || $post->post_type !== 'gp_elements') {
            return [];
        }
        $meta = [];
        foreach (get_post_meta($id) as $k => $v) {
            if (str_starts_with((string) $k, '_generate')) {
                $meta[$k] = maybe_unserialize(is_array($v) ? ($v[0] ?? '') : $v);
            }
        }
        return [
            'id'      => $id,
            'title'   => $post->post_title,
            'status'  => $post->post_status,
            'type'    => get_post_meta($id, '_generate_element_type', true),
            'hook'    => get_post_meta($id, '_generate_hook', true),
            'priority'=> get_post_meta($id, '_generate_hook_priority', true),
            'meta'    => $meta,
            'has_content' => $post->post_content !== '',
        ];
    };

    switch ($action) {
        case 'list':
            $items = [];
            foreach (get_posts(['post_type' => 'gp_elements', 'post_status' => ['publish', 'draft'], 'numberposts' => 100]) as $p) {
                $items[] = [
                    'id'     => $p->ID,
                    'title'  => $p->post_title,
                    'status' => $p->post_status,
                    'type'   => get_post_meta($p->ID, '_generate_element_type', true),
                    'hook'   => get_post_meta($p->ID, '_generate_hook', true),
                ];
            }
            return ['elements' => $items, 'count' => count($items)];

        case 'get':
            $el = $dump((int) ($input['id'] ?? 0));
            return $el ?: nibwp_gp_err('not_found', 'Element not found.', 404);

        case 'create_hook':
        case 'create_block':
            $type = $action === 'create_hook' ? 'hook' : 'block';
            $pid = wp_insert_post([
                'post_type'    => 'gp_elements',
                'post_status'  => in_array($input['status'] ?? 'publish', ['publish', 'draft'], true) ? (string) $input['status'] : 'publish',
                'post_title'   => sanitize_text_field((string) ($input['title'] ?? ('GP ' . $type . ' element'))),
                'post_content' => (string) ($input['content'] ?? ''),
            ], true);
            if (is_wp_error($pid)) {
                return $pid;
            }
            update_post_meta($pid, '_generate_element_type', $type);
            if ($type === 'hook') {
                update_post_meta($pid, '_generate_hook', sanitize_text_field((string) ($input['hook'] ?? 'generate_after_header')));
                update_post_meta($pid, '_generate_hook_priority', (int) ($input['priority'] ?? 10));
                update_post_meta($pid, '_generate_hook_execute_php', !empty($input['execute_php']) ? true : false);
            }
            nibwp_gp_apply_conditions($pid, $input);
            foreach ((array) ($input['meta'] ?? []) as $mk => $mv) {
                update_post_meta($pid, sanitize_key((string) $mk), nibwp_gp_sanitize($mv));
            }
            return ['created' => true, 'element' => $dump((int) $pid)];

        case 'update':
            $id = (int) ($input['id'] ?? 0);
            if (get_post_type($id) !== 'gp_elements') {
                return nibwp_gp_err('not_found', 'Element not found.', 404);
            }
            $postarr = ['ID' => $id];
            if (isset($input['title']))   { $postarr['post_title']   = sanitize_text_field((string) $input['title']); }
            if (isset($input['content'])) { $postarr['post_content'] = (string) $input['content']; }
            if (isset($input['status']))  { $postarr['post_status']  = in_array($input['status'], ['publish', 'draft'], true) ? (string) $input['status'] : 'publish'; }
            if (count($postarr) > 1) {
                wp_update_post($postarr);
            }
            if (isset($input['hook']))     { update_post_meta($id, '_generate_hook', sanitize_text_field((string) $input['hook'])); }
            if (isset($input['priority'])) { update_post_meta($id, '_generate_hook_priority', (int) $input['priority']); }
            foreach ((array) ($input['meta'] ?? []) as $mk => $mv) {
                update_post_meta($id, sanitize_key((string) $mk), nibwp_gp_sanitize($mv));
            }
            nibwp_gp_apply_conditions($id, $input);
            return ['updated' => true, 'element' => $dump($id)];

        case 'set_conditions':
            $id = (int) ($input['id'] ?? 0);
            if (get_post_type($id) !== 'gp_elements') {
                return nibwp_gp_err('not_found', 'Element not found.', 404);
            }
            nibwp_gp_apply_conditions($id, $input);
            return ['updated' => true, 'element' => $dump($id)];

        case 'delete':
            $id = (int) ($input['id'] ?? 0);
            if (get_post_type($id) !== 'gp_elements') {
                return nibwp_gp_err('not_found', 'Element not found.', 404);
            }
            wp_delete_post($id, true);
            return ['deleted' => true, 'id' => $id];
    }
    return nibwp_gp_err('bad_action', 'Unknown action: ' . $action);
}

/**
 * Apply display / exclude / user conditions to a GP element. These meta keys are
 * the GP Premium Elements convention; pass corrections via the `meta` param if a
 * future GP version renames them.
 */
function nibwp_gp_apply_conditions(int $id, array $input): void
{
    if (isset($input['display'])) {
        update_post_meta($id, '_generate_element_display_conditions', nibwp_gp_sanitize((array) $input['display']));
    }
    if (isset($input['exclude'])) {
        update_post_meta($id, '_generate_element_exclude_conditions', nibwp_gp_sanitize((array) $input['exclude']));
    }
    if (isset($input['users'])) {
        update_post_meta($id, '_generate_element_user_conditions', nibwp_gp_sanitize((array) $input['users']));
    }
}
