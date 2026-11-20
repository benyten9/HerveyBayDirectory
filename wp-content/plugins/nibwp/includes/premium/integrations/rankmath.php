<?php

declare(strict_types=1);

/**
 * Rank Math SEO integration — NIBWP MCP abilities (Layer 1 adapter).
 *
 * Seven abilities: info, per-post SEO, bulk SEO across a query, global
 * settings, redirections, the sitemap, and an audit.
 *
 * WHERE RANK MATH KEEPS THINGS
 *
 * Per-object SEO is ordinary post meta under a `rank_math_` prefix. Rank Math
 * reads it through RankMath\Post::get_meta( $key ), which prepends that prefix
 * (RankMath\Metadata::get_metadata) — so its internal key `title` is the meta
 * key `rank_math_title`. Its own REST writer (RankMath\Rest\Shared) deletes a
 * meta row when the incoming value is empty rather than storing an empty
 * string, and this adapter does the same, because an empty string stored on
 * `rank_math_title` suppresses the post-type title template instead of
 * inheriting it.
 *
 * Global settings are four options, read as one snapshot by
 * rank_math()->settings->all():
 *
 *   rank-math-options-general            general.*
 *   rank-math-options-titles             titles.*
 *   rank-math-options-sitemap            sitemap.*
 *   rank-math-options-instant-indexing   instant_indexing.*
 *
 * Writing them has a trap. update_option() alone leaves the in-memory registry
 * rank_math()->settings holding the previous values for the rest of the
 * request, so anything that reads a setting after the write — including Rank
 * Math's own frontend output on the same request — sees the old one. Rank Math
 * writes through RankMath\Helper::update_all_settings() and then calls
 * rank_math()->settings->reset(). This adapter does both, and falls back to
 * update_option() only when that helper is missing.
 *
 * Two modules keep their own tables rather than post meta:
 *
 *   {prefix}rank_math_redirections     id, sources (a serialized array of
 *                                      { pattern, comparison, ignore }),
 *                                      url_to, header_code, hits, status,
 *                                      created, updated, last_accessed
 *   {prefix}rank_math_404_logs         id, uri, accessed, times_accessed,
 *                                      referer, user_agent
 *
 * Redirection WRITES go through RankMath\Redirections\Redirection::from()
 * ->save() and RankMath\Redirections\DB, never through SQL, because a
 * redirection is two tables: saving also maintains
 * {prefix}rank_math_redirections_cache, and deleting purges it. A row inserted
 * behind Rank Math's back leaves that cache disagreeing with the rule, which is
 * a redirect that fires for URLs nobody wrote. Reads fall back to a plain
 * SELECT when the module's classes are not loaded, since a read cannot
 * desynchronise anything and the audit still has to be able to see the rows.
 *
 * Rank Math's helpers are reached through call_user_func() with the class named
 * as a string, guarded by class_exists()/method_exists(), so nothing in this
 * file references a Rank Math symbol at compile time. RankMath\Helper defines
 * __callStatic() and returns an empty string for any method it does not know —
 * a misspelled helper therefore fails silently rather than throwing, which is
 * why every call here is method_exists()-checked first rather than wrapped in a
 * try/catch and hoped for.
 *
 * WHAT IS DELIBERATELY NOT HERE
 *
 *   • Schema bodies. A schema is one or more `rank_math_schema_<Type>` meta
 *     ROWS whose identity (the meta id) the Schema module tracks, with a
 *     separate `rank_math_shortcode_schema_<code>` row pointing at it. Writing
 *     a JSON-LD document from outside that bookkeeping produces schema its
 *     editor then repairs unpredictably. Schema is read here (types present,
 *     the post-type default, whether it is switched off) and edited in Rank
 *     Math. The one write offered is `schema_disabled`, which sets
 *     `rank_math_rich_snippet` to 'off' — the exact value Rank Math itself
 *     writes to mean "no schema for this post".
 *   • Analytics, Content AI and Instant Indexing. Their storage is remote
 *     state, credentials, or cron-driven queues.
 *   • The remote half of Rank Math's own site audit (its rankmath.com API
 *     tests). nibwp/rankmath-audit is entirely local.
 *   • Per-post-type default schema type, social image overlays, and the Twitter
 *     app/player field family — their accepted values are not pinned down
 *     firmly enough to write.
 *
 * Detection: RANK_MATH_VERSION, or the RankMath\Helper class.
 *
 * Verified against Rank Math SEO 1.0.280. Rank Math registers its own abilities
 * under `rank-math/`; these live under `nibwp/rankmath-` and cannot collide.
 */

if (!defined('ABSPATH')) {
    exit();
}

/* ----------------------------------------------------------------------------
 * Detection and plumbing
 * ------------------------------------------------------------------------- */

/** Is Rank Math active? */
function nibwp_rankmath_available(): bool
{
    return defined('RANK_MATH_VERSION') || class_exists('RankMath\\Helper');
}

/** House WP_Error wrapper. */
function nibwp_rankmath_err(string $code, string $message): WP_Error
{
    return new WP_Error($code, $message);
}

/** The guard every callback opens with. */
function nibwp_rankmath_guard(): ?WP_Error
{
    if (!nibwp_rankmath_available()) {
        return nibwp_rankmath_err('nibwp_rankmath_missing', __('Rank Math SEO is not active on this site.', 'nibwp'));
    }

    return null;
}

/** Run a Rank Math call, converting throwables into WP_Error. */
function nibwp_rankmath_try(callable $fn, string $code = 'nibwp_rankmath_failed')
{
    try {
        return $fn();
    } catch (\Throwable $e) {
        return nibwp_rankmath_err($code, $e->getMessage());
    }
}

/** Is a Rank Math static method actually there to call? */
function nibwp_rankmath_can_call(string $class, string $method): bool
{
    return class_exists($class) && method_exists($class, $method);
}

/** Clamp pagination to the house 1..100 window. */
function nibwp_rankmath_paginate(array $in): array
{
    $per_page = min(max((int) ($in['per_page'] ?? 20), 1), 100);
    $page = max((int) ($in['page'] ?? 1), 1);

    return [
        'per_page' => $per_page,
        'page'     => $page,
        'limit'    => $per_page,
        'offset'   => ($page - 1) * $per_page,
    ];
}

/** A prefixed Rank Math table name. */
function nibwp_rankmath_table(string $suffix): string
{
    global $wpdb;

    return $wpdb->prefix . $suffix;
}

/**
 * Rank Math stores booleans as the strings 'on' and 'off'.
 *
 * Its own RankMath\Helpers\Options::normalize_data() maps 'on'/'true' to true
 * and 'off'/'false' to false, so both spellings turn up in a settings array
 * depending on whether it was read through the registry or straight off the
 * option.
 */
function nibwp_rankmath_is_on(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value === 1;
    }

    return in_array(strtolower((string) $value), ['on', 'true', '1', 'yes'], true);
}

/* ----------------------------------------------------------------------------
 * The meta keys this adapter owns
 * ------------------------------------------------------------------------- */

/** Rank Math's six robots directives (RankMath\Helpers\Choices::choices_robots). */
function nibwp_rankmath_robots_directives(): array
{
    return ['index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet'];
}

/** The three keys `rank_math_advanced_robots` may carry. */
function nibwp_rankmath_advanced_robots_keys(): array
{
    return ['max-snippet', 'max-video-preview', 'max-image-preview'];
}

/** Values Rank Math accepts for a Twitter card. */
function nibwp_rankmath_twitter_card_types(): array
{
    // 'summary_card' is the label its global setting stores; RankMath\OpenGraph
    // \Twitter::sanitize_card_type() turns anything outside
    // summary/summary_large_image/app/player into 'summary' on output.
    return ['summary', 'summary_card', 'summary_large_image', 'app', 'player'];
}

/** Plain-text post fields: ability field name => meta key. */
function nibwp_rankmath_text_fields(): array
{
    return [
        'title'               => 'rank_math_title',
        'description'         => 'rank_math_description',
        'breadcrumb_title'    => 'rank_math_breadcrumb_title',
        'og_title'            => 'rank_math_facebook_title',
        'og_description'      => 'rank_math_facebook_description',
        'og_image_id'         => 'rank_math_facebook_image_id',
        'twitter_title'       => 'rank_math_twitter_title',
        'twitter_description' => 'rank_math_twitter_description',
        'twitter_image_id'    => 'rank_math_twitter_image_id',
    ];
}

/** URL post fields: ability field name => meta key. */
function nibwp_rankmath_url_fields(): array
{
    return [
        'canonical'     => 'rank_math_canonical_url',
        'og_image'      => 'rank_math_facebook_image',
        'twitter_image' => 'rank_math_twitter_image',
    ];
}

/**
 * Every meta key this adapter is allowed to WRITE.
 *
 * `rank_math_primary_<taxonomy>` is the one dynamic key; it is validated
 * against taxonomy_exists() at the point of writing and is listed here as its
 * prefix so a check can assert nothing else dynamic creeps in.
 *
 * @return array{fixed: string[], dynamic_prefixes: string[]}
 */
function nibwp_rankmath_writable_meta(): array
{
    return [
        'fixed' => array_merge(
            array_values(nibwp_rankmath_text_fields()),
            array_values(nibwp_rankmath_url_fields()),
            [
                'rank_math_focus_keyword',
                'rank_math_robots',
                'rank_math_advanced_robots',
                'rank_math_pillar_content',
                'rank_math_twitter_card_type',
                'rank_math_twitter_use_facebook',
                'rank_math_rich_snippet',
            ]
        ),
        'dynamic_prefixes' => ['rank_math_primary_'],
    ];
}

/** Keys reported on a read but never written — Rank Math owns them. */
function nibwp_rankmath_readonly_meta(): array
{
    return ['rank_math_seo_score', 'rank_math_internal_links', 'rank_math_permalink'];
}

/* ----------------------------------------------------------------------------
 * Settings
 * ------------------------------------------------------------------------- */

/** Section name => option name. */
function nibwp_rankmath_option_names(): array
{
    return [
        'general'          => 'rank-math-options-general',
        'titles'           => 'rank-math-options-titles',
        'sitemap'          => 'rank-math-options-sitemap',
        'instant_indexing' => 'rank-math-options-instant-indexing',
    ];
}

/**
 * General-settings keys withheld from a read.
 *
 * The same list Rank Math's own rank-math/get-settings ability strips: Search
 * Console and Analytics state plus the Facebook app secret.
 */
function nibwp_rankmath_sensitive_general_keys(): array
{
    return ['facebook_secret', 'console_profile', 'searchConsole', 'analyticsData', 'analytics', 'console_caching_control'];
}

/** One settings section, straight off its option. */
function nibwp_rankmath_settings(string $section): array
{
    $names = nibwp_rankmath_option_names();
    if (!isset($names[$section])) {
        return [];
    }

    $value = get_option($names[$section], []);

    return is_array($value) ? $value : [];
}

/** One setting value. */
function nibwp_rankmath_setting(string $section, string $key, mixed $default_value = '')
{
    $settings = nibwp_rankmath_settings($section);

    return array_key_exists($key, $settings) ? $settings[$key] : $default_value;
}

/**
 * Persist one or more settings sections the way Rank Math does.
 *
 * Helper::update_all_settings() writes the three options it knows about; the
 * registry reset is what stops the rest of the request reading stale values.
 * instant_indexing is not part of that helper, so it is written directly.
 */
function nibwp_rankmath_save_settings(?array $general, ?array $titles, ?array $sitemap): void
{
    if (nibwp_rankmath_can_call('RankMath\\Helper', 'update_all_settings')) {
        call_user_func(['RankMath\\Helper', 'update_all_settings'], $general, $titles, $sitemap);
    } else {
        $names = nibwp_rankmath_option_names();
        if ($general !== null) {
            update_option($names['general'], $general);
        }
        if ($titles !== null) {
            update_option($names['titles'], $titles);
        }
        if ($sitemap !== null) {
            update_option($names['sitemap'], $sitemap);
        }
    }

    nibwp_rankmath_reset_settings_registry();
}

/** Drop Rank Math's in-memory settings cache after a write. */
function nibwp_rankmath_reset_settings_registry(): void
{
    if (!function_exists('rank_math')) {
        return;
    }

    try {
        $plugin = rank_math();
        if (!is_object($plugin)) {
            return;
        }
        $registry = $plugin->settings ?? null;
        if (is_object($registry) && method_exists($registry, 'reset')) {
            $registry->reset();
        }
    } catch (\Throwable $e) {
        // A container that refuses the property is not a failure worth
        // reporting: the option is already written, only the cache is stale.
        return;
    }
}

/** Active module ids (the rank_math_modules option). */
function nibwp_rankmath_active_modules(): array
{
    $modules = get_option('rank_math_modules', []);

    return is_array($modules) ? array_values(array_map('strval', $modules)) : [];
}

/** Is one Rank Math module switched on? */
function nibwp_rankmath_module_active(string $id): bool
{
    return in_array($id, nibwp_rankmath_active_modules(), true);
}

/* ----------------------------------------------------------------------------
 * Per-post read / write
 * ------------------------------------------------------------------------- */

/** The focus keywords, as a list. Rank Math stores them comma separated. */
function nibwp_rankmath_focus_keywords(int $post_id): array
{
    $raw = (string) get_post_meta($post_id, 'rank_math_focus_keyword', true);
    if (trim($raw) === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn(string $k): bool => $k !== ''));
}

/** The robots directives stored on a post, normalised to a clean list. */
function nibwp_rankmath_post_robots(int $post_id): array
{
    $robots = get_post_meta($post_id, 'rank_math_robots', true);
    if (!is_array($robots)) {
        return [];
    }

    return nibwp_rankmath_clean_robots($robots);
}

/**
 * Keep only real directives, and never both index and noindex.
 *
 * Rank Math's metabox presents them as one exclusive choice; a post carrying
 * both emits contradictory tags, and noindex is the one that wins in practice,
 * so it is the one kept.
 */
function nibwp_rankmath_clean_robots(array $robots): array
{
    $allowed = nibwp_rankmath_robots_directives();
    $clean = [];
    foreach ($robots as $directive) {
        $directive = strtolower(trim((string) $directive));
        if (in_array($directive, $allowed, true) && !in_array($directive, $clean, true)) {
            $clean[] = $directive;
        }
    }

    if (in_array('noindex', $clean, true)) {
        $clean = array_values(array_diff($clean, ['index']));
    }

    return array_values($clean);
}

/**
 * The robots directives that actually apply to a post.
 *
 * Mirrors RankMath\Helpers\WordPress::get_robots_defaults(): the post's own
 * value wins; failing that the post type's, but only when that post type has
 * `custom_robots` switched on; failing that the site-wide default.
 */
function nibwp_rankmath_effective_robots(int $post_id): array
{
    $own = nibwp_rankmath_post_robots($post_id);
    if ($own !== []) {
        return $own;
    }

    $post_type = (string) get_post_type($post_id);
    if ($post_type !== '' && nibwp_rankmath_is_on(nibwp_rankmath_setting('titles', "pt_{$post_type}_custom_robots"))) {
        $per_type = nibwp_rankmath_setting('titles', "pt_{$post_type}_robots", []);
        if (is_array($per_type) && $per_type !== []) {
            return nibwp_rankmath_clean_robots($per_type);
        }
    }

    $global = nibwp_rankmath_setting('titles', 'robots_global', []);

    return is_array($global) ? nibwp_rankmath_clean_robots($global) : [];
}

/** Is this post noindexed, once the defaults are resolved? */
function nibwp_rankmath_is_noindexed(int $post_id): bool
{
    return in_array('noindex', nibwp_rankmath_effective_robots($post_id), true);
}

/** The advanced-robots map stored on a post, restricted to the three keys. */
function nibwp_rankmath_post_advanced_robots(int $post_id): array
{
    $stored = get_post_meta($post_id, 'rank_math_advanced_robots', true);
    if (!is_array($stored)) {
        return [];
    }

    return array_intersect_key($stored, array_fill_keys(nibwp_rankmath_advanced_robots_keys(), true));
}

/**
 * The schema types attached to a post.
 *
 * Each is one `rank_math_schema_<Type>` meta row holding a JSON-LD array; the
 * `@type` inside it is authoritative because a template can be saved under a
 * key that no longer matches.
 */
function nibwp_rankmath_schema_types(int $post_id): array
{
    $prefix = 'rank_math_schema_';
    $types = [];

    foreach ((array) get_post_meta($post_id) as $key => $values) {
        if (strpos((string) $key, $prefix) !== 0) {
            continue;
        }
        $fallback = substr((string) $key, strlen($prefix));
        foreach ((array) $values as $raw) {
            $data = maybe_unserialize($raw);
            $type = is_array($data) && isset($data['@type']) ? $data['@type'] : $fallback;
            $type = is_array($type) ? (string) reset($type) : (string) $type;
            if ($type !== '' && !in_array($type, $types, true)) {
                $types[] = $type;
            }
        }
    }

    return $types;
}

/** The schema type a post would get from its post type, when it has none of its own. */
function nibwp_rankmath_default_schema_type(int $post_id): string
{
    if (nibwp_rankmath_can_call('RankMath\\Helper', 'get_default_schema_type')) {
        $type = call_user_func(['RankMath\\Helper', 'get_default_schema_type'], $post_id);
        if (is_string($type) && $type !== '') {
            return $type;
        }
        if ($type === false) {
            return '';
        }
    }

    $post_type = (string) get_post_type($post_id);

    return $post_type === '' ? '' : (string) nibwp_rankmath_setting('titles', "pt_{$post_type}_default_rich_snippet", '');
}

/** Everything this adapter can tell you about one post's SEO. */
function nibwp_rankmath_read_post(int $post_id): array
{
    $out = [
        'post_id'    => $post_id,
        'post_type'  => (string) get_post_type($post_id),
        'post_title' => (string) get_the_title($post_id),
        'permalink'  => (string) get_permalink($post_id),
    ];

    foreach (nibwp_rankmath_text_fields() as $field => $key) {
        $out[$field] = (string) get_post_meta($post_id, $key, true);
    }
    foreach (nibwp_rankmath_url_fields() as $field => $key) {
        $out[$field] = (string) get_post_meta($post_id, $key, true);
    }

    $out['focus_keywords'] = nibwp_rankmath_focus_keywords($post_id);
    $out['robots'] = nibwp_rankmath_post_robots($post_id);
    $out['effective_robots'] = nibwp_rankmath_effective_robots($post_id);
    $out['advanced_robots'] = nibwp_rankmath_post_advanced_robots($post_id);
    $out['pillar_content'] = get_post_meta($post_id, 'rank_math_pillar_content', true) === 'on';
    $out['twitter_card_type'] = (string) get_post_meta($post_id, 'rank_math_twitter_card_type', true);
    // Rank Math's metabox treats an unset value as "reuse Facebook", so the
    // default here has to be true rather than false.
    $out['twitter_use_facebook'] = get_post_meta($post_id, 'rank_math_twitter_use_facebook', true) !== 'off';
    $out['schema_types'] = nibwp_rankmath_schema_types($post_id);
    $out['schema_default_type'] = nibwp_rankmath_default_schema_type($post_id);
    $out['schema_disabled'] = get_post_meta($post_id, 'rank_math_rich_snippet', true) === 'off';
    $out['seo_score'] = (int) get_post_meta($post_id, 'rank_math_seo_score', true);
    $out['primary_terms'] = nibwp_rankmath_read_primary_terms($post_id);

    return $out;
}

/** The `rank_math_primary_<taxonomy>` rows a post carries. */
function nibwp_rankmath_read_primary_terms(int $post_id): array
{
    $prefix = 'rank_math_primary_';
    $out = [];

    foreach ((array) get_post_meta($post_id) as $key => $values) {
        if (strpos((string) $key, $prefix) !== 0) {
            continue;
        }
        $taxonomy = substr((string) $key, strlen($prefix));
        $value = is_array($values) ? reset($values) : $values;
        $out[$taxonomy] = (int) $value;
    }

    return $out;
}

/**
 * Write a meta key, or delete it when the value is empty.
 *
 * Rank Math's own writer does exactly this. It matters: an empty string on
 * `rank_math_title` is not "no override", it is an override that renders
 * nothing, and it suppresses the post-type title template.
 */
function nibwp_rankmath_put_meta(int $post_id, string $key, mixed $value, array &$changed): void
{
    $empty = $value === '' || $value === [] || $value === null;

    if ($empty) {
        delete_post_meta($post_id, $key);
    } else {
        update_post_meta($post_id, $key, $value);
    }

    $changed[] = $key;
}

/**
 * Apply an SEO patch to one post.
 *
 * Only keys present in the patch are touched; a key present but empty deletes
 * the override so the post falls back to Rank Math's defaults.
 *
 * @return string[]|WP_Error The meta keys written, or the first validation failure.
 */
function nibwp_rankmath_write_post(int $post_id, array $in): array|WP_Error
{
    $changed = [];

    foreach (nibwp_rankmath_text_fields() as $field => $key) {
        if (array_key_exists($field, $in)) {
            nibwp_rankmath_put_meta($post_id, $key, sanitize_text_field((string) $in[$field]), $changed);
        }
    }

    foreach (nibwp_rankmath_url_fields() as $field => $key) {
        if (array_key_exists($field, $in)) {
            nibwp_rankmath_put_meta($post_id, $key, esc_url_raw((string) $in[$field]), $changed);
        }
    }

    if (array_key_exists('focus_keywords', $in)) {
        $keywords = is_array($in['focus_keywords']) ? $in['focus_keywords'] : explode(',', (string) $in['focus_keywords']);
        $keywords = array_values(array_filter(array_map(
            static fn($k): string => sanitize_text_field(trim((string) $k)),
            $keywords
        ), static fn(string $k): bool => $k !== ''));
        nibwp_rankmath_put_meta($post_id, 'rank_math_focus_keyword', implode(', ', $keywords), $changed);
    }

    if (array_key_exists('robots', $in)) {
        if (!is_array($in['robots'])) {
            return nibwp_rankmath_err('nibwp_rankmath_bad_robots', __('robots must be an array of directives.', 'nibwp'));
        }
        $unknown = array_diff(array_map('strval', $in['robots']), nibwp_rankmath_robots_directives());
        if ($unknown !== []) {
            return nibwp_rankmath_err(
                'nibwp_rankmath_bad_robots',
                sprintf(
                    /* translators: 1: the rejected values, 2: the accepted values. */
                    __('Unknown robots directive(s): %1$s. Rank Math accepts only %2$s.', 'nibwp'),
                    implode(', ', $unknown),
                    implode(', ', nibwp_rankmath_robots_directives())
                )
            );
        }
        nibwp_rankmath_put_meta($post_id, 'rank_math_robots', nibwp_rankmath_clean_robots($in['robots']), $changed);
    }

    if (array_key_exists('advanced_robots', $in)) {
        if (!is_array($in['advanced_robots'])) {
            return nibwp_rankmath_err('nibwp_rankmath_bad_advanced_robots', __('advanced_robots must be an object.', 'nibwp'));
        }
        $unknown = array_diff(array_keys($in['advanced_robots']), nibwp_rankmath_advanced_robots_keys());
        if ($unknown !== []) {
            return nibwp_rankmath_err(
                'nibwp_rankmath_bad_advanced_robots',
                sprintf(
                    /* translators: 1: the rejected keys, 2: the accepted keys. */
                    __('Unknown advanced_robots key(s): %1$s. Rank Math stores only %2$s.', 'nibwp'),
                    implode(', ', $unknown),
                    implode(', ', nibwp_rankmath_advanced_robots_keys())
                )
            );
        }
        // Rank Math stores a length or false. -1 and 'large' both have to
        // survive, so a number stays a number rather than becoming "-1".
        $advanced = [];
        foreach ($in['advanced_robots'] as $key => $value) {
            $advanced[(string) $key] = (is_bool($value) || is_int($value)) ? $value : sanitize_text_field((string) $value);
        }
        nibwp_rankmath_put_meta($post_id, 'rank_math_advanced_robots', $advanced, $changed);
    }

    if (array_key_exists('pillar_content', $in)) {
        nibwp_rankmath_put_meta($post_id, 'rank_math_pillar_content', !empty($in['pillar_content']) ? 'on' : '', $changed);
    }

    if (array_key_exists('twitter_card_type', $in)) {
        $card = sanitize_text_field((string) $in['twitter_card_type']);
        if ($card !== '' && !in_array($card, nibwp_rankmath_twitter_card_types(), true)) {
            return nibwp_rankmath_err(
                'nibwp_rankmath_bad_card_type',
                sprintf(
                    /* translators: the accepted Twitter card values. */
                    __('twitter_card_type must be one of %s.', 'nibwp'),
                    implode(', ', nibwp_rankmath_twitter_card_types())
                )
            );
        }
        nibwp_rankmath_put_meta($post_id, 'rank_math_twitter_card_type', $card, $changed);
    }

    if (array_key_exists('twitter_use_facebook', $in)) {
        // Both spellings have to be stored: there is no "unset" that means off,
        // because an absent value already reads as on.
        nibwp_rankmath_put_meta($post_id, 'rank_math_twitter_use_facebook', !empty($in['twitter_use_facebook']) ? 'on' : 'off', $changed);
    }

    if (array_key_exists('schema_disabled', $in)) {
        nibwp_rankmath_put_meta($post_id, 'rank_math_rich_snippet', !empty($in['schema_disabled']) ? 'off' : '', $changed);
    }

    if (array_key_exists('primary_terms', $in)) {
        if (!is_array($in['primary_terms'])) {
            return nibwp_rankmath_err('nibwp_rankmath_bad_primary_terms', __('primary_terms must be an object of taxonomy => term id.', 'nibwp'));
        }
        foreach ($in['primary_terms'] as $taxonomy => $term_id) {
            $taxonomy = sanitize_key((string) $taxonomy);
            if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
                return nibwp_rankmath_err(
                    'nibwp_rankmath_bad_taxonomy',
                    sprintf(
                        /* translators: the unknown taxonomy name. */
                        __('Unknown taxonomy: %s.', 'nibwp'),
                        (string) $taxonomy
                    )
                );
            }
            $term_id = (int) $term_id;
            nibwp_rankmath_put_meta($post_id, 'rank_math_primary_' . $taxonomy, $term_id > 0 ? (string) $term_id : '', $changed);
        }
    }

    return $changed;
}

/* ----------------------------------------------------------------------------
 * Redirections
 * ------------------------------------------------------------------------- */

/** Is the redirections module usable through Rank Math's own classes? */
function nibwp_rankmath_redirections_writable(): bool
{
    return nibwp_rankmath_can_call('RankMath\\Redirections\\Redirection', 'from')
        && nibwp_rankmath_can_call('RankMath\\Redirections\\DB', 'delete');
}

/** Does the redirections table exist? */
function nibwp_rankmath_table_exists(string $suffix): bool
{
    global $wpdb;

    $table = nibwp_rankmath_table($suffix);

    return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
}

/**
 * Read redirection rows.
 *
 * Through RankMath\Redirections\DB when it is loaded, otherwise a plain SELECT
 * against the documented schema — a read cannot leave the redirection cache
 * disagreeing with anything, and the audit has to work whether or not the
 * module's classes happen to be in memory.
 *
 * @return array{rows: array<int, array<string, mixed>>, total: int}|WP_Error
 */
function nibwp_rankmath_redirection_rows(int $limit, int $offset, string $status = 'any', string $search = ''): array|WP_Error
{
    if (nibwp_rankmath_can_call('RankMath\\Redirections\\DB', 'get_redirections')) {
        $result = call_user_func(['RankMath\\Redirections\\DB', 'get_redirections'], [
            'limit'  => $limit,
            'paged'  => (int) floor($offset / max($limit, 1)) + 1,
            'status' => $status,
            'search' => $search,
        ]);
        $rows = is_array($result) && isset($result['redirections']) && is_array($result['redirections']) ? $result['redirections'] : [];

        return [
            'rows'  => array_map('nibwp_rankmath_shape_redirection', $rows),
            'total' => (int) ($result['count'] ?? count($rows)),
        ];
    }

    if (!nibwp_rankmath_table_exists('rank_math_redirections')) {
        return nibwp_rankmath_err(
            'nibwp_rankmath_no_redirections',
            __('The Rank Math Redirections module is not active, so there is no redirections table to read. Switch it on with nibwp/rankmath-settings set_module.', 'nibwp')
        );
    }

    global $wpdb;
    $table = nibwp_rankmath_table('rank_math_redirections');
    $where = in_array($status, ['active', 'inactive', 'trashed'], true)
        ? $wpdb->prepare('status = %s', $status)
        : "status <> 'trashed'";

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix and a literal.
    $rows = $wpdb->get_results(
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->prepare("SELECT * FROM `{$table}` WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset),
        ARRAY_A
    );
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE {$where}");

    return ['rows' => array_map('nibwp_rankmath_shape_redirection', (array) $rows), 'total' => $total];
}

/** One redirection row, with its serialized sources unpacked. */
function nibwp_rankmath_shape_redirection(mixed $row): array
{
    $row = is_object($row) ? get_object_vars($row) : (array) $row;
    $sources = maybe_unserialize($row['sources'] ?? '');

    return [
        'id'            => (int) ($row['id'] ?? 0),
        'sources'       => is_array($sources) ? array_values($sources) : [],
        'url_to'        => (string) ($row['url_to'] ?? ''),
        'header_code'   => (int) ($row['header_code'] ?? 301),
        'hits'          => (int) ($row['hits'] ?? 0),
        'status'        => (string) ($row['status'] ?? 'active'),
        'created'       => (string) ($row['created'] ?? ''),
        'updated'       => (string) ($row['updated'] ?? ''),
        'last_accessed' => (string) ($row['last_accessed'] ?? ''),
    ];
}

/** Validate an incoming sources list into Rank Math's own shape. */
function nibwp_rankmath_build_sources(array $sources): array|WP_Error
{
    $comparisons = ['exact', 'contains', 'start', 'end', 'regex'];
    $built = [];

    foreach ($sources as $source) {
        $source = is_array($source) ? $source : ['pattern' => (string) $source];
        $pattern = trim((string) ($source['pattern'] ?? ''));
        if ($pattern === '') {
            return nibwp_rankmath_err('nibwp_rankmath_bad_source', __('Every source needs a non-empty pattern.', 'nibwp'));
        }
        $comparison = (string) ($source['comparison'] ?? 'exact');
        if (!in_array($comparison, $comparisons, true)) {
            return nibwp_rankmath_err(
                'nibwp_rankmath_bad_source',
                sprintf(
                    /* translators: the accepted comparison values. */
                    __('comparison must be one of %s.', 'nibwp'),
                    implode(', ', $comparisons)
                )
            );
        }
        $built[] = [
            // Rank Math stores the string 'case' here, not a boolean.
            'ignore'     => !empty($source['ignore']) ? 'case' : '',
            'pattern'    => $pattern,
            'comparison' => $comparison,
        ];
    }

    if ($built === []) {
        return nibwp_rankmath_err('nibwp_rankmath_bad_source', __('At least one source is required.', 'nibwp'));
    }

    return $built;
}

/** Header codes Rank Math offers. */
function nibwp_rankmath_header_codes(): array
{
    return [301, 302, 307, 410, 451];
}

/* ----------------------------------------------------------------------------
 * 404 monitor
 * ------------------------------------------------------------------------- */

/**
 * Read 404 log rows.
 *
 * @return array{rows: array<int, array<string, mixed>>, total: int}|WP_Error
 */
function nibwp_rankmath_404_rows(int $limit, int $offset): array|WP_Error
{
    if (nibwp_rankmath_can_call('RankMath\\Monitor\\DB', 'get_logs')) {
        $result = call_user_func(['RankMath\\Monitor\\DB', 'get_logs'], [
            'orderby' => 'times_accessed',
            'order'   => 'DESC',
            'limit'   => $limit,
            'paged'   => (int) floor($offset / max($limit, 1)) + 1,
        ]);
        $rows = is_array($result) && isset($result['logs']) && is_array($result['logs']) ? $result['logs'] : [];

        return ['rows' => array_map('nibwp_rankmath_shape_404', $rows), 'total' => (int) ($result['count'] ?? count($rows))];
    }

    if (!nibwp_rankmath_table_exists('rank_math_404_logs')) {
        return nibwp_rankmath_err(
            'nibwp_rankmath_no_404_monitor',
            __('The Rank Math 404 Monitor module is not active, so nothing has been recorded. Switch it on with nibwp/rankmath-settings set_module.', 'nibwp')
        );
    }

    global $wpdb;
    $table = nibwp_rankmath_table('rank_math_404_logs');
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix and a literal.
    $rows = $wpdb->get_results(
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->prepare("SELECT * FROM `{$table}` ORDER BY times_accessed DESC LIMIT %d OFFSET %d", $limit, $offset),
        ARRAY_A
    );
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");

    return ['rows' => array_map('nibwp_rankmath_shape_404', (array) $rows), 'total' => $total];
}

/** One 404 log row. */
function nibwp_rankmath_shape_404(mixed $row): array
{
    $row = is_object($row) ? get_object_vars($row) : (array) $row;

    return [
        'id'         => (int) ($row['id'] ?? 0),
        'uri'        => (string) ($row['uri'] ?? ''),
        'hits'       => (int) ($row['times_accessed'] ?? 0),
        'accessed'   => (string) ($row['accessed'] ?? ''),
        'referer'    => (string) ($row['referer'] ?? ''),
        'user_agent' => (string) ($row['user_agent'] ?? ''),
    ];
}

/* ----------------------------------------------------------------------------
 * Sitemap
 * ------------------------------------------------------------------------- */

/** The sitemap index URL. */
function nibwp_rankmath_sitemap_index_url(): string
{
    $slug = 'sitemap_index';
    if (nibwp_rankmath_can_call('RankMath\\Sitemap\\Sitemap', 'get_sitemap_index_slug')) {
        $slug = (string) call_user_func(['RankMath\\Sitemap\\Sitemap', 'get_sitemap_index_slug']);
    }
    if (nibwp_rankmath_can_call('RankMath\\Sitemap\\Router', 'get_base_url')) {
        return (string) call_user_func(['RankMath\\Sitemap\\Router', 'get_base_url'], $slug . '.xml');
    }

    return home_url('/' . $slug . '.xml');
}

/** A comma-separated ID list from the sitemap option, as integers. */
function nibwp_rankmath_exclusion_ids(string $key): array
{
    $raw = (string) nibwp_rankmath_setting('sitemap', $key, '');
    $ids = array_map('intval', array_filter(array_map('trim', explode(',', $raw)), static fn(string $v): bool => $v !== ''));

    return array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
}

/** Post types whose sitemap inclusion the option records. */
function nibwp_rankmath_sitemap_toggles(string $prefix): array
{
    $out = [];
    foreach (nibwp_rankmath_settings('sitemap') as $key => $value) {
        $key = (string) $key;
        if (strpos($key, $prefix) !== 0 || substr($key, -strlen('_sitemap')) !== '_sitemap') {
            continue;
        }
        $slug = substr($key, strlen($prefix), -strlen('_sitemap'));
        if ($slug !== '') {
            $out[$slug] = nibwp_rankmath_is_on($value);
        }
    }

    return $out;
}

/* ----------------------------------------------------------------------------
 * Ability 1 — info
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/rankmath-info', [
    'label'       => __('Rank Math — Info', 'nibwp'),
    'description' => 'Detect Rank Math, its version and database version, which of its modules are switched on, which of its own capabilities the current user holds, and where its redirections and 404 tables live (read-only).',
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'active'            => ['type' => 'boolean'],
            'version'           => ['type' => 'string'],
            'db_version'        => ['type' => 'string'],
            'configured'        => ['type' => 'boolean'],
            'modules'           => ['type' => 'array', 'items' => ['type' => 'string']],
            'module_status'     => ['type' => 'object'],
            'sitemap_index_url' => ['type' => 'string'],
            'tables'            => ['type' => 'object'],
            'capabilities'      => ['type' => 'object'],
            'meta_prefix'       => ['type' => 'string'],
        ],
        'required' => ['active', 'version', 'modules', 'module_status'],
    ],
    'execute_callback'    => 'nibwp_rankmath_info',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Call this first. Half of Rank Math is modules that can be off, and an ability that reads a module\'s table returns nothing rather than an error when its module was never switched on.',
                'redirections and 404-monitor are the two that matter most: no redirections module means no redirections table, and no 404 monitor means nothing has been recorded to audit.',
                'Per-post values are ordinary post meta under the rank_math_ prefix, so a post SEO field can also be read through any generic meta tool — but write them through nibwp/rankmath-post, which knows which keys take arrays and which must be deleted rather than emptied.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_rankmath_info(array $input): array|WP_Error
{
    if ($guard = nibwp_rankmath_guard()) {
        return $guard;
    }

    return nibwp_rankmath_try(static function (): array {
        $modules = nibwp_rankmath_active_modules();
        $watched = ['redirections', '404-monitor', 'sitemap', 'rich-snippet', 'link-counter', 'image-seo', 'local-seo', 'instant-indexing', 'seo-analysis', 'role-manager'];

        $status = [];
        foreach ($watched as $id) {
            $status[$id] = in_array($id, $modules, true);
        }

        return [
            'active'     => true,
            'version'    => defined('RANK_MATH_VERSION') ? (string) RANK_MATH_VERSION : '',
            'db_version' => (string) get_option('rank_math_db_version', ''),
            'configured' => !empty(get_option('rank_math_is_configured')),
            'modules'    => $modules,
            'module_status' => $status,
            'sitemap_index_url' => nibwp_rankmath_sitemap_index_url(),
            'tables' => [
                'redirections'       => nibwp_rankmath_table('rank_math_redirections'),
                'redirections_cache' => nibwp_rankmath_table('rank_math_redirections_cache'),
                'logs_404'           => nibwp_rankmath_table('rank_math_404_logs'),
                'internal_links'     => nibwp_rankmath_table('rank_math_internal_links'),
            ],
            // Rank Math grants its own capabilities through a Role Manager
            // rather than on activation, so an editor can hold manage_options
            // on a small site and still be refused the redirections screen.
            'capabilities' => [
                'rank_math_onpage_general' => current_user_can('rank_math_onpage_general'),
                'rank_math_titles'         => current_user_can('rank_math_titles'),
                'rank_math_sitemap'        => current_user_can('rank_math_sitemap'),
                'rank_math_redirections'   => current_user_can('rank_math_redirections'),
                'rank_math_404_monitor'    => current_user_can('rank_math_404_monitor'),
                'rank_math_site_analysis'  => current_user_can('rank_math_site_analysis'),
                'manage_options'           => current_user_can('manage_options'),
            ],
            'meta_prefix' => 'rank_math_',
            'options'     => array_values(nibwp_rankmath_option_names()),
            'note'        => __('Rank Math keeps per-post SEO in rank_math_* post meta, global settings in four rank-math-options-* options, and redirections plus 404s in its own tables.', 'nibwp'),
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 2 — per-post SEO
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/rankmath-post', [
    'label'       => __('Rank Math — Post SEO', 'nibwp'),
    'description' => 'Read or write one post\'s Rank Math SEO: meta title and description, focus keywords, canonical URL, robots and advanced robots, Open Graph and Twitter overrides, the pillar-content flag, primary terms, and whether schema is switched off. Actions: get, set.',
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['get', 'set'], 'description' => 'The action to perform.'],
            'post_id' => ['type' => 'integer', 'description' => 'The post, page or custom-post-type ID.'],
            'title'   => ['type' => 'string', 'description' => 'SEO title override (rank_math_title). Rank Math template variables such as %title% %sep% %sitename% are kept verbatim. Send an empty string to clear the override and fall back to the post-type template.'],
            'description' => ['type' => 'string', 'description' => 'Meta description override (rank_math_description).'],
            'focus_keywords' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Focus keywords, first one primary. Stored comma separated in rank_math_focus_keyword.'],
            'canonical' => ['type' => 'string', 'description' => 'Canonical URL override (rank_math_canonical_url).'],
            'robots' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => ['index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet']],
                'description' => 'Robots directives for this post. An empty array clears the override so the post type or site default applies. index and noindex are exclusive; noindex wins.',
            ],
            'advanced_robots' => ['type' => 'object', 'description' => 'Only max-snippet, max-video-preview and max-image-preview. Values are the lengths Google is given, e.g. { "max-snippet": -1, "max-image-preview": "large" }.'],
            'breadcrumb_title' => ['type' => 'string', 'description' => 'Label for this post in Rank Math breadcrumbs.'],
            'og_title' => ['type' => 'string', 'description' => 'Open Graph / Facebook title override.'],
            'og_description' => ['type' => 'string'],
            'og_image' => ['type' => 'string', 'description' => 'Open Graph image URL.'],
            'og_image_id' => ['type' => 'string', 'description' => 'Attachment ID for the Open Graph image, when it is in the media library.'],
            'twitter_title' => ['type' => 'string'],
            'twitter_description' => ['type' => 'string'],
            'twitter_image' => ['type' => 'string'],
            'twitter_image_id' => ['type' => 'string'],
            'twitter_card_type' => ['type' => 'string', 'enum' => ['summary', 'summary_card', 'summary_large_image', 'app', 'player']],
            'twitter_use_facebook' => ['type' => 'boolean', 'description' => 'Reuse the Open Graph values for Twitter. Rank Math treats an unset value as true.'],
            'pillar_content' => ['type' => 'boolean', 'description' => 'Mark this post as pillar content, which makes it a preferred internal-link target.'],
            'primary_terms' => ['type' => 'object', 'description' => 'Map of taxonomy slug => term ID, stored as rank_math_primary_<taxonomy>. The taxonomy must exist.'],
            'schema_disabled' => ['type' => 'boolean', 'description' => 'true writes rank_math_rich_snippet = off, which suppresses the post type\'s default schema for this post. Schema bodies themselves are read-only here.'],
        ],
        'required' => ['action', 'post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_rankmath_post',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'get returns both the stored overrides and effective_robots, which is what actually applies once the post type and site defaults are resolved. A post with an empty robots array is not indexable by default — read effective_robots before concluding anything about indexing.',
                'set touches only the fields you send. Sending a field with an empty value DELETES the override rather than storing an empty string, because an empty rank_math_title suppresses the post-type title template instead of inheriting it.',
                'Titles and descriptions may contain Rank Math template variables (%title%, %sep%, %sitename%, %excerpt%). They are stored verbatim and resolved at render time.',
                'Schema is read-only: schema_types and schema_default_type report what a post has, and schema_disabled switches the default off, but schema bodies are edited in Rank Math because each one is a tracked meta row the Schema module owns.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_rankmath_post(array $input): array|WP_Error
{
    if ($guard = nibwp_rankmath_guard()) {
        return $guard;
    }

    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0) {
        return nibwp_rankmath_err('nibwp_rankmath_bad_id', __('A valid post ID is required.', 'nibwp'));
    }
    if (!get_post($post_id)) {
        return nibwp_rankmath_err('nibwp_rankmath_not_found', __('No post with that ID.', 'nibwp'));
    }

    $action = (string) ($input['action'] ?? '');

    if ($action === 'get') {
        return nibwp_rankmath_read_post($post_id);
    }

    if ($action === 'set') {
        $changed = nibwp_rankmath_write_post($post_id, $input);
        if ($changed instanceof WP_Error) {
            return $changed;
        }
        if ($changed === []) {
            return nibwp_rankmath_err('nibwp_rankmath_nothing_to_set', __('No SEO fields were supplied, so nothing was written.', 'nibwp'));
        }

        return ['saved' => true, 'post_id' => $post_id, 'changed' => $changed, 'seo' => nibwp_rankmath_read_post($post_id)];
    }

    return nibwp_rankmath_err('nibwp_rankmath_bad_action', __('Unknown action. Use get or set.', 'nibwp'));
}

/* ----------------------------------------------------------------------------
 * Ability 3 — bulk SEO across a query
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/rankmath-bulk', [
    'label'       => __('Rank Math — Bulk SEO', 'nibwp'),
    'description' => 'Read or write Rank Math SEO across a query: filter by post type, status, category, search term, or by what is missing (no SEO title, no meta description, no focus keyword, noindexed), then read the matches or apply one patch to all of them. Actions: get, set.',
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'    => ['type' => 'string', 'enum' => ['get', 'set'], 'description' => 'The action to perform. get is read-only.'],
            'post_type' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Post types to query. Defaults to post and page.'],
            'post_status' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Post statuses to query. Defaults to publish.'],
            'category'  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Category slugs to restrict to.'],
            'taxonomy'  => ['type' => 'string', 'description' => 'A taxonomy to restrict by, used with terms. Defaults to category when terms are given.'],
            'terms'     => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Term slugs or IDs within taxonomy.'],
            'search'    => ['type' => 'string', 'description' => 'Restrict to posts matching this search term.'],
            'missing_title' => ['type' => 'boolean', 'description' => 'Only posts with no rank_math_title override.'],
            'missing_description' => ['type' => 'boolean', 'description' => 'Only posts with no rank_math_description.'],
            'missing_focus_keyword' => ['type' => 'boolean', 'description' => 'Only posts with no rank_math_focus_keyword.'],
            'noindexed' => ['type' => 'boolean', 'description' => 'Only posts whose effective robots include noindex.'],
            'per_page'  => ['type' => 'integer', 'default' => 20, 'description' => 'Matches per page, 1 to 100.'],
            'page'      => ['type' => 'integer', 'default' => 1],
            'fields'    => ['type' => 'object', 'description' => 'For set: one patch applied to every match. Same field names as nibwp/rankmath-post set.'],
            'confirm'   => ['type' => 'boolean', 'default' => false, 'description' => 'Must be true for set. A bulk write overwrites existing overrides on every match and there is no undo.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'count'    => ['type' => 'integer'],
            'total'    => ['type' => 'integer'],
            'page'     => ['type' => 'integer'],
            'per_page' => ['type' => 'integer'],
            'items'    => ['type' => 'array', 'items' => ['type' => 'object']],
        ],
        'required' => ['count', 'items'],
    ],
    'execute_callback'    => 'nibwp_rankmath_bulk',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Run get with the same filters first and read the items. set applies one patch to every match on the requested page, overwrites whatever override was there, and cannot be undone — which is why it needs confirm and why this tool is annotated destructive even though get only reads.',
                'The missing_* filters ask whether the OVERRIDE is absent, not whether the rendered page has a title: a post with no rank_math_title still renders the post type\'s title template. Use them to find work, not to declare a fault.',
                'noindexed resolves the post type and site defaults, so it also catches posts that are noindexed by a setting rather than by their own meta.',
                'per_page is capped at 100. Page through rather than asking for everything.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/** Build the WP_Query args from the bulk filters. */
function nibwp_rankmath_bulk_query_args(array $input, array $page): array
{
    $post_type = (array) ($input['post_type'] ?? ['post', 'page']);
    $post_type = array_values(array_filter(array_map('sanitize_key', $post_type)));
    $post_status = (array) ($input['post_status'] ?? ['publish']);
    $post_status = array_values(array_filter(array_map('sanitize_key', $post_status)));

    $args = [
        'post_type'      => $post_type === [] ? ['post', 'page'] : $post_type,
        'post_status'    => $post_status === [] ? ['publish'] : $post_status,
        'posts_per_page' => $page['limit'],
        'paged'          => $page['page'],
        'orderby'        => 'ID',
        'order'          => 'DESC',
        'fields'         => 'ids',
        // A bulk SEO pass has no business warming the term and meta caches for
        // every match; the fields it reads are fetched per post anyway.
        'no_found_rows'  => false,
        'ignore_sticky_posts' => true,
    ];

    if (!empty($input['search'])) {
        $args['s'] = sanitize_text_field((string) $input['search']);
    }

    $terms = (array) ($input['terms'] ?? []);
    $taxonomy = sanitize_key((string) ($input['taxonomy'] ?? 'category'));
    if (!empty($input['category'])) {
        $terms = (array) $input['category'];
        $taxonomy = 'category';
    }
    $terms = array_values(array_filter(array_map(static fn($t): string => sanitize_text_field((string) $t), $terms)));
    if ($terms !== [] && taxonomy_exists($taxonomy)) {
        $numeric = array_filter($terms, 'is_numeric');
        $args['tax_query'] = [[
            'taxonomy' => $taxonomy,
            'field'    => count($numeric) === count($terms) ? 'term_id' : 'slug',
            'terms'    => count($numeric) === count($terms) ? array_map('intval', $terms) : $terms,
        ]];
    }

    // The "missing" filters run on the meta table. NOT EXISTS alone is not
    // enough: Rank Math's own writer leaves an empty row behind in some import
    // paths, so an empty value has to count as missing too.
    $meta_query = [];
    $missing = [
        'missing_title'         => 'rank_math_title',
        'missing_description'   => 'rank_math_description',
        'missing_focus_keyword' => 'rank_math_focus_keyword',
    ];
    foreach ($missing as $flag => $key) {
        if (!empty($input[$flag])) {
            $meta_query[] = [
                'relation' => 'OR',
                ['key' => $key, 'compare' => 'NOT EXISTS'],
                ['key' => $key, 'value' => '', 'compare' => '='],
            ];
        }
    }
    if ($meta_query !== []) {
        $meta_query['relation'] = 'AND';
        $args['meta_query'] = $meta_query;
    }

    return $args;
}

function nibwp_rankmath_bulk(array $input): array|WP_Error
{
    if ($guard = nibwp_rankmath_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    if (!in_array($action, ['get', 'set'], true)) {
        return nibwp_rankmath_err('nibwp_rankmath_bad_action', __('Unknown action. Use get or set.', 'nibwp'));
    }

    // The confirm gate comes before the filters are validated on purpose: a
    // caller who forgot confirm should be told that, not sent to fix a filter
    // and re-issue the write believing it is now approved.
    if ($action === 'set' && !(bool) ($input['confirm'] ?? false)) {
        return nibwp_rankmath_err(
            'nibwp_rankmath_unconfirmed',
            __('A bulk write overwrites the SEO overrides on every match and cannot be undone. Run action=get with the same filters, then re-issue with confirm set to true.', 'nibwp')
        );
    }

    $fields = (array) ($input['fields'] ?? []);
    if ($action === 'set' && $fields === []) {
        return nibwp_rankmath_err('nibwp_rankmath_nothing_to_set', __('Provide a non-empty "fields" object for set.', 'nibwp'));
    }

    return nibwp_rankmath_try(static function () use ($input, $action, $fields) {
        $page = nibwp_rankmath_paginate($input);
        $query = new WP_Query(nibwp_rankmath_bulk_query_args($input, $page));
        $ids = array_map('intval', (array) $query->posts);

        if (!empty($input['noindexed'])) {
            $ids = array_values(array_filter($ids, 'nibwp_rankmath_is_noindexed'));
        }

        $items = [];
        foreach ($ids as $id) {
            if ($action === 'get') {
                $items[] = nibwp_rankmath_read_post($id);
                continue;
            }

            $changed = nibwp_rankmath_write_post($id, $fields);
            if ($changed instanceof WP_Error) {
                // A rejected patch is rejected for every post, so stop rather
                // than writing half the query and reporting a partial success.
                return $changed;
            }
            $items[] = ['post_id' => $id, 'changed' => $changed];
        }

        return [
            'action'   => $action,
            'count'    => count($items),
            'total'    => (int) $query->found_posts,
            'page'     => $page['page'],
            'per_page' => $page['per_page'],
            'items'    => $items,
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 4 — global settings
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/rankmath-settings', [
    'label'       => __('Rank Math — Settings', 'nibwp'),
    'description' => 'Read the Rank Math settings snapshot (general, titles, sitemap, instant indexing, module status), and write the safe subset: site-wide robots and title defaults, a post type\'s title and description templates and robots, and switching a module on or off. Actions: get, update_global, update_post_type, set_module.',
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['get', 'update_global', 'update_post_type', 'set_module'], 'description' => 'The action to perform.'],
            'sections' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => ['general', 'titles', 'sitemap', 'instant_indexing', 'modules']],
                'description' => 'For get: return only these sections. Omit for all of them.',
            ],
            'robots_global' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => ['index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet']],
                'description' => 'For update_global: the site-wide robots default.',
            ],
            'noindex_empty_taxonomies' => ['type' => 'boolean', 'description' => 'For update_global: noindex taxonomy archives with no posts.'],
            'title_separator' => ['type' => 'string', 'description' => 'For update_global: the %sep% character, e.g. "-", "|", "&mdash;".'],
            'capitalize_titles' => ['type' => 'boolean', 'description' => 'For update_global: capitalise every word in SEO titles.'],
            'twitter_card_type' => ['type' => 'string', 'enum' => ['summary_card', 'summary_large_image'], 'description' => 'For update_global: the default Twitter card.'],
            'open_graph_image' => ['type' => 'string', 'description' => 'For update_global: the fallback Open Graph image URL.'],
            'post_type' => ['type' => 'string', 'description' => 'For update_post_type: which post type to configure.'],
            'title' => ['type' => 'string', 'description' => 'For update_post_type: the single title template, e.g. "%title% %sep% %sitename%".'],
            'description' => ['type' => 'string', 'description' => 'For update_post_type: the single description template, e.g. "%excerpt%".'],
            'archive_title' => ['type' => 'string', 'description' => 'For update_post_type: the archive title template.'],
            'archive_description' => ['type' => 'string', 'description' => 'For update_post_type: the archive description template.'],
            'custom_robots' => ['type' => 'boolean', 'description' => 'For update_post_type: give this post type its own robots default instead of the site-wide one.'],
            'robots' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => ['index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet']],
                'description' => 'For update_post_type: that post type\'s robots default. Only applies when custom_robots is on.',
            ],
            'module' => ['type' => 'string', 'description' => 'For set_module: the module id, e.g. redirections, 404-monitor, sitemap, rich-snippet, link-counter, image-seo, local-seo, instant-indexing.'],
            'enabled' => ['type' => 'boolean', 'description' => 'For set_module: on or off.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_rankmath_settings_ability',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'get first, always. The titles section is hundreds of keys — every post type and taxonomy has its own title, description, robots and schema defaults — and a write that guesses a key name silently creates a setting Rank Math never reads.',
                'Only the fields listed here are writable. That is deliberate: this covers the settings whose accepted values are pinned down, and leaves the rest to Rank Math\'s own screens rather than storing a value it will not understand.',
                'Google Search Console, Analytics and the Facebook app secret are withheld from get.',
                'set_module is the one that changes what exists rather than what is configured: switching redirections or 404-monitor on creates their database tables, and switching redirections off stops every redirect on the site from firing while leaving the rules in place.',
                'Sitemap settings and sitemap exclusions live in nibwp/rankmath-sitemap, not here.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_rankmath_settings_ability(array $input): array|WP_Error
{
    if ($guard = nibwp_rankmath_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');

    return match ($action) {
        'get'              => nibwp_rankmath_settings_get($input),
        'update_global'    => nibwp_rankmath_settings_update_global($input),
        'update_post_type' => nibwp_rankmath_settings_update_post_type($input),
        'set_module'       => nibwp_rankmath_settings_set_module($input),
        default            => nibwp_rankmath_err('nibwp_rankmath_bad_action', __('Unknown action. Use get, update_global, update_post_type or set_module.', 'nibwp')),
    };
}

function nibwp_rankmath_settings_get(array $input): array
{
    $general = nibwp_rankmath_settings('general');
    foreach (nibwp_rankmath_sensitive_general_keys() as $key) {
        unset($general[$key]);
    }

    $snapshot = [
        'general'          => $general,
        'titles'           => nibwp_rankmath_settings('titles'),
        'sitemap'          => nibwp_rankmath_settings('sitemap'),
        'instant_indexing' => nibwp_rankmath_settings('instant_indexing'),
        'modules'          => nibwp_rankmath_active_modules(),
    ];

    $sections = array_values(array_filter(array_map('strval', (array) ($input['sections'] ?? []))));
    if ($sections !== []) {
        $snapshot = array_intersect_key($snapshot, array_fill_keys($sections, true));
    }

    $snapshot['withheld'] = nibwp_rankmath_sensitive_general_keys();

    return $snapshot;
}

function nibwp_rankmath_settings_update_global(array $input): array|WP_Error
{
    $titles = nibwp_rankmath_settings('titles');
    $updated = [];

    if (array_key_exists('robots_global', $input)) {
        if (!is_array($input['robots_global'])) {
            return nibwp_rankmath_err('nibwp_rankmath_bad_robots', __('robots_global must be an array of directives.', 'nibwp'));
        }
        $unknown = array_diff(array_map('strval', $input['robots_global']), nibwp_rankmath_robots_directives());
        if ($unknown !== []) {
            return nibwp_rankmath_err(
                'nibwp_rankmath_bad_robots',
                sprintf(
                    /* translators: 1: the rejected values, 2: the accepted values. */
                    __('Unknown robots directive(s): %1$s. Rank Math accepts only %2$s.', 'nibwp'),
                    implode(', ', $unknown),
                    implode(', ', nibwp_rankmath_robots_directives())
                )
            );
        }
        $titles['robots_global'] = nibwp_rankmath_clean_robots($input['robots_global']);
        $updated[] = 'robots_global';
    }

    foreach (['noindex_empty_taxonomies', 'capitalize_titles'] as $toggle) {
        if (array_key_exists($toggle, $input)) {
            $titles[$toggle] = !empty($input[$toggle]) ? 'on' : 'off';
            $updated[] = $toggle;
        }
    }

    if (array_key_exists('title_separator', $input)) {
        $titles['title_separator'] = sanitize_text_field((string) $input['title_separator']);
        $updated[] = 'title_separator';
    }

    if (array_key_exists('twitter_card_type', $input)) {
        $card = sanitize_text_field((string) $input['twitter_card_type']);
        if (!in_array($card, ['summary_card', 'summary_large_image'], true)) {
            return nibwp_rankmath_err('nibwp_rankmath_bad_card_type', __('twitter_card_type must be summary_card or summary_large_image.', 'nibwp'));
        }
        $titles['twitter_card_type'] = $card;
        $updated[] = 'twitter_card_type';
    }

    if (array_key_exists('open_graph_image', $input)) {
        $titles['open_graph_image'] = esc_url_raw((string) $input['open_graph_image']);
        $updated[] = 'open_graph_image';
    }

    if ($updated === []) {
        return nibwp_rankmath_err('nibwp_rankmath_nothing_to_set', __('No writable global settings were supplied.', 'nibwp'));
    }

    nibwp_rankmath_save_settings(null, $titles, null);

    return ['saved' => true, 'section' => 'titles', 'updated' => $updated];
}

function nibwp_rankmath_settings_update_post_type(array $input): array|WP_Error
{
    $post_type = sanitize_key((string) ($input['post_type'] ?? ''));
    if ($post_type === '') {
        return nibwp_rankmath_err('nibwp_rankmath_bad_post_type', __('post_type is required for update_post_type.', 'nibwp'));
    }
    if (!post_type_exists($post_type)) {
        return nibwp_rankmath_err(
            'nibwp_rankmath_bad_post_type',
            sprintf(
                /* translators: the unknown post type name. */
                __('Unknown post type: %s.', 'nibwp'),
                $post_type
            )
        );
    }

    $titles = nibwp_rankmath_settings('titles');
    $prefix = "pt_{$post_type}_";
    $updated = [];

    foreach (['title', 'description', 'archive_title', 'archive_description'] as $field) {
        if (array_key_exists($field, $input)) {
            // Templates carry %variables% and separators, so they are text
            // fields rather than URLs or keys.
            $titles[$prefix . $field] = sanitize_text_field((string) $input[$field]);
            $updated[] = $prefix . $field;
        }
    }

    if (array_key_exists('custom_robots', $input)) {
        $titles[$prefix . 'custom_robots'] = !empty($input['custom_robots']) ? 'on' : 'off';
        $updated[] = $prefix . 'custom_robots';
    }

    if (array_key_exists('robots', $input)) {
        if (!is_array($input['robots'])) {
            return nibwp_rankmath_err('nibwp_rankmath_bad_robots', __('robots must be an array of directives.', 'nibwp'));
        }
        $unknown = array_diff(array_map('strval', $input['robots']), nibwp_rankmath_robots_directives());
        if ($unknown !== []) {
            return nibwp_rankmath_err(
                'nibwp_rankmath_bad_robots',
                sprintf(
                    /* translators: 1: the rejected values, 2: the accepted values. */
                    __('Unknown robots directive(s): %1$s. Rank Math accepts only %2$s.', 'nibwp'),
                    implode(', ', $unknown),
                    implode(', ', nibwp_rankmath_robots_directives())
                )
            );
        }
        $titles[$prefix . 'robots'] = nibwp_rankmath_clean_robots($input['robots']);
        $updated[] = $prefix . 'robots';
    }

    if ($updated === []) {
        return nibwp_rankmath_err('nibwp_rankmath_nothing_to_set', __('No writable post-type settings were supplied.', 'nibwp'));
    }

    nibwp_rankmath_save_settings(null, $titles, null);

    return ['saved' => true, 'section' => 'titles', 'post_type' => $post_type, 'updated' => $updated];
}

function nibwp_rankmath_settings_set_module(array $input): array|WP_Error
{
    $module = sanitize_key(str_replace('_', '-', (string) ($input['module'] ?? '')));
    if ($module === '') {
        return nibwp_rankmath_err('nibwp_rankmath_bad_module', __('module is required for set_module.', 'nibwp'));
    }
    if (!array_key_exists('enabled', $input)) {
        return nibwp_rankmath_err('nibwp_rankmath_bad_module', __('enabled is required for set_module.', 'nibwp'));
    }

    $enabled = !empty($input['enabled']);

    // Helper::update_modules() is the only correct way ON: it also creates the
    // module's database tables. Writing the option alone switches redirections
    // on with no table behind it.
    if (nibwp_rankmath_can_call('RankMath\\Helper', 'update_modules')) {
        call_user_func(['RankMath\\Helper', 'update_modules'], [$module => $enabled ? 'on' : 'off']);
    } elseif ($enabled) {
        return nibwp_rankmath_err(
            'nibwp_rankmath_no_module_api',
            __('Rank Math\'s module helper is not loaded, and switching a module on without it would leave the module active with no database table. Enable it from the Rank Math dashboard.', 'nibwp')
        );
    } else {
        update_option('rank_math_modules', array_values(array_diff(nibwp_rankmath_active_modules(), [$module])));
    }

    nibwp_rankmath_reset_settings_registry();

    return [
        'saved'   => true,
        'module'  => $module,
        'enabled' => nibwp_rankmath_module_active($module),
        'modules' => nibwp_rankmath_active_modules(),
    ];
}

/* ----------------------------------------------------------------------------
 * Ability 5 — redirections
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/rankmath-redirections', [
    'label'       => __('Rank Math — Redirections', 'nibwp'),
    'description' => 'Manage Rank Math\'s own redirections: list them with hit counts, read one, create or update a rule with its source patterns and HTTP code, switch one active or inactive, and delete. Actions: list, get, create, update, set_status, delete.',
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'set_status', 'delete'], 'description' => 'The action to perform.'],
            'id'     => ['type' => 'integer', 'description' => 'The redirection ID. Required for get, update, set_status and delete.'],
            'sources' => [
                'type' => 'array',
                'description' => 'For create and update: the URLs that redirect. Each item { pattern, comparison, ignore }. comparison is exact, contains, start, end or regex; ignore true ignores letter case. A pattern for an exact match is a path such as /old-page.',
                'items' => ['type' => 'object'],
            ],
            'url_to' => ['type' => 'string', 'description' => 'For create and update: the destination. Ignored for 410 and 451, which have no destination.'],
            'header_code' => ['type' => 'integer', 'enum' => [301, 302, 307, 410, 451], 'default' => 301, 'description' => '301 permanent, 302 or 307 temporary, 410 content deleted, 451 unavailable for legal reasons.'],
            'status' => ['type' => 'string', 'enum' => ['active', 'inactive', 'trashed'], 'description' => 'For set_status, and the status a created rule starts in.'],
            'status_filter' => ['type' => 'string', 'enum' => ['any', 'active', 'inactive', 'trashed'], 'default' => 'any', 'description' => 'For list.'],
            'search' => ['type' => 'string', 'description' => 'For list: match a source or destination URL.'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page' => ['type' => 'integer', 'default' => 1],
            'confirm' => ['type' => 'boolean', 'default' => false, 'description' => 'Must be true for delete. Deletion is permanent; set_status to trashed is the reversible option.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_rankmath_redirections',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'This is Rank Math\'s own redirection module, not the Redirection plugin. It needs the redirections module switched on; nibwp/rankmath-info reports that, and nibwp/rankmath-settings set_module turns it on and creates the table.',
                'A redirection is one destination with one or more sources, stored as a serialized list. update replaces the whole sources list rather than adding to it, so read the rule first and send back the full list.',
                'Prefer set_status with trashed over delete. Trashing is reversible from the Rank Math admin and Rank Math itself clears trashed rules after 30 days; delete is immediate and permanent.',
                'Writes go through Rank Math\'s own save path because a redirection is two tables — the rule and a lookup cache. A rule written any other way leaves the cache firing for URLs the rule no longer covers.',
                'Check the result of nibwp/rankmath-audit before adding a rule whose destination is itself redirected: that is how chains and loops start.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_rankmath_redirections(array $input): array|WP_Error
{
    if ($guard = nibwp_rankmath_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $id = (int) ($input['id'] ?? 0);

    if (!in_array($action, ['list', 'get', 'create', 'update', 'set_status', 'delete'], true)) {
        return nibwp_rankmath_err('nibwp_rankmath_bad_action', __('Unknown action. Use list, get, create, update, set_status or delete.', 'nibwp'));
    }

    if ($action === 'delete' && !(bool) ($input['confirm'] ?? false)) {
        return nibwp_rankmath_err(
            'nibwp_rankmath_unconfirmed',
            __('Deleting a redirection is permanent. Use set_status with trashed for the reversible option, or re-issue with confirm set to true.', 'nibwp')
        );
    }

    if (in_array($action, ['get', 'update', 'set_status', 'delete'], true) && $id <= 0) {
        return nibwp_rankmath_err('nibwp_rankmath_bad_id', __('A valid redirection ID is required.', 'nibwp'));
    }

    return nibwp_rankmath_try(static function () use ($input, $action, $id) {
        if ($action === 'list') {
            $page = nibwp_rankmath_paginate($input);
            $rows = nibwp_rankmath_redirection_rows(
                $page['limit'],
                $page['offset'],
                (string) ($input['status_filter'] ?? 'any'),
                sanitize_text_field((string) ($input['search'] ?? ''))
            );
            if ($rows instanceof WP_Error) {
                return $rows;
            }

            return [
                'count'    => count($rows['rows']),
                'total'    => $rows['total'],
                'page'     => $page['page'],
                'per_page' => $page['per_page'],
                'items'    => $rows['rows'],
            ];
        }

        if ($action === 'get') {
            $row = nibwp_rankmath_find_redirection($id);

            return $row instanceof WP_Error ? $row : ['item' => $row];
        }

        if (!nibwp_rankmath_redirections_writable()) {
            return nibwp_rankmath_err(
                'nibwp_rankmath_no_redirections',
                __('Rank Math\'s redirection classes are not loaded, so a rule cannot be written safely — a redirection is a rule plus a lookup cache, and only Rank Math\'s own save path keeps the two together. Switch the redirections module on with nibwp/rankmath-settings set_module.', 'nibwp')
            );
        }

        if ($action === 'delete') {
            $row = nibwp_rankmath_find_redirection($id);
            if ($row instanceof WP_Error) {
                return $row;
            }
            call_user_func(['RankMath\\Redirections\\DB', 'delete'], [$id]);

            return ['deleted' => true, 'id' => $id, 'reversible' => false];
        }

        if ($action === 'set_status') {
            $status = (string) ($input['status'] ?? '');
            if (!in_array($status, ['active', 'inactive', 'trashed'], true)) {
                return nibwp_rankmath_err('nibwp_rankmath_bad_status', __('status must be active, inactive or trashed.', 'nibwp'));
            }
            $row = nibwp_rankmath_find_redirection($id);
            if ($row instanceof WP_Error) {
                return $row;
            }
            if (!nibwp_rankmath_can_call('RankMath\\Redirections\\DB', 'change_status')) {
                return nibwp_rankmath_err('nibwp_rankmath_no_redirections', __('Rank Math\'s redirection data layer is unavailable.', 'nibwp'));
            }
            call_user_func(['RankMath\\Redirections\\DB', 'change_status'], [$id], $status);

            return ['saved' => true, 'id' => $id, 'status' => $status, 'reversible' => $status === 'trashed'];
        }

        // create / update.
        $header_code = (int) ($input['header_code'] ?? 301);
        if (!in_array($header_code, nibwp_rankmath_header_codes(), true)) {
            return nibwp_rankmath_err(
                'nibwp_rankmath_bad_header_code',
                sprintf(
                    /* translators: the accepted HTTP codes. */
                    __('header_code must be one of %s.', 'nibwp'),
                    implode(', ', array_map('strval', nibwp_rankmath_header_codes()))
                )
            );
        }

        $sources = nibwp_rankmath_build_sources((array) ($input['sources'] ?? []));
        if ($sources instanceof WP_Error) {
            return $sources;
        }

        $url_to = esc_url_raw((string) ($input['url_to'] ?? ''));
        if ($url_to === '' && !in_array($header_code, [410, 451], true)) {
            return nibwp_rankmath_err('nibwp_rankmath_no_destination', __('url_to is required for 301, 302 and 307. Only 410 and 451 have no destination.', 'nibwp'));
        }

        $status = (string) ($input['status'] ?? 'active');
        if (!in_array($status, ['active', 'inactive', 'trashed'], true)) {
            $status = 'active';
        }

        $data = [
            'sources'     => $sources,
            'url_to'      => $url_to,
            'header_code' => (string) $header_code,
            'status'      => $status,
        ];

        if ($action === 'update') {
            $row = nibwp_rankmath_find_redirection($id);
            if ($row instanceof WP_Error) {
                return $row;
            }
            $data['id'] = $id;
        }

        $redirection = call_user_func(['RankMath\\Redirections\\Redirection', 'from'], $data);
        if (!is_object($redirection) || !method_exists($redirection, 'save')) {
            return nibwp_rankmath_err('nibwp_rankmath_no_redirections', __('Rank Math\'s redirection data layer is unavailable.', 'nibwp'));
        }

        $saved_id = $redirection->save();
        if (empty($saved_id)) {
            return nibwp_rankmath_err('nibwp_rankmath_redirect_rejected', __('Rank Math refused the rule. Every source pattern has to survive its own sanitisation — an empty pattern, or an invalid regex, is dropped and a rule with no sources is not saved.', 'nibwp'));
        }

        return ['saved' => true, 'id' => (int) $saved_id, 'created' => $action === 'create', 'item' => nibwp_rankmath_find_redirection((int) $saved_id)];
    });
}

/** One redirection by id, whichever read path is available. */
function nibwp_rankmath_find_redirection(int $id): array|WP_Error
{
    if (nibwp_rankmath_can_call('RankMath\\Redirections\\DB', 'get_redirection_by_id')) {
        $row = call_user_func(['RankMath\\Redirections\\DB', 'get_redirection_by_id'], $id);
        if (empty($row)) {
            return nibwp_rankmath_err('nibwp_rankmath_not_found', __('No redirection with that ID.', 'nibwp'));
        }

        return nibwp_rankmath_shape_redirection($row);
    }

    if (!nibwp_rankmath_table_exists('rank_math_redirections')) {
        return nibwp_rankmath_err(
            'nibwp_rankmath_no_redirections',
            __('The Rank Math Redirections module is not active, so there is no redirections table to read.', 'nibwp')
        );
    }

    global $wpdb;
    $table = nibwp_rankmath_table('rank_math_redirections');
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix and a literal.
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $id), ARRAY_A);
    if (empty($row)) {
        return nibwp_rankmath_err('nibwp_rankmath_not_found', __('No redirection with that ID.', 'nibwp'));
    }

    return nibwp_rankmath_shape_redirection($row);
}

/* ----------------------------------------------------------------------------
 * Ability 6 — sitemap
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/rankmath-sitemap', [
    'label'       => __('Rank Math — Sitemap', 'nibwp'),
    'description' => 'Read the XML sitemap status — whether the module is on, the index URL, and which post types and taxonomies are included — update those inclusion settings, and read or change the excluded post and term IDs. Actions: status, update, get_exclusions, set_exclusions.',
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['status', 'update', 'get_exclusions', 'set_exclusions'], 'description' => 'The action to perform.'],
            'items_per_page' => ['type' => 'integer', 'description' => 'For update: maximum links on one sitemap page.'],
            'include_images' => ['type' => 'boolean', 'description' => 'For update: reference images from the post content.'],
            'include_featured_image' => ['type' => 'boolean', 'description' => 'For update: also reference the featured image. Only takes effect when include_images is on.'],
            'authors_sitemap' => ['type' => 'boolean', 'description' => 'For update: include author archives.'],
            'post_types' => ['type' => 'object', 'description' => 'For update: map of post type slug => included. Every slug must be a registered post type.'],
            'taxonomies' => ['type' => 'object', 'description' => 'For update: map of taxonomy slug => included.'],
            'exclude_posts' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'For set_exclusions: post IDs. Applies to every post type.'],
            'exclude_terms' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'For set_exclusions: term IDs. Applies to every taxonomy.'],
            'mode' => ['type' => 'string', 'enum' => ['replace', 'add', 'remove'], 'default' => 'replace', 'description' => 'For set_exclusions: replace the list, add to it, or remove from it.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_rankmath_sitemap',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'The sitemap module can be off, in which case index_url is still reported but nothing is served there. nibwp/rankmath-settings set_module switches it on.',
                'Exclusions are stored as comma-separated ID strings in one option and apply site-wide: exclude_posts covers every post type and exclude_terms covers every taxonomy. Read them with get_exclusions before writing, or use mode add / remove rather than replace.',
                'Excluding a post from the sitemap does not stop it being indexed — a page Google already knows about stays indexed. Set robots to noindex on the post with nibwp/rankmath-post for that.',
                'Rank Math caches generated sitemaps. A change here takes effect for the next regeneration, not instantly.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_rankmath_sitemap(array $input): array|WP_Error
{
    if ($guard = nibwp_rankmath_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');

    return match ($action) {
        'status'         => nibwp_rankmath_sitemap_status(),
        'update'         => nibwp_rankmath_sitemap_update($input),
        'get_exclusions' => nibwp_rankmath_sitemap_exclusions(),
        'set_exclusions' => nibwp_rankmath_sitemap_set_exclusions($input),
        default          => nibwp_rankmath_err('nibwp_rankmath_bad_action', __('Unknown action. Use status, update, get_exclusions or set_exclusions.', 'nibwp')),
    };
}

function nibwp_rankmath_sitemap_status(): array
{
    return [
        'enabled'   => nibwp_rankmath_module_active('sitemap'),
        'index_url' => nibwp_rankmath_sitemap_index_url(),
        'items_per_page' => (int) nibwp_rankmath_setting('sitemap', 'items_per_page', 200),
        'include_images' => nibwp_rankmath_is_on(nibwp_rankmath_setting('sitemap', 'include_images', 'on')),
        'include_featured_image' => nibwp_rankmath_is_on(nibwp_rankmath_setting('sitemap', 'include_featured_image', 'off')),
        'authors_sitemap' => nibwp_rankmath_is_on(nibwp_rankmath_setting('sitemap', 'authors_sitemap', 'off')),
        'post_types' => nibwp_rankmath_sitemap_toggles('pt_'),
        'taxonomies' => nibwp_rankmath_sitemap_toggles('tax_'),
        'exclusions' => nibwp_rankmath_sitemap_exclusions(),
    ];
}

function nibwp_rankmath_sitemap_exclusions(): array
{
    return [
        'exclude_posts' => nibwp_rankmath_exclusion_ids('exclude_posts'),
        'exclude_terms' => nibwp_rankmath_exclusion_ids('exclude_terms'),
    ];
}

function nibwp_rankmath_sitemap_update(array $input): array|WP_Error
{
    $sitemap = nibwp_rankmath_settings('sitemap');
    $updated = [];

    if (array_key_exists('items_per_page', $input)) {
        $sitemap['items_per_page'] = (string) max(1, (int) $input['items_per_page']);
        $updated[] = 'items_per_page';
    }

    foreach (['include_images', 'include_featured_image', 'authors_sitemap'] as $toggle) {
        if (array_key_exists($toggle, $input)) {
            $sitemap[$toggle] = !empty($input[$toggle]) ? 'on' : 'off';
            $updated[] = $toggle;
        }
    }

    if (array_key_exists('post_types', $input)) {
        if (!is_array($input['post_types'])) {
            return nibwp_rankmath_err('nibwp_rankmath_bad_post_type', __('post_types must be an object of post type => boolean.', 'nibwp'));
        }
        foreach ($input['post_types'] as $slug => $enabled) {
            $slug = sanitize_key((string) $slug);
            if ($slug === '' || !post_type_exists($slug)) {
                return nibwp_rankmath_err(
                    'nibwp_rankmath_bad_post_type',
                    sprintf(
                        /* translators: the unknown post type name. */
                        __('Unknown post type: %s.', 'nibwp'),
                        (string) $slug
                    )
                );
            }
            $sitemap["pt_{$slug}_sitemap"] = !empty($enabled) ? 'on' : 'off';
            $updated[] = "pt_{$slug}_sitemap";
        }
    }

    if (array_key_exists('taxonomies', $input)) {
        if (!is_array($input['taxonomies'])) {
            return nibwp_rankmath_err('nibwp_rankmath_bad_taxonomy', __('taxonomies must be an object of taxonomy => boolean.', 'nibwp'));
        }
        foreach ($input['taxonomies'] as $slug => $enabled) {
            $slug = sanitize_key((string) $slug);
            if ($slug === '' || !taxonomy_exists($slug)) {
                return nibwp_rankmath_err(
                    'nibwp_rankmath_bad_taxonomy',
                    sprintf(
                        /* translators: the unknown taxonomy name. */
                        __('Unknown taxonomy: %s.', 'nibwp'),
                        (string) $slug
                    )
                );
            }
            $sitemap["tax_{$slug}_sitemap"] = !empty($enabled) ? 'on' : 'off';
            $updated[] = "tax_{$slug}_sitemap";
        }
    }

    if ($updated === []) {
        return nibwp_rankmath_err('nibwp_rankmath_nothing_to_set', __('No writable sitemap settings were supplied.', 'nibwp'));
    }

    nibwp_rankmath_save_settings(null, null, $sitemap);

    return ['saved' => true, 'section' => 'sitemap', 'updated' => $updated, 'status' => nibwp_rankmath_sitemap_status()];
}

function nibwp_rankmath_sitemap_set_exclusions(array $input): array|WP_Error
{
    $mode = (string) ($input['mode'] ?? 'replace');
    if (!in_array($mode, ['replace', 'add', 'remove'], true)) {
        return nibwp_rankmath_err('nibwp_rankmath_bad_mode', __('mode must be replace, add or remove.', 'nibwp'));
    }
    if (!array_key_exists('exclude_posts', $input) && !array_key_exists('exclude_terms', $input)) {
        return nibwp_rankmath_err('nibwp_rankmath_nothing_to_set', __('Provide exclude_posts, exclude_terms, or both.', 'nibwp'));
    }

    $sitemap = nibwp_rankmath_settings('sitemap');
    $updated = [];

    foreach (['exclude_posts', 'exclude_terms'] as $key) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        if (!is_array($input[$key])) {
            return nibwp_rankmath_err('nibwp_rankmath_bad_exclusions', sprintf(
                /* translators: the field name. */
                __('%s must be an array of IDs.', 'nibwp'),
                $key
            ));
        }
        $incoming = array_values(array_unique(array_filter(array_map('intval', $input[$key]), static fn(int $id): bool => $id > 0)));
        $current = nibwp_rankmath_exclusion_ids($key);
        $next = match ($mode) {
            'add'    => array_values(array_unique(array_merge($current, $incoming))),
            'remove' => array_values(array_diff($current, $incoming)),
            default  => $incoming,
        };
        sort($next);
        $sitemap[$key] = implode(',', $next);
        $updated[] = $key;
    }

    nibwp_rankmath_save_settings(null, null, $sitemap);

    return ['saved' => true, 'mode' => $mode, 'updated' => $updated, 'exclusions' => nibwp_rankmath_sitemap_exclusions()];
}

/* ----------------------------------------------------------------------------
 * Ability 7 — audit
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/rankmath-audit', [
    'label'       => __('Rank Math — Audit', 'nibwp'),
    'description' => 'Check the site for the Rank Math faults that cost traffic: posts with no meta description, over-long titles and descriptions, missing focus keywords, noindexed pages that are linked in a menu, redirect loops and chains, and 404s piling up in the monitor. Read-only; every finding carries a severity and the fix.',
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_type' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Post types to audit. Defaults to post and page.'],
            'limit'     => ['type' => 'integer', 'default' => 200, 'description' => 'Most posts to audit in one pass, 1 to 1000.'],
            'title_max' => ['type' => 'integer', 'default' => 60, 'description' => 'Title length to flag above. 60 is where Rank Math truncates a title in its own SERP preview.'],
            'description_max' => ['type' => 'integer', 'default' => 160, 'description' => 'Description length to flag above. 160 is where Rank Math truncates a description in its own SERP preview.'],
            'min_404_hits' => ['type' => 'integer', 'default' => 10, 'description' => 'How many hits a logged 404 needs before it is reported.'],
            'checks' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => ['content', 'menu', 'redirects', 'notfound']],
                'description' => 'Run only these groups of checks. Omit for all of them.',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'posts_checked'     => ['type' => 'integer'],
            'redirects_checked' => ['type' => 'integer'],
            'verdict'  => ['type' => 'string'],
            'blockers' => ['type' => 'array', 'items' => ['type' => 'object']],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'object']],
            'notes'    => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['posts_checked', 'redirects_checked', 'verdict', 'blockers', 'warnings'],
    ],
    'execute_callback'    => 'nibwp_rankmath_audit',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Two of these are real breakage and are reported as blockers. A page that is noindexed while sitting in a navigation menu is a page the site is telling visitors to visit and telling Google to forget — almost always a noindex someone forgot to lift. A redirect loop is a URL that never resolves at all.',
                'The rest cost traffic without breaking anything, so they are warnings: no meta description means Google writes its own, an over-long title is cut off mid-sentence in the results, a redirect chain leaks a little authority at every hop, and a 404 with hits is a real URL people keep reaching for.',
                'The length limits are the ones Rank Math truncates at in its own SERP preview, 60 and 160 characters, and both are overridable inputs.',
                'A missing meta description is judged on the post\'s own override, and the finding says whether the post type has a description template that will fill the gap — so "no description" is not the same as "no description will be rendered".',
                'Redirect analysis only follows sources with exact comparison. A contains, start, end or regex rule cannot be resolved to a single URL without running it, so those are not chained or looped here.',
                'This audit is entirely local. It does not call rankmath.com, so it is safe to run repeatedly and reports nothing about the site\'s external SEO.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/** Normalise a URL so two spellings of the same page compare equal. */
function nibwp_rankmath_normalize_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $url = explode('#', $url)[0];

    return strtolower(untrailingslashit($url));
}

/**
 * Index the exact-comparison sources of every active redirection.
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array<string, array{id: int, target: string, pattern: string}>
 */
function nibwp_rankmath_redirect_index(array $rows): array
{
    $index = [];

    foreach ($rows as $row) {
        if (($row['status'] ?? 'active') !== 'active') {
            continue;
        }
        foreach ((array) ($row['sources'] ?? []) as $source) {
            $source = (array) $source;
            if (($source['comparison'] ?? 'exact') !== 'exact') {
                continue;
            }
            $pattern = (string) ($source['pattern'] ?? '');
            if ($pattern === '') {
                continue;
            }
            // home_url( $pattern ) is exactly how Rank Math turns an exact
            // source back into a URL in Redirection::is_infinite_loop().
            $key = nibwp_rankmath_normalize_url(home_url($pattern));
            if ($key !== '' && !isset($index[$key])) {
                $index[$key] = [
                    'id'      => (int) ($row['id'] ?? 0),
                    'target'  => nibwp_rankmath_normalize_url((string) ($row['url_to'] ?? '')),
                    'pattern' => $pattern,
                ];
            }
        }
    }

    return $index;
}

/** One audit finding. */
function nibwp_rankmath_finding(string $code, string $severity, string $message, string $fix, array $context = []): array
{
    return array_merge([
        'code'     => $code,
        'severity' => $severity,
        'message'  => $message,
        'fix'      => $fix,
    ], $context);
}

function nibwp_rankmath_audit(array $input): array|WP_Error
{
    if ($guard = nibwp_rankmath_guard()) {
        return $guard;
    }

    return nibwp_rankmath_try(static function () use ($input) {
        $checks = array_values(array_filter(array_map('strval', (array) ($input['checks'] ?? []))));
        $wants = static fn(string $group): bool => $checks === [] || in_array($group, $checks, true);

        $limit = min(max((int) ($input['limit'] ?? 200), 1), 1000);
        $title_max = max((int) ($input['title_max'] ?? 60), 1);
        $description_max = max((int) ($input['description_max'] ?? 160), 1);
        $min_404_hits = max((int) ($input['min_404_hits'] ?? 10), 1);

        $post_types = array_values(array_filter(array_map('sanitize_key', (array) ($input['post_type'] ?? ['post', 'page']))));
        if ($post_types === []) {
            $post_types = ['post', 'page'];
        }

        $findings = [];
        $notes = [];
        $posts_checked = 0;
        $redirects_checked = 0;

        /* ── Content: descriptions, lengths, focus keywords ───────────── */
        if ($wants('content')) {
            $query = new WP_Query([
                'post_type'      => $post_types,
                'post_status'    => 'publish',
                'posts_per_page' => $limit,
                'orderby'        => 'ID',
                'order'          => 'DESC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'ignore_sticky_posts' => true,
            ]);

            foreach (array_map('intval', (array) $query->posts) as $post_id) {
                $posts_checked++;
                $post_type = (string) get_post_type($post_id);
                $title = (string) get_post_meta($post_id, 'rank_math_title', true);
                $description = (string) get_post_meta($post_id, 'rank_math_description', true);
                $context = [
                    'post_id'   => $post_id,
                    'post_type' => $post_type,
                    'title'     => (string) get_the_title($post_id),
                ];

                if (trim($description) === '') {
                    $template = (string) nibwp_rankmath_setting('titles', "pt_{$post_type}_description", '');
                    $findings[] = nibwp_rankmath_finding(
                        'no_meta_description',
                        'warning',
                        $template === ''
                            ? __('No meta description, and this post type has no description template either, so Google writes the snippet itself from whatever it finds on the page.', 'nibwp')
                            : sprintf(
                                /* translators: the post type's Rank Math description template. */
                                __('No meta description of its own. The post type template "%s" fills the gap, which is better than nothing but is the same sentence on every post.', 'nibwp'),
                                $template
                            ),
                        __('Write one with nibwp/rankmath-post set, or fix the whole set at once with nibwp/rankmath-bulk using the missing_description filter.', 'nibwp'),
                        $context + ['post_type_template' => $template]
                    );
                }

                if ($title !== '' && mb_strlen($title) > $title_max) {
                    $findings[] = nibwp_rankmath_finding(
                        'title_too_long',
                        'warning',
                        sprintf(
                            /* translators: 1: the title length, 2: the limit. */
                            __('The SEO title is %1$d characters. Rank Math cuts a title off at %2$d in its own preview, and so does Google in the results.', 'nibwp'),
                            mb_strlen($title),
                            $title_max
                        ),
                        sprintf(
                            /* translators: the length limit. */
                            __('Shorten the title to %d characters or fewer with nibwp/rankmath-post set. Put the keyword at the front so it survives the cut.', 'nibwp'),
                            $title_max
                        ),
                        $context + ['length' => mb_strlen($title), 'limit' => $title_max]
                    );
                }

                if ($description !== '' && mb_strlen($description) > $description_max) {
                    $findings[] = nibwp_rankmath_finding(
                        'description_too_long',
                        'warning',
                        sprintf(
                            /* translators: 1: the description length, 2: the limit. */
                            __('The meta description is %1$d characters. Rank Math cuts a description off at %2$d in its own preview.', 'nibwp'),
                            mb_strlen($description),
                            $description_max
                        ),
                        sprintf(
                            /* translators: the length limit. */
                            __('Trim it to %d characters or fewer with nibwp/rankmath-post set.', 'nibwp'),
                            $description_max
                        ),
                        $context + ['length' => mb_strlen($description), 'limit' => $description_max]
                    );
                }

                if (nibwp_rankmath_focus_keywords($post_id) === []) {
                    $findings[] = nibwp_rankmath_finding(
                        'no_focus_keyword',
                        'warning',
                        __('No focus keyword, so Rank Math scores nothing for this post and its content analysis has no target to measure against.', 'nibwp'),
                        __('Set focus_keywords with nibwp/rankmath-post set — the first one is the primary keyword.', 'nibwp'),
                        $context
                    );
                }
            }
        }

        /* ── Menus: noindexed pages people are being sent to ──────────── */
        if ($wants('menu')) {
            if (!function_exists('wp_get_nav_menus') || !function_exists('wp_get_nav_menu_items')) {
                $notes[] = __('Navigation menus could not be read on this request, so the noindexed-in-menu check was skipped.', 'nibwp');
            } else {
                $seen = [];
                foreach ((array) wp_get_nav_menus() as $menu) {
                    $menu_id = (int) ($menu->term_id ?? 0);
                    if ($menu_id <= 0) {
                        continue;
                    }
                    foreach ((array) wp_get_nav_menu_items($menu_id) as $item) {
                        if (($item->type ?? '') !== 'post_type') {
                            continue;
                        }
                        $object_id = (int) ($item->object_id ?? 0);
                        if ($object_id <= 0 || isset($seen[$object_id])) {
                            continue;
                        }
                        $seen[$object_id] = true;
                        if (!nibwp_rankmath_is_noindexed($object_id)) {
                            continue;
                        }
                        $findings[] = nibwp_rankmath_finding(
                            'noindexed_in_menu',
                            'blocker',
                            __('This page is in a navigation menu and is set to noindex. The site is pointing visitors at it and telling search engines to forget it, which is almost always a noindex nobody lifted after launch.', 'nibwp'),
                            __('Remove noindex from the page with nibwp/rankmath-post set robots, or take the item out of the menu if the page really is meant to be private.', 'nibwp'),
                            [
                                'post_id'   => $object_id,
                                'post_type' => (string) get_post_type($object_id),
                                'title'     => (string) get_the_title($object_id),
                                'menu'      => (string) ($menu->name ?? ''),
                                'robots'    => nibwp_rankmath_effective_robots($object_id),
                            ]
                        );
                    }
                }
            }
        }

        /* ── Redirects: loops and chains ──────────────────────────────── */
        $index = [];
        if ($wants('redirects') || $wants('notfound')) {
            $rows = nibwp_rankmath_redirection_rows(1000, 0, 'any', '');
            if ($rows instanceof WP_Error) {
                $notes[] = $rows->get_error_message();
            } else {
                $redirects_checked = count($rows['rows']);
                $index = nibwp_rankmath_redirect_index($rows['rows']);
            }
        }

        if ($wants('redirects') && $index !== []) {
            foreach ($index as $from => $entry) {
                $target = $entry['target'];
                if ($target === '') {
                    continue;
                }

                if ($target === $from) {
                    $findings[] = nibwp_rankmath_finding(
                        'redirect_loop',
                        'blocker',
                        __('This redirection sends a URL to itself, so the URL never resolves — the browser gives up with a redirect-loop error and search engines drop the page.', 'nibwp'),
                        __('Point url_to at the real destination with nibwp/rankmath-redirections update, or delete the rule if the page still exists at its own address.', 'nibwp'),
                        ['redirection_id' => $entry['id'], 'source' => $entry['pattern'], 'target' => $entry['target'], 'hops' => 1]
                    );
                    continue;
                }

                // Walk the chain. A visited node coming round again is a cycle;
                // more than one hop is a chain.
                $visited = [$from => true];
                $hops = [];
                $cursor = $target;
                $looped = false;
                while (isset($index[$cursor]) && count($hops) < 10) {
                    if (isset($visited[$cursor])) {
                        $looped = true;
                        break;
                    }
                    $visited[$cursor] = true;
                    $hops[] = ['redirection_id' => $index[$cursor]['id'], 'from' => $cursor, 'to' => $index[$cursor]['target']];
                    $cursor = $index[$cursor]['target'];
                    if ($cursor === '') {
                        break;
                    }
                }

                if ($looped) {
                    $findings[] = nibwp_rankmath_finding(
                        'redirect_loop',
                        'blocker',
                        __('These redirections point at each other, so the URL never resolves — the browser gives up with a redirect-loop error and search engines drop the page.', 'nibwp'),
                        __('Break the cycle with nibwp/rankmath-redirections update: one of these rules has to point at a URL that is not itself redirected.', 'nibwp'),
                        ['redirection_id' => $entry['id'], 'source' => $entry['pattern'], 'target' => $entry['target'], 'chain' => $hops]
                    );
                    continue;
                }

                if ($hops !== []) {
                    $findings[] = nibwp_rankmath_finding(
                        'redirect_chain',
                        'warning',
                        sprintf(
                            /* translators: the number of extra hops. */
                            __('This redirection points at a URL that is itself redirected, %d more time(s). Every hop costs a round trip and a little of the link authority that was meant to arrive.', 'nibwp'),
                            count($hops)
                        ),
                        __('Point this rule straight at the final destination with nibwp/rankmath-redirections update, and leave the later rules in place for anyone still linking to the middle URLs.', 'nibwp'),
                        ['redirection_id' => $entry['id'], 'source' => $entry['pattern'], 'target' => $entry['target'], 'chain' => $hops]
                    );
                }
            }
        }

        /* ── 404s piling up ───────────────────────────────────────────── */
        if ($wants('notfound')) {
            if (!nibwp_rankmath_module_active('404-monitor')) {
                $notes[] = __('The Rank Math 404 Monitor module is off, so nothing has been recorded and no 404 can be reported. Switch it on with nibwp/rankmath-settings set_module to find the URLs people are actually reaching for.', 'nibwp');
            } else {
                $logs = nibwp_rankmath_404_rows(200, 0);
                if ($logs instanceof WP_Error) {
                    $notes[] = $logs->get_error_message();
                } else {
                    foreach ($logs['rows'] as $log) {
                        if ($log['hits'] < $min_404_hits) {
                            continue;
                        }
                        $key = nibwp_rankmath_normalize_url(home_url($log['uri']));
                        if (isset($index[$key])) {
                            continue;
                        }
                        $findings[] = nibwp_rankmath_finding(
                            'four_oh_four_unredirected',
                            'warning',
                            sprintf(
                                /* translators: the number of hits. */
                                __('This URL has 404ed %d times and no redirection covers it. Each of those was a visitor or a crawler following a link that goes nowhere.', 'nibwp'),
                                $log['hits']
                            ),
                            __('Create a 301 to the page that replaced it with nibwp/rankmath-redirections create, or answer 410 if the content is genuinely gone and should stop being crawled.', 'nibwp'),
                            ['uri' => $log['uri'], 'hits' => $log['hits'], 'last_seen' => $log['accessed']]
                        );
                    }
                }
            }
        }

        if ($wants('redirects') && !nibwp_rankmath_module_active('redirections')) {
            $notes[] = __('The Rank Math Redirections module is off, so no redirect loops or chains could be checked.', 'nibwp');
        }

        $blockers = array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'blocker'));

        return [
            'posts_checked'     => $posts_checked,
            'redirects_checked' => $redirects_checked,
            'verdict'  => $blockers === [] ? 'ok' : 'needs_attention',
            'blockers' => $blockers,
            'warnings' => array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'warning')),
            'notes'    => $notes,
            'limits'   => ['title_max' => $title_max, 'description_max' => $description_max, 'min_404_hits' => $min_404_hits],
        ];
    });
}
