<?php

declare(strict_types=1);

/**
 * Divi integration — NIBWP MCP abilities (Layer 1 adapter).
 *
 * Eight domain-grouped abilities give an AI agent broad read/write control of a
 * Divi site (Divi/Extra theme OR the standalone Divi Builder plugin): discovery,
 * per-post builder content, the ePanel/theme + builder options, the Global Color
 * palette, the Divi Library (saved layouts), the Theme Builder (header/body/
 * footer layouts + templates), global module Presets, and the Role Editor.
 *
 * Mechanism: in-process, calling Divi's OWN option layer and helpers rather than
 * raw get_option — Divi routes most settings through `et_get_option()` /
 * `et_update_option()` (which read/write either the theme-options array or a
 * standalone option), keeps the Role Editor in `et_pb_role_settings`, global
 * colors in `et_global_colors`, global presets via ET_Builder_Element /
 * ET_Builder_Global_Presets_Settings, and per-post builder state in the
 * `_et_pb_use_builder` / `_et_pb_built_for_post_type` meta.
 *
 * Content format: Divi 4 stores the layout as `[et_pb_*]` shortcodes in
 * post_content; Divi 5 ("D5") uses a new serialized element tree. These
 * abilities are format-agnostic — they read the raw content + detect the format,
 * and write whatever content the agent supplies (slashed to survive WP's
 * escaping) — so they work on both engines. Grounded against Divi Builder
 * 4.27.4 source; D5 paths gate on the runtime version.
 *
 * Detection: ET_BUILDER_VERSION / ET_BUILDER_PLUGIN_VERSION, or the active theme
 * being Divi/Extra. Verified option keys: et_pb_builder_options,
 * et_pb_role_settings, et_global_colors, builder_global_presets_ng, the
 * et_pb_layout / et_header_layout / et_body_layout / et_footer_layout / project
 * post types.
 */

if (!defined('ABSPATH')) {
    exit();
}

/* ----------------------------------------------------------------------------
 * Shared helpers (file-local)
 * ------------------------------------------------------------------------- */

/** Is Divi (theme or Builder plugin) active? */
function nibwp_divi_available(): bool
{
    if (defined('ET_BUILDER_VERSION') || defined('ET_BUILDER_PLUGIN_VERSION') || function_exists('et_setup_theme')) {
        return true;
    }
    $theme = wp_get_theme();
    foreach ([$theme->get('Name'), $theme->get_template()] as $name) {
        if (in_array(strtolower((string) $name), ['divi', 'extra'], true)) {
            return true;
        }
    }
    return false;
}

/** Is the standalone Divi Builder plugin (vs the Divi/Extra theme) active? */
function nibwp_divi_is_plugin(): bool
{
    return defined('ET_BUILDER_PLUGIN_VERSION');
}

/** The running builder version string. */
function nibwp_divi_version(): string
{
    if (defined('ET_BUILDER_VERSION')) {
        return (string) ET_BUILDER_VERSION;
    }
    if (defined('ET_BUILDER_PLUGIN_VERSION')) {
        return (string) ET_BUILDER_PLUGIN_VERSION;
    }
    return (string) wp_get_theme()->get('Version');
}

/** Content engine: 'D5' on Divi 5+, else 'D4' (shortcode). */
function nibwp_divi_engine(): string
{
    if (function_exists('et_builder_d5_enabled') && et_builder_d5_enabled()) {
        return 'D5';
    }
    $v = nibwp_divi_version();
    return ($v !== '' && version_compare($v, '5.0', '>=')) ? 'D5' : 'D4';
}

/** House WP_Error wrapper. */
function nibwp_divi_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error($code, $message, ['status' => $status]);
}

/**
 * Read a Divi option through its own layer (handles theme-array vs standalone).
 *
 * @param mixed $default
 * @return mixed
 */
function nibwp_divi_opt_get(string $key, $default = '')
{
    if (function_exists('et_get_option')) {
        return et_get_option($key, $default);
    }
    return get_option($key, $default);
}

/** Write a Divi option through its own layer. */
function nibwp_divi_opt_set(string $key, $value): void
{
    if (function_exists('et_update_option')) {
        et_update_option($key, $value);
    } else {
        update_option($key, $value);
    }
}

/**
 * Recursively sanitize a meta/settings value, preserving scalar type. NOT used
 * for builder content (which is stored raw + slashed).
 *
 * @param mixed $v
 * @return mixed
 */
function nibwp_divi_sanitize($v)
{
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $val) {
            $out[is_string($k) ? sanitize_text_field($k) : $k] = nibwp_divi_sanitize($val);
        }
        return $out;
    }
    if (is_bool($v) || is_int($v) || is_float($v) || $v === null) {
        return $v;
    }
    return sanitize_text_field((string) $v);
}

/** Detect the builder content format of a raw content string. */
function nibwp_divi_content_format(string $content): string
{
    if ($content === '') {
        return 'empty';
    }
    if (strpos($content, '[et_pb_') !== false) {
        return 'shortcode';
    }
    $trimmed = ltrim($content);
    if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
        return 'd5_serialized';
    }
    return 'unknown';
}

/** The Divi post types this integration touches that actually exist. */
function nibwp_divi_post_types(): array
{
    $candidates = [
        'library'      => 'et_pb_layout',
        'tb_header'    => 'et_header_layout',
        'tb_body'      => 'et_body_layout',
        'tb_footer'    => 'et_footer_layout',
        'tb_template'  => 'et_template',
        'project'      => 'project',
    ];
    $out = [];
    foreach ($candidates as $label => $pt) {
        if (post_type_exists($pt)) {
            $out[$label] = $pt;
        }
    }
    return $out;
}

/** Per-post builder meta keys Divi reads. */
function nibwp_divi_layout_meta_keys(): array
{
    return [
        '_et_pb_use_builder', '_et_pb_built_for_post_type', '_et_pb_old_content',
        '_et_pb_page_layout', '_et_pb_side_nav', '_et_pb_post_hide_nav',
        '_et_pb_show_title', '_et_pb_post_type_layout', '_et_pb_first_image',
        '_et_pb_truncate_post', '_et_pb_truncate_post_date', '_et_pb_gutter_width',
        '_et_pb_light_text_color', '_et_pb_dark_text_color',
    ];
}

/* ----------------------------------------------------------------------------
 * 1) divi-info — discovery
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/divi-info', [
    'label'       => __('Divi — Info', 'nibwp'),
    'description' => __('Detect Divi (theme or Builder plugin) + Extra, the builder version, the content engine (Divi 4 shortcode vs Divi 5 serialized), Theme Builder / Projects / Role Editor support, available post types, and counts of layouts, theme-builder templates and builder-enabled posts.', 'nibwp'),
    'category'    => 'divi',
    'input_schema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_divi_info_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function nibwp_divi_info_execute(array $input): array|WP_Error
{
    if (!nibwp_divi_available()) {
        return nibwp_divi_err('divi_inactive', 'Divi is not active on this site (no Divi/Extra theme and no Divi Builder plugin).', 404);
    }
    $theme   = wp_get_theme();
    $pts     = nibwp_divi_post_types();
    $colors  = nibwp_divi_opt_get('et_global_colors', []);
    $colors  = is_array($colors) ? $colors : (is_string($colors) ? (json_decode($colors, true) ?: []) : []);

    $counts = [];
    foreach ($pts as $label => $pt) {
        $c = wp_count_posts($pt);
        $counts[$label] = (int) ($c->publish ?? 0) + (int) ($c->draft ?? 0);
    }

    $presets = [];
    if (class_exists('ET_Builder_Element') && method_exists('ET_Builder_Element', 'get_global_presets')) {
        $presets = (array) ET_Builder_Element::get_global_presets();
    }

    return [
        'divi_active'     => true,
        'distribution'    => nibwp_divi_is_plugin() ? 'divi-builder-plugin' : 'divi-theme',
        'theme_name'      => (string) $theme->get('Name'),
        'builder_version' => nibwp_divi_version(),
        'core_version'    => defined('ET_CORE_VERSION') ? ET_CORE_VERSION : '',
        'content_engine'  => nibwp_divi_engine(),
        'active_theme'    => $theme->get_stylesheet(),
        'theme_builder_supported' => function_exists('et_theme_builder_get_theme_builder_post_id') || post_type_exists('et_template'),
        'role_editor_supported'   => function_exists('et_pb_get_role_settings'),
        'projects_supported'      => post_type_exists('project'),
        'post_types'      => $pts,
        'counts'          => $counts,
        'global_colors'   => is_array($colors) ? count($colors) : 0,
        'global_presets_modules' => isset($presets['module']) && is_array($presets['module']) ? count($presets['module']) : count($presets),
        'abilities'       => ['content', 'theme-options', 'colors', 'library', 'theme-builder', 'presets', 'roles'],
    ];
}

/* ----------------------------------------------------------------------------
 * 2) divi-content — per-post builder content
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/divi-content', [
    'label'       => __('Divi — Content', 'nibwp'),
    'description' => __('Read/write the Divi builder content of a post or page. Actions: get, set_content, enable_builder, disable_builder, get_meta, set_meta, duplicate. Content is stored raw in the detected format (Divi 4 shortcodes or Divi 5 serialized) — supply content in the format that matches content_engine from divi-info.', 'nibwp'),
    'category'    => 'divi',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['get', 'set_content', 'enable_builder', 'disable_builder', 'get_meta', 'set_meta', 'duplicate']],
            'id'       => ['type' => 'integer', 'description' => 'The post/page ID.'],
            'content'  => ['type' => 'string', 'description' => 'For set_content: the raw builder content ([et_pb_*] shortcodes for D4, serialized tree for D5).'],
            'meta'     => ['type' => 'object', 'description' => 'For set_meta: a map of Divi layout meta key → value (see get_meta for the known keys).'],
            'title'    => ['type' => 'string', 'description' => 'For duplicate: the new post title.'],
        ],
        'required' => ['action', 'id'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_divi_content_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false, 'instructions' => 'Call divi-info first to read content_engine. set_content overwrites the layout — get first to back up. enable_builder is required before a non-builder post will render Divi content.']],
]);

function nibwp_divi_content_execute(array $input): array|WP_Error
{
    if (!nibwp_divi_available()) {
        return nibwp_divi_err('divi_inactive', 'Divi is not active on this site.', 404);
    }
    $action = (string) ($input['action'] ?? '');
    $id     = (int) ($input['id'] ?? 0);
    $post   = $id ? get_post($id) : null;
    if (!$post) {
        return nibwp_divi_err('not_found', 'Post not found: ' . $id, 404);
    }

    switch ($action) {
        case 'get':
            return [
                'id'           => $id,
                'title'        => $post->post_title,
                'post_type'    => $post->post_type,
                'status'       => $post->post_status,
                'uses_builder' => get_post_meta($id, '_et_pb_use_builder', true) === 'on',
                'built_for'    => get_post_meta($id, '_et_pb_built_for_post_type', true),
                'format'       => nibwp_divi_content_format((string) $post->post_content),
                'content'      => $post->post_content,
                'length'       => strlen((string) $post->post_content),
            ];

        case 'set_content':
            if (!array_key_exists('content', $input)) {
                return nibwp_divi_err('no_content', 'Provide "content" to set.');
            }
            $content = (string) $input['content'];
            wp_update_post(['ID' => $id, 'post_content' => wp_slash($content)]);
            // Ensure the builder is on so Divi renders the layout.
            if (get_post_meta($id, '_et_pb_use_builder', true) !== 'on') {
                update_post_meta($id, '_et_pb_use_builder', 'on');
                update_post_meta($id, '_et_pb_built_for_post_type', $post->post_type ?: 'page');
            }
            return ['updated' => true, 'id' => $id, 'format' => nibwp_divi_content_format($content), 'length' => strlen($content)];

        case 'enable_builder':
            update_post_meta($id, '_et_pb_use_builder', 'on');
            update_post_meta($id, '_et_pb_built_for_post_type', $post->post_type ?: 'page');
            return ['enabled' => true, 'id' => $id];

        case 'disable_builder':
            update_post_meta($id, '_et_pb_use_builder', 'off');
            return ['disabled' => true, 'id' => $id];

        case 'get_meta':
            $meta = [];
            foreach (nibwp_divi_layout_meta_keys() as $k) {
                $v = get_post_meta($id, $k, true);
                if ($v !== '') {
                    $meta[$k] = $v;
                }
            }
            return ['id' => $id, 'meta' => $meta, 'known_keys' => nibwp_divi_layout_meta_keys()];

        case 'set_meta':
            $patch = (array) ($input['meta'] ?? []);
            if ($patch === []) {
                return nibwp_divi_err('no_meta', 'Provide a non-empty "meta" object.');
            }
            $changed = [];
            foreach ($patch as $k => $v) {
                update_post_meta($id, sanitize_key((string) $k), nibwp_divi_sanitize($v));
                $changed[] = (string) $k;
            }
            return ['updated' => $changed, 'id' => $id];

        case 'duplicate':
            $new = wp_insert_post([
                'post_title'   => sanitize_text_field((string) ($input['title'] ?? ($post->post_title . ' (copy)'))),
                'post_type'    => $post->post_type,
                'post_status'  => 'draft',
                'post_content' => wp_slash((string) $post->post_content),
            ], true);
            if (is_wp_error($new)) {
                return $new;
            }
            foreach (nibwp_divi_layout_meta_keys() as $k) {
                $v = get_post_meta($id, $k, true);
                if ($v !== '') {
                    update_post_meta((int) $new, $k, $v);
                }
            }
            return ['duplicated' => true, 'from' => $id, 'id' => (int) $new];
    }
    return nibwp_divi_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 3) divi-theme-options — ePanel / theme + builder options
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/divi-theme-options', [
    'label'       => __('Divi — Theme Options', 'nibwp'),
    'description' => __('Read/write Divi theme options (ePanel: logo, layout, colors, typography, performance, integration) via Divi\'s own option layer, plus the Divi Builder plugin options. Actions: get, update, get_key, set_key, builder_options.', 'nibwp'),
    'category'    => 'divi',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['get', 'update', 'get_key', 'set_key', 'builder_options']],
            'key'      => ['type' => 'string', 'description' => 'For get_key/set_key: a Divi option key (e.g. divi_logo, color_schemes, divi_fixed_nav).'],
            'value'    => ['description' => 'For set_key: the value to store.'],
            'settings' => ['type' => 'object', 'description' => 'For update: a map of option key → value.'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_divi_theme_options_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false, 'instructions' => 'Divi keeps most theme options inside a single array option (et_divi / et_extra). get returns that whole array so you can see exact keys before set_key/update.']],
]);

function nibwp_divi_theme_options_execute(array $input): array|WP_Error
{
    if (!nibwp_divi_available()) {
        return nibwp_divi_err('divi_inactive', 'Divi is not active on this site.', 404);
    }
    $action = (string) ($input['action'] ?? '');
    // The theme-options array option name varies by distribution.
    $option_name = nibwp_divi_is_plugin() ? 'et_pb_builder_options' : (get_option('et_divi', null) !== null ? 'et_divi' : 'et_extra');

    switch ($action) {
        case 'builder_options':
            return ['et_pb_builder_options' => get_option('et_pb_builder_options', [])];

        case 'get':
            return ['option_name' => $option_name, 'settings' => get_option($option_name, [])];

        case 'get_key':
            $key = (string) ($input['key'] ?? '');
            if ($key === '') {
                return nibwp_divi_err('no_key', 'Provide a "key".');
            }
            return ['key' => $key, 'value' => nibwp_divi_opt_get($key, null)];

        case 'set_key':
            $key = (string) ($input['key'] ?? '');
            if ($key === '' || !array_key_exists('value', $input)) {
                return nibwp_divi_err('bad_set', 'set_key needs "key" + "value".');
            }
            nibwp_divi_opt_set($key, nibwp_divi_sanitize($input['value']));
            return ['key' => $key, 'value' => nibwp_divi_opt_get($key, null)];

        case 'update':
            $patch = (array) ($input['settings'] ?? []);
            if ($patch === []) {
                return nibwp_divi_err('no_settings', 'Provide a non-empty "settings" object.');
            }
            $current = get_option($option_name, []);
            $current = is_array($current) ? $current : [];
            $changed = [];
            foreach ($patch as $k => $v) {
                $current[(string) $k] = nibwp_divi_sanitize($v);
                $changed[] = (string) $k;
            }
            update_option($option_name, $current);
            return ['updated' => $changed, 'option_name' => $option_name];
    }
    return nibwp_divi_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 4) divi-colors — the Global Color palette
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/divi-colors', [
    'label'       => __('Divi — Colors', 'nibwp'),
    'description' => __('Read/write the Divi Global Colors palette (the swatches surfaced across the builder). Actions: get, set, add, update, remove.', 'nibwp'),
    'category'    => 'divi',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['get', 'set', 'add', 'update', 'remove']],
            'colors' => ['type' => 'object', 'description' => 'For set: the full global-colors map {gcid: {color, active}}.'],
            'id'     => ['type' => 'string', 'description' => 'Global color id, e.g. gcid-primary or gcid-xxxx.'],
            'color'  => ['type' => 'string', 'description' => 'Hex/rgba value.'],
            'active' => ['type' => 'string', 'enum' => ['yes', 'no'], 'default' => 'yes'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_divi_colors_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function nibwp_divi_colors_execute(array $input): array|WP_Error
{
    if (!nibwp_divi_available()) {
        return nibwp_divi_err('divi_inactive', 'Divi is not active on this site.', 404);
    }
    $action = (string) ($input['action'] ?? '');
    $raw    = nibwp_divi_opt_get('et_global_colors', []);
    if (is_string($raw)) {
        $raw = json_decode($raw, true) ?: [];
    }
    $colors = is_array($raw) ? $raw : [];

    $save = static function (array $c): array {
        nibwp_divi_opt_set('et_global_colors', $c);
        return ['global_colors' => $c, 'count' => count($c)];
    };

    switch ($action) {
        case 'get':
            return ['global_colors' => $colors, 'count' => count($colors)];

        case 'set':
            $new = (array) ($input['colors'] ?? []);
            if ($new === []) {
                return nibwp_divi_err('no_colors', 'Provide a non-empty "colors" map.');
            }
            return $save(nibwp_divi_sanitize($new));

        case 'add':
        case 'update':
            $cid = (string) ($input['id'] ?? '');
            if ($cid === '' || ($input['color'] ?? '') === '') {
                return nibwp_divi_err('bad_color', 'add/update needs "id" + "color".');
            }
            $colors[$cid] = [
                'color'  => sanitize_text_field((string) $input['color']),
                'active' => (($input['active'] ?? 'yes') === 'no') ? 'no' : 'yes',
            ];
            return $save($colors);

        case 'remove':
            $cid = (string) ($input['id'] ?? '');
            unset($colors[$cid]);
            return $save($colors);
    }
    return nibwp_divi_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 5) divi-library — saved layouts (et_pb_layout)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/divi-library', [
    'label'       => __('Divi — Library', 'nibwp'),
    'description' => __('Manage the Divi Library of saved layouts (et_pb_layout): sections, rows, modules and full layouts, with categories. Actions: list, get, create, update, delete, categories.', 'nibwp'),
    'category'    => 'divi',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'     => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'delete', 'categories']],
            'id'         => ['type' => 'integer'],
            'title'      => ['type' => 'string'],
            'content'    => ['type' => 'string', 'description' => 'The layout content (shortcodes / serialized).'],
            'layout_type'=> ['type' => 'string', 'enum' => ['layout', 'section', 'row', 'module'], 'default' => 'layout'],
            'scope'      => ['type' => 'string', 'enum' => ['not_global', 'global'], 'default' => 'not_global'],
            'category'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'layout_category term names/slugs.'],
            'built_for'  => ['type' => 'string', 'default' => 'page'],
            'status'     => ['type' => 'string', 'enum' => ['publish', 'draft'], 'default' => 'publish'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_divi_library_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false]],
]);

function nibwp_divi_library_execute(array $input): array|WP_Error
{
    if (!nibwp_divi_available()) {
        return nibwp_divi_err('divi_inactive', 'Divi is not active on this site.', 404);
    }
    if (!post_type_exists('et_pb_layout')) {
        return nibwp_divi_err('library_unavailable', 'The Divi Library post type (et_pb_layout) is not registered.', 404);
    }
    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'categories':
            $terms = get_terms(['taxonomy' => 'layout_category', 'hide_empty' => false]);
            $out = [];
            foreach (is_array($terms) ? $terms : [] as $t) {
                $out[] = ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count];
            }
            return ['categories' => $out];

        case 'list':
            $items = [];
            foreach (get_posts(['post_type' => 'et_pb_layout', 'post_status' => ['publish', 'draft'], 'numberposts' => 200]) as $p) {
                $items[] = [
                    'id'     => $p->ID,
                    'title'  => $p->post_title,
                    'status' => $p->post_status,
                    'type'   => wp_get_object_terms($p->ID, 'layout_type', ['fields' => 'slugs']),
                    'scope'  => wp_get_object_terms($p->ID, 'scope', ['fields' => 'slugs']),
                ];
            }
            return ['layouts' => $items, 'count' => count($items)];

        case 'get':
            $p = get_post((int) ($input['id'] ?? 0));
            if (!$p || $p->post_type !== 'et_pb_layout') {
                return nibwp_divi_err('not_found', 'Layout not found.', 404);
            }
            return [
                'id'       => $p->ID,
                'title'    => $p->post_title,
                'status'   => $p->post_status,
                'content'  => $p->post_content,
                'category' => wp_get_object_terms($p->ID, 'layout_category', ['fields' => 'names']),
                'type'     => wp_get_object_terms($p->ID, 'layout_type', ['fields' => 'slugs']),
                'scope'    => wp_get_object_terms($p->ID, 'scope', ['fields' => 'slugs']),
            ];

        case 'create':
            $pid = wp_insert_post([
                'post_type'    => 'et_pb_layout',
                'post_status'  => in_array($input['status'] ?? 'publish', ['publish', 'draft'], true) ? (string) $input['status'] : 'publish',
                'post_title'   => sanitize_text_field((string) ($input['title'] ?? 'Divi layout')),
                'post_content' => wp_slash((string) ($input['content'] ?? '')),
            ], true);
            if (is_wp_error($pid)) {
                return $pid;
            }
            update_post_meta((int) $pid, '_et_pb_built_for_post_type', sanitize_text_field((string) ($input['built_for'] ?? 'page')));
            if (taxonomy_exists('layout_type')) {
                wp_set_object_terms((int) $pid, sanitize_text_field((string) ($input['layout_type'] ?? 'layout')), 'layout_type');
            }
            if (taxonomy_exists('scope')) {
                wp_set_object_terms((int) $pid, sanitize_text_field((string) ($input['scope'] ?? 'not_global')), 'scope');
            }
            if (!empty($input['category']) && taxonomy_exists('layout_category')) {
                wp_set_object_terms((int) $pid, array_map('sanitize_text_field', (array) $input['category']), 'layout_category');
            }
            return ['created' => true, 'id' => (int) $pid];

        case 'update':
            $id = (int) ($input['id'] ?? 0);
            if (get_post_type($id) !== 'et_pb_layout') {
                return nibwp_divi_err('not_found', 'Layout not found.', 404);
            }
            $postarr = ['ID' => $id];
            if (isset($input['title']))   { $postarr['post_title']   = sanitize_text_field((string) $input['title']); }
            if (isset($input['content'])) { $postarr['post_content'] = wp_slash((string) $input['content']); }
            if (isset($input['status']))  { $postarr['post_status']  = in_array($input['status'], ['publish', 'draft'], true) ? (string) $input['status'] : 'publish'; }
            if (count($postarr) > 1) {
                wp_update_post($postarr);
            }
            if (!empty($input['category']) && taxonomy_exists('layout_category')) {
                wp_set_object_terms($id, array_map('sanitize_text_field', (array) $input['category']), 'layout_category');
            }
            return ['updated' => true, 'id' => $id];

        case 'delete':
            $id = (int) ($input['id'] ?? 0);
            if (get_post_type($id) !== 'et_pb_layout') {
                return nibwp_divi_err('not_found', 'Layout not found.', 404);
            }
            wp_delete_post($id, true);
            return ['deleted' => true, 'id' => $id];
    }
    return nibwp_divi_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 6) divi-theme-builder — header/body/footer layouts + templates
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/divi-theme-builder', [
    'label'       => __('Divi — Theme Builder', 'nibwp'),
    'description' => __('Inspect and edit Divi Theme Builder layouts: the header/body/footer layout post types and template containers. Actions: list, get_layout, create_layout, update_layout, delete_layout. Full template assignment (which pages a template applies to) is best finalised in the Divi UI — get returns the raw template meta so conditions can be read.', 'nibwp'),
    'category'    => 'divi',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'get_layout', 'create_layout', 'update_layout', 'delete_layout']],
            'id'      => ['type' => 'integer'],
            'part'    => ['type' => 'string', 'enum' => ['header', 'body', 'footer'], 'description' => 'Which layout post type to target for create_layout.'],
            'title'   => ['type' => 'string'],
            'content' => ['type' => 'string'],
            'status'  => ['type' => 'string', 'enum' => ['publish', 'draft'], 'default' => 'publish'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_divi_theme_builder_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false, 'instructions' => 'Theme Builder wiring (template → layout → assignment) is intricate; this ability manages the individual header/body/footer layout posts + reads template meta. Assigning a new template to pages should be confirmed in the Divi Theme Builder UI.']],
]);

function nibwp_divi_theme_builder_execute(array $input): array|WP_Error
{
    if (!nibwp_divi_available()) {
        return nibwp_divi_err('divi_inactive', 'Divi is not active on this site.', 404);
    }
    $part_map = ['header' => 'et_header_layout', 'body' => 'et_body_layout', 'footer' => 'et_footer_layout'];
    $all_pts  = array_merge(array_values($part_map), ['et_template']);
    if (!array_filter($all_pts, 'post_type_exists')) {
        return nibwp_divi_err('tb_unavailable', 'Divi Theme Builder is not available on this site.', 404);
    }
    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'list':
            $out = [];
            foreach ($all_pts as $pt) {
                if (!post_type_exists($pt)) {
                    continue;
                }
                $items = [];
                foreach (get_posts(['post_type' => $pt, 'post_status' => ['publish', 'draft'], 'numberposts' => 100]) as $p) {
                    $items[] = ['id' => $p->ID, 'title' => $p->post_title, 'status' => $p->post_status];
                }
                $out[$pt] = $items;
            }
            return ['theme_builder' => $out];

        case 'get_layout':
            $id = (int) ($input['id'] ?? 0);
            $p  = get_post($id);
            if (!$p || !in_array($p->post_type, $all_pts, true)) {
                return nibwp_divi_err('not_found', 'Theme Builder item not found.', 404);
            }
            $meta = [];
            foreach (get_post_meta($id) as $k => $v) {
                if (str_starts_with((string) $k, '_et')) {
                    $meta[$k] = maybe_unserialize(is_array($v) ? ($v[0] ?? '') : $v);
                }
            }
            return ['id' => $id, 'post_type' => $p->post_type, 'title' => $p->post_title, 'status' => $p->post_status, 'content' => $p->post_content, 'meta' => $meta];

        case 'create_layout':
            $part = (string) ($input['part'] ?? '');
            if (!isset($part_map[$part]) || !post_type_exists($part_map[$part])) {
                return nibwp_divi_err('bad_part', 'create_layout needs "part" (header|body|footer) whose post type is registered.');
            }
            $pid = wp_insert_post([
                'post_type'    => $part_map[$part],
                'post_status'  => in_array($input['status'] ?? 'publish', ['publish', 'draft'], true) ? (string) $input['status'] : 'publish',
                'post_title'   => sanitize_text_field((string) ($input['title'] ?? ('Divi ' . $part))),
                'post_content' => wp_slash((string) ($input['content'] ?? '')),
            ], true);
            if (is_wp_error($pid)) {
                return $pid;
            }
            update_post_meta((int) $pid, '_et_pb_use_builder', 'on');
            update_post_meta((int) $pid, '_et_pb_built_for_post_type', $part_map[$part]);
            return ['created' => true, 'id' => (int) $pid, 'post_type' => $part_map[$part]];

        case 'update_layout':
            $id = (int) ($input['id'] ?? 0);
            $p  = get_post($id);
            if (!$p || !in_array($p->post_type, array_values($part_map), true)) {
                return nibwp_divi_err('not_found', 'Theme Builder layout not found.', 404);
            }
            $postarr = ['ID' => $id];
            if (isset($input['title']))   { $postarr['post_title']   = sanitize_text_field((string) $input['title']); }
            if (isset($input['content'])) { $postarr['post_content'] = wp_slash((string) $input['content']); }
            if (isset($input['status']))  { $postarr['post_status']  = in_array($input['status'], ['publish', 'draft'], true) ? (string) $input['status'] : 'publish'; }
            if (count($postarr) > 1) {
                wp_update_post($postarr);
            }
            return ['updated' => true, 'id' => $id];

        case 'delete_layout':
            $id = (int) ($input['id'] ?? 0);
            $p  = get_post($id);
            if (!$p || !in_array($p->post_type, $all_pts, true)) {
                return nibwp_divi_err('not_found', 'Theme Builder item not found.', 404);
            }
            wp_delete_post($id, true);
            return ['deleted' => true, 'id' => $id];
    }
    return nibwp_divi_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 7) divi-presets — global module presets
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/divi-presets', [
    'label'       => __('Divi — Presets', 'nibwp'),
    'description' => __('Read the Divi global module Presets (per-module default styles) and overwrite the raw presets store. Actions: get, get_module, set_raw. Presets are a large structured object — get first, edit, then set_raw the whole structure.', 'nibwp'),
    'category'    => 'divi',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['get', 'get_module', 'set_raw']],
            'module'  => ['type' => 'string', 'description' => 'For get_module: a module slug, e.g. et_pb_button.'],
            'presets' => ['description' => 'For set_raw: the full presets object to store verbatim.'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_divi_presets_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false, 'instructions' => 'set_raw overwrites ALL global presets — always get first and pass the full edited structure back. The store key is builder_global_presets_ng (Divi 4) on the et_update_option layer.']],
]);

function nibwp_divi_presets_execute(array $input): array|WP_Error
{
    if (!nibwp_divi_available()) {
        return nibwp_divi_err('divi_inactive', 'Divi is not active on this site.', 404);
    }
    $action = (string) ($input['action'] ?? '');

    $get_presets = static function () {
        if (class_exists('ET_Builder_Element') && method_exists('ET_Builder_Element', 'get_global_presets')) {
            return ET_Builder_Element::get_global_presets();
        }
        $key = defined('ET_Builder_Global_Presets_Settings::GLOBAL_PRESETS_OPTION') ? ET_Builder_Global_Presets_Settings::GLOBAL_PRESETS_OPTION : 'builder_global_presets_ng';
        return nibwp_divi_opt_get($key, []);
    };

    switch ($action) {
        case 'get':
            return ['presets' => $get_presets()];

        case 'get_module':
            $module = (string) ($input['module'] ?? '');
            if ($module === '') {
                return nibwp_divi_err('no_module', 'Provide a "module" slug.');
            }
            $presets = (array) json_decode((string) wp_json_encode($get_presets()), true);
            $mod = $presets['module'][$module] ?? ($presets[$module] ?? null);
            return ['module' => $module, 'presets' => $mod];

        case 'set_raw':
            if (!array_key_exists('presets', $input)) {
                return nibwp_divi_err('no_presets', 'Provide "presets" to store.');
            }
            $key = class_exists('ET_Builder_Global_Presets_Settings') ? ET_Builder_Global_Presets_Settings::GLOBAL_PRESETS_OPTION : 'builder_global_presets_ng';
            nibwp_divi_opt_set($key, $input['presets']);
            return ['updated' => true, 'key' => $key];
    }
    return nibwp_divi_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 8) divi-roles — Divi Role Editor
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/divi-roles', [
    'label'       => __('Divi — Roles', 'nibwp'),
    'description' => __('Read/write the Divi Role Editor capabilities (which roles can use the builder, edit settings, see specific tabs/modules). Actions: get, set, reset.', 'nibwp'),
    'category'    => 'divi',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['get', 'set', 'reset']],
            'settings' => ['type' => 'object', 'description' => 'For set: a map of role => {capability => on|off}.'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_divi_roles_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function nibwp_divi_roles_execute(array $input): array|WP_Error
{
    if (!nibwp_divi_available()) {
        return nibwp_divi_err('divi_inactive', 'Divi is not active on this site.', 404);
    }
    $action = (string) ($input['action'] ?? '');
    $current = function_exists('et_pb_get_role_settings') ? (array) et_pb_get_role_settings() : (array) get_option('et_pb_role_settings', []);

    switch ($action) {
        case 'get':
            return ['roles' => $current];

        case 'set':
            $patch = (array) ($input['settings'] ?? []);
            if ($patch === []) {
                return nibwp_divi_err('no_settings', 'Provide a non-empty "settings" map of role => capabilities.');
            }
            foreach ($patch as $role => $caps) {
                $role = sanitize_key((string) $role);
                $current[$role] = array_merge((array) ($current[$role] ?? []), nibwp_divi_sanitize((array) $caps));
            }
            update_option('et_pb_role_settings', $current);
            return ['updated' => array_keys($patch), 'roles' => $current];

        case 'reset':
            delete_option('et_pb_role_settings');
            return ['reset' => true];
    }
    return nibwp_divi_err('bad_action', 'Unknown action: ' . $action);
}
