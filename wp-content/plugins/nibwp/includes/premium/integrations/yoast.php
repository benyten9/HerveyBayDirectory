<?php

declare(strict_types=1);

/**
 * Yoast SEO integration — NIBWP MCP abilities (Layer 1 adapter).
 *
 * Seven abilities: info, per-post SEO, bulk read/write across a query, the
 * title + meta templates, taxonomy term SEO, Premium redirects, and an audit.
 *
 * Mechanism is Yoast's own documented surfaces, in this order of preference:
 *
 *   WPSEO_Meta::get_value( $key, $post_id )          per-post read (key has NO
 *   WPSEO_Meta::set_value( $key, $value, $post_id )  prefix; returns/accepts
 *                                                    strings, applies Yoast's
 *                                                    own validation)
 *   WPSEO_Taxonomy_Meta::get_term_meta( $term, $taxonomy, $meta = null )
 *   WPSEO_Taxonomy_Meta::set_values( $term_id, $taxonomy, array $values )
 *   WPSEO_Options::get( $key, $default, $groups )     one key across groups
 *   WPSEO_Options::set( $key, $value )                routes the key to its own
 *                                                     option group and validates
 *   YoastSEO()->meta->for_post( $id )                 the RENDERED values —
 *                                                     ->title, ->description,
 *                                                     ->canonical, ->robots,
 *                                                     ->open_graph_title, …
 *
 * Every one of those is guarded with class_exists / method_exists and falls
 * back to plain post meta or get_option, because a surface that moved must
 * degrade rather than fatal.
 *
 * Three things about Yoast that decide the shape of this file:
 *
 *   1. `_yoast_wpseo_meta-robots-noindex` is NOT a boolean. Yoast's own field
 *      definition gives it three values: '0' = follow the post type's default,
 *      '2' = index, '1' = noindex. Reading it as a boolean cannot tell "the
 *      site default happens to be index" from "this post is explicitly
 *      indexed", and writing it as a boolean silently throws away the third
 *      state. It is exposed here as `index: default|index|noindex`.
 *   2. Yoast DELETES a meta row whose value equals its default, through the
 *      `update_post_metadata` / `add_post_metadata` filters it registers, and
 *      the comparison is `===` against a STRING. So every value written here is
 *      cast to a string first: handing it an int 0 writes a junk row that
 *      Yoast's own reader then cannot recognise as the default.
 *   3. The social meta fields (`opengraph-*`, `twitter-*`) are only registered
 *      — and therefore only sanitised and only default-pruned — when the
 *      matching `wpseo_social` switch (`opengraph` / `twitter`) is on. With the
 *      switch off the keys still accept writes but Yoast never outputs them.
 *      Both states are reported rather than hidden.
 *
 * Indexables: recent Yoast keeps a derived copy of every object in its own
 * `{prefix}yoast_indexable` table. Nothing here writes to it — post meta and
 * the options are the source of truth and the indexable is rebuilt from them.
 * It is read in exactly one place, the audit's orphaned-content check, because
 * `incoming_link_count` cannot be recomputed without crawling every post's
 * content. That read is gated on the table existing AND on Yoast's text link
 * counter being enabled, because the column is only maintained while it is —
 * with the counter off every post reads as orphaned.
 *
 * Detection: WPSEO_VERSION or the WPSEO_Options class. Premium (redirects)
 * gates separately on WPSEO_PREMIUM_VERSION / WPSEO_PREMIUM_FILE.
 *
 * Verified against the meta, option and surface definitions in Yoast SEO's own
 * source: WPSEO_Meta::$meta_fields, WPSEO_Taxonomy_Meta::$defaults_per_term,
 * WPSEO_Option_Titles / WPSEO_Option_Social / WPSEO_Option_Wpseo defaults, and
 * Yoast\WP\SEO\Surfaces\Values\Meta.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** The prefix on every Yoast post-meta key. WPSEO_Meta::$meta_prefix. */
const NIBWP_YOAST_META_PREFIX = '_yoast_wpseo_';

/** Yoast's three option groups. */
const NIBWP_YOAST_OPT_GENERAL = 'wpseo';
const NIBWP_YOAST_OPT_TITLES = 'wpseo_titles';
const NIBWP_YOAST_OPT_SOCIAL = 'wpseo_social';

/** The option holding every term's SEO data, keyed taxonomy => term id. */
const NIBWP_YOAST_OPT_TAXONOMY = 'wpseo_taxonomy_meta';

/** Yoast's derived index. Read for orphaned content only, never written. */
const NIBWP_YOAST_TABLE_INDEXABLE = 'yoast_indexable';

/**
 * Yoast's own meta-description length limit, in characters.
 *
 * WPSEO_Meta::$meta_length. Read off the class when it is loaded so the audit
 * agrees with the editor's own counter rather than with a number frozen here.
 */
const NIBWP_YOAST_DESC_MAX = 156;

/**
 * The character count at which a title is reported as likely to be truncated.
 *
 * Yoast measures the title in PIXELS (its limit is 600px), which cannot be
 * reproduced outside a browser. This is a character-count approximation of that
 * limit and the audit finding says so, rather than claiming Yoast's own verdict.
 */
const NIBWP_YOAST_TITLE_MAX = 60;

/* ----------------------------------------------------------------------------
 * Shared helpers (file-local)
 * ------------------------------------------------------------------------- */

/** Is Yoast SEO active? */
function nibwp_yoast_available(): bool
{
    return defined('WPSEO_VERSION') || class_exists('WPSEO_Options');
}

/** Is Yoast SEO Premium active? Redirects are a Premium-only feature. */
function nibwp_yoast_premium(): bool
{
    return defined('WPSEO_PREMIUM_VERSION') || defined('WPSEO_PREMIUM_FILE');
}

/** House WP_Error wrapper. */
function nibwp_yoast_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error($code, $message, ['status' => $status]);
}

/** The guard every callback opens with. */
function nibwp_yoast_guard(): ?WP_Error
{
    if (!nibwp_yoast_available()) {
        return nibwp_yoast_err('nibwp_yoast_missing', __('Yoast SEO is not active on this site.', 'nibwp'), 404);
    }

    return null;
}

/** Run a call against Yoast, converting throwables into WP_Error. */
function nibwp_yoast_try(callable $fn, string $code = 'nibwp_yoast_failed')
{
    try {
        return $fn();
    } catch (\Throwable $e) {
        return nibwp_yoast_err($code, $e->getMessage());
    }
}

/**
 * Every post-meta key this adapter is allowed to read or write, WITHOUT the
 * `_yoast_wpseo_` prefix.
 *
 * This is the whole contract with Yoast's database. nibwp_yoast_meta_set()
 * refuses anything outside it, so a typo or a future field cannot land a key
 * Yoast does not recognise — an unrecognised key is not sanitised, not pruned
 * when it holds the default, and invisible in Yoast's own editor.
 *
 * Taken from WPSEO_Meta::$meta_fields. The social entries appear there only
 * when the matching wpseo_social switch is on; they are listed unconditionally
 * here because the key itself does not change with the switch.
 *
 * @return array<int, string>
 */
function nibwp_yoast_meta_keys(): array
{
    return [
        // general
        'title',
        'metadesc',
        'focuskw',
        'is_cornerstone',
        // advanced
        'meta-robots-noindex',
        'meta-robots-nofollow',
        'meta-robots-adv',
        'canonical',
        'bctitle',
        // social
        'opengraph-title',
        'opengraph-description',
        'opengraph-image',
        'opengraph-image-id',
        'twitter-title',
        'twitter-description',
        'twitter-image',
        'twitter-image-id',
        // schema
        'schema_page_type',
        'schema_article_type',
    ];
}

/**
 * Read-only meta keys Yoast's own analysis writes.
 *
 * Reported by the post read so an agent can see the editor's scores, never
 * written: they are the output of Yoast's analysis, and a number we invented
 * would be indistinguishable from one it measured.
 *
 * @return array<string, string>
 */
function nibwp_yoast_score_keys(): array
{
    return [
        'seo'                => 'linkdex',
        'readability'        => 'content_score',
        'inclusive_language' => 'inclusive_language_score',
    ];
}

/**
 * The plain field names this adapter exposes, mapped to Yoast's meta key and
 * how the value is cleaned.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function nibwp_yoast_post_fields(): array
{
    return [
        'title'               => ['title', 'text'],
        'metadesc'            => ['metadesc', 'textarea'],
        'focuskw'             => ['focuskw', 'text'],
        'canonical'           => ['canonical', 'url'],
        'breadcrumb_title'    => ['bctitle', 'text'],
        'og_title'            => ['opengraph-title', 'text'],
        'og_description'      => ['opengraph-description', 'textarea'],
        'og_image'            => ['opengraph-image', 'url'],
        'og_image_id'         => ['opengraph-image-id', 'int'],
        'twitter_title'       => ['twitter-title', 'text'],
        'twitter_description' => ['twitter-description', 'textarea'],
        'twitter_image'       => ['twitter-image', 'url'],
        'twitter_image_id'    => ['twitter-image-id', 'int'],
        'schema_page_type'    => ['schema_page_type', 'text'],
        'schema_article_type' => ['schema_article_type', 'text'],
    ];
}

/** The three values Yoast allows in meta-robots-adv. */
function nibwp_yoast_robots_adv_options(): array
{
    return ['noimageindex', 'noarchive', 'nosnippet'];
}

/**
 * Read one Yoast post-meta value as a string.
 *
 * WPSEO_Meta::get_value() is Yoast's own reader and returns the field's DEFAULT
 * when no row exists — '0' for robots-noindex, 'false' for is_cornerstone —
 * which is what the decoders below expect. The fallback reads the row directly
 * and yields '' instead, so every decoder treats '' as "default" too.
 */
function nibwp_yoast_meta_get(int $post_id, string $key): string
{
    if (class_exists('WPSEO_Meta') && method_exists('WPSEO_Meta', 'get_value')) {
        $value = WPSEO_Meta::get_value($key, $post_id);

        return is_scalar($value) ? (string) $value : '';
    }

    $value = get_post_meta($post_id, NIBWP_YOAST_META_PREFIX . $key, true);

    return is_scalar($value) ? (string) $value : '';
}

/**
 * Write one Yoast post-meta value.
 *
 * Always through WPSEO_Meta::set_value() when it exists: it slashes the value
 * because update_metadata() will unslash it, so calling update_post_meta()
 * directly mangles anything containing a backslash or a quote. The fallback
 * does the same slashing by hand.
 *
 * The value is a string on purpose — Yoast compares against its defaults with
 * `===`, so an int 0 does not read as the default '0' and leaves a row behind
 * that its own reader treats as an unset field.
 *
 * @return bool True when the key was recognised and the write attempted.
 */
function nibwp_yoast_meta_set(int $post_id, string $key, string $value): bool
{
    if (!in_array($key, nibwp_yoast_meta_keys(), true)) {
        return false;
    }

    if (class_exists('WPSEO_Meta') && method_exists('WPSEO_Meta', 'set_value')) {
        WPSEO_Meta::set_value($key, $value, $post_id);

        return true;
    }

    update_post_meta($post_id, NIBWP_YOAST_META_PREFIX . $key, wp_slash($value));

    return true;
}

/** Clean an incoming value for the field kind it belongs to. */
function nibwp_yoast_clean(string $kind, mixed $value): string
{
    return match ($kind) {
        'textarea' => sanitize_textarea_field((string) $value),
        'url'      => esc_url_raw((string) $value),
        'int'      => ((int) $value) > 0 ? (string) (int) $value : '',
        default    => sanitize_text_field((string) $value),
    };
}

/** Decode meta-robots-noindex into its three real states. */
function nibwp_yoast_decode_index(string $raw): string
{
    return match ($raw) {
        '1' => 'noindex',
        '2' => 'index',
        default => 'default',
    };
}

/** Encode index|noindex|default back to Yoast's stored value. */
function nibwp_yoast_encode_index(string $name): ?string
{
    return match ($name) {
        'noindex' => '1',
        'index'   => '2',
        'default' => '0',
        default   => null,
    };
}

/**
 * Decode meta-robots-adv.
 *
 * Stored comma-joined. Older Yoast wrote 'none' or '-' for "nothing selected";
 * filtering to the three valid options drops those without a version check.
 *
 * @return array<int, string>
 */
function nibwp_yoast_decode_robots_adv(string $raw): array
{
    $parts = array_map('trim', explode(',', $raw));

    return array_values(array_intersect($parts, nibwp_yoast_robots_adv_options()));
}

/** Read one Yoast setting, whichever option group it lives in. */
function nibwp_yoast_opt(string $key, mixed $fallback = null): mixed
{
    if (class_exists('WPSEO_Options') && method_exists('WPSEO_Options', 'get')) {
        $value = WPSEO_Options::get($key, null);
        if ($value !== null) {
            return $value;
        }
    }

    foreach ([NIBWP_YOAST_OPT_GENERAL, NIBWP_YOAST_OPT_TITLES, NIBWP_YOAST_OPT_SOCIAL] as $group) {
        $option = nibwp_yoast_group($group);
        if (array_key_exists($key, $option)) {
            return $option[$key];
        }
    }

    return $fallback;
}

/** One of Yoast's option groups, straight off the row. */
function nibwp_yoast_group(string $name): array
{
    $value = get_option($name, []);

    return is_array($value) ? $value : [];
}

/**
 * Write one Yoast setting.
 *
 * WPSEO_Options::set() resolves which option group owns the key and runs that
 * group's validation. A key it does not recognise is only cached in memory and
 * never stored, which is why every caller here verifies the write by re-reading
 * the option row rather than trusting the call.
 */
function nibwp_yoast_opt_set(string $key, mixed $value): bool
{
    if (!class_exists('WPSEO_Options') || !method_exists('WPSEO_Options', 'set')) {
        return false;
    }

    WPSEO_Options::set($key, $value);

    return true;
}

/**
 * The key shapes the templates ability is allowed to write into wpseo_titles.
 *
 * Yoast enriches that option with one key per registered post type and
 * taxonomy, so the writable set cannot be a fixed list. These are the prefixes
 * from WPSEO_Option_Titles' own variable-key patterns and enriched defaults,
 * minus the bookkeeping ones (`post_types-`, `taxonomy-`, `org-`) that hold
 * primary-taxonomy and organisation state rather than templates.
 *
 * @return array<int, string>
 */
function nibwp_yoast_title_key_prefixes(): array
{
    return [
        'title-',
        'metadesc-',
        'noindex-',
        'bctitle-ptarchive-',
        'display-metabox-pt-',
        'display-metabox-tax-',
        'schema-page-type-',
        'schema-article-type-',
        'social-title-',
        'social-description-',
        'social-image-url-',
        'social-image-id-',
        'breadcrumbs-',
    ];
}

/** wpseo_titles keys that are not prefixed patterns. */
function nibwp_yoast_title_key_exact(): array
{
    return ['separator', 'forcerewritetitle'];
}

/** May this key be written into wpseo_titles? */
function nibwp_yoast_title_key_allowed(string $key): bool
{
    if (in_array($key, nibwp_yoast_title_key_exact(), true)) {
        return true;
    }

    foreach (nibwp_yoast_title_key_prefixes() as $prefix) {
        if (strpos($key, $prefix) === 0) {
            return true;
        }
    }

    return false;
}

/**
 * Character length, counting a multibyte character once.
 *
 * mbstring is not guaranteed to be compiled in, and strlen() on a UTF-8 title
 * counts bytes — which would report a perfectly short title with an em dash or
 * an accent in it as over the limit.
 */
function nibwp_yoast_strlen(string $value): int
{
    return function_exists('mb_strlen') ? (int) mb_strlen($value, 'UTF-8') : strlen($value);
}

/** Clamp pagination to the house 1..100 window. */
function nibwp_yoast_paginate(array $in): array
{
    $per_page = min(max((int) ($in['per_page'] ?? 20), 1), 100);
    $page = max((int) ($in['page'] ?? 1), 1);

    return ['per_page' => $per_page, 'page' => $page];
}

/** Yoast's indexable table name, or '' when the table is not there. */
function nibwp_yoast_indexable_table(): string
{
    global $wpdb;

    if (!isset($wpdb) || !is_object($wpdb)) {
        return '';
    }

    $table = class_exists('Yoast\\WP\\Lib\\Model') && method_exists('Yoast\\WP\\Lib\\Model', 'get_table_name')
        ? (string) Yoast\WP\Lib\Model::get_table_name('Indexable')
        : $wpdb->prefix . NIBWP_YOAST_TABLE_INDEXABLE;

    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

    return $found === $table ? $table : '';
}

/**
 * Yoast's rendered meta for one post, or null.
 *
 * YoastSEO()->meta->for_post() returns what Yoast will actually output — title
 * and description with their %%variables%% already replaced, the resolved
 * canonical, the robots array. It returns false for a post with no indexable
 * yet, and it builds a whole rendering context, so it is optional everywhere it
 * is used.
 *
 * @return array<string, mixed>|null
 */
function nibwp_yoast_rendered(int $post_id): ?array
{
    if (!function_exists('YoastSEO')) {
        return null;
    }

    try {
        $api = YoastSEO();
        if (!is_object($api) || !isset($api->meta) || !is_object($api->meta) || !method_exists($api->meta, 'for_post')) {
            return null;
        }

        $meta = $api->meta->for_post($post_id);
        if (!is_object($meta)) {
            return null;
        }

        return [
            'title'               => (string) ($meta->title ?? ''),
            'description'         => (string) ($meta->description ?? ''),
            'canonical'           => (string) ($meta->canonical ?? ''),
            'robots'              => (array) ($meta->robots ?? []),
            'og_title'            => (string) ($meta->open_graph_title ?? ''),
            'og_description'      => (string) ($meta->open_graph_description ?? ''),
            'twitter_title'       => (string) ($meta->twitter_title ?? ''),
            'twitter_description' => (string) ($meta->twitter_description ?? ''),
            'twitter_image'       => (string) ($meta->twitter_image ?? ''),
            'schema_page_type'    => (string) ($meta->schema_page_type ?? ''),
        ];
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * One post's stored Yoast fields, decoded.
 *
 * @return array<string, mixed>
 */
function nibwp_yoast_read_post(int $post_id): array
{
    $out = [];
    foreach (nibwp_yoast_post_fields() as $field => $spec) {
        $out[$field] = nibwp_yoast_meta_get($post_id, $spec[0]);
    }

    $out['robots'] = [
        'index'    => nibwp_yoast_decode_index(nibwp_yoast_meta_get($post_id, 'meta-robots-noindex')),
        'nofollow' => nibwp_yoast_meta_get($post_id, 'meta-robots-nofollow') === '1',
        'advanced' => nibwp_yoast_decode_robots_adv(nibwp_yoast_meta_get($post_id, 'meta-robots-adv')),
    ];
    // '1' is the only "on" value: Yoast casts the legacy 'true' to '1' on save
    // and prunes the row when it holds the default 'false'.
    $out['cornerstone'] = nibwp_yoast_meta_get($post_id, 'is_cornerstone') === '1';

    $scores = [];
    foreach (nibwp_yoast_score_keys() as $name => $key) {
        $scores[$name] = nibwp_yoast_meta_get($post_id, $key);
    }
    $out['scores'] = $scores;

    return $out;
}

/**
 * Apply a field set to one post.
 *
 * `$overwrite` false only fills fields that are currently empty, which is what
 * a bulk "write the missing descriptions" pass wants — the destructive version
 * of that same call replaces work somebody did by hand.
 *
 * @return array<string, mixed> changed meta keys, skipped fields and notes.
 */
function nibwp_yoast_write_post(int $post_id, array $in, bool $overwrite = true, bool $dry_run = false): array
{
    $changed = [];
    $skipped = [];
    $rejected = [];

    $apply = static function (string $key, string $value) use ($post_id, $dry_run, &$changed): void {
        if (!$dry_run) {
            nibwp_yoast_meta_set($post_id, $key, $value);
        }
        $changed[] = NIBWP_YOAST_META_PREFIX . $key;
    };

    foreach (nibwp_yoast_post_fields() as $field => $spec) {
        if (!array_key_exists($field, $in)) {
            continue;
        }

        [$key, $kind] = $spec;
        $value = nibwp_yoast_clean($kind, $in[$field]);

        if (!$overwrite && nibwp_yoast_meta_get($post_id, $key) !== '') {
            $skipped[] = $field;
            continue;
        }

        $apply($key, $value);
    }

    if (array_key_exists('index', $in)) {
        $encoded = nibwp_yoast_encode_index((string) $in['index']);
        if ($encoded === null) {
            $rejected['index'] = __('index must be one of default, index, noindex.', 'nibwp');
        } elseif ($overwrite || nibwp_yoast_meta_get($post_id, 'meta-robots-noindex') === '0') {
            $apply('meta-robots-noindex', $encoded);
        } else {
            $skipped[] = 'index';
        }
    }

    if (array_key_exists('nofollow', $in)) {
        if ($overwrite || nibwp_yoast_meta_get($post_id, 'meta-robots-nofollow') !== '1') {
            $apply('meta-robots-nofollow', !empty($in['nofollow']) ? '1' : '0');
        } else {
            $skipped[] = 'nofollow';
        }
    }

    if (array_key_exists('advanced', $in)) {
        $wanted = array_values(array_intersect(
            array_map('strval', (array) $in['advanced']),
            nibwp_yoast_robots_adv_options()
        ));
        if ($overwrite || nibwp_yoast_meta_get($post_id, 'meta-robots-adv') === '') {
            $apply('meta-robots-adv', implode(',', $wanted));
        } else {
            $skipped[] = 'advanced';
        }
    }

    if (array_key_exists('cornerstone', $in)) {
        // 'false' is the field's default, so writing it makes Yoast delete the
        // row — which is exactly what "not cornerstone" means to Yoast.
        $apply('is_cornerstone', !empty($in['cornerstone']) ? '1' : 'false');
    }

    // A social field written while its network is switched off is stored and
    // never rendered — and, because Yoast only registers those meta keys when
    // the switch is on, it is not sanitised or default-pruned either. Saying so
    // is the difference between a silent no-op and a fixable one.
    $touched = static function (array $keys, string $group): bool {
        foreach ($keys as $key) {
            if (strpos($key, NIBWP_YOAST_META_PREFIX . $group) === 0) {
                return true;
            }
        }

        return false;
    };

    $notes = [];
    if ($touched($changed, 'opengraph-') && nibwp_yoast_opt('opengraph', true) !== true) {
        $notes[] = __('Open Graph output is switched off in Yoast\'s social settings, so these values are stored but not rendered.', 'nibwp');
    }
    if ($touched($changed, 'twitter-') && nibwp_yoast_opt('twitter', true) !== true) {
        $notes[] = __('X/Twitter card output is switched off in Yoast\'s social settings, so these values are stored but not rendered.', 'nibwp');
    }

    return [
        'post_id'  => $post_id,
        'changed'  => $changed,
        'skipped'  => $skipped,
        'rejected' => $rejected,
        'notes'    => $notes,
    ];
}

/** The post types worth auditing: public, and not the media library. */
function nibwp_yoast_public_post_types(): array
{
    $types = get_post_types(['public' => true], 'names');
    $types = is_array($types) ? array_values($types) : [];

    return array_values(array_filter($types, static fn(string $t): bool => $t !== 'attachment'));
}

/* ── Ability 1 — info ───────────────────────────────────────────────── */

wp_register_ability('nibwp/yoast-info', [
    'label'       => __('Yoast SEO — Info', 'nibwp'),
    'description' => 'Detect Yoast SEO and Yoast SEO Premium, their versions, which Yoast features are switched on (keyphrase and readability analysis, cornerstone content, XML sitemap, text link counter, Open Graph, X/Twitter cards), whether the indexable table and Premium redirects are available, and the Yoast meta keys this integration reads and writes (read-only).',
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'active'      => ['type' => 'boolean'],
            'version'     => ['type' => 'string'],
            'premium'     => ['type' => 'boolean'],
            'features'    => ['type' => 'object'],
            'meta_prefix' => ['type' => 'string'],
            'meta_keys'   => ['type' => 'array', 'items' => ['type' => 'string']],
            'abilities'   => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['active', 'version', 'premium', 'features', 'meta_keys'],
    ],
    'execute_callback'    => 'nibwp_yoast_info',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'instructions' => implode("\n", [
                'Call this first. Two of the flags it reports change what the other abilities can honestly answer.',
                'opengraph / twitter off means Yoast registers no social meta fields: the og_* and twitter_* values can still be stored but nothing renders them, and nibwp/yoast-post says so when it writes one.',
                'enable_text_link_counter off means incoming_link_count is not maintained, so the audit cannot look for orphaned content and reports that check as skipped rather than passing every post.',
                'keyword_analysis_active off means a missing focus keyphrase is not a fault on this site, and the audit leaves it alone.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_yoast_info(array $input): array|WP_Error
{
    if ($guard = nibwp_yoast_guard()) {
        return $guard;
    }

    return nibwp_yoast_try(static function (): array {
        $table = nibwp_yoast_indexable_table();

        return [
            'active'      => true,
            'version'     => defined('WPSEO_VERSION') ? (string) WPSEO_VERSION : '',
            'premium'     => nibwp_yoast_premium(),
            'premium_version' => defined('WPSEO_PREMIUM_VERSION') ? (string) WPSEO_PREMIUM_VERSION : '',
            'features'    => [
                'keyword_analysis_active'  => nibwp_yoast_opt('keyword_analysis_active', null),
                'content_analysis_active'  => nibwp_yoast_opt('content_analysis_active', null),
                'inclusive_language_analysis_active' => nibwp_yoast_opt('inclusive_language_analysis_active', null),
                'enable_cornerstone_content' => nibwp_yoast_opt('enable_cornerstone_content', null),
                'enable_xml_sitemap'       => nibwp_yoast_opt('enable_xml_sitemap', null),
                'enable_text_link_counter' => nibwp_yoast_opt('enable_text_link_counter', null),
                'opengraph'                => nibwp_yoast_opt('opengraph', null),
                'twitter'                  => nibwp_yoast_opt('twitter', null),
                'twitter_card_type'        => nibwp_yoast_opt('twitter_card_type', null),
                'separator'                => nibwp_yoast_opt('separator', null),
                'breadcrumbs-enable'       => nibwp_yoast_opt('breadcrumbs-enable', null),
            ],
            'option_groups' => [
                NIBWP_YOAST_OPT_GENERAL  => get_option(NIBWP_YOAST_OPT_GENERAL, null) !== null,
                NIBWP_YOAST_OPT_TITLES   => get_option(NIBWP_YOAST_OPT_TITLES, null) !== null,
                NIBWP_YOAST_OPT_SOCIAL   => get_option(NIBWP_YOAST_OPT_SOCIAL, null) !== null,
                NIBWP_YOAST_OPT_TAXONOMY => get_option(NIBWP_YOAST_OPT_TAXONOMY, null) !== null,
            ],
            'surfaces' => [
                'wpseo_meta'        => class_exists('WPSEO_Meta'),
                'wpseo_options'     => class_exists('WPSEO_Options'),
                'wpseo_taxonomy_meta' => class_exists('WPSEO_Taxonomy_Meta'),
                'yoastseo_helper'   => function_exists('YoastSEO'),
                'indexable_table'   => $table !== '' ? $table : '',
                'redirects'         => nibwp_yoast_premium() && class_exists('WPSEO_Redirect_Option'),
            ],
            'meta_prefix' => NIBWP_YOAST_META_PREFIX,
            'meta_keys'   => array_map(
                static fn(string $key): string => NIBWP_YOAST_META_PREFIX . $key,
                nibwp_yoast_meta_keys()
            ),
            'abilities'   => ['post', 'bulk', 'templates', 'term', 'redirects', 'audit'],
            'note'        => __('Yoast keeps a derived copy of every object in its own indexable table. This integration writes post meta and options only and lets Yoast rebuild the index from them.', 'nibwp'),
        ];
    });
}

/* ── Ability 2 — post ───────────────────────────────────────────────── */

wp_register_ability('nibwp/yoast-post', [
    'label'       => __('Yoast SEO — Post SEO', 'nibwp'),
    'description' => 'Read or write one post\'s Yoast SEO fields: SEO title, meta description, focus keyphrase, canonical URL, robots (index/nofollow/advanced), Open Graph title, description and image, X/Twitter title, description and image, the cornerstone flag, the breadcrumb title and the schema page and article types. The read also reports Yoast\'s rendered output and its analysis scores. Actions: get, set.',
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'    => ['type' => 'string', 'enum' => ['get', 'set'], 'description' => 'get reads, set writes.'],
            'post_id'   => ['type' => 'integer', 'description' => 'The post, page or custom post type item to read or write.'],
            'title'     => ['type' => 'string', 'description' => 'SEO title. May use Yoast %%variables%%. Empty clears it back to the post type template.'],
            'metadesc'  => ['type' => 'string', 'description' => 'Meta description.'],
            'focuskw'   => ['type' => 'string', 'description' => 'Focus keyphrase.'],
            'canonical' => ['type' => 'string', 'description' => 'Canonical URL override. Empty clears it.'],
            'breadcrumb_title' => ['type' => 'string', 'description' => 'Title used in the breadcrumb trail.'],
            'index'     => ['type' => 'string', 'enum' => ['default', 'index', 'noindex'], 'description' => 'Yoast stores three states, not a boolean: default follows the post type setting, index forces indexing, noindex excludes the post.'],
            'nofollow'  => ['type' => 'boolean', 'description' => 'true adds nofollow.'],
            'advanced'  => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['noimageindex', 'noarchive', 'nosnippet']], 'description' => 'Advanced robots directives. An empty array clears them.'],
            'cornerstone' => ['type' => 'boolean', 'description' => 'Mark the post as cornerstone content.'],
            'og_title'  => ['type' => 'string'],
            'og_description' => ['type' => 'string'],
            'og_image'  => ['type' => 'string', 'description' => 'Open Graph image URL.'],
            'og_image_id' => ['type' => 'integer', 'description' => 'Attachment ID of the Open Graph image.'],
            'twitter_title' => ['type' => 'string'],
            'twitter_description' => ['type' => 'string'],
            'twitter_image' => ['type' => 'string', 'description' => 'X/Twitter card image URL.'],
            'twitter_image_id' => ['type' => 'integer'],
            'schema_page_type' => ['type' => 'string', 'description' => 'Schema page type, e.g. WebPage, AboutPage, FAQPage.'],
            'schema_article_type' => ['type' => 'string', 'description' => 'Schema article type, e.g. Article, BlogPosting, NewsArticle.'],
            'rendered'  => ['type' => 'boolean', 'default' => true, 'description' => 'On a get, also ask Yoast for the rendered output. Turn off on a slow site.'],
        ],
        'required' => ['action', 'post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_yoast_post',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'instructions' => implode("\n", [
                'index is the field most often got wrong. Yoast stores three states and a boolean cannot express them: "default" means the post follows its post type setting, so switching a noindexed post back to "default" is not the same as forcing "index".',
                'An empty string clears a field. Yoast deletes a meta row whose value is the field default, so clearing the SEO title genuinely hands the page back to the post type template rather than leaving an empty title.',
                'stored is what is in the database; rendered is what Yoast will output, with %%variables%% already replaced. A page with no stored title still has a rendered one, which is why an empty stored title is not by itself a fault.',
                'scores are read-only: they are the output of Yoast\'s own analysis and there is no honest way to set one from here.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_yoast_post(array $input): array|WP_Error
{
    if ($guard = nibwp_yoast_guard()) {
        return $guard;
    }

    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0) {
        return nibwp_yoast_err('nibwp_yoast_bad_id', __('A valid post ID is required.', 'nibwp'));
    }

    $action = (string) ($input['action'] ?? '');
    if (!in_array($action, ['get', 'set'], true)) {
        return nibwp_yoast_err('nibwp_yoast_bad_action', __('action must be get or set.', 'nibwp'));
    }

    return nibwp_yoast_try(static function () use ($action, $post_id, $input) {
        $post = get_post($post_id);
        if (!$post) {
            return nibwp_yoast_err('nibwp_yoast_not_found', __('No post with that ID.', 'nibwp'), 404);
        }

        if ($action === 'set') {
            $result = nibwp_yoast_write_post($post_id, $input, true, false);
            $result['stored'] = nibwp_yoast_read_post($post_id);

            return $result;
        }

        $out = [
            'post_id'     => $post_id,
            'post_title'  => (string) ($post->post_title ?? ''),
            'post_type'   => (string) ($post->post_type ?? ''),
            'post_status' => (string) ($post->post_status ?? ''),
            'stored'      => nibwp_yoast_read_post($post_id),
        ];

        $out['rendered'] = ($input['rendered'] ?? true) ? nibwp_yoast_rendered($post_id) : null;

        return $out;
    });
}

/* ── Ability 3 — bulk ───────────────────────────────────────────────── */

wp_register_ability('nibwp/yoast-bulk', [
    'label'       => __('Yoast SEO — Bulk', 'nibwp'),
    'description' => 'Read or write Yoast SEO fields across a query rather than one post at a time. Filter by post type, post status, taxonomy term, search text, or by what is missing (no meta description, no SEO title, no focus keyphrase, no canonical), plus the cornerstone and noindex flags. Actions: get, set. A set can fill only the empty fields or overwrite, and supports a dry run.',
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'      => ['type' => 'string', 'enum' => ['get', 'set'], 'default' => 'get'],
            'post_type'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Post types to include. Defaults to every public post type except attachments.'],
            'post_status' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Defaults to publish.'],
            'taxonomy'    => ['type' => 'string', 'description' => 'Restrict to a term in this taxonomy. Needs term.'],
            'term'        => ['type' => 'string', 'description' => 'Term ID or slug in the taxonomy above.'],
            'search'      => ['type' => 'string', 'description' => 'Post search text.'],
            'missing'     => ['type' => 'string', 'enum' => ['metadesc', 'title', 'focuskw', 'canonical'], 'description' => 'Only posts whose stored Yoast field is empty or absent.'],
            'cornerstone' => ['type' => 'boolean', 'description' => 'true limits to cornerstone content.'],
            'noindex'     => ['type' => 'boolean', 'description' => 'true limits to posts explicitly set to noindex.'],
            'include'     => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Explicit post IDs. When given, the other filters are ignored.'],
            'per_page'    => ['type' => 'integer', 'default' => 20, 'description' => 'Clamped to 1..100.'],
            'page'        => ['type' => 'integer', 'default' => 1],
            'fields'      => ['type' => 'object', 'description' => 'For set: the same field names nibwp/yoast-post accepts, applied to every matched post.'],
            'overwrite'   => ['type' => 'boolean', 'default' => false, 'description' => 'false only fills fields that are currently empty. true replaces what is there, including values a person wrote.'],
            'dry_run'     => ['type' => 'boolean', 'default' => false, 'description' => 'Report what would change without writing.'],
            'confirm'     => ['type' => 'boolean', 'default' => false, 'description' => 'Must be true for a set that actually writes. A dry run does not need it.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_yoast_bulk',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'instructions' => implode("\n", [
                'Read first. missing=metadesc with a post_type is the query behind "which pages have no meta description", and it matches posts with no meta row as well as posts with an empty one.',
                'overwrite defaults to false on purpose: a bulk write is usually meant to fill the gaps, and the version that replaces hand-written descriptions is the same call with one flag flipped. Fields skipped because they already had a value come back in skipped.',
                'A real write needs confirm=true. dry_run=true reports the same result without touching anything and needs no confirm, so preview then commit.',
                'One page at a time, capped at 100 posts. Page through rather than asking for everything.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Build the WP_Query arguments for a bulk call.
 *
 * @return array<string, mixed>
 */
function nibwp_yoast_bulk_query(array $input): array
{
    $page = nibwp_yoast_paginate($input);

    $args = [
        'post_type'      => nibwp_yoast_public_post_types(),
        'post_status'    => ['publish'],
        'posts_per_page' => $page['per_page'],
        'paged'          => $page['page'],
        'orderby'        => 'ID',
        'order'          => 'DESC',
        'ignore_sticky_posts' => true,
        'no_found_rows'  => false,
        'suppress_filters' => false,
    ];

    $include = array_values(array_filter(array_map('intval', (array) ($input['include'] ?? []))));
    if ($include !== []) {
        $args['post__in'] = $include;
        $args['post_type'] = 'any';
        $args['post_status'] = 'any';
        $args['posts_per_page'] = min(count($include), 100);
        $args['paged'] = 1;

        return $args;
    }

    $types = array_values(array_filter(array_map('sanitize_key', (array) ($input['post_type'] ?? []))));
    if ($types !== []) {
        $args['post_type'] = $types;
    }

    $statuses = array_values(array_filter(array_map('sanitize_key', (array) ($input['post_status'] ?? []))));
    if ($statuses !== []) {
        $args['post_status'] = $statuses;
    }

    $search = trim((string) ($input['search'] ?? ''));
    if ($search !== '') {
        $args['s'] = $search;
    }

    $taxonomy = sanitize_key((string) ($input['taxonomy'] ?? ''));
    $term = trim((string) ($input['term'] ?? ''));
    if ($taxonomy !== '' && $term !== '') {
        $args['tax_query'] = [[
            'taxonomy' => $taxonomy,
            'field'    => ctype_digit($term) ? 'term_id' : 'slug',
            'terms'    => ctype_digit($term) ? (int) $term : $term,
        ]];
    }

    $meta_query = [];

    $missing = (string) ($input['missing'] ?? '');
    $missing_map = [
        'metadesc'  => 'metadesc',
        'title'     => 'title',
        'focuskw'   => 'focuskw',
        'canonical' => 'canonical',
    ];
    if (isset($missing_map[$missing])) {
        $key = NIBWP_YOAST_META_PREFIX . $missing_map[$missing];
        // Both halves are needed: Yoast prunes the row when the field holds its
        // default, so "no description" is a missing row on most posts and an
        // empty string on the ones somebody cleared by hand.
        $meta_query[] = [
            'relation' => 'OR',
            ['key' => $key, 'compare' => 'NOT EXISTS'],
            ['key' => $key, 'value' => '', 'compare' => '='],
        ];
    }

    if (array_key_exists('cornerstone', $input) && !empty($input['cornerstone'])) {
        $meta_query[] = ['key' => NIBWP_YOAST_META_PREFIX . 'is_cornerstone', 'value' => '1', 'compare' => '='];
    }

    if (array_key_exists('noindex', $input) && !empty($input['noindex'])) {
        $meta_query[] = ['key' => NIBWP_YOAST_META_PREFIX . 'meta-robots-noindex', 'value' => '1', 'compare' => '='];
    }

    if ($meta_query !== []) {
        $meta_query['relation'] = 'AND';
        $args['meta_query'] = $meta_query;
    }

    return $args;
}

function nibwp_yoast_bulk(array $input): array|WP_Error
{
    if ($guard = nibwp_yoast_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? 'get');
    if (!in_array($action, ['get', 'set'], true)) {
        return nibwp_yoast_err('nibwp_yoast_bad_action', __('action must be get or set.', 'nibwp'));
    }

    $dry_run = (bool) ($input['dry_run'] ?? false);

    if ($action === 'set') {
        $fields = (array) ($input['fields'] ?? []);
        if ($fields === []) {
            return nibwp_yoast_err('nibwp_yoast_no_fields', __('Provide a non-empty "fields" object saying what to write.', 'nibwp'));
        }
        // The confirm gate comes before anything is queried: a caller who
        // forgot it should be told that, not shown a result that looks written.
        if (!$dry_run && !(bool) ($input['confirm'] ?? false)) {
            return nibwp_yoast_err(
                'nibwp_yoast_unconfirmed',
                __('A bulk write changes many posts at once. Re-issue with dry_run=true to preview it, or confirm=true to commit it.', 'nibwp')
            );
        }
    }

    return nibwp_yoast_try(static function () use ($action, $input, $dry_run) {
        if (!class_exists('WP_Query')) {
            return nibwp_yoast_err('nibwp_yoast_no_query', __('WP_Query is unavailable.', 'nibwp'), 500);
        }

        $args = nibwp_yoast_bulk_query($input);
        $query = new WP_Query($args);
        $posts = is_array($query->posts) ? $query->posts : [];

        $rows = [];
        $overwrite = (bool) ($input['overwrite'] ?? false);
        $fields = (array) ($input['fields'] ?? []);

        foreach ($posts as $post) {
            $post_id = (int) (is_object($post) ? ($post->ID ?? 0) : (int) $post);
            if ($post_id <= 0) {
                continue;
            }

            if ($action === 'set') {
                $result = nibwp_yoast_write_post($post_id, $fields, $overwrite, $dry_run);
                $result['post_title'] = is_object($post) ? (string) ($post->post_title ?? '') : '';
                $rows[] = $result;
                continue;
            }

            $rows[] = [
                'post_id'     => $post_id,
                'post_title'  => is_object($post) ? (string) ($post->post_title ?? '') : '',
                'post_type'   => is_object($post) ? (string) ($post->post_type ?? '') : '',
                'post_status' => is_object($post) ? (string) ($post->post_status ?? '') : '',
                'stored'      => nibwp_yoast_read_post($post_id),
            ];
        }

        $page = nibwp_yoast_paginate($input);

        return [
            'action'    => $action,
            'count'     => count($rows),
            'total'     => (int) ($query->found_posts ?? count($rows)),
            'per_page'  => $page['per_page'],
            'page'      => $page['page'],
            'overwrite' => $action === 'set' ? $overwrite : null,
            'dry_run'   => $action === 'set' ? $dry_run : null,
            'results'   => $rows,
        ];
    });
}

/* ── Ability 4 — templates ──────────────────────────────────────────── */

wp_register_ability('nibwp/yoast-templates', [
    'label'       => __('Yoast SEO — Title & meta templates', 'nibwp'),
    'description' => 'Read or write Yoast\'s title and meta description templates: per post type (single, archive, social, schema types, noindex), per taxonomy, and the site-wide ones (home, author, date archive, search, 404, separator, breadcrumbs). Actions: get, get_raw, set. These are the templates every page falls back to, so a change here rewrites titles across the site.',
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'    => ['type' => 'string', 'enum' => ['get', 'get_raw', 'set'], 'default' => 'get'],
            'post_type' => ['type' => 'string', 'description' => 'For set: the post type whose templates to change.'],
            'taxonomy'  => ['type' => 'string', 'description' => 'For set: the taxonomy whose templates to change.'],
            'title'     => ['type' => 'string', 'description' => 'Title template. Yoast %%variables%% are kept as written.'],
            'metadesc'  => ['type' => 'string', 'description' => 'Meta description template.'],
            'noindex'   => ['type' => 'boolean', 'description' => 'Exclude this post type or taxonomy from search results.'],
            'archive_title' => ['type' => 'string', 'description' => 'Post type archive title template.'],
            'archive_metadesc' => ['type' => 'string', 'description' => 'Post type archive meta description template.'],
            'archive_noindex' => ['type' => 'boolean', 'description' => 'Exclude the post type archive.'],
            'social_title' => ['type' => 'string', 'description' => 'Social title template for this post type.'],
            'social_description' => ['type' => 'string', 'description' => 'Social description template for this post type.'],
            'schema_page_type' => ['type' => 'string', 'description' => 'Default schema page type for this post type.'],
            'schema_article_type' => ['type' => 'string', 'description' => 'Default schema article type for this post type.'],
            'settings'  => ['type' => 'object', 'description' => 'For set: raw wpseo_titles keys, e.g. separator, title-home-wpseo, metadesc-home-wpseo, breadcrumbs-sep. Run get_raw first to see the exact keys on this site.'],
            'confirm'   => ['type' => 'boolean', 'default' => false, 'description' => 'Must be true for a set. A template change affects every page of that type.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_yoast_templates',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'instructions' => implode("\n", [
                'get resolves the templates per post type and taxonomy and is the readable view. get_raw returns the wpseo_titles option exactly as stored, which is what to look at before writing an unusual key.',
                'A template is not a title. It contains Yoast %%variables%% such as %%title%% %%sep%% %%sitename%%, and they are passed through untouched — write the variables, not the rendered text.',
                'Writes go through Yoast\'s own option layer so its validation runs. The result separates changed from unconfirmed: a key Yoast did not recognise is reported as unconfirmed rather than claimed as written, which is what happens when a post type name is wrong.',
                'noindex here is a boolean because the option is one, unlike the three-state per-post field on nibwp/yoast-post.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);

/**
 * The templates for one post type or taxonomy, resolved.
 *
 * @return array<string, mixed>
 */
function nibwp_yoast_template_rows(): array
{
    $post_types = [];
    foreach (nibwp_yoast_public_post_types() as $type) {
        $post_types[$type] = [
            'title'    => nibwp_yoast_opt('title-' . $type, ''),
            'metadesc' => nibwp_yoast_opt('metadesc-' . $type, ''),
            'noindex'  => nibwp_yoast_opt('noindex-' . $type, null),
            'archive_title'    => nibwp_yoast_opt('title-ptarchive-' . $type, ''),
            'archive_metadesc' => nibwp_yoast_opt('metadesc-ptarchive-' . $type, ''),
            'archive_noindex'  => nibwp_yoast_opt('noindex-ptarchive-' . $type, null),
            'social_title'       => nibwp_yoast_opt('social-title-' . $type, ''),
            'social_description' => nibwp_yoast_opt('social-description-' . $type, ''),
            'schema_page_type'    => nibwp_yoast_opt('schema-page-type-' . $type, ''),
            'schema_article_type' => nibwp_yoast_opt('schema-article-type-' . $type, ''),
        ];
    }

    $taxes = get_taxonomies(['public' => true], 'names');
    $taxes = is_array($taxes) ? array_values($taxes) : [];

    $taxonomies = [];
    foreach ($taxes as $tax) {
        $taxonomies[$tax] = [
            'title'    => nibwp_yoast_opt('title-tax-' . $tax, ''),
            'metadesc' => nibwp_yoast_opt('metadesc-tax-' . $tax, ''),
            'noindex'  => nibwp_yoast_opt('noindex-tax-' . $tax, null),
        ];
    }

    return [
        'post_types'  => $post_types,
        'taxonomies'  => $taxonomies,
        'site' => [
            'separator'             => nibwp_yoast_opt('separator', ''),
            'forcerewritetitle'     => nibwp_yoast_opt('forcerewritetitle', null),
            'title-home-wpseo'      => nibwp_yoast_opt('title-home-wpseo', ''),
            'metadesc-home-wpseo'   => nibwp_yoast_opt('metadesc-home-wpseo', ''),
            'title-author-wpseo'    => nibwp_yoast_opt('title-author-wpseo', ''),
            'metadesc-author-wpseo' => nibwp_yoast_opt('metadesc-author-wpseo', ''),
            'title-archive-wpseo'   => nibwp_yoast_opt('title-archive-wpseo', ''),
            'metadesc-archive-wpseo' => nibwp_yoast_opt('metadesc-archive-wpseo', ''),
            'title-search-wpseo'    => nibwp_yoast_opt('title-search-wpseo', ''),
            'title-404-wpseo'       => nibwp_yoast_opt('title-404-wpseo', ''),
            'noindex-author-wpseo'  => nibwp_yoast_opt('noindex-author-wpseo', null),
            'noindex-archive-wpseo' => nibwp_yoast_opt('noindex-archive-wpseo', null),
            'breadcrumbs-enable'    => nibwp_yoast_opt('breadcrumbs-enable', null),
            'breadcrumbs-sep'       => nibwp_yoast_opt('breadcrumbs-sep', ''),
            'breadcrumbs-home'      => nibwp_yoast_opt('breadcrumbs-home', ''),
            'breadcrumbs-prefix'    => nibwp_yoast_opt('breadcrumbs-prefix', ''),
        ],
    ];
}

/**
 * Turn a templates `set` payload into the wpseo_titles keys it means.
 *
 * @return array<string, mixed>
 */
function nibwp_yoast_template_patch(array $input): array
{
    $patch = [];

    $post_type = sanitize_key((string) ($input['post_type'] ?? ''));
    if ($post_type !== '') {
        $map = [
            'title'               => 'title-' . $post_type,
            'metadesc'            => 'metadesc-' . $post_type,
            'noindex'             => 'noindex-' . $post_type,
            'archive_title'       => 'title-ptarchive-' . $post_type,
            'archive_metadesc'    => 'metadesc-ptarchive-' . $post_type,
            'archive_noindex'     => 'noindex-ptarchive-' . $post_type,
            'social_title'        => 'social-title-' . $post_type,
            'social_description'  => 'social-description-' . $post_type,
            'schema_page_type'    => 'schema-page-type-' . $post_type,
            'schema_article_type' => 'schema-article-type-' . $post_type,
        ];
        foreach ($map as $field => $key) {
            if (array_key_exists($field, $input)) {
                $patch[$key] = strpos($field, 'noindex') !== false
                    ? (bool) $input[$field]
                    // Templates carry %%variables%%, so only tags and control
                    // characters are stripped — the percent signs stay.
                    : sanitize_text_field((string) $input[$field]);
            }
        }
    }

    $taxonomy = sanitize_key((string) ($input['taxonomy'] ?? ''));
    if ($taxonomy !== '') {
        $map = [
            'title'    => 'title-tax-' . $taxonomy,
            'metadesc' => 'metadesc-tax-' . $taxonomy,
            'noindex'  => 'noindex-tax-' . $taxonomy,
        ];
        foreach ($map as $field => $key) {
            if (array_key_exists($field, $input)) {
                $patch[$key] = $field === 'noindex'
                    ? (bool) $input[$field]
                    : sanitize_text_field((string) $input[$field]);
            }
        }
    }

    foreach ((array) ($input['settings'] ?? []) as $key => $value) {
        $key = (string) $key;
        if (!nibwp_yoast_title_key_allowed($key)) {
            continue;
        }
        $patch[$key] = is_bool($value) ? $value : (is_scalar($value) ? sanitize_text_field((string) $value) : '');
    }

    return $patch;
}

function nibwp_yoast_templates(array $input): array|WP_Error
{
    if ($guard = nibwp_yoast_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? 'get');
    if (!in_array($action, ['get', 'get_raw', 'set'], true)) {
        return nibwp_yoast_err('nibwp_yoast_bad_action', __('action must be get, get_raw or set.', 'nibwp'));
    }

    if ($action === 'set' && !(bool) ($input['confirm'] ?? false)) {
        return nibwp_yoast_err(
            'nibwp_yoast_unconfirmed',
            __('A template change rewrites the titles of every page of that type. Re-issue with confirm=true.', 'nibwp')
        );
    }

    return nibwp_yoast_try(static function () use ($action, $input) {
        if ($action === 'get_raw') {
            return [
                'action'   => 'get_raw',
                'option'   => NIBWP_YOAST_OPT_TITLES,
                'settings' => nibwp_yoast_group(NIBWP_YOAST_OPT_TITLES),
            ];
        }

        if ($action === 'get') {
            return ['action' => 'get'] + nibwp_yoast_template_rows();
        }

        $patch = nibwp_yoast_template_patch($input);
        if ($patch === []) {
            return nibwp_yoast_err(
                'nibwp_yoast_no_patch',
                __('Nothing to write. Give a post_type or taxonomy with the template fields, or a settings object of wpseo_titles keys.', 'nibwp')
            );
        }

        if (!class_exists('WPSEO_Options') || !method_exists('WPSEO_Options', 'set')) {
            return nibwp_yoast_err(
                'nibwp_yoast_no_option_api',
                __('WPSEO_Options::set() is unavailable, and NIBWP will not write Yoast\'s option rows directly — its validation is what keeps a template from breaking every title. Change these in Yoast > Settings.', 'nibwp'),
                501
            );
        }

        foreach ($patch as $key => $value) {
            nibwp_yoast_opt_set((string) $key, $value);
        }

        // Verified against the stored row rather than the in-memory cache:
        // WPSEO_Options::set() keeps an unrecognised key in memory without
        // saving it, so reading it back any other way reports a write that
        // never happened.
        $stored = nibwp_yoast_group(NIBWP_YOAST_OPT_TITLES);
        $changed = [];
        $unconfirmed = [];
        foreach ($patch as $key => $value) {
            $key = (string) $key;
            if (array_key_exists($key, $stored) && (string) $stored[$key] === (string) (is_bool($value) ? (int) $value : $value)) {
                $changed[] = $key;
            } else {
                $unconfirmed[] = $key;
            }
        }

        return [
            'action'      => 'set',
            'changed'     => $changed,
            'unconfirmed' => $unconfirmed,
            'note'        => $unconfirmed === []
                ? ''
                : __('Yoast did not store these keys. Usually the post type or taxonomy name does not exist on this site, or its own validation rejected the value.', 'nibwp'),
        ];
    });
}

/* ── Ability 5 — term ───────────────────────────────────────────────── */

wp_register_ability('nibwp/yoast-term', [
    'label'       => __('Yoast SEO — Term SEO', 'nibwp'),
    'description' => 'Read or write the Yoast SEO fields on a taxonomy term: SEO title, description, canonical, focus keyphrase, breadcrumb title, the term\'s own noindex setting, the cornerstone flag, and its Open Graph and X/Twitter overrides. Actions: get, set.',
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'    => ['type' => 'string', 'enum' => ['get', 'set']],
            'taxonomy'  => ['type' => 'string', 'description' => 'The taxonomy the term belongs to.'],
            'term_id'   => ['type' => 'integer', 'description' => 'The term to read or write.'],
            'title'     => ['type' => 'string'],
            'description' => ['type' => 'string', 'description' => 'Meta description for the term archive.'],
            'canonical' => ['type' => 'string'],
            'focuskw'   => ['type' => 'string'],
            'breadcrumb_title' => ['type' => 'string'],
            'index'     => ['type' => 'string', 'enum' => ['default', 'index', 'noindex'], 'description' => 'The term\'s own robots setting. default follows the taxonomy template.'],
            'cornerstone' => ['type' => 'boolean'],
            'og_title'  => ['type' => 'string'],
            'og_description' => ['type' => 'string'],
            'og_image'  => ['type' => 'string'],
            'og_image_id' => ['type' => 'integer'],
            'twitter_title' => ['type' => 'string'],
            'twitter_description' => ['type' => 'string'],
            'twitter_image' => ['type' => 'string'],
            'twitter_image_id' => ['type' => 'integer'],
        ],
        'required' => ['action', 'taxonomy', 'term_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_yoast_term',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'instructions' => implode("\n", [
                'Term SEO does not live in term meta. Yoast keeps every term\'s fields in one wpseo_taxonomy_meta option, keyed by taxonomy and term ID, so nothing in get_term_meta() finds them.',
                'A write here reads the term\'s whole current record, merges the given fields into it and saves the result. That is deliberate: Yoast\'s own single-value setter re-validates the record from its defaults and drops any field not passed in, so writing one field the direct way clears the title, description and canonical.',
                'index on a term is default|index|noindex, the same three states as a post, and Yoast stores those words rather than numbers here.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * The term fields this adapter exposes, mapped to Yoast's stored keys.
 *
 * Keys are WPSEO_Taxonomy_Meta::$defaults_per_term names, which carry their own
 * `wpseo_` prefix and are NOT the post-meta names — the description is
 * `wpseo_desc`, not `wpseo_metadesc`.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function nibwp_yoast_term_fields(): array
{
    return [
        'title'               => ['wpseo_title', 'text'],
        'description'         => ['wpseo_desc', 'textarea'],
        'canonical'           => ['wpseo_canonical', 'url'],
        'focuskw'             => ['wpseo_focuskw', 'text'],
        'breadcrumb_title'    => ['wpseo_bctitle', 'text'],
        'og_title'            => ['wpseo_opengraph-title', 'text'],
        'og_description'      => ['wpseo_opengraph-description', 'textarea'],
        'og_image'            => ['wpseo_opengraph-image', 'url'],
        'og_image_id'         => ['wpseo_opengraph-image-id', 'int'],
        'twitter_title'       => ['wpseo_twitter-title', 'text'],
        'twitter_description' => ['wpseo_twitter-description', 'textarea'],
        'twitter_image'       => ['wpseo_twitter-image', 'url'],
        'twitter_image_id'    => ['wpseo_twitter-image-id', 'int'],
    ];
}

/** A term's stored Yoast record, straight from Yoast or from the option row. */
function nibwp_yoast_term_record(int $term_id, string $taxonomy): array
{
    if (class_exists('WPSEO_Taxonomy_Meta') && method_exists('WPSEO_Taxonomy_Meta', 'get_term_meta')) {
        $record = WPSEO_Taxonomy_Meta::get_term_meta($term_id, $taxonomy);
        if (is_array($record)) {
            return $record;
        }
    }

    $option = nibwp_yoast_group(NIBWP_YOAST_OPT_TAXONOMY);
    $record = $option[$taxonomy][$term_id] ?? [];

    return is_array($record) ? $record : [];
}

function nibwp_yoast_term(array $input): array|WP_Error
{
    if ($guard = nibwp_yoast_guard()) {
        return $guard;
    }

    $term_id = (int) ($input['term_id'] ?? 0);
    $taxonomy = sanitize_key((string) ($input['taxonomy'] ?? ''));

    if ($term_id <= 0 || $taxonomy === '') {
        return nibwp_yoast_err('nibwp_yoast_bad_id', __('A valid term_id and taxonomy are required.', 'nibwp'));
    }

    $action = (string) ($input['action'] ?? '');
    if (!in_array($action, ['get', 'set'], true)) {
        return nibwp_yoast_err('nibwp_yoast_bad_action', __('action must be get or set.', 'nibwp'));
    }

    return nibwp_yoast_try(static function () use ($action, $term_id, $taxonomy, $input) {
        $term = get_term($term_id, $taxonomy);
        if (!$term || $term instanceof WP_Error) {
            return nibwp_yoast_err('nibwp_yoast_not_found', __('No term with that ID in that taxonomy.', 'nibwp'), 404);
        }

        $record = nibwp_yoast_term_record($term_id, $taxonomy);

        $decode = static function (array $record): array {
            $out = [];
            foreach (nibwp_yoast_term_fields() as $field => $spec) {
                $value = $record[$spec[0]] ?? '';
                $out[$field] = is_scalar($value) ? (string) $value : '';
            }
            // Yoast stores the words here, not the numbers it uses on posts.
            $index = (string) ($record['wpseo_noindex'] ?? 'default');
            $out['index'] = in_array($index, ['default', 'index', 'noindex'], true) ? $index : 'default';
            $out['cornerstone'] = (string) ($record['wpseo_is_cornerstone'] ?? '0') === '1';

            return $out;
        };

        if ($action === 'get') {
            return [
                'term_id'  => $term_id,
                'taxonomy' => $taxonomy,
                'name'     => (string) ($term->name ?? ''),
                'stored'   => $decode($record),
                'raw'      => $record,
            ];
        }

        // Merge onto the whole record. Yoast's validator rebuilds the record
        // from its defaults and only keeps what it is handed for most fields,
        // so a partial payload wipes the rest.
        $merged = $record;
        $changed = [];

        foreach (nibwp_yoast_term_fields() as $field => $spec) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $merged[$spec[0]] = nibwp_yoast_clean($spec[1], $input[$field]);
            $changed[] = $spec[0];
        }

        if (array_key_exists('index', $input)) {
            $index = (string) $input['index'];
            if (!in_array($index, ['default', 'index', 'noindex'], true)) {
                return nibwp_yoast_err('nibwp_yoast_bad_index', __('index must be one of default, index, noindex.', 'nibwp'));
            }
            $merged['wpseo_noindex'] = $index;
            $changed[] = 'wpseo_noindex';
        }

        if (array_key_exists('cornerstone', $input)) {
            $merged['wpseo_is_cornerstone'] = !empty($input['cornerstone']) ? '1' : '0';
            $changed[] = 'wpseo_is_cornerstone';
        }

        if ($changed === []) {
            return nibwp_yoast_err('nibwp_yoast_no_fields', __('Nothing to write. Give at least one field.', 'nibwp'));
        }

        if (!class_exists('WPSEO_Taxonomy_Meta') || !method_exists('WPSEO_Taxonomy_Meta', 'set_values')) {
            return nibwp_yoast_err(
                'nibwp_yoast_no_term_api',
                __('WPSEO_Taxonomy_Meta::set_values() is unavailable, and NIBWP will not rewrite the wpseo_taxonomy_meta option directly — its validation decides which fields survive. Edit the term in Yoast instead.', 'nibwp'),
                501
            );
        }

        WPSEO_Taxonomy_Meta::set_values($term_id, $taxonomy, $merged);

        return [
            'term_id'  => $term_id,
            'taxonomy' => $taxonomy,
            'changed'  => $changed,
            'stored'   => $decode(nibwp_yoast_term_record($term_id, $taxonomy)),
        ];
    });
}

/* ── Ability 6 — redirects (Premium) ────────────────────────────────── */

wp_register_ability('nibwp/yoast-redirects', [
    'label'       => __('Yoast SEO — Redirects (Premium)', 'nibwp'),
    'description' => 'List, create or delete Yoast SEO Premium redirects. Premium only: on a free Yoast install this reports that redirects are unavailable and points at the alternatives instead of failing silently.',
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['list', 'create', 'delete'], 'default' => 'list'],
            'origin' => ['type' => 'string', 'description' => 'The old URL or path being redirected. Required for create and delete.'],
            'target' => ['type' => 'string', 'description' => 'Where it should go. Required for create unless the type is 410 or 451.'],
            'type'   => ['type' => 'integer', 'enum' => [301, 302, 307, 410, 451], 'default' => 301, 'description' => 'HTTP status for the redirect.'],
            'format' => ['type' => 'string', 'enum' => ['plain', 'regex'], 'default' => 'plain', 'description' => 'plain matches a URL, regex matches a pattern.'],
            'confirm' => ['type' => 'boolean', 'default' => false, 'description' => 'Must be true for create and delete. A redirect changes where a live URL sends visitors.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_yoast_redirects',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'instructions' => implode("\n", [
                'Premium only. Call nibwp/yoast-info first: surfaces.redirects says whether this will work at all, and on free Yoast the NIBWP Redirection integration or a server-level rule is the alternative.',
                'Every call goes through Yoast Premium\'s own redirect classes, never the option row. Premium is not open source, so if those classes are not shaped as expected this refuses and names what was missing rather than writing a redirect table by guesswork.',
                'A redirect changes where a live URL sends visitors and search engines, so create and delete need confirm=true.',
                'Yoast also creates redirects by itself when a published post\'s slug changes. A redirect you did not add is usually that, not a fault.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Normalise one Yoast redirect object into plain values.
 *
 * Its getters are checked one at a time because Premium is closed source: a
 * missing getter yields an empty field rather than a fatal.
 *
 * @return array<string, mixed>
 */
function nibwp_yoast_redirect_row(mixed $redirect): array
{
    if (is_array($redirect)) {
        return [
            'origin' => (string) ($redirect['origin'] ?? ''),
            'target' => (string) ($redirect['url'] ?? ($redirect['target'] ?? '')),
            'type'   => (int) ($redirect['type'] ?? 0),
            'format' => (string) ($redirect['format'] ?? ''),
        ];
    }

    if (!is_object($redirect)) {
        return ['origin' => '', 'target' => '', 'type' => 0, 'format' => ''];
    }

    return [
        'origin' => method_exists($redirect, 'get_origin') ? (string) $redirect->get_origin() : '',
        'target' => method_exists($redirect, 'get_target') ? (string) $redirect->get_target() : '',
        'type'   => method_exists($redirect, 'get_type') ? (int) $redirect->get_type() : 0,
        'format' => method_exists($redirect, 'get_format') ? (string) $redirect->get_format() : '',
    ];
}

function nibwp_yoast_redirects(array $input): array|WP_Error
{
    if ($guard = nibwp_yoast_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? 'list');
    if (!in_array($action, ['list', 'create', 'delete'], true)) {
        return nibwp_yoast_err('nibwp_yoast_bad_action', __('action must be list, create or delete.', 'nibwp'));
    }

    if (!nibwp_yoast_premium()) {
        return nibwp_yoast_err(
            'nibwp_yoast_premium_required',
            __('Redirects are a Yoast SEO Premium feature and Premium is not active on this site. Use the NIBWP Redirection integration, or add the rule at server level.', 'nibwp'),
            404
        );
    }

    if (in_array($action, ['create', 'delete'], true) && !(bool) ($input['confirm'] ?? false)) {
        return nibwp_yoast_err(
            'nibwp_yoast_unconfirmed',
            __('A redirect changes where a live URL sends visitors. Re-issue with confirm=true.', 'nibwp')
        );
    }

    return nibwp_yoast_try(static function () use ($action, $input) {
        if (!class_exists('WPSEO_Redirect_Option')) {
            return nibwp_yoast_err(
                'nibwp_yoast_redirect_api_missing',
                __('Yoast Premium is active but its WPSEO_Redirect_Option class was not found, so NIBWP cannot reach the redirect store. Manage redirects in Yoast > Redirects.', 'nibwp'),
                501
            );
        }

        $store = new WPSEO_Redirect_Option();

        if ($action === 'list') {
            $rows = [];
            foreach (['get_all', 'get_redirects'] as $reader) {
                if (!method_exists($store, $reader)) {
                    continue;
                }
                foreach ((array) $store->$reader() as $redirect) {
                    $rows[] = nibwp_yoast_redirect_row($redirect);
                }
                break;
            }

            return [
                'action'    => 'list',
                'redirects' => $rows,
                'count'     => count($rows),
                'note'      => $rows === []
                    ? __('No redirects were returned. On a site that has some, this means Yoast Premium\'s redirect reader is not shaped as expected — check Yoast > Redirects.', 'nibwp')
                    : '',
            ];
        }

        $origin = trim((string) ($input['origin'] ?? ''));
        if ($origin === '') {
            return nibwp_yoast_err('nibwp_yoast_no_origin', __('origin is required.', 'nibwp'));
        }

        if ($action === 'delete') {
            if (!method_exists($store, 'delete') || !class_exists('WPSEO_Redirect')) {
                return nibwp_yoast_err(
                    'nibwp_yoast_redirect_api_missing',
                    __('Yoast Premium\'s redirect deletion API was not found. Remove the redirect in Yoast > Redirects.', 'nibwp'),
                    501
                );
            }

            $redirect = new WPSEO_Redirect($origin, '', 301, (string) ($input['format'] ?? 'plain'));
            $store->delete($redirect);
            if (method_exists($store, 'save')) {
                $store->save();
            }

            return ['action' => 'delete', 'origin' => $origin, 'deleted' => true, 'reversible' => false];
        }

        $type = (int) ($input['type'] ?? 301);
        if (!in_array($type, [301, 302, 307, 410, 451], true)) {
            return nibwp_yoast_err('nibwp_yoast_bad_type', __('type must be one of 301, 302, 307, 410, 451.', 'nibwp'));
        }

        $target = trim((string) ($input['target'] ?? ''));
        if ($target === '' && !in_array($type, [410, 451], true)) {
            return nibwp_yoast_err('nibwp_yoast_no_target', __('target is required for a 301, 302 or 307.', 'nibwp'));
        }

        if (!class_exists('WPSEO_Redirect') || !method_exists($store, 'add') || !method_exists($store, 'save')) {
            return nibwp_yoast_err(
                'nibwp_yoast_redirect_api_missing',
                __('Yoast Premium\'s redirect creation API was not found, so NIBWP will not write its redirect store by hand. Add the redirect in Yoast > Redirects.', 'nibwp'),
                501
            );
        }

        $format = (string) ($input['format'] ?? 'plain') === 'regex' ? 'regex' : 'plain';
        $redirect = new WPSEO_Redirect($origin, $target, $type, $format);
        $store->add($redirect);
        $store->save();

        // Yoast writes the web-server rules from the stored redirects; without
        // this the redirect exists in its table and does nothing on Apache or
        // nginx configurations that use the exported file.
        if (class_exists('WPSEO_Redirect_Manager')) {
            $manager = new WPSEO_Redirect_Manager();
            if (method_exists($manager, 'export_redirects')) {
                $manager->export_redirects();
            }
        }

        return [
            'action' => 'create',
            'origin' => $origin,
            'target' => $target,
            'type'   => $type,
            'format' => $format,
            'created' => true,
        ];
    });
}

/* ── Ability 7 — audit ──────────────────────────────────────────────── */

wp_register_ability('nibwp/yoast-audit', [
    'label'       => __('Yoast SEO — Audit', 'nibwp'),
    'description' => 'Check published content for the Yoast faults that quietly cost traffic: no meta description, titles and descriptions long enough to be truncated, no focus keyphrase, cornerstone content that is set to noindex, orphaned content nothing links to, and pages sharing a canonical URL. Read-only, with a severity and the exact fix on every finding.',
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_type' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Post types to audit. Defaults to every public post type except attachments.'],
            'limit'     => ['type' => 'integer', 'default' => 100, 'description' => 'Most posts to audit in one pass. Clamped to 1..200.'],
            'page'      => ['type' => 'integer', 'default' => 1, 'description' => 'Page through a larger site rather than raising the limit.'],
            'title_max' => ['type' => 'integer', 'default' => 60, 'description' => 'Character count above which a title is reported as likely truncated.'],
            'rendered'  => ['type' => 'boolean', 'default' => true, 'description' => 'Measure Yoast\'s rendered title and description rather than the stored template. Turn off on a slow site.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'posts_checked' => ['type' => 'integer'],
            'verdict'  => ['type' => 'string'],
            'blockers' => ['type' => 'array', 'items' => ['type' => 'object']],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'object']],
            'skipped'  => ['type' => 'array', 'items' => ['type' => 'object']],
            'counts'   => ['type' => 'object'],
        ],
        'required' => ['posts_checked', 'verdict', 'blockers', 'warnings', 'skipped'],
    ],
    'execute_callback'    => 'nibwp_yoast_audit',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'instructions' => implode("\n", [
                'Two blockers, because both actively undo work somebody paid for.',
                'Cornerstone content set to noindex is the plainest: the page was marked as the most important thing on the site and then hidden from search. Yoast shows the two settings on different tabs and never mentions that they contradict each other.',
                'Two pages sharing a canonical URL means one of them has told search engines to count it as the other. Whichever loses is gone from results while still being linked, indexed in sitemaps and served to visitors.',
                'Orphaned content needs Yoast\'s text link counter. With it off the incoming-link column is never filled, so rather than reporting every page as orphaned the check appears under skipped with the reason.',
                'A missing meta description is only reported when the post type has no description template either — with a template Yoast generates one, and flagging those is noise.',
                'Length findings are approximations and say so. Yoast measures the title in pixels, which cannot be reproduced outside a browser; the description limit is Yoast\'s own character count, read off its class when it is loaded.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Posts that share a canonical URL with another post.
 *
 * One grouped query on WordPress's own postmeta table — nothing Yoast-specific
 * beyond the meta key, so it is correct whether or not the indexable table
 * exists. An empty canonical is not a duplicate: it is the normal state.
 *
 * @return array<string, array<int, int>> canonical URL => post ids
 */
function nibwp_yoast_duplicate_canonicals(int $limit = 50): array
{
    global $wpdb;

    if (!isset($wpdb) || !is_object($wpdb)) {
        return [];
    }

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_value AS canonical, GROUP_CONCAT(post_id) AS post_ids, COUNT(*) AS hits"
            . " FROM {$wpdb->postmeta}"
            . " WHERE meta_key = %s AND meta_value <> ''"
            . " GROUP BY meta_value HAVING hits > 1 LIMIT %d",
        NIBWP_YOAST_META_PREFIX . 'canonical',
        $limit
    ));

    $out = [];
    foreach ((array) $rows as $row) {
        $canonical = (string) ($row->canonical ?? '');
        if ($canonical === '') {
            continue;
        }
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($row->post_ids ?? '')))));
        if (count($ids) > 1) {
            $out[$canonical] = $ids;
        }
    }

    return $out;
}

/**
 * Published posts with no incoming internal links, from Yoast's indexable.
 *
 * `incoming_link_count` is only maintained while Yoast's text link counter is
 * enabled, so the caller checks that before asking. is_robots_noindex is
 * allowed to be NULL: Yoast uses NULL for "not decided", which is the common
 * case for a page following its post type default.
 *
 * @return array<int, array{post_id: int, title: string, post_type: string}>
 */
function nibwp_yoast_orphaned(array $post_types, int $limit = 50): array
{
    global $wpdb;

    $table = nibwp_yoast_indexable_table();
    if ($table === '' || $post_types === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
    $params = $post_types;
    $params[] = $limit;

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from Yoast's own helper, values are bound.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT object_id, title, object_sub_type FROM `{$table}`"
            . " WHERE object_type = 'post' AND post_status = 'publish'"
            . " AND object_sub_type IN ({$placeholders})"
            . " AND ( is_robots_noindex = 0 OR is_robots_noindex IS NULL )"
            . " AND ( incoming_link_count = 0 OR incoming_link_count IS NULL )"
            . " LIMIT %d",
        $params
    ));

    $out = [];
    foreach ((array) $rows as $row) {
        $out[] = [
            'post_id'   => (int) ($row->object_id ?? 0),
            'title'     => (string) ($row->title ?? ''),
            'post_type' => (string) ($row->object_sub_type ?? ''),
        ];
    }

    return $out;
}

function nibwp_yoast_audit(array $input): array|WP_Error
{
    if ($guard = nibwp_yoast_guard()) {
        return $guard;
    }

    return nibwp_yoast_try(static function () use ($input) {
        if (!class_exists('WP_Query')) {
            return nibwp_yoast_err('nibwp_yoast_no_query', __('WP_Query is unavailable.', 'nibwp'), 500);
        }

        $types = array_values(array_filter(array_map('sanitize_key', (array) ($input['post_type'] ?? []))));
        if ($types === []) {
            $types = nibwp_yoast_public_post_types();
        }

        $limit = min(max((int) ($input['limit'] ?? 100), 1), 200);
        $page = max((int) ($input['page'] ?? 1), 1);
        $title_max = min(max((int) ($input['title_max'] ?? NIBWP_YOAST_TITLE_MAX), 20), 200);
        $use_rendered = (bool) ($input['rendered'] ?? true);

        $desc_max = (class_exists('WPSEO_Meta') && isset(WPSEO_Meta::$meta_length))
            ? (int) WPSEO_Meta::$meta_length
            : NIBWP_YOAST_DESC_MAX;

        $keyphrase_on = nibwp_yoast_opt('keyword_analysis_active', true) !== false;
        $link_counter_on = nibwp_yoast_opt('enable_text_link_counter', true) !== false;

        $query = new WP_Query([
            'post_type'      => $types,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'paged'          => $page,
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'ignore_sticky_posts' => true,
        ]);
        $posts = is_array($query->posts) ? $query->posts : [];

        $findings = [];
        $skipped = [];
        $counts = [
            'description_from_template' => 0,
            'title_not_measured'        => 0,
            'cornerstone'               => 0,
            'noindexed'                 => 0,
        ];

        $duplicates = nibwp_yoast_duplicate_canonicals(50);
        $duplicate_of = [];
        foreach ($duplicates as $canonical => $ids) {
            foreach ($ids as $id) {
                $duplicate_of[$id] = ['canonical' => $canonical, 'ids' => $ids];
            }
        }

        foreach ($posts as $post) {
            $post_id = (int) (is_object($post) ? ($post->ID ?? 0) : (int) $post);
            if ($post_id <= 0) {
                continue;
            }
            $post_type = is_object($post) ? (string) ($post->post_type ?? '') : '';
            $label = is_object($post) ? (string) ($post->post_title ?? '') : '';

            $stored = nibwp_yoast_read_post($post_id);
            $rendered = $use_rendered ? nibwp_yoast_rendered($post_id) : null;

            if ($stored['cornerstone']) {
                $counts['cornerstone']++;
            }
            if ($stored['robots']['index'] === 'noindex') {
                $counts['noindexed']++;
            }

            // ── cornerstone content excluded from search ──────────────────
            if ($stored['cornerstone'] && $stored['robots']['index'] === 'noindex') {
                $findings[] = [
                    'post_id'  => $post_id,
                    'title'    => $label,
                    'severity' => 'blocker',
                    'code'     => 'cornerstone_noindexed',
                    'message'  => __('This is marked as cornerstone content and set to noindex, so the page you told Yoast matters most is the one you have asked search engines to ignore.', 'nibwp'),
                    'fix'      => __('Call nibwp/yoast-post with action=set, index=default to hand the page back to its post type setting, or index=index to force it in. If the page really should stay hidden, clear the cornerstone flag with cornerstone=false instead.', 'nibwp'),
                ];
            }

            // ── duplicate canonical ───────────────────────────────────────
            if (isset($duplicate_of[$post_id])) {
                $others = array_values(array_filter(
                    $duplicate_of[$post_id]['ids'],
                    static fn(int $id): bool => $id !== $post_id
                ));
                $findings[] = [
                    'post_id'  => $post_id,
                    'title'    => $label,
                    'severity' => 'blocker',
                    'code'     => 'duplicate_canonical',
                    'message'  => sprintf(
                        /* translators: 1: canonical URL, 2: comma-separated post IDs. */
                        __('Its canonical URL is %1$s, which %2$s also claims. Search engines will keep only one of them, and the losers stay linked and in the sitemap while being absent from results.', 'nibwp'),
                        $duplicate_of[$post_id]['canonical'],
                        implode(', ', array_map('strval', array_slice($others, 0, 10)))
                    ),
                    'fix'      => __('Decide which URL is the real one. On every other post call nibwp/yoast-post with action=set and canonical set to an empty string, which clears the override and lets Yoast use the post\'s own URL.', 'nibwp'),
                ];
            }

            // ── no meta description ───────────────────────────────────────
            $template_desc = (string) nibwp_yoast_opt('metadesc-' . $post_type, '');
            $rendered_desc = $rendered === null ? '' : (string) $rendered['description'];
            $effective_desc = $stored['metadesc'] !== '' ? $stored['metadesc'] : $rendered_desc;

            if ($stored['metadesc'] === '' && $template_desc === '' && $rendered_desc === '') {
                $findings[] = [
                    'post_id'  => $post_id,
                    'title'    => $label,
                    'severity' => 'warning',
                    'code'     => 'no_meta_description',
                    'message'  => __('No meta description, and the post type has no description template to fall back on, so the search result snippet is whatever Google decides to quote from the page.', 'nibwp'),
                    'fix'      => __('Write one with nibwp/yoast-post action=set, metadesc="…", or set a template for the whole post type with nibwp/yoast-templates action=set, post_type=…, metadesc="…". To fill a backlog in one pass use nibwp/yoast-bulk with missing=metadesc.', 'nibwp'),
                ];
            } elseif ($stored['metadesc'] === '' && $template_desc !== '') {
                $counts['description_from_template']++;
            }

            if ($effective_desc !== '' && nibwp_yoast_strlen($effective_desc) > $desc_max) {
                $findings[] = [
                    'post_id'  => $post_id,
                    'title'    => $label,
                    'severity' => 'warning',
                    'code'     => 'meta_description_too_long',
                    'message'  => sprintf(
                        /* translators: 1: actual length, 2: Yoast's limit. */
                        __('The meta description is %1$d characters against Yoast\'s own limit of %2$d, so the end of it will be cut off in search results.', 'nibwp'),
                        nibwp_yoast_strlen($effective_desc),
                        $desc_max
                    ),
                    'fix'      => sprintf(
                        /* translators: %d: Yoast's meta description limit. */
                        __('Shorten it to %d characters or fewer with nibwp/yoast-post action=set, metadesc="…", putting what matters first.', 'nibwp'),
                        $desc_max
                    ),
                ];
            }

            // ── title length ──────────────────────────────────────────────
            // Only a title with no unresolved %%variables%% can be measured.
            // A stored template whose variables we did not expand would be
            // measured as the literal text, which is not what anyone sees.
            $measurable = '';
            if ($rendered !== null && (string) $rendered['title'] !== '' && strpos((string) $rendered['title'], '%%') === false) {
                $measurable = (string) $rendered['title'];
            } elseif ($stored['title'] !== '' && strpos($stored['title'], '%%') === false) {
                $measurable = $stored['title'];
            }

            if ($measurable === '') {
                $counts['title_not_measured']++;
            } elseif (nibwp_yoast_strlen($measurable) > $title_max) {
                $findings[] = [
                    'post_id'  => $post_id,
                    'title'    => $label,
                    'severity' => 'warning',
                    'code'     => 'seo_title_too_long',
                    'message'  => sprintf(
                        /* translators: 1: title length, 2: the threshold used. */
                        __('The SEO title is %1$d characters, past the %2$d-character mark where search results usually truncate. Yoast measures this in pixels, which cannot be reproduced here, so treat it as an approximation and check the editor\'s own bar.', 'nibwp'),
                        nibwp_yoast_strlen($measurable),
                        $title_max
                    ),
                    'fix'      => __('Shorten it with nibwp/yoast-post action=set, title="…". Moving the site name into the template with nibwp/yoast-templates usually buys back the most room.', 'nibwp'),
                ];
            }

            // ── focus keyphrase ───────────────────────────────────────────
            if ($keyphrase_on && $stored['focuskw'] === '') {
                $findings[] = [
                    'post_id'  => $post_id,
                    'title'    => $label,
                    'severity' => 'warning',
                    'code'     => 'missing_focus_keyphrase',
                    'message'  => __('No focus keyphrase, so Yoast\'s keyphrase analysis has nothing to measure this page against and its SEO score means nothing.', 'nibwp'),
                    'fix'      => __('Set one with nibwp/yoast-post action=set, focuskw="…". nibwp/yoast-bulk with missing=focuskw lists every post in the same state.', 'nibwp'),
                ];
            }
        }

        if (!$keyphrase_on) {
            $skipped[] = [
                'code'   => 'missing_focus_keyphrase',
                'cause'  => 'keyphrase_analysis_disabled',
                'reason' => __('Yoast\'s keyphrase analysis is switched off on this site, so a missing focus keyphrase is not a fault here.', 'nibwp'),
            ];
        }

        // ── orphaned content ──────────────────────────────────────────────
        // Two reasons this check cannot run, and they are not interchangeable:
        // the table being absent is a Yoast that has not indexed yet, while the
        // counter being off means the column exists and is permanently zero.
        // `cause` is machine-readable so a client can tell them apart without
        // reading the sentence.
        $table = nibwp_yoast_indexable_table();
        if (!$link_counter_on) {
            $skipped[] = [
                'code'   => 'orphaned_content',
                'cause'  => 'text_link_counter_disabled',
                'reason' => __('Yoast\'s text link counter is switched off, so the incoming-link count this check reads stays at zero and every post would look orphaned.', 'nibwp'),
            ];
        } elseif ($table === '') {
            $skipped[] = [
                'code'   => 'orphaned_content',
                'cause'  => 'indexable_table_missing',
                'reason' => __('Yoast\'s indexable table was not found, and incoming links cannot be counted without it.', 'nibwp'),
            ];
        } else {
            foreach (nibwp_yoast_orphaned($types, 50) as $orphan) {
                $findings[] = [
                    'post_id'  => $orphan['post_id'],
                    'title'    => $orphan['title'],
                    'severity' => 'warning',
                    'code'     => 'orphaned_content',
                    'message'  => __('Nothing on the site links to this page. Search engines reach it only through the sitemap, and visitors reading anything else will never find it.', 'nibwp'),
                    'fix'      => __('Link to it from a related published page. If it should not be found at all, set it to noindex with nibwp/yoast-post action=set, index=noindex so it stops diluting the rest of the site.', 'nibwp'),
                ];
            }
        }

        $blockers = array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'blocker'));

        return [
            'posts_checked' => count($posts),
            'total_posts'   => (int) ($query->found_posts ?? count($posts)),
            'page'          => $page,
            'verdict'       => $blockers === [] ? 'ok' : 'needs_attention',
            'blockers'      => $blockers,
            'warnings'      => array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'warning')),
            'skipped'       => $skipped,
            'counts'        => $counts,
            'thresholds'    => [
                'description_max_characters' => $desc_max,
                'title_max_characters'       => $title_max,
                'title_limit_is_approximate' => true,
            ],
        ];
    });
}
