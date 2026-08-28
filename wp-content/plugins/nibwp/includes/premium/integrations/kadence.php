<?php

declare(strict_types=1);

/**
 * Kadence integration — NIBWP MCP abilities (Layer 1 adapter).
 *
 * Six domain-grouped abilities give an AI agent full read/write control of a
 * Kadence site: theme info/discovery, the main theme settings (layout, header
 * rows, footer, structure, branding), the Global Color Palette, typography
 * (base/heading/per-tag fonts), Kadence Blocks global settings + colors, and —
 * when Kadence Pro is active — Elements (hooked custom content) with display
 * conditions.
 *
 * Mechanism: in-process WP options + theme_mods + post meta. The Kadence theme
 * stores its Customizer settings in theme_mods (option `theme_mods_kadence`) and
 * exposes them through `kadence()->option($key)`, which merges saved values over
 * the theme's defaults() array; the global palette lives in the JSON option
 * `kadence_global_palette`; Kadence Blocks keeps its own `kadence_blocks_*`
 * options; Pro Elements use the `kadence_element` custom post type.
 *
 * We read effective values through `kadence()->option()` when the theme helper
 * is loaded and fall back to `get_theme_mod()` (or the `option`-type store when
 * the `kadence_theme_option_type` filter switches it) otherwise, so reads and
 * writes are authoritative regardless of request context. Verified against
 * Kadence 1.x option keys + the `kadence_theme_option_type => theme_mod` default.
 */

if (!defined('ABSPATH')) {
    exit();
}

/* ----------------------------------------------------------------------------
 * Shared helpers (file-local)
 * ------------------------------------------------------------------------- */

/** Is the Kadence theme the active (or parent) theme? */
function nibwp_kadence_available(): bool
{
    if (class_exists('Kadence\\Theme') || function_exists('kadence')) {
        return true;
    }
    $theme = wp_get_theme();
    return $theme->get_template() === 'kadence' || $theme->get_stylesheet() === 'kadence';
}

/** Is the Kadence Blocks plugin active? */
function nibwp_kadence_blocks_active(): bool
{
    return defined('KADENCE_BLOCKS_VERSION') || class_exists('Kadence_Blocks_Frontend') || function_exists('kadence_blocks_init');
}

/** Is Kadence Pro (theme add-on) or Kadence Blocks Pro active? */
function nibwp_kadence_pro(): bool
{
    return defined('KADENCE_PRO_VERSION') || defined('KTP_VERSION') || defined('KADENCE_BLOCKS_PRO_VERSION')
        || function_exists('kadence_theme_pro') || class_exists('Kadence_Pro\\Theme') || class_exists('KadenceWP\\KadenceBlocksPro\\Plugin');
}

/** House WP_Error wrapper. */
function nibwp_kadence_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error($code, $message, ['status' => $status]);
}

/** Where Kadence stores settings: 'theme_mod' (default) or 'option'. */
function nibwp_kadence_option_type(): string
{
    return (string) apply_filters('kadence_theme_option_type', 'theme_mod');
}

/** The option name used when Kadence is in 'option' storage mode. */
function nibwp_kadence_option_name(): string
{
    return (string) apply_filters('kadence_theme_option_name', 'kadence');
}

/**
 * Read an effective Kadence setting. Prefers the theme helper (merges defaults)
 * when loaded; otherwise reads the raw saved value from the active store.
 *
 * @param mixed $default
 * @return mixed
 */
function nibwp_kadence_get(string $key, $default = null)
{
    if (function_exists('kadence')) {
        $v = kadence()->option($key);
        if ($v !== '' && $v !== null) {
            return $v;
        }
    }
    if (nibwp_kadence_option_type() === 'option') {
        $o = get_option(nibwp_kadence_option_name(), []);
        $o = is_array($o) ? $o : [];
        return array_key_exists($key, $o) ? $o[$key] : $default;
    }
    return get_theme_mod($key, $default);
}

/** Persist a single Kadence setting to the active store, then flush CSS caches. */
function nibwp_kadence_set(string $key, $value): void
{
    $value = nibwp_kadence_sanitize($value);
    if (nibwp_kadence_option_type() === 'option') {
        $o = get_option(nibwp_kadence_option_name(), []);
        $o = is_array($o) ? $o : [];
        $o[$key] = $value;
        update_option(nibwp_kadence_option_name(), $o);
    } else {
        set_theme_mod($key, $value);
    }
}

/** The raw saved theme settings (customized values only — defaults not merged). */
function nibwp_kadence_saved(): array
{
    if (nibwp_kadence_option_type() === 'option') {
        $o = get_option(nibwp_kadence_option_name(), []);
        return is_array($o) ? $o : [];
    }
    $mods = get_option('theme_mods_' . get_option('stylesheet'), []);
    if (!is_array($mods)) {
        $mods = [];
    }
    // Drop core theme_mod plumbing that isn't a Kadence setting.
    unset($mods['nav_menu_locations'], $mods['custom_css_post_id'], $mods['sidebars_widgets']);
    return $mods;
}

/**
 * Recursively sanitize an arbitrary settings value while preserving scalar type
 * (bool/int/float kept; strings → sanitize_text_field; arrays recurse). Keeps
 * hex colors, font families, units and responsive size shapes intact.
 *
 * @param mixed $v
 * @return mixed
 */
function nibwp_kadence_sanitize($v)
{
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $val) {
            $out[is_string($k) ? sanitize_text_field($k) : $k] = nibwp_kadence_sanitize($val);
        }
        return $out;
    }
    if (is_bool($v) || is_int($v) || is_float($v) || $v === null) {
        return $v;
    }
    return sanitize_text_field((string) $v);
}

/**
 * Merge a patch of setting keys into the active store. Returns the list of keys
 * that actually changed.
 *
 * @param array<string,mixed> $patch
 * @return string[]
 */
function nibwp_kadence_update_settings(array $patch): array
{
    $changed = [];
    foreach ($patch as $key => $value) {
        $key   = (string) $key;
        $value = nibwp_kadence_sanitize($value);
        $current = nibwp_kadence_get($key, null);
        if ($current !== $value) {
            $changed[] = $key;
        }
        nibwp_kadence_set($key, $value);
    }
    nibwp_kadence_flush_css();
    return $changed;
}

/** Best-effort flush of Kadence's cached dynamic CSS after a settings change. */
function nibwp_kadence_flush_css(): void
{
    // Theme inline CSS is regenerated per request; Blocks + some caches persist.
    foreach (['kadence_dynamic_css', 'kadence_customizer_css', 'kadence_blocks_css'] as $o) {
        delete_option($o);
        delete_transient($o);
    }
    do_action('kadence_clear_dynamic_css_cache');
    if (function_exists('kadence_blocks_delete_cache')) {
        kadence_blocks_delete_cache();
    }
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
}

/** Decode the Kadence global color palette option into its array shape. */
function nibwp_kadence_palette(): array
{
    $raw = get_option('kadence_global_palette', '');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return is_array($raw) ? $raw : [];
}

/** Persist the Kadence global palette array back to its JSON option. */
function nibwp_kadence_save_palette(array $palette): void
{
    update_option('kadence_global_palette', wp_json_encode($palette));
    nibwp_kadence_flush_css();
}

/** Canonical Kadence theme hook locations available to Pro Elements. */
function nibwp_kadence_hooks(): array
{
    return [
        'wp_head', 'wp_body_open', 'wp_footer',
        'kadence_before_wrapper', 'kadence_after_wrapper',
        'kadence_before_header', 'kadence_header', 'kadence_after_header',
        'kadence_top_header', 'kadence_main_header', 'kadence_bottom_header',
        'kadence_before_main_content', 'kadence_after_main_content',
        'kadence_before_content', 'kadence_after_content',
        'kadence_single_before_entry_content', 'kadence_single_after_entry_content',
        'kadence_single_before_inner_content', 'kadence_single_after_inner_content',
        'kadence_archive_before_entry_content', 'kadence_archive_after_entry_content',
        'kadence_before_sidebar', 'kadence_after_sidebar',
        'kadence_before_comments', 'kadence_after_comments',
        'kadence_before_footer', 'kadence_footer', 'kadence_after_footer',
        'kadence_footer_top', 'kadence_footer_middle', 'kadence_footer_bottom',
    ];
}

/** Common typography setting keys (Kadence stores each as its own theme_mod). */
function nibwp_kadence_font_keys(): array
{
    return [
        'base_font', 'heading_font',
        'h1_font', 'h2_font', 'h3_font', 'h4_font', 'h5_font', 'h6_font',
        'title_font', 'post_title_font', 'archive_title_font',
        'load_base_font', 'load_heading_font', 'load_italics',
        'buttons_typography', 'breadcrumb_typography',
        'sidebar_widget_title_font', 'footer_widget_title_font',
    ];
}

/** The Kadence Blocks plugin option keys this integration manages. */
function nibwp_kadence_blocks_options(): array
{
    return [
        'kadence_blocks_settings',          // general plugin settings
        'kadence_blocks_colors',            // Blocks global color palette
        'kadence_blocks_config_blocks',     // per-block default config
        'kadence_blocks_settings_blocks',   // block enable/disable
        'kadence_blocks_font_settings',     // typography defaults
        'kadence_blocks_global',            // global styling
        'kt_blocks_editor_width',           // editor width
    ];
}

/** The first registered Kadence Elements-style CPT (Pro), or '' if none. */
function nibwp_kadence_elements_cpt(): string
{
    foreach (['kadence_element', 'kadence_custom_block', 'kadence_template'] as $cpt) {
        if (post_type_exists($cpt)) {
            return $cpt;
        }
    }
    return '';
}

/* ----------------------------------------------------------------------------
 * 1) kadence-info — discovery
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/kadence-info', [
    'label'       => __('Kadence — Info', 'nibwp'),
    'description' => __('Detect the Kadence theme + Kadence Blocks + Kadence Pro, versions, the settings storage mode, the global color palette size, Elements support, and the available hook locations.', 'nibwp'),
    'category'    => 'kadence',
    'input_schema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_kadence_info_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function nibwp_kadence_info_execute(array $input): array|WP_Error
{
    if (!nibwp_kadence_available()) {
        return nibwp_kadence_err('kadence_inactive', 'The Kadence theme is not active on this site.', 404);
    }
    $theme   = wp_get_theme();
    $palette = nibwp_kadence_palette();
    $colors  = isset($palette['palette']) && is_array($palette['palette']) ? $palette['palette'] : [];
    $cpt     = nibwp_kadence_elements_cpt();

    $blocks_options = [];
    foreach (nibwp_kadence_blocks_options() as $o) {
        if (get_option($o, null) !== null) {
            $blocks_options[] = $o;
        }
    }

    return [
        'kadence_active'   => true,
        'kadence_version'  => defined('KADENCE_VERSION') ? KADENCE_VERSION : $theme->get('Version'),
        'blocks_active'    => nibwp_kadence_blocks_active(),
        'blocks_version'   => defined('KADENCE_BLOCKS_VERSION') ? KADENCE_BLOCKS_VERSION : '',
        'pro_active'       => nibwp_kadence_pro(),
        'pro_version'      => defined('KADENCE_PRO_VERSION') ? KADENCE_PRO_VERSION : (defined('KADENCE_BLOCKS_PRO_VERSION') ? KADENCE_BLOCKS_PRO_VERSION : ''),
        'active_theme'     => $theme->get_stylesheet(),
        'is_child_theme'   => $theme->get_template() === 'kadence' && $theme->get_stylesheet() !== 'kadence',
        'theme_helper_loaded' => function_exists('kadence'),
        'option_store'     => nibwp_kadence_option_type(),
        'option_name'      => nibwp_kadence_option_type() === 'option' ? nibwp_kadence_option_name() : 'theme_mods_' . $theme->get_stylesheet(),
        'palette_active'   => isset($palette['active']) ? $palette['active'] : '',
        'palette_size'     => count($colors),
        'blocks_options'   => $blocks_options,
        'elements_supported' => $cpt !== '',
        'elements_cpt'     => $cpt,
        'elements_count'   => $cpt !== '' ? ((int) wp_count_posts($cpt)->publish + (int) wp_count_posts($cpt)->draft) : 0,
        'hooks'            => nibwp_kadence_hooks(),
        'saved_settings_count' => count(nibwp_kadence_saved()),
        'abilities'        => ['settings', 'colors', 'typography', 'blocks', 'elements'],
    ];
}

/* ----------------------------------------------------------------------------
 * 2) kadence-settings — the main theme settings (theme_mods)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/kadence-settings', [
    'label'       => __('Kadence — Settings', 'nibwp'),
    'description' => __('Read and write Kadence theme settings (layout, content width, header rows, navigation, sidebars, footer, structure, branding). Each setting is a Kadence option key. Actions: get, update, list_saved.', 'nibwp'),
    'category'    => 'kadence',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['get', 'update', 'list_saved']],
            'keys'     => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'For get: the Kadence option keys to read (e.g. content_width, header_main_layout, site_background).'],
            'settings' => ['type' => 'object', 'description' => 'For update: a map of Kadence option key → value (responsive shapes like {size,unit} are preserved).'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_kadence_settings_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false, 'instructions' => 'Kadence has no single "get all" — pass explicit "keys" to get, or use list_saved to see which keys are currently customized. Effective values merge the theme defaults only when the kadence() helper is loaded.']],
]);

function nibwp_kadence_settings_execute(array $input): array|WP_Error
{
    if (!nibwp_kadence_available()) {
        return nibwp_kadence_err('kadence_inactive', 'The Kadence theme is not active on this site.', 404);
    }
    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'list_saved':
            return ['saved' => nibwp_kadence_saved(), 'store' => nibwp_kadence_option_type()];

        case 'get':
            $keys = array_map('strval', (array) ($input['keys'] ?? []));
            if ($keys === []) {
                return ['settings' => nibwp_kadence_saved(), 'note' => 'No keys given — returning currently-customized settings. Pass "keys" to read specific effective values.'];
            }
            $out = [];
            foreach ($keys as $k) {
                $out[$k] = nibwp_kadence_get($k, null);
            }
            return ['settings' => $out];

        case 'update':
            $patch = (array) ($input['settings'] ?? []);
            if ($patch === []) {
                return nibwp_kadence_err('no_settings', 'Provide a non-empty "settings" object for update.');
            }
            $changed = nibwp_kadence_update_settings($patch);
            return ['updated' => $changed, 'count' => count($changed)];
    }
    return nibwp_kadence_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 3) kadence-colors — the global color palette
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/kadence-colors', [
    'label'       => __('Kadence — Colors', 'nibwp'),
    'description' => __('Read/write the Kadence Global Color Palette (the 9 palette swatches surfaced everywhere in the theme + Blocks) and arbitrary color setting keys. Actions: get, list_palette, set_palette, set_color, update.', 'nibwp'),
    'category'    => 'kadence',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['get', 'list_palette', 'set_palette', 'set_color', 'update']],
            'palette' => ['type' => 'array', 'description' => 'For set_palette: the full swatch list [{color,slug,name}].'],
            'slug'    => ['type' => 'string', 'description' => 'For set_color: the swatch slug, e.g. palette1..palette9.'],
            'color'   => ['type' => 'string', 'description' => 'For set_color: the hex/rgba value.'],
            'name'    => ['type' => 'string'],
            'colors'  => ['type' => 'object', 'description' => 'For update: map of color setting key → value.'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_kadence_colors_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function nibwp_kadence_colors_execute(array $input): array|WP_Error
{
    if (!nibwp_kadence_available()) {
        return nibwp_kadence_err('kadence_inactive', 'The Kadence theme is not active on this site.', 404);
    }
    $action  = (string) ($input['action'] ?? '');
    $palette = nibwp_kadence_palette();
    $swatches = isset($palette['palette']) && is_array($palette['palette']) ? array_values($palette['palette']) : [];

    switch ($action) {
        case 'get':
        case 'list_palette':
            return ['active' => $palette['active'] ?? '', 'palette' => $swatches, 'count' => count($swatches)];

        case 'set_palette':
            $new = array_map(static fn($c) => [
                'color' => sanitize_text_field((string) (((array) $c)['color'] ?? '')),
                'slug'  => sanitize_title((string) (((array) $c)['slug'] ?? '')),
                'name'  => sanitize_text_field((string) (((array) $c)['name'] ?? '')),
            ], (array) ($input['palette'] ?? []));
            if ($new === []) {
                return nibwp_kadence_err('no_palette', 'Provide a non-empty "palette" array.');
            }
            $palette['palette'] = $new;
            nibwp_kadence_save_palette($palette);
            return ['palette' => $new, 'count' => count($new)];

        case 'set_color':
            $slug = sanitize_title((string) ($input['slug'] ?? ''));
            if ($slug === '' || ($input['color'] ?? '') === '') {
                return nibwp_kadence_err('bad_color', 'set_color needs "slug" (e.g. palette1) + "color".');
            }
            $found = false;
            foreach ($swatches as &$c) {
                if ((((array) $c)['slug'] ?? '') === $slug) {
                    $c['color'] = sanitize_text_field((string) $input['color']);
                    if (isset($input['name'])) { $c['name'] = sanitize_text_field((string) $input['name']); }
                    $found = true;
                }
            }
            unset($c);
            if (!$found) {
                $swatches[] = ['color' => sanitize_text_field((string) $input['color']), 'slug' => $slug, 'name' => sanitize_text_field((string) ($input['name'] ?? $slug))];
            }
            $palette['palette'] = array_values($swatches);
            nibwp_kadence_save_palette($palette);
            return ['palette' => $palette['palette'], 'updated_slug' => $slug, 'added' => !$found];

        case 'update':
            $colors = (array) ($input['colors'] ?? []);
            if ($colors === []) {
                return nibwp_kadence_err('no_colors', 'Provide a non-empty "colors" object.');
            }
            return ['updated' => nibwp_kadence_update_settings($colors)];
    }
    return nibwp_kadence_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 4) kadence-typography — base / heading / per-tag fonts
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/kadence-typography', [
    'label'       => __('Kadence — Typography', 'nibwp'),
    'description' => __('Read/write Kadence typography: the base body font, heading font, per-tag fonts (h1–h6), buttons and widget-title fonts. Each font is a Kadence option holding family/variant/size/lineHeight/etc. Actions: get, update, set_font, font_keys.', 'nibwp'),
    'category'    => 'kadence',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['get', 'update', 'set_font', 'font_keys']],
            'keys'     => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'For get: which font keys to read (default: all common font keys).'],
            'key'      => ['type' => 'string', 'description' => 'For set_font: the font option key, e.g. base_font or h2_font.'],
            'font'     => ['type' => 'object', 'description' => 'For set_font: the font object (family, variant, size:{size,unit}, lineHeight, letterSpacing, transform, weight, color).'],
            'settings' => ['type' => 'object', 'description' => 'For update: map of typography option key → value.'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_kadence_typography_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function nibwp_kadence_typography_execute(array $input): array|WP_Error
{
    if (!nibwp_kadence_available()) {
        return nibwp_kadence_err('kadence_inactive', 'The Kadence theme is not active on this site.', 404);
    }
    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'font_keys':
            return ['font_keys' => nibwp_kadence_font_keys()];

        case 'get':
            $keys = array_map('strval', (array) ($input['keys'] ?? []));
            if ($keys === []) {
                $keys = nibwp_kadence_font_keys();
            }
            $out = [];
            foreach ($keys as $k) {
                $out[$k] = nibwp_kadence_get($k, null);
            }
            return ['typography' => $out];

        case 'set_font':
            $key = (string) ($input['key'] ?? '');
            $font = (array) ($input['font'] ?? []);
            if ($key === '' || $font === []) {
                return nibwp_kadence_err('bad_font', 'set_font needs a "key" (e.g. base_font) and a "font" object.');
            }
            return ['updated' => nibwp_kadence_update_settings([$key => $font])];

        case 'update':
            $patch = (array) ($input['settings'] ?? []);
            if ($patch === []) {
                return nibwp_kadence_err('no_settings', 'Provide typography "settings" to update.');
            }
            return ['updated' => nibwp_kadence_update_settings($patch)];
    }
    return nibwp_kadence_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 5) kadence-blocks — Kadence Blocks global settings + colors
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/kadence-blocks', [
    'label'       => __('Kadence — Blocks', 'nibwp'),
    'description' => __('Read/write the Kadence Blocks plugin global settings: the Blocks color palette, per-block default config, enabled blocks, default typography and editor width. Actions: get, update, get_option, set_option.', 'nibwp'),
    'category'    => 'kadence',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['get', 'update', 'get_option', 'set_option']],
            'option' => ['type' => 'string', 'description' => 'For get_option/set_option: a kadence_blocks_* option name.'],
            'value'  => ['description' => 'For set_option: the value (object/array/string) to store.'],
            'settings' => ['type' => 'object', 'description' => 'For update: a map of kadence_blocks_* option name → value.'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_kadence_blocks_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false, 'instructions' => 'Only the kadence_blocks_* option names returned by action=get are writable. Pass values as plain objects/arrays — the ability stores them in the exact shape Kadence expects (a compact JSON string for sanitize_text_field-registered options, native otherwise) and decodes them on read. Always get first, edit, then write the same shape.']],
]);

function nibwp_kadence_blocks_execute(array $input): array|WP_Error
{
    if (!nibwp_kadence_blocks_active()) {
        return nibwp_kadence_err('blocks_inactive', 'Kadence Blocks is not active on this site.', 404);
    }
    $action  = (string) ($input['action'] ?? '');
    $allowed = nibwp_kadence_blocks_options();

    switch ($action) {
        case 'get':
            $out = [];
            foreach ($allowed as $o) {
                $out[$o] = nibwp_kadence_blocks_decode(get_option($o, null));
            }
            return ['blocks' => $out, 'options' => $allowed];

        case 'get_option':
            $o = (string) ($input['option'] ?? '');
            if (!in_array($o, $allowed, true)) {
                return nibwp_kadence_err('bad_option', 'Unknown Kadence Blocks option. Allowed: ' . implode(', ', $allowed));
            }
            $raw = get_option($o, null);
            return ['option' => $o, 'value' => nibwp_kadence_blocks_decode($raw), 'raw' => $raw];

        case 'set_option':
            $o = (string) ($input['option'] ?? '');
            if (!in_array($o, $allowed, true)) {
                return nibwp_kadence_err('bad_option', 'Unknown Kadence Blocks option. Allowed: ' . implode(', ', $allowed));
            }
            if (!array_key_exists('value', $input)) {
                return nibwp_kadence_err('no_value', 'Provide a "value" for set_option.');
            }
            $mode = nibwp_kadence_blocks_store($o, $input['value']);
            nibwp_kadence_flush_css();
            return ['option' => $o, 'stored_as' => $mode, 'value' => nibwp_kadence_blocks_decode(get_option($o, null))];

        case 'update':
            $patch = (array) ($input['settings'] ?? []);
            if ($patch === []) {
                return nibwp_kadence_err('no_settings', 'Provide a non-empty "settings" object.');
            }
            $changed = [];
            foreach ($patch as $o => $v) {
                if (!in_array((string) $o, $allowed, true)) {
                    continue;
                }
                nibwp_kadence_blocks_store((string) $o, $v);
                $changed[] = $o;
            }
            nibwp_kadence_flush_css();
            return ['updated' => $changed, 'skipped' => array_values(array_diff(array_keys($patch), $changed))];
    }
    return nibwp_kadence_err('bad_action', 'Unknown action: ' . $action);
}

/**
 * Store a Kadence Blocks option in the shape Kadence actually keeps it. Options
 * registered with sanitize_text_field (the common case) must hold a compact JSON
 * string — a raw array would be blanked on save; non-registered options keep the
 * native value. Returns the storage mode used ('json'|'native').
 *
 * @param mixed $value
 */
function nibwp_kadence_blocks_store(string $option, $value): string
{
    $registered  = isset($GLOBALS['wp_registered_settings'][$option]);
    $current      = get_option($option, null);
    $keeps_string = $registered || (is_string($current) && $current !== '');
    if ($keeps_string) {
        update_option($option, is_array($value) ? (string) wp_json_encode($value) : (is_scalar($value) ? (string) $value : ''));
        return 'json';
    }
    update_option($option, nibwp_kadence_sanitize($value));
    return 'native';
}

/**
 * Decode a stored Kadence Blocks option for presentation: JSON strings become
 * arrays; everything else passes through unchanged.
 *
 * @param mixed $value
 * @return mixed
 */
function nibwp_kadence_blocks_decode($value)
{
    if (is_string($value) && $value !== '' && ($value[0] === '{' || $value[0] === '[')) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $value;
}

/* ----------------------------------------------------------------------------
 * 6) kadence-elements — Kadence Pro Elements (kadence_element CPT)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/kadence-elements', [
    'label'       => __('Kadence — Elements (Pro)', 'nibwp'),
    'description' => __('Manage Kadence Pro Elements: custom hooked content placed at theme hook locations, with display conditions. Actions: list, get, hooks, create, update, set_conditions, delete.', 'nibwp'),
    'category'    => 'kadence',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['list', 'get', 'hooks', 'create', 'update', 'set_conditions', 'delete']],
            'id'       => ['type' => 'integer'],
            'title'    => ['type' => 'string'],
            'content'  => ['type' => 'string', 'description' => 'The element body (block markup / HTML / shortcodes).'],
            'hook'     => ['type' => 'string', 'description' => 'Hook location to place the element (see action=hooks).'],
            'priority' => ['type' => 'integer', 'default' => 10],
            'status'   => ['type' => 'string', 'enum' => ['publish', 'draft'], 'default' => 'publish'],
            'conditions' => ['type' => 'array', 'description' => 'Display conditions (array of rule objects).'],
            'meta'     => ['type' => 'object', 'description' => 'Extra _kad_* meta to set verbatim (overrides). Run get/list first to confirm the exact meta keys on the installed Kadence Pro version.'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_kadence_elements_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false, 'instructions' => 'Kadence Pro only. Element placement/condition meta keys vary by Pro version — run get/list first to read the actual _kad_* keys, then pass corrections through the "meta" param.']],
]);

function nibwp_kadence_elements_execute(array $input): array|WP_Error
{
    if (!nibwp_kadence_available()) {
        return nibwp_kadence_err('kadence_inactive', 'The Kadence theme is not active on this site.', 404);
    }
    $action = (string) ($input['action'] ?? '');

    if ($action === 'hooks') {
        return ['hooks' => nibwp_kadence_hooks()];
    }

    $cpt = nibwp_kadence_elements_cpt();
    if ($cpt === '') {
        return nibwp_kadence_err('elements_unavailable', 'Kadence Pro Elements are not available — activate Kadence Pro (the Elements / Hooked Elements feature).', 404);
    }

    // Read all _kad* meta for an element (self-describing).
    $dump = static function (int $id) use ($cpt): array {
        $post = get_post($id);
        if (!$post || $post->post_type !== $cpt) {
            return [];
        }
        $meta = [];
        foreach (get_post_meta($id) as $k => $v) {
            if (str_starts_with((string) $k, '_kad')) {
                $meta[$k] = maybe_unserialize(is_array($v) ? ($v[0] ?? '') : $v);
            }
        }
        return [
            'id'          => $id,
            'title'       => $post->post_title,
            'status'      => $post->post_status,
            'meta'        => $meta,
            'has_content' => $post->post_content !== '',
        ];
    };

    switch ($action) {
        case 'list':
            $items = [];
            foreach (get_posts(['post_type' => $cpt, 'post_status' => ['publish', 'draft'], 'numberposts' => 100]) as $p) {
                $items[] = [
                    'id'     => $p->ID,
                    'title'  => $p->post_title,
                    'status' => $p->post_status,
                    'hook'   => get_post_meta($p->ID, '_kad_element_hook', true) ?: get_post_meta($p->ID, '_kad_post_layout_in_content', true),
                ];
            }
            return ['elements' => $items, 'count' => count($items), 'cpt' => $cpt];

        case 'get':
            $el = $dump((int) ($input['id'] ?? 0));
            return $el ?: nibwp_kadence_err('not_found', 'Element not found.', 404);

        case 'create':
            $pid = wp_insert_post([
                'post_type'    => $cpt,
                'post_status'  => in_array($input['status'] ?? 'publish', ['publish', 'draft'], true) ? (string) $input['status'] : 'publish',
                'post_title'   => sanitize_text_field((string) ($input['title'] ?? 'Kadence element')),
                'post_content' => (string) ($input['content'] ?? ''),
            ], true);
            if (is_wp_error($pid)) {
                return $pid;
            }
            if (isset($input['hook'])) {
                update_post_meta($pid, '_kad_element_hook', sanitize_text_field((string) $input['hook']));
                update_post_meta($pid, '_kad_element_priority', (int) ($input['priority'] ?? 10));
            }
            nibwp_kadence_apply_conditions((int) $pid, $input);
            foreach ((array) ($input['meta'] ?? []) as $mk => $mv) {
                update_post_meta($pid, sanitize_key((string) $mk), nibwp_kadence_sanitize($mv));
            }
            return ['created' => true, 'element' => $dump((int) $pid)];

        case 'update':
            $id = (int) ($input['id'] ?? 0);
            if (get_post_type($id) !== $cpt) {
                return nibwp_kadence_err('not_found', 'Element not found.', 404);
            }
            $postarr = ['ID' => $id];
            if (isset($input['title']))   { $postarr['post_title']   = sanitize_text_field((string) $input['title']); }
            if (isset($input['content'])) { $postarr['post_content'] = (string) $input['content']; }
            if (isset($input['status']))  { $postarr['post_status']  = in_array($input['status'], ['publish', 'draft'], true) ? (string) $input['status'] : 'publish'; }
            if (count($postarr) > 1) {
                wp_update_post($postarr);
            }
            if (isset($input['hook']))     { update_post_meta($id, '_kad_element_hook', sanitize_text_field((string) $input['hook'])); }
            if (isset($input['priority'])) { update_post_meta($id, '_kad_element_priority', (int) $input['priority']); }
            foreach ((array) ($input['meta'] ?? []) as $mk => $mv) {
                update_post_meta($id, sanitize_key((string) $mk), nibwp_kadence_sanitize($mv));
            }
            nibwp_kadence_apply_conditions($id, $input);
            return ['updated' => true, 'element' => $dump($id)];

        case 'set_conditions':
            $id = (int) ($input['id'] ?? 0);
            if (get_post_type($id) !== $cpt) {
                return nibwp_kadence_err('not_found', 'Element not found.', 404);
            }
            nibwp_kadence_apply_conditions($id, $input);
            return ['updated' => true, 'element' => $dump($id)];

        case 'delete':
            $id = (int) ($input['id'] ?? 0);
            if (get_post_type($id) !== $cpt) {
                return nibwp_kadence_err('not_found', 'Element not found.', 404);
            }
            wp_delete_post($id, true);
            return ['deleted' => true, 'id' => $id];
    }
    return nibwp_kadence_err('bad_action', 'Unknown action: ' . $action);
}

/**
 * Apply display conditions to a Kadence element. The meta key is the Kadence Pro
 * Elements convention; pass corrections via the `meta` param if a future Pro
 * version renames it.
 */
function nibwp_kadence_apply_conditions(int $id, array $input): void
{
    if (isset($input['conditions'])) {
        update_post_meta($id, '_kad_element_target_rules', nibwp_kadence_sanitize((array) $input['conditions']));
    }
}
