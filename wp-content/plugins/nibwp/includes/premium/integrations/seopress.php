<?php

declare(strict_types=1);

/**
 * SEOPress integration — NIBWP MCP abilities (Layer 1 adapter).
 *
 * Nine domain-grouped abilities give an AI agent complete control of SEOPress
 * (free + Pro): discovery, per-post SEO meta (title, description, canonical,
 * robots, target keywords, primary category), per-post social (Open Graph +
 * Twitter), and the global modules — Titles & Metas, Social/Knowledge Graph,
 * XML Sitemap, Advanced (verification + cleanup), feature toggles, and per-post
 * Redirections (Pro).
 *
 * Mechanism: in-process WP post meta + options — exactly where SEOPress reads.
 * Per-post values live in `_seopress_*` meta; global modules live in the
 * `seopress_titles_option_name`, `seopress_social_option_name`,
 * `seopress_xml_sitemap_option_name`, `seopress_advanced_option_name` option
 * arrays; module on/off lives in `seopress_toggle`. We read/merge these
 * directly so the integration is stable across SEOPress versions. Verified
 * against SEOPress 9.x meta keys + option structures.
 *
 * Robots semantics (SEOPress convention): a `_seopress_robots_*` meta value of
 * 'yes' turns the rule ON — `_seopress_robots_index = 'yes'` means NOINDEX,
 * `_seopress_robots_follow = 'yes'` means NOFOLLOW, etc. This adapter exposes
 * those as plain booleans (noindex/nofollow/…) and maps them.
 *
 * Detection: SEOPRESS_VERSION. Pro gates on SEOPRESS_PRO_VERSION.
 */

if (!defined('ABSPATH')) {
    exit();
}

/* ----------------------------------------------------------------------------
 * Shared helpers (file-local)
 * ------------------------------------------------------------------------- */

/** Is SEOPress active? */
function nibwp_seopress_available(): bool
{
    return defined('SEOPRESS_VERSION');
}

/** Is SEOPress Pro active? */
function nibwp_seopress_pro(): bool
{
    return defined('SEOPRESS_PRO_VERSION');
}

/** House WP_Error wrapper. */
function nibwp_seopress_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error($code, $message, ['status' => $status]);
}

/** Read a SEOPress global option array. */
function nibwp_seopress_opt(string $name): array
{
    $v = get_option($name, []);
    return is_array($v) ? $v : [];
}

/**
 * Merge a patch into a SEOPress global option array and persist. Returns the
 * keys that changed.
 *
 * @param array<string,mixed> $patch
 * @return string[]
 */
function nibwp_seopress_opt_merge(string $name, array $patch): array
{
    $current = nibwp_seopress_opt($name);
    $changed = [];
    foreach ($patch as $k => $v) {
        $k = (string) $k;
        if (!array_key_exists($k, $current) || $current[$k] !== $v) {
            $changed[] = $k;
        }
        $current[$k] = $v;
    }
    update_option($name, $current);
    return $changed;
}

/** The per-post robots boolean → SEOPress meta-key map (meta 'yes' = rule ON). */
function nibwp_seopress_robots_map(): array
{
    return [
        'noindex'      => '_seopress_robots_index',
        'nofollow'     => '_seopress_robots_follow',
        'noimageindex' => '_seopress_robots_imageindex',
        'noarchive'    => '_seopress_robots_archive',
        'nosnippet'    => '_seopress_robots_snippet',
    ];
}

/** Dump every _seopress_* meta key for a post (self-describing). */
function nibwp_seopress_dump_meta(int $id): array
{
    $out = [];
    foreach (get_post_meta($id) as $k => $v) {
        if (strpos((string) $k, '_seopress_') === 0) {
            $out[$k] = maybe_unserialize(is_array($v) ? ($v[0] ?? '') : $v);
        }
    }
    return $out;
}

/* ----------------------------------------------------------------------------
 * 1) seopress-info — discovery
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/seopress-info', [
    'label'       => __('SEOPress — Info', 'nibwp'),
    'description' => __('Detect SEOPress (free + Pro), its version, the enabled feature toggles, and which global option groups are configured.', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_seopress_info_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function nibwp_seopress_info_execute(array $input): array|WP_Error
{
    if (!nibwp_seopress_available()) {
        return nibwp_seopress_err('seopress_inactive', 'SEOPress is not active on this site.', 404);
    }
    return [
        'seopress_active' => true,
        'version'      => SEOPRESS_VERSION,
        'pro_active'   => nibwp_seopress_pro(),
        'pro_version'  => defined('SEOPRESS_PRO_VERSION') ? SEOPRESS_PRO_VERSION : '',
        'toggles'      => get_option('seopress_toggle', []),
        'configured'   => [
            'titles'   => get_option('seopress_titles_option_name', null) !== null,
            'social'   => get_option('seopress_social_option_name', null) !== null,
            'sitemap'  => get_option('seopress_xml_sitemap_option_name', null) !== null,
            'advanced' => get_option('seopress_advanced_option_name', null) !== null,
        ],
        'abilities'    => ['post', 'social-post', 'titles', 'social', 'sitemap', 'advanced', 'toggles', 'redirections'],
    ];
}

/* ----------------------------------------------------------------------------
 * 2) seopress-post — per-post SEO meta
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/seopress-post', [
    'label'       => __('SEOPress — Post SEO', 'nibwp'),
    'description' => __('Read/write a post or term SEO: title, meta description, canonical URL, robots (noindex/nofollow/noimageindex/noarchive/nosnippet), target keywords and primary category. Actions: get, set, bulk_set. Booleans map to SEOPress robots meta (true = rule ON, e.g. noindex).', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'       => ['type' => 'string', 'enum' => ['get', 'set', 'bulk_set']],
            'id'           => ['type' => 'integer', 'description' => 'Post ID.'],
            'title'        => ['type' => 'string'],
            'description'  => ['type' => 'string'],
            'canonical'    => ['type' => 'string'],
            'noindex'      => ['type' => 'boolean'],
            'nofollow'     => ['type' => 'boolean'],
            'noimageindex' => ['type' => 'boolean'],
            'noarchive'    => ['type' => 'boolean'],
            'nosnippet'    => ['type' => 'boolean'],
            'target_keywords' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Content-analysis target keywords.'],
            'primary_category' => ['type' => 'integer', 'description' => 'Primary category term id.'],
            'meta'         => ['type' => 'object', 'description' => 'Extra _seopress_* meta to set verbatim.'],
            'items'        => ['type' => 'array', 'description' => 'For bulk_set: array of objects each with an "id" + any of the fields above.'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_seopress_post_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

/** Apply SEO fields to a single post. Returns the changed meta keys. */
function nibwp_seopress_apply_post(int $id, array $in): array
{
    $changed = [];
    $set = static function (string $key, $value) use ($id, &$changed) {
        update_post_meta($id, $key, $value);
        $changed[] = $key;
    };
    if (array_key_exists('title', $in))       { $set('_seopress_titles_title', sanitize_text_field((string) $in['title'])); }
    if (array_key_exists('description', $in)) { $set('_seopress_titles_desc', sanitize_textarea_field((string) $in['description'])); }
    if (array_key_exists('canonical', $in))   { $set('_seopress_robots_canonical', esc_url_raw((string) $in['canonical'])); }
    foreach (nibwp_seopress_robots_map() as $flag => $metaKey) {
        if (array_key_exists($flag, $in)) {
            $set($metaKey, !empty($in[$flag]) ? 'yes' : '');
        }
    }
    if (array_key_exists('target_keywords', $in)) {
        $kw = is_array($in['target_keywords']) ? implode(',', array_map('sanitize_text_field', $in['target_keywords'])) : sanitize_text_field((string) $in['target_keywords']);
        $set('_seopress_analysis_target_kw', $kw);
    }
    if (array_key_exists('primary_category', $in)) {
        $set('_seopress_robots_primary_cat', (string) (int) $in['primary_category']);
    }
    foreach ((array) ($in['meta'] ?? []) as $mk => $mv) {
        if (strpos((string) $mk, '_seopress_') === 0) {
            $set((string) $mk, is_array($mv) ? $mv : sanitize_text_field((string) $mv));
        }
    }
    return $changed;
}

function nibwp_seopress_post_execute(array $input): array|WP_Error
{
    if (!nibwp_seopress_available()) {
        return nibwp_seopress_err('seopress_inactive', 'SEOPress is not active on this site.', 404);
    }
    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'get':
            $id = (int) ($input['id'] ?? 0);
            if (!get_post($id)) {
                return nibwp_seopress_err('not_found', 'Post not found.', 404);
            }
            $robots = [];
            foreach (nibwp_seopress_robots_map() as $flag => $key) {
                $robots[$flag] = get_post_meta($id, $key, true) === 'yes';
            }
            return [
                'id'          => $id,
                'title'       => get_post_meta($id, '_seopress_titles_title', true),
                'description' => get_post_meta($id, '_seopress_titles_desc', true),
                'canonical'   => get_post_meta($id, '_seopress_robots_canonical', true),
                'robots'      => $robots,
                'target_keywords' => array_values(array_filter(explode(',', (string) get_post_meta($id, '_seopress_analysis_target_kw', true)))),
                'primary_category' => get_post_meta($id, '_seopress_robots_primary_cat', true),
                'all_meta'    => nibwp_seopress_dump_meta($id),
            ];

        case 'set':
            $id = (int) ($input['id'] ?? 0);
            if (!get_post($id)) {
                return nibwp_seopress_err('not_found', 'Post not found.', 404);
            }
            return ['updated' => true, 'id' => $id, 'changed' => nibwp_seopress_apply_post($id, $input)];

        case 'bulk_set':
            $items = (array) ($input['items'] ?? []);
            if ($items === []) {
                return nibwp_seopress_err('no_items', 'Provide a non-empty "items" array.');
            }
            $results = [];
            foreach ($items as $item) {
                $item = (array) $item;
                $id = (int) ($item['id'] ?? 0);
                if (!$id || !get_post($id)) {
                    $results[] = ['id' => $id, 'error' => 'not found'];
                    continue;
                }
                $results[] = ['id' => $id, 'changed' => nibwp_seopress_apply_post($id, $item)];
            }
            return ['results' => $results, 'count' => count($results)];
    }
    return nibwp_seopress_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 3) seopress-social-post — per-post Open Graph + Twitter
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/seopress-social-post', [
    'label'       => __('SEOPress — Post Social', 'nibwp'),
    'description' => __('Read/write a post\'s social preview: Open Graph (Facebook) title, description, image, and Twitter title, description, image. Actions: get, set.', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'       => ['type' => 'string', 'enum' => ['get', 'set']],
            'id'           => ['type' => 'integer'],
            'fb_title'     => ['type' => 'string'],
            'fb_desc'      => ['type' => 'string'],
            'fb_image'     => ['type' => 'string'],
            'twitter_title'=> ['type' => 'string'],
            'twitter_desc' => ['type' => 'string'],
            'twitter_image'=> ['type' => 'string'],
        ],
        'required' => ['action', 'id'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_seopress_social_post_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function nibwp_seopress_social_post_execute(array $input): array|WP_Error
{
    if (!nibwp_seopress_available()) {
        return nibwp_seopress_err('seopress_inactive', 'SEOPress is not active on this site.', 404);
    }
    $id = (int) ($input['id'] ?? 0);
    if (!get_post($id)) {
        return nibwp_seopress_err('not_found', 'Post not found.', 404);
    }
    $map = [
        'fb_title'      => '_seopress_social_fb_title',
        'fb_desc'       => '_seopress_social_fb_desc',
        'fb_image'      => '_seopress_social_fb_img',
        'twitter_title' => '_seopress_social_twitter_title',
        'twitter_desc'  => '_seopress_social_twitter_desc',
        'twitter_image' => '_seopress_social_twitter_img',
    ];
    if ((string) ($input['action'] ?? '') === 'get') {
        $out = ['id' => $id];
        foreach ($map as $field => $key) {
            $out[$field] = get_post_meta($id, $key, true);
        }
        return $out;
    }
    $changed = [];
    foreach ($map as $field => $key) {
        if (array_key_exists($field, $input)) {
            $val = strpos($field, 'image') !== false ? esc_url_raw((string) $input[$field]) : sanitize_text_field((string) $input[$field]);
            update_post_meta($id, $key, $val);
            $changed[] = $key;
        }
    }
    return ['updated' => true, 'id' => $id, 'changed' => $changed];
}

/* ----------------------------------------------------------------------------
 * 4) seopress-titles — global Titles & Metas
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/seopress-titles', [
    'label'       => __('SEOPress — Titles & Metas', 'nibwp'),
    'description' => __('Read/write the global Titles & Metas settings: the home title/description, the title separator, and per-post-type / per-taxonomy title + description templates and noindex flags. Actions: get, update. Common keys: seopress_titles_sep, seopress_titles_home_site_title, seopress_titles_home_site_desc, seopress_titles_single_titles[cpt][title|description|noindex], seopress_titles_tax_titles[tax][...].', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['get', 'update']],
            'settings' => ['type' => 'object', 'description' => 'Map of seopress_titles_* key → value, merged into the titles option.'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_seopress_titles_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false, 'instructions' => 'Call action=get first to see the exact current keys (per-post-type templates are nested arrays) before update.']],
]);

function nibwp_seopress_titles_execute(array $input): array|WP_Error
{
    return nibwp_seopress_global_module($input, 'seopress_titles_option_name');
}

/* ----------------------------------------------------------------------------
 * 5) seopress-social — global Social + Knowledge Graph
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/seopress-social', [
    'label'       => __('SEOPress — Social', 'nibwp'),
    'description' => __('Read/write the global Social settings: Open Graph defaults, the Knowledge Graph (person/organization name, type, logo), social profile URLs (Facebook, Twitter, Instagram, LinkedIn, YouTube, Pinterest), Twitter card type and the Facebook app id. Actions: get, update.', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['get', 'update']],
            'settings' => ['type' => 'object', 'description' => 'Map of seopress_social_* key → value. e.g. seopress_social_knowledge_type, seopress_social_knowledge_name, seopress_social_accounts_twitter, seopress_social_facebook_og (on).'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_seopress_social_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function nibwp_seopress_social_execute(array $input): array|WP_Error
{
    return nibwp_seopress_global_module($input, 'seopress_social_option_name');
}

/* ----------------------------------------------------------------------------
 * 6) seopress-sitemap — XML sitemap
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/seopress-sitemap', [
    'label'       => __('SEOPress — XML Sitemap', 'nibwp'),
    'description' => __('Read/write the XML & HTML sitemap settings: enable the XML sitemap, which post types and taxonomies are included, author/image/video sitemaps and the HTML sitemap. Actions: get, update. Common keys: seopress_xml_sitemap_general_enable (1), seopress_xml_sitemap_post_types_list[cpt][include], seopress_xml_sitemap_taxonomies_list[tax][include], seopress_xml_sitemap_img_enable, seopress_xml_sitemap_html_enable.', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['get', 'update']],
            'settings' => ['type' => 'object'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_seopress_sitemap_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function nibwp_seopress_sitemap_execute(array $input): array|WP_Error
{
    return nibwp_seopress_global_module($input, 'seopress_xml_sitemap_option_name');
}

/* ----------------------------------------------------------------------------
 * 7) seopress-advanced — Advanced settings
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/seopress-advanced', [
    'label'       => __('SEOPress — Advanced', 'nibwp'),
    'description' => __('Read/write the Advanced settings: search-engine verification codes (Google, Bing, Pinterest, Yandex, Baidu), header cleanup (remove WP generator, shortlink, RSD, WLW, oEmbed, X-Pingback), automatic image alt text, category URL handling and attachment redirects. Actions: get, update. Common keys: seopress_advanced_advanced_google, seopress_advanced_advanced_bing, seopress_advanced_advanced_image_auto_alt_txt, seopress_advanced_advanced_wp_generator.', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['get', 'update']],
            'settings' => ['type' => 'object'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_seopress_advanced_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function nibwp_seopress_advanced_execute(array $input): array|WP_Error
{
    return nibwp_seopress_global_module($input, 'seopress_advanced_option_name');
}

/** Shared get/update handler for a SEOPress global option module. */
function nibwp_seopress_global_module(array $input, string $optionName): array|WP_Error
{
    if (!nibwp_seopress_available()) {
        return nibwp_seopress_err('seopress_inactive', 'SEOPress is not active on this site.', 404);
    }
    $action = (string) ($input['action'] ?? '');
    if ($action === 'get') {
        return ['option' => $optionName, 'settings' => nibwp_seopress_opt($optionName)];
    }
    if ($action === 'update') {
        $patch = (array) ($input['settings'] ?? []);
        if ($patch === []) {
            return nibwp_seopress_err('no_settings', 'Provide a non-empty "settings" object.');
        }
        return ['option' => $optionName, 'updated' => nibwp_seopress_opt_merge($optionName, $patch)];
    }
    return nibwp_seopress_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 8) seopress-toggles — enable/disable SEOPress modules
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/seopress-toggles', [
    'label'       => __('SEOPress — Feature Toggles', 'nibwp'),
    'description' => __('Read/write which SEOPress modules are enabled (the seopress_toggle option). Actions: get, set. Toggle keys include toggle-titles, toggle-xml-sitemap, toggle-social, toggle-advanced, toggle-robots, toggle-breadcrumbs, toggle-404, toggle-instant-indexing, toggle-rich-snippets, toggle-google-analytics.', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['get', 'set']],
            'toggles' => ['type' => 'object', 'description' => 'Map of toggle key → boolean/"1"/"0".'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_seopress_toggles_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function nibwp_seopress_toggles_execute(array $input): array|WP_Error
{
    if (!nibwp_seopress_available()) {
        return nibwp_seopress_err('seopress_inactive', 'SEOPress is not active on this site.', 404);
    }
    $action = (string) ($input['action'] ?? '');
    $toggles = get_option('seopress_toggle', []);
    $toggles = is_array($toggles) ? $toggles : [];

    if ($action === 'get') {
        return ['toggles' => $toggles];
    }
    if ($action === 'set') {
        $patch = (array) ($input['toggles'] ?? []);
        if ($patch === []) {
            return nibwp_seopress_err('no_toggles', 'Provide a non-empty "toggles" object.');
        }
        $changed = [];
        foreach ($patch as $k => $v) {
            $toggles[(string) $k] = (!empty($v) && $v !== '0') ? '1' : '0';
            $changed[] = (string) $k;
        }
        update_option('seopress_toggle', $toggles);
        return ['updated' => $changed, 'toggles' => $toggles];
    }
    return nibwp_seopress_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 9) seopress-redirections — per-post redirects (Pro)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/seopress-redirections', [
    'label'       => __('SEOPress — Redirections (Pro)', 'nibwp'),
    'description' => __('Manage SEOPress Pro redirections (stored per redirect post). Actions: list, get, set, disable. A redirect targets a URL with an HTTP type (301, 302, 307, 410, 451).', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'get', 'set', 'disable']],
            'id'      => ['type' => 'integer', 'description' => 'The redirect post id (post_type seopress_404).'],
            'value'   => ['type' => 'string', 'description' => 'Target URL.'],
            'type'    => ['type' => 'string', 'enum' => ['301', '302', '307', '410', '451'], 'default' => '301'],
            'enabled' => ['type' => 'boolean', 'default' => true],
            'per_page'=> ['type' => 'integer'],
            'page'    => ['type' => 'integer'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_seopress_redirections_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false, 'instructions' => 'SEOPress Pro stores redirects as a custom post type. Run list/get first to confirm the post type + meta keys on this version; the _seopress_redirections_* meta is set on the redirect post.']],
]);

function nibwp_seopress_redirections_execute(array $input): array|WP_Error
{
    if (!nibwp_seopress_available()) {
        return nibwp_seopress_err('seopress_inactive', 'SEOPress is not active on this site.', 404);
    }
    $cpt = post_type_exists('seopress_404') ? 'seopress_404' : (post_type_exists('seopress_redirections') ? 'seopress_redirections' : '');
    if ($cpt === '') {
        return nibwp_seopress_err('redirects_unavailable', 'SEOPress redirections are not available — this is a SEOPress Pro feature.', 404);
    }
    $action = (string) ($input['action'] ?? '');
    $per  = min(max((int) ($input['per_page'] ?? 50), 1), 100);
    $page = max((int) ($input['page'] ?? 1), 1);

    switch ($action) {
        case 'list':
            $items = [];
            foreach (get_posts(['post_type' => $cpt, 'post_status' => ['publish', 'draft'], 'numberposts' => $per, 'paged' => $page]) as $p) {
                $items[] = [
                    'id'      => $p->ID,
                    'title'   => $p->post_title,
                    'enabled' => get_post_meta($p->ID, '_seopress_redirections_enabled', true) === 'yes',
                    'type'    => get_post_meta($p->ID, '_seopress_redirections_type', true),
                    'value'   => get_post_meta($p->ID, '_seopress_redirections_value', true),
                ];
            }
            return ['redirections' => $items, 'count' => count($items), 'cpt' => $cpt];

        case 'get':
            $id = (int) ($input['id'] ?? 0);
            if (get_post_type($id) !== $cpt) {
                return nibwp_seopress_err('not_found', 'Redirect not found.', 404);
            }
            return ['id' => $id, 'meta' => nibwp_seopress_dump_meta($id), 'title' => get_post($id)->post_title];

        case 'set':
            $id = (int) ($input['id'] ?? 0);
            if ($id && get_post_type($id) !== $cpt) {
                return nibwp_seopress_err('not_found', 'Redirect not found.', 404);
            }
            if (!$id) {
                $id = (int) wp_insert_post(['post_type' => $cpt, 'post_status' => 'publish', 'post_title' => sanitize_text_field((string) ($input['value'] ?? 'redirect'))]);
            }
            update_post_meta($id, '_seopress_redirections_enabled', !empty($input['enabled']) ? 'yes' : '');
            if (isset($input['value'])) { update_post_meta($id, '_seopress_redirections_value', esc_url_raw((string) $input['value'])); }
            update_post_meta($id, '_seopress_redirections_type', in_array($input['type'] ?? '301', ['301', '302', '307', '410', '451'], true) ? (string) $input['type'] : '301');
            return ['updated' => true, 'id' => $id, 'meta' => nibwp_seopress_dump_meta($id)];

        case 'disable':
            $id = (int) ($input['id'] ?? 0);
            if (get_post_type($id) !== $cpt) {
                return nibwp_seopress_err('not_found', 'Redirect not found.', 404);
            }
            update_post_meta($id, '_seopress_redirections_enabled', '');
            return ['disabled' => true, 'id' => $id];
    }
    return nibwp_seopress_err('bad_action', 'Unknown action: ' . $action);
}
