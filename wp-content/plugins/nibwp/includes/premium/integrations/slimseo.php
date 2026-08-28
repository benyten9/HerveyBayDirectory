<?php

declare(strict_types=1);

/**
 * Slim SEO integration — NIBWP MCP abilities (Layer 1 adapter).
 *
 * Four abilities give an AI agent control of Slim SEO and the Slim SEO Schema
 * add-on: discovery, per-post SEO (title, description, canonical, robots, social
 * images), the global settings (enabled features, social defaults, code
 * injection, redirects), and per-post structured-data (Schema) overrides.
 *
 * Mechanism: Slim SEO is deliberately minimal — every per-post value lives in a
 * SINGLE post-meta array under the `slim_seo` key, and all global settings live
 * in the `slim_seo` option. The Schema add-on stores per-post schema overrides
 * in the `slim_seo_schema` post-meta. This adapter reads/merges those directly,
 * so it stays correct across versions. Verified against Slim SEO 4.x + Slim SEO
 * Schema.
 *
 * Detection: SLIM_SEO_VER. Schema gates on SLIM_SEO_SCHEMA_DIR.
 */

if (!defined('ABSPATH')) {
    exit();
}

/* ----------------------------------------------------------------------------
 * Shared helpers (file-local)
 * ------------------------------------------------------------------------- */

/** Is Slim SEO active? */
function nibwp_slimseo_available(): bool
{
    return defined('SLIM_SEO_VER') || function_exists('slim_seo');
}

/** Is the Slim SEO Schema add-on active? */
function nibwp_slimseo_schema_available(): bool
{
    return defined('SLIM_SEO_SCHEMA_DIR') || defined('SLIM_SEO_SCHEMA_URL');
}

/** House WP_Error wrapper. */
function nibwp_slimseo_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error($code, $message, ['status' => $status]);
}

/** The per-post slim_seo meta array. */
function nibwp_slimseo_post_meta(int $id): array
{
    $v = get_post_meta($id, 'slim_seo', true);
    return is_array($v) ? $v : [];
}

/** The fields the per-post ability maps into the slim_seo array. */
function nibwp_slimseo_post_fields(): array
{
    return ['title', 'description', 'canonical', 'facebook_image', 'twitter_image', 'breadcrumb_title'];
}

/* ----------------------------------------------------------------------------
 * 1) slimseo-info — discovery
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/slimseo-info', [
    'label'       => __('Slim SEO — Info', 'nibwp'),
    'description' => __('Detect Slim SEO and the Slim SEO Schema add-on, their versions, and which Slim SEO features are enabled (meta title/description, robots, Open Graph, Twitter, canonical, sitemaps, breadcrumbs, schema, redirection).', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_slimseo_info_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function nibwp_slimseo_info_execute(array $input): array|WP_Error
{
    if (!nibwp_slimseo_available()) {
        return nibwp_slimseo_err('slimseo_inactive', 'Slim SEO is not active on this site.', 404);
    }
    $settings = get_option('slim_seo', []);
    $settings = is_array($settings) ? $settings : [];
    return [
        'slimseo_active'  => true,
        'version'         => defined('SLIM_SEO_VER') ? SLIM_SEO_VER : '',
        'schema_active'   => nibwp_slimseo_schema_available(),
        'features'        => $settings['features'] ?? [],
        'redirects_supported' => defined('SLIM_SEO_REDIRECTS'),
        'abilities'       => ['post', 'settings', 'schema'],
    ];
}

/* ----------------------------------------------------------------------------
 * 2) slimseo-post — per-post SEO (the slim_seo meta array)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/slimseo-post', [
    'label'       => __('Slim SEO — Post SEO', 'nibwp'),
    'description' => __('Read/write a post\'s Slim SEO data: meta title, description, canonical URL, robots (noindex/nofollow), Facebook + Twitter image, and breadcrumb title. Actions: get, set, bulk_set. All values live in the single slim_seo post-meta array.', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'         => ['type' => 'string', 'enum' => ['get', 'set', 'bulk_set']],
            'id'             => ['type' => 'integer'],
            'title'          => ['type' => 'string'],
            'description'    => ['type' => 'string'],
            'canonical'      => ['type' => 'string'],
            'noindex'        => ['type' => 'boolean'],
            'nofollow'       => ['type' => 'boolean'],
            'facebook_image' => ['type' => 'string'],
            'twitter_image'  => ['type' => 'string'],
            'breadcrumb_title' => ['type' => 'string'],
            'items'          => ['type' => 'array', 'description' => 'For bulk_set: array of objects each with an "id" + any of the fields above.'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_slimseo_post_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

/** Merge SEO fields into a post's slim_seo array. Returns the new array. */
function nibwp_slimseo_apply_post(int $id, array $in): array
{
    $data = nibwp_slimseo_post_meta($id);
    foreach (nibwp_slimseo_post_fields() as $field) {
        if (array_key_exists($field, $in)) {
            $val = strpos($field, 'image') !== false ? esc_url_raw((string) $in[$field]) : sanitize_text_field((string) $in[$field]);
            if ($val === '') {
                unset($data[$field]);
            } else {
                $data[$field] = $val;
            }
        }
    }
    foreach (['noindex', 'nofollow'] as $flag) {
        if (array_key_exists($flag, $in)) {
            if (!empty($in[$flag])) {
                $data[$flag] = true;
            } else {
                unset($data[$flag]);
            }
        }
    }
    if ($data === []) {
        delete_post_meta($id, 'slim_seo');
    } else {
        update_post_meta($id, 'slim_seo', $data);
    }
    return $data;
}

function nibwp_slimseo_post_execute(array $input): array|WP_Error
{
    if (!nibwp_slimseo_available()) {
        return nibwp_slimseo_err('slimseo_inactive', 'Slim SEO is not active on this site.', 404);
    }
    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'get':
            $id = (int) ($input['id'] ?? 0);
            if (!get_post($id)) {
                return nibwp_slimseo_err('not_found', 'Post not found.', 404);
            }
            $data = nibwp_slimseo_post_meta($id);
            return [
                'id'      => $id,
                'data'    => $data,
                'noindex' => !empty($data['noindex']),
                'nofollow'=> !empty($data['nofollow']),
            ];

        case 'set':
            $id = (int) ($input['id'] ?? 0);
            if (!get_post($id)) {
                return nibwp_slimseo_err('not_found', 'Post not found.', 404);
            }
            return ['updated' => true, 'id' => $id, 'data' => nibwp_slimseo_apply_post($id, $input)];

        case 'bulk_set':
            $items = (array) ($input['items'] ?? []);
            if ($items === []) {
                return nibwp_slimseo_err('no_items', 'Provide a non-empty "items" array.');
            }
            $results = [];
            foreach ($items as $item) {
                $item = (array) $item;
                $id = (int) ($item['id'] ?? 0);
                if (!$id || !get_post($id)) {
                    $results[] = ['id' => $id, 'error' => 'not found'];
                    continue;
                }
                $results[] = ['id' => $id, 'data' => nibwp_slimseo_apply_post($id, $item)];
            }
            return ['results' => $results, 'count' => count($results)];
    }
    return nibwp_slimseo_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 3) slimseo-settings — global settings (the slim_seo option)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/slimseo-settings', [
    'label'       => __('Slim SEO — Settings', 'nibwp'),
    'description' => __('Read/write the global Slim SEO settings (the slim_seo option): the enabled features list, social defaults (default Facebook/Twitter image, Facebook app id, Twitter site), header/body/footer code injection, and redirection/404 behavior. Actions: get, update, set_features. Use get first to see exact keys.', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['get', 'update', 'set_features']],
            'settings' => ['type' => 'object', 'description' => 'For update: a map of slim_seo option key → value, merged in.'],
            'features' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'For set_features: the full enabled-features list (e.g. meta_title, meta_description, meta_robots, open_graph, twitter_cards, canonical_url, sitemaps, breadcrumbs, schema, redirection).'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_slimseo_settings_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function nibwp_slimseo_settings_execute(array $input): array|WP_Error
{
    if (!nibwp_slimseo_available()) {
        return nibwp_slimseo_err('slimseo_inactive', 'Slim SEO is not active on this site.', 404);
    }
    $action = (string) ($input['action'] ?? '');
    $settings = get_option('slim_seo', []);
    $settings = is_array($settings) ? $settings : [];

    switch ($action) {
        case 'get':
            return ['settings' => $settings];

        case 'set_features':
            if (!isset($input['features']) || !is_array($input['features'])) {
                return nibwp_slimseo_err('no_features', 'Provide a "features" array.');
            }
            $settings['features'] = array_values(array_map('sanitize_key', $input['features']));
            update_option('slim_seo', $settings);
            return ['updated' => true, 'features' => $settings['features']];

        case 'update':
            $patch = (array) ($input['settings'] ?? []);
            if ($patch === []) {
                return nibwp_slimseo_err('no_settings', 'Provide a non-empty "settings" object.');
            }
            $changed = [];
            foreach ($patch as $k => $v) {
                $settings[(string) $k] = $v;
                $changed[] = (string) $k;
            }
            update_option('slim_seo', $settings);
            return ['updated' => $changed];
    }
    return nibwp_slimseo_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 4) slimseo-schema — Slim SEO Schema (structured data)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/slimseo-schema', [
    'label'       => __('Slim SEO — Schema', 'nibwp'),
    'description' => __('Read/write per-post structured-data (Schema) overrides for the Slim SEO Schema add-on. Actions: get, set, delete. The schema override is stored verbatim in the slim_seo_schema post-meta (an array of schema-type definitions); run get first to see its exact shape on this site.', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['get', 'set', 'delete']],
            'id'     => ['type' => 'integer'],
            'schema' => ['description' => 'For set: the schema override structure to store in slim_seo_schema.'],
        ],
        'required' => ['action', 'id'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_slimseo_schema_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false, 'instructions' => 'Requires the Slim SEO Schema add-on. Global schema rules are managed in the Slim SEO Schema UI; this ability sets per-post overrides. get first to mirror the structure.']],
]);

function nibwp_slimseo_schema_execute(array $input): array|WP_Error
{
    if (!nibwp_slimseo_available()) {
        return nibwp_slimseo_err('slimseo_inactive', 'Slim SEO is not active on this site.', 404);
    }
    if (!nibwp_slimseo_schema_available()) {
        return nibwp_slimseo_err('schema_unavailable', 'The Slim SEO Schema add-on is not active.', 404);
    }
    $id = (int) ($input['id'] ?? 0);
    if (!get_post($id)) {
        return nibwp_slimseo_err('not_found', 'Post not found.', 404);
    }
    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'get':
            return ['id' => $id, 'schema' => get_post_meta($id, 'slim_seo_schema', true)];

        case 'set':
            if (!array_key_exists('schema', $input)) {
                return nibwp_slimseo_err('no_schema', 'Provide a "schema" value.');
            }
            update_post_meta($id, 'slim_seo_schema', $input['schema']);
            return ['updated' => true, 'id' => $id, 'schema' => get_post_meta($id, 'slim_seo_schema', true)];

        case 'delete':
            delete_post_meta($id, 'slim_seo_schema');
            return ['deleted' => true, 'id' => $id];
    }
    return nibwp_slimseo_err('bad_action', 'Unknown action: ' . $action);
}
