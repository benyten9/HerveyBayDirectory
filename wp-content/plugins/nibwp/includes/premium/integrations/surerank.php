<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: SureRank SEO.
 *
 * Verified against SureRank 1.10.1 (surerank.php:30).
 *
 * WHERE THE DATA LIVES, because nothing about it is guessable:
 *
 * Per-post SEO is six post meta rows, not one. Every row is named
 * 'surerank_settings_' . $group, where the groups are the top-level keys of the
 * plugin's own post defaults — general, post_no_index, post_no_follow,
 * post_no_archive, social, schemas (inc/functions/defaults.php:239-269). The
 * reader walks exactly those keys (inc/functions/get.php:112-123) and the plugin's
 * own editor writes them back the same way (inc/api/post.php:379). general and
 * social hold arrays of fields; the three robots rows hold the string 'yes' or
 * 'no'. 'yes' is what the sitemap builder tests for (inc/sitemap/checksum.php:203),
 * so a boolean here would be stored and then ignored.
 *
 * Site-wide settings are one option, surerank_settings (constants.php:22), read
 * merged over code defaults (inc/functions/settings.php:54-81). robots.txt is its
 * own option, surerank_robots_txt_content (constants.php:44). The plugin's cached
 * per-post findings are a seventh meta row, surerank_seo_checks (constants.php:27).
 *
 * SECRETS: three options hold third-party credentials —
 * surerank_google_console_credentials carries the Search Console access and
 * refresh tokens (inc/google-search-console/auth.php:17-25, written at :180),
 * surerank_gsc_verification_token carries the site-verification token
 * (inc/google-search-console/site-verification.php:34), and surerank_auth carries
 * the AI account record (inc/modules/ai-auth/controller.php:36). None of them
 * leaves here, and neither does anything else: settings travel through an
 * ALLOW-LIST of field names, because surerank_settings is one flat key space that
 * a later release will add to, and a key nobody has named yet would default to
 * leaking under a denylist. get_search_console_status reports connected or not and
 * nothing more.
 *
 * WHAT IS NOT HERE: redirects. This build has no redirect manager — the only
 * redirect it performs is attachment pages to their parent post, a single boolean
 * (inc/functions/defaults.php:152), reported under get_settings. There is nothing
 * to list, create or delete, and inventing an action that pretends otherwise would
 * be worse than its absence. Also absent: anything that writes schema. Schema is
 * read here (get_schema_settings) because an owner asks what it emits; writing it
 * means composing nested type definitions against the plugin's own validator
 * (inc/schema/validator.php), and a half-valid graph is worse than none.
 *
 * ROBOTS AND CANONICALS REMOVE PAGES FROM SEARCH. update_post_seo,
 * update_robots_settings and update_robots_txt can each take a live page out of
 * Google. Every one of them says so in its description and reports back exactly
 * what changed.
 */

/** Site-wide settings change every page on the site, so they take this. */
const NIBWP_SURERANK_SITE_CAP = 'manage_options';

/** How many rows any listing returns, whatever was asked for. */
const NIBWP_SURERANK_MAX_ROWS = 200;

/** How many posts one audit pass will read. */
const NIBWP_SURERANK_MAX_AUDIT = 500;

/**
 * The option holding every site-wide setting.
 *
 * Hardcoded rather than read through SURERANK_SETTINGS so the guard decides
 * whether the plugin is present, not whether a constant happens to be defined.
 */
const NIBWP_SURERANK_OPTION = 'surerank_settings';

/** The option holding the robots.txt body. */
const NIBWP_SURERANK_ROBOTS_OPTION = 'surerank_robots_txt_content';

/** The meta row holding the plugin's own cached findings for one post. */
const NIBWP_SURERANK_CHECKS_META = 'surerank_seo_checks';

/**
 * Is SureRank actually loaded?
 *
 * The version constant is defined by the main plugin file before anything else
 * (surerank.php:30); the settings class is what every site-wide read here goes
 * through. Between them they tell "not installed" from "installed and broken".
 */
function nibwp_surerank_guard(): ?WP_Error
{
    if (!defined('SURERANK_VERSION') && !class_exists('SureRank\\Inc\\Functions\\Settings')) {
        return new WP_Error(
            'nibwp_surerank_missing',
            __('SureRank SEO is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/** A refusal about the request itself. */
function nibwp_surerank_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_surerank_' . $code, $message, ['status' => $status]);
}

/** A refusal about authority rather than about the request. */
function nibwp_surerank_forbidden(string $what, string $cap): WP_Error
{
    return new WP_Error(
        'nibwp_surerank_forbidden',
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name */
            __('%1$s needs the %2$s capability, which this account does not have.', 'nibwp'),
            $what,
            $cap
        ),
        ['status' => 403]
    );
}

/**
 * The one capability gate for anything site-wide.
 *
 * Reading is held to the same bar as writing: the settings describe how every
 * page on the site presents itself to search engines, and the Search Console
 * status says whether an external account is attached.
 */
function nibwp_surerank_require_site(string $what): ?WP_Error
{
    return current_user_can(NIBWP_SURERANK_SITE_CAP)
        ? null
        : nibwp_surerank_forbidden($what, NIBWP_SURERANK_SITE_CAP);
}

/**
 * Post types SureRank's per-post fields actually apply to.
 *
 * The plugin's own screens use public + show_ui (inc/functions/helper.php:233-243).
 * Attachments are dropped on top of that: SureRank redirects attachment pages to
 * their parent post by default (inc/functions/defaults.php:152) and ships
 * 'attachment' in the global no_index list (inc/functions/defaults.php:206-214),
 * so an attachment's own SEO fields are stored and then never rendered. Writing
 * them would look like it worked.
 *
 * @return array<int, string>
 */
function nibwp_surerank_post_types(): array
{
    if (!function_exists('get_post_types')) {
        return [];
    }

    $types = get_post_types(['public' => true, 'show_ui' => true], 'names');
    $types = is_array($types) ? array_values(array_map('strval', $types)) : [];

    return array_values(array_diff($types, ['attachment']));
}

/**
 * Resolve a post id that arrived from a caller.
 *
 * ORDER MATTERS AND IS DELIBERATE. A post id from the wire is a claim, so the
 * post has to exist and be a type these fields apply to BEFORE any capability is
 * consulted — current_user_can('edit_post', $id) on a missing id is answered by
 * WordPress against a null post object, which is not a decision anybody made. The
 * refusal codes differ precisely so a reader can tell which test stopped the call.
 *
 * Note edit_post and not edit_posts: edit_posts says the account may edit
 * something, edit_post says it may edit THIS. An author who cannot touch a page
 * must not be able to rewrite that page's meta description.
 *
 * Declared `object` and not `object|WP_Error`: PHP rejects a union of `object`
 * with a class type outright, so the refusal travels as an object the caller
 * tests with instanceof.
 *
 * @return object|WP_Error
 */
function nibwp_surerank_post(mixed $raw): object
{
    $post_id = is_numeric($raw) ? (int) $raw : 0;

    if ($post_id <= 0) {
        return nibwp_surerank_err('missing_post_id', __('Give the post as post_id, a positive integer.', 'nibwp'), 422);
    }

    $post = get_post($post_id);

    if (!$post || !isset($post->post_type)) {
        return nibwp_surerank_err(
            'post_not_found',
            sprintf(
                /* translators: %d: the post ID that was asked for */
                __('There is no post with ID %d.', 'nibwp'),
                $post_id
            ),
            404
        );
    }

    $allowed = nibwp_surerank_post_types();

    if (!in_array((string) $post->post_type, $allowed, true)) {
        return nibwp_surerank_err(
            'unsupported_type',
            sprintf(
                /* translators: 1: post type slug, 2: list of supported post type slugs */
                __('SureRank does not keep SEO fields for the "%1$s" post type. Supported here: %2$s.', 'nibwp'),
                (string) $post->post_type,
                implode(', ', $allowed) !== '' ? implode(', ', $allowed) : __('none', 'nibwp')
            ),
            422
        );
    }

    if (!current_user_can('edit_post', $post_id)) {
        return nibwp_surerank_forbidden(
            sprintf(
                /* translators: %d: the post ID */
                __('Reading or writing SEO fields on post %d', 'nibwp'),
                $post_id
            ),
            'edit_post'
        );
    }

    return $post;
}

/** Clamp a row count that arrived over the wire. */
function nibwp_surerank_limit(mixed $value, int $default = 50, int $max = NIBWP_SURERANK_MAX_ROWS): int
{
    $limit = is_numeric($value) ? (int) $value : $default;

    return max(1, min($max, $limit));
}

/** Clamp an offset that arrived over the wire. */
function nibwp_surerank_offset(mixed $value): int
{
    $offset = is_numeric($value) ? (int) $value : 0;

    return max(0, $offset);
}

/**
 * Read the whole site-wide settings option without trusting its shape.
 *
 * Read directly rather than through SureRank's Settings::get() on purpose: that
 * method merges code defaults over the stored row and caches the result in a
 * static (inc/functions/settings.php:54-81), so what comes back is partly the
 * plugin's own idea of a default rather than what this site stored. Defaults are
 * reported separately, under their own key, so an owner can see which is which.
 *
 * @return array<string, mixed>
 */
function nibwp_surerank_settings(): array
{
    $stored = get_option(NIBWP_SURERANK_OPTION, []);

    return is_array($stored) ? $stored : [];
}

/**
 * The plugin's own default for one setting, when it will tell us.
 *
 * Wrapped because Settings::get() reaches defaults that reach a filter that any
 * plugin may have hooked (inc/functions/defaults.php:286), and an uncaught Error
 * in there takes the whole REST request with it.
 */
function nibwp_surerank_default(string $key): mixed
{
    if (!class_exists('SureRank\\Inc\\Functions\\Settings')) {
        return null;
    }

    try {
        return SureRank\Inc\Functions\Settings::get($key, false);
    } catch (\Throwable $error) {
        return null;
    }
}

/**
 * Settings whose VALUE may be reported, and what each one is.
 *
 * An ALLOW-LIST, not a denylist. surerank_settings is one flat key space holding
 * everything from a title template to the Search Console feature flag, and a
 * release that adds a key would, under a denylist, publish it by default. Three
 * credential-bearing options live outside this row entirely and are listed in
 * nibwp_surerank_secret_options() so the check can assert they never travel.
 *
 * @return array<string, string> setting key => bool|int|string|list
 */
function nibwp_surerank_public_settings(): array
{
    return [
        // Titles and descriptions.
        'separator' => 'string',
        'page_title' => 'string',
        'page_description' => 'string',
        'auto_generate_description' => 'bool',
        'home_page_title' => 'string',
        'home_page_description' => 'string',
        // Social defaults.
        'open_graph_tags' => 'bool',
        'facebook_meta_tags' => 'bool',
        'twitter_meta_tags' => 'bool',
        'twitter_card_type' => 'string',
        'twitter_same_as_facebook' => 'bool',
        'facebook_page_url' => 'string',
        'facebook_author_fallback' => 'string',
        'twitter_profile_username' => 'string',
        'twitter_profile_fallback' => 'string',
        'fallback_image' => 'string',
        'home_page_facebook_title' => 'string',
        'home_page_facebook_description' => 'string',
        'home_page_facebook_image_url' => 'string',
        'home_page_twitter_title' => 'string',
        'home_page_twitter_description' => 'string',
        'home_page_twitter_image_url' => 'string',
        'oembeded_scripts' => 'bool',
        'oembeded_og_title' => 'bool',
        'oembeded_social_images' => 'bool',
        'oembeded_remove_author_name' => 'bool',
        // Robots and indexing.
        'no_index' => 'list',
        'no_follow' => 'list',
        'no_archive' => 'list',
        'author_archive' => 'bool',
        'date_archive' => 'bool',
        'noindex_paginated_pages' => 'bool',
        'index_home_page_paginated_pages' => 'bool',
        'paginated_link_relationships' => 'list',
        // Sitemaps.
        'enable_xml_sitemap' => 'bool',
        'enable_xml_image_sitemap' => 'bool',
        'sitemap_excluded_post_types' => 'list',
        'sitemap_excluded_taxonomies' => 'list',
        // Feature flags.
        'enable_page_level_seo' => 'bool',
        'enable_google_console' => 'bool',
        'enable_schemas' => 'bool',
        'enable_migration' => 'bool',
        'enable_mcp' => 'bool',
        'enable_headless_rest_api' => 'bool',
        'enable_woocommerce_integration' => 'bool',
        'enable_angie_integration' => 'bool',
        'enable_fluentcart_integration' => 'bool',
        // Images and feeds.
        'redirect_attachment_pages_to_post_parent' => 'bool',
        'auto_set_image_title' => 'bool',
        'auto_set_image_alt' => 'bool',
        'addlink_to_source_below_feed_entries' => 'bool',
        'remove_global_comments_feed' => 'bool',
        'remove_post_authors_feed' => 'bool',
        'remove_post_types_feed' => 'bool',
        'remove_category_feed' => 'bool',
        'remove_tag_feeds' => 'bool',
        'remove_custom_taxonomy_feeds' => 'bool',
        'remove_search_results_feed' => 'bool',
        'remove_atom_rdf_feeds' => 'bool',
    ];
}

/**
 * Options whose value must never travel, however it is asked for.
 *
 * A second layer rather than the only one: the allow-list above already governs
 * what leaves this file. These are named so the check can assert the names never
 * appear, and so a future reader knows what the allow-list is protecting.
 *
 * @return array<int, string>
 */
function nibwp_surerank_secret_options(): array
{
    return [
        'surerank_google_console_credentials',
        'surerank_gsc_verification_token',
        'surerank_auth',
    ];
}

/**
 * Report one setting the way this integration reports settings.
 *
 * A non-scalar under a key the allow-list calls scalar is DROPPED, not cast:
 * (string) on an array is "Array" at best and a serialised blob at worst, and the
 * blob is how a credential map would travel under an innocent name.
 *
 * @return array<string, mixed>
 */
function nibwp_surerank_setting_row(string $key, mixed $value, string $type): array
{
    $out = ['key' => $key, 'value' => null];

    switch ($type) {
        case 'bool':
            $out['value'] = is_scalar($value) ? (bool) $value : null;
            break;
        case 'int':
            $out['value'] = is_scalar($value) ? (int) $value : null;
            break;
        case 'list':
            $out['value'] = is_array($value)
                ? array_values(array_filter(array_map(
                    static fn($item) => is_scalar($item) ? (string) $item : null,
                    $value
                ), static fn($item) => $item !== null))
                : [];
            break;
        default:
            $out['value'] = is_scalar($value) ? (string) $value : null;
    }

    return $out;
}

/**
 * The six meta rows that hold one object's SureRank fields.
 *
 * Group name => the fields inside it, taken from the plugin's own post defaults
 * (inc/functions/defaults.php:239-269). The stored meta key is the prefix plus
 * the group name (inc/functions/get.php:116, inc/api/post.php:379).
 *
 * @return array<string, array<int, string>>
 */
function nibwp_surerank_meta_groups(): array
{
    return [
        'general' => [
            'page_title',
            'page_description',
            'canonical_url',
            'focus_keyword',
            'auto_description',
            'fallback_image',
            'auto_generated_og_image',
            'separator',
            'auto_generate_description',
        ],
        'social' => [
            'facebook_title',
            'facebook_description',
            'facebook_image_url',
            'facebook_image_id',
            'facebook_image_width',
            'facebook_image_height',
            'twitter_title',
            'twitter_description',
            'twitter_image_url',
            'twitter_image_id',
            'twitter_card_type',
            'twitter_same_as_facebook',
        ],
    ];
}

/** The meta key one group is stored under. */
function nibwp_surerank_meta_key(string $group): string
{
    return 'surerank_settings_' . $group;
}

/**
 * The three robots rows, and the input name each one answers to.
 *
 * Each row stores the literal string 'yes' or 'no' — the sitemap builder tests
 * for 'yes' (inc/sitemap/checksum.php:203) and the indexable query tests for 'no'
 * (inc/sitemap/utils.php:354-378), so a boolean written here would be stored and
 * then quietly ignored by both.
 *
 * @return array<string, string> input name => group name
 */
function nibwp_surerank_robots_rows(): array
{
    return [
        'noindex' => 'post_no_index',
        'nofollow' => 'post_no_follow',
        'noarchive' => 'post_no_archive',
    ];
}

/**
 * Read one group's stored array without trusting its shape.
 *
 * @return array<string, mixed>
 */
function nibwp_surerank_group(int $post_id, string $group): array
{
    $stored = get_post_meta($post_id, nibwp_surerank_meta_key($group), true);

    return is_array($stored) ? $stored : [];
}

/** A stored 'yes'/'no' row, reported as the three states it actually has. */
function nibwp_surerank_robots_state(int $post_id, string $group): string
{
    $raw = get_post_meta($post_id, nibwp_surerank_meta_key($group), true);

    if ($raw === 'yes') {
        return 'yes';
    }

    if ($raw === 'no') {
        return 'no';
    }

    // Nothing stored means the global post-type setting decides, which is not the
    // same answer as "no" and must not be flattened into one.
    return 'default';
}

/** One field out of a stored group, as a string, never a serialised blob. */
function nibwp_surerank_field(array $group, string $field): string
{
    $value = $group[$field] ?? '';

    return is_scalar($value) ? (string) $value : '';
}

/**
 * One post's SEO fields, shaped the same way every read here shapes them.
 *
 * @return array<string, mixed>
 */
function nibwp_surerank_post_record(object $post): array
{
    $post_id = (int) ($post->ID ?? 0);
    $general = nibwp_surerank_group($post_id, 'general');
    $social = nibwp_surerank_group($post_id, 'social');

    return [
        'post_id' => $post_id,
        'post_title' => isset($post->post_title) ? (string) $post->post_title : '',
        'post_type' => isset($post->post_type) ? (string) $post->post_type : '',
        'post_status' => isset($post->post_status) ? (string) $post->post_status : '',
        'title' => nibwp_surerank_field($general, 'page_title'),
        'description' => nibwp_surerank_field($general, 'page_description'),
        'canonical' => nibwp_surerank_field($general, 'canonical_url'),
        'focus_keyword' => nibwp_surerank_field($general, 'focus_keyword'),
        'robots' => [
            'noindex' => nibwp_surerank_robots_state($post_id, 'post_no_index'),
            'nofollow' => nibwp_surerank_robots_state($post_id, 'post_no_follow'),
            'noarchive' => nibwp_surerank_robots_state($post_id, 'post_no_archive'),
        ],
        'social' => [
            'facebook_title' => nibwp_surerank_field($social, 'facebook_title'),
            'facebook_description' => nibwp_surerank_field($social, 'facebook_description'),
            'facebook_image_url' => nibwp_surerank_field($social, 'facebook_image_url'),
            'twitter_title' => nibwp_surerank_field($social, 'twitter_title'),
            'twitter_description' => nibwp_surerank_field($social, 'twitter_description'),
            'twitter_image_url' => nibwp_surerank_field($social, 'twitter_image_url'),
            'twitter_card_type' => nibwp_surerank_field($social, 'twitter_card_type'),
        ],
        'has_schema_override' => nibwp_surerank_group($post_id, 'schemas') !== [],
    ];
}

/**
 * The lengths a title and a description actually render at.
 *
 * SureRank's own numbers, not ours: TITLE_MIN_LENGTH 50, TITLE_LENGTH 60,
 * DESCRIPTION_MIN_LENGTH 150, DESCRIPTION_LENGTH 160
 * (inc/functions/get.php:28-49). Reported with every audit so a caller can see
 * which ruler was used rather than guessing at ours.
 *
 * @return array<string, int>
 */
function nibwp_surerank_lengths(): array
{
    return [
        'title_min' => 50,
        'title_max' => 60,
        'description_min' => 150,
        'description_max' => 160,
    ];
}

/** Measure a string the way a search result does — characters, not bytes. */
function nibwp_surerank_length(string $text): int
{
    return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
}

/**
 * The actions this hub offers.
 *
 * @return array<int, string>
 */
function nibwp_surerank_actions(): array
{
    return [
        // Per-post reads.
        'get_post_seo',
        'get_post_seo_checks',
        'bulk_get_post_seo',
        // Per-post writes.
        'update_post_seo',
        'clear_post_seo',
        // Site-wide reads.
        'get_settings',
        'get_title_templates',
        'get_robots_settings',
        'get_sitemap_state',
        'get_robots_txt',
        'get_social_defaults',
        'get_schema_settings',
        'get_feature_flags',
        'get_search_console_status',
        'list_content_types',
        // Site-wide writes.
        'update_title_templates',
        'update_robots_settings',
        'update_sitemap_settings',
        'update_robots_txt',
        'update_social_defaults',
        // The audit.
        'audit',
        'audit_duplicates',
        'audit_noindex_in_sitemap',
        'audit_summary',
    ];
}

wp_register_ability('nibwp/surerank-manage', [
    'label' => __('SureRank SEO – Manage', 'nibwp'),
    'description' => 'Read and write SureRank SEO on this site. Per post: get_post_seo, update_post_seo, clear_post_seo, get_post_seo_checks and bulk_get_post_seo over a whole post type. '
        . 'Site-wide: get_settings, get_title_templates, get_robots_settings, get_sitemap_state, get_robots_txt, get_social_defaults, get_schema_settings, get_feature_flags, '
        . 'get_search_console_status, list_content_types, and the matching update_title_templates, update_robots_settings, update_sitemap_settings, update_robots_txt and update_social_defaults. '
        . 'The audit — audit, audit_duplicates, audit_noindex_in_sitemap, audit_summary — finds the faults that quietly cost traffic: no meta description, titles and descriptions outside the '
        . 'lengths that render, the same title or description on more than one page, content indexed in the sitemap while its post type says noindex, no canonical on paginated content, and no social image. '
        . 'Writing a robots directive or a canonical can remove a page from Google. The Search Console tokens, the site-verification token and the AI account record are never returned.',
    'category' => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_surerank_actions(),
                'description' => 'Which operation to run. Start with audit_summary for the site, get_post_seo for one page.',
            ],
            'post_id' => ['type' => 'integer', 'description' => 'For the per-post actions: which post, page or custom post type item.'],
            'post_type' => ['type' => 'string', 'description' => 'For bulk_get_post_seo and the audits: one post type slug. Left out, the audits cover every supported type.'],
            'title' => ['type' => 'string', 'description' => 'For update_post_seo: the SEO title. May use SureRank %variables%. An empty string clears it back to the site template.'],
            'description' => ['type' => 'string', 'description' => 'For update_post_seo: the meta description. Empty clears it.'],
            'canonical' => ['type' => 'string', 'description' => 'For update_post_seo: the canonical URL override. A wrong value here points search engines at another page and drops this one. Empty clears it.'],
            'focus_keyword' => ['type' => 'string', 'description' => 'For update_post_seo: the focus keyword.'],
            'noindex' => ['type' => 'string', 'enum' => ['default', 'yes', 'no'], 'description' => 'For update_post_seo. SureRank stores three states, not a boolean: default follows the post type setting, yes removes the page from search, no forces indexing.'],
            'nofollow' => ['type' => 'string', 'enum' => ['default', 'yes', 'no'], 'description' => 'For update_post_seo: whether links on this page carry nofollow.'],
            'noarchive' => ['type' => 'string', 'enum' => ['default', 'yes', 'no'], 'description' => 'For update_post_seo: whether search engines may keep a cached copy.'],
            'social' => ['type' => 'object', 'description' => 'For update_post_seo: any of facebook_title, facebook_description, facebook_image_url, twitter_title, twitter_description, twitter_image_url, twitter_card_type.'],
            'fields' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'For clear_post_seo: which to clear — title, description, canonical, focus_keyword, robots, social. Left out, all of them.'],
            'settings' => ['type' => 'object', 'description' => 'For the site-wide updates: setting key => value. Only keys this integration names are accepted; anything else is refused by name.'],
            'content' => ['type' => 'string', 'description' => 'For update_robots_txt: the whole robots.txt body. A Disallow line here can remove a section of the site from search.'],
            'confirm' => ['type' => 'boolean', 'description' => 'Required true for update_robots_settings and update_robots_txt. Both can remove pages from search results.'],
            'limit' => ['type' => 'integer', 'description' => 'Rows to return. Default 50, max 200.'],
            'offset' => ['type' => 'integer', 'description' => 'Where a listing starts.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string'],
        ],
        'required' => ['action'],
    ],
    'execute_callback' => 'nibwp_surerank_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'SureRank SEO, one hub.',
                '',
                'START WITH audit_summary for a whole site, or get_post_seo for one page.',
                'audit_summary is counts only and cheap; audit, audit_duplicates and',
                'audit_noindex_in_sitemap return the findings themselves.',
                '',
                'PER POST: get_post_seo reads the title, description, canonical, focus keyword,',
                'the three robots states and the social fields. update_post_seo writes any',
                'subset of them; clear_post_seo removes them so the site templates take over',
                'again. get_post_seo_checks returns what SureRank itself last found on the page,',
                'from its own cache — it does not re-run the analysis.',
                '',
                'ROBOTS ARE THREE STATES, NOT A BOOLEAN. default follows the post type setting,',
                'yes removes the page, no forces indexing. Sending true or false stores a value',
                'SureRank will ignore, so the enum is default | yes | no and nothing else.',
                '',
                'THIS CAN REMOVE PAGES FROM GOOGLE. update_post_seo with noindex=yes,',
                'update_robots_settings, and a Disallow line in update_robots_txt all take live',
                'URLs out of search results. The last two require confirm=true. Say back exactly',
                'what changed, including the old value, every time.',
                '',
                'A WRONG CANONICAL IS AS BAD AS A NOINDEX: it tells search engines the real page',
                'is somewhere else. Read the current value before writing one.',
                '',
                'SECRETS: get_settings reports values only for the settings this integration',
                'names, and nothing else — a key a future SureRank release adds comes back as a',
                'name with no value. The Google Search Console access and refresh tokens, the',
                'site-verification token and the AI account record never travel, and',
                'get_search_console_status reports only whether an account is attached. Asking',
                'differently will not change that.',
                '',
                'WHAT THIS CANNOT DO: there are no redirect actions, because this build of',
                'SureRank has no redirect manager — saying so is the correct answer. Schema is',
                'read, never written: a half-valid graph is worse than none, and the plugin\'s own',
                'schema screen is one click away.',
                '',
                'PERMISSIONS: every site-wide action needs manage_options, because it changes how',
                'every page on the site presents itself. Per-post actions need edit rights on',
                'that specific post, so a listing silently drops nothing — it reports how many',
                'posts it skipped.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_surerank_manage(array $input): array|WP_Error
{
    $guard = nibwp_surerank_guard();
    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = isset($input['action']) ? (string) $input['action'] : '';

    if (!in_array($action, nibwp_surerank_actions(), true)) {
        return nibwp_surerank_err(
            'unknown_action',
            sprintf(
                /* translators: 1: action name, 2: list of valid actions */
                __('Unknown SureRank action "%1$s". Valid actions: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_surerank_actions())
            )
        );
    }

    // SureRank's own classes reach filters any plugin may have hooked and a
    // settings row any migration may have reshaped. One catch here turns all of
    // that into a named refusal instead of a fatal that takes the REST request
    // with it.
    try {
        $result = nibwp_surerank_dispatch($action, $input);
    } catch (\Throwable $error) {
        return nibwp_surerank_err(
            'plugin_error',
            sprintf(
                /* translators: 1: action name, 2: error message from SureRank */
                __('SureRank could not complete %1$s: %2$s', 'nibwp'),
                $action,
                $error->getMessage()
            ),
            500
        );
    }

    if ($result instanceof WP_Error) {
        return $result;
    }

    return ['action' => $action] + $result;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_surerank_dispatch(string $action, array $input): array|WP_Error
{
    return match ($action) {
        'get_post_seo' => nibwp_surerank_get_post_seo($input),
        'get_post_seo_checks' => nibwp_surerank_get_post_seo_checks($input),
        'bulk_get_post_seo' => nibwp_surerank_bulk_get_post_seo($input),
        'update_post_seo' => nibwp_surerank_update_post_seo($input),
        'clear_post_seo' => nibwp_surerank_clear_post_seo($input),
        'get_settings' => nibwp_surerank_get_settings($input),
        'get_title_templates' => nibwp_surerank_get_title_templates(),
        'get_robots_settings' => nibwp_surerank_get_robots_settings(),
        'get_sitemap_state' => nibwp_surerank_get_sitemap_state(),
        'get_robots_txt' => nibwp_surerank_get_robots_txt(),
        'get_social_defaults' => nibwp_surerank_get_social_defaults(),
        'get_schema_settings' => nibwp_surerank_get_schema_settings(),
        'get_feature_flags' => nibwp_surerank_get_feature_flags(),
        'get_search_console_status' => nibwp_surerank_get_search_console_status(),
        'list_content_types' => nibwp_surerank_list_content_types(),
        'update_title_templates' => nibwp_surerank_update_settings_group($input, 'update_title_templates'),
        'update_robots_settings' => nibwp_surerank_update_settings_group($input, 'update_robots_settings'),
        'update_sitemap_settings' => nibwp_surerank_update_settings_group($input, 'update_sitemap_settings'),
        'update_social_defaults' => nibwp_surerank_update_settings_group($input, 'update_social_defaults'),
        'update_robots_txt' => nibwp_surerank_update_robots_txt($input),
        'audit' => nibwp_surerank_audit($input),
        'audit_duplicates' => nibwp_surerank_audit_duplicates($input),
        'audit_noindex_in_sitemap' => nibwp_surerank_audit_noindex_in_sitemap($input),
        'audit_summary' => nibwp_surerank_audit_summary($input),
    };
}

/* ───────────────────────────── per-post reads ───────────────────────────── */

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_surerank_get_post_seo(array $input): array|WP_Error
{
    $post = nibwp_surerank_post($input['post_id'] ?? null);
    if ($post instanceof WP_Error) {
        return $post;
    }

    $record = nibwp_surerank_post_record($post);
    $lengths = nibwp_surerank_lengths();

    $record['measured'] = [
        'title_length' => nibwp_surerank_length($record['title']),
        'description_length' => nibwp_surerank_length($record['description']),
        'lengths' => $lengths,
    ];
    $record['meta_keys'] = [
        'general' => nibwp_surerank_meta_key('general'),
        'social' => nibwp_surerank_meta_key('social'),
        'noindex' => nibwp_surerank_meta_key('post_no_index'),
        'nofollow' => nibwp_surerank_meta_key('post_no_follow'),
        'noarchive' => nibwp_surerank_meta_key('post_no_archive'),
        'schemas' => nibwp_surerank_meta_key('schemas'),
    ];

    return $record;
}

/**
 * SureRank's own cached findings for one post.
 *
 * Read from the plugin's cache rather than re-run: the analyser fetches the
 * rendered page over HTTP (inc/analyzer/scraper.php), which is not something an
 * ability should do on a caller's behalf without being asked.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_surerank_get_post_seo_checks(array $input): array|WP_Error
{
    $post = nibwp_surerank_post($input['post_id'] ?? null);
    if ($post instanceof WP_Error) {
        return $post;
    }

    $post_id = (int) $post->ID;
    $stored = get_post_meta($post_id, NIBWP_SURERANK_CHECKS_META, true);
    $updated = get_post_meta($post_id, 'surerank_seo_checks_last_updated', true);

    if (!is_array($stored) || $stored === []) {
        return [
            'post_id' => $post_id,
            'cached' => false,
            'note' => __('SureRank has not analysed this post yet. Open it in the editor, or run the plugin\'s own site-wide scan.', 'nibwp'),
            'checks' => [],
        ];
    }

    // Shape is the plugin's, and it nests. Reported as-is for the named buckets
    // only, so a future key cannot ride along unexamined.
    $buckets = [];
    foreach (['error', 'warning', 'suggestion', 'passed'] as $severity) {
        $rows = $stored[$severity] ?? null;
        $buckets[$severity] = is_array($rows) ? count($rows) : 0;
    }

    return [
        'post_id' => $post_id,
        'cached' => true,
        'last_updated' => is_scalar($updated) ? (string) $updated : '',
        'counts' => $buckets,
        'checks' => nibwp_surerank_shallow_checks($stored),
    ];
}

/**
 * Flatten the plugin's nested findings into rows a caller can read.
 *
 * @param array<string, mixed> $stored
 * @return array<int, array<string, mixed>>
 */
function nibwp_surerank_shallow_checks(array $stored): array
{
    $rows = [];

    foreach (['error', 'warning', 'suggestion'] as $severity) {
        $group = $stored[$severity] ?? null;

        if (!is_array($group)) {
            continue;
        }

        foreach ($group as $key => $row) {
            if (count($rows) >= NIBWP_SURERANK_MAX_ROWS) {
                return $rows;
            }

            $message = is_array($row) ? ($row['message'] ?? ($row['title'] ?? '')) : $row;

            $rows[] = [
                'id' => is_scalar($key) ? (string) $key : '',
                'severity' => $severity,
                // Scalar or nothing: a finding's payload can carry the whole
                // scraped document, and that is not what was asked for.
                'message' => is_scalar($message) ? (string) $message : '',
            ];
        }
    }

    return $rows;
}

/**
 * Many posts' SEO fields at once, bounded, with the skips counted.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_surerank_bulk_get_post_seo(array $input): array|WP_Error
{
    $types = nibwp_surerank_requested_types($input);
    if ($types instanceof WP_Error) {
        return $types;
    }

    $limit = nibwp_surerank_limit($input['limit'] ?? null);
    $offset = nibwp_surerank_offset($input['offset'] ?? null);

    $ids = nibwp_surerank_post_ids($types, $limit, $offset);

    $rows = [];
    $skipped = 0;

    foreach ($ids as $post_id) {
        $post = get_post($post_id);

        if (!$post) {
            $skipped++;
            continue;
        }

        // The per-post gate again, per row. A listing that returned every post
        // because the caller could edit one of them would be a hole, so the only
        // thing that leaves here about a post the account cannot edit is that it
        // was skipped.
        if (!current_user_can('edit_post', (int) $post_id)) {
            $skipped++;
            continue;
        }

        $rows[] = nibwp_surerank_post_record($post);
    }

    return [
        'post_types' => $types,
        'limit' => $limit,
        'offset' => $offset,
        'returned' => count($rows),
        'skipped' => $skipped,
        'skipped_note' => $skipped > 0
            ? __('Posts this account cannot edit, or that no longer exist, are not included.', 'nibwp')
            : '',
        'posts' => $rows,
    ];
}

/* ───────────────────────────── per-post writes ──────────────────────────── */

/**
 * Write any subset of one post's SEO fields.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_surerank_update_post_seo(array $input): array|WP_Error
{
    $post = nibwp_surerank_post($input['post_id'] ?? null);
    if ($post instanceof WP_Error) {
        return $post;
    }

    $post_id = (int) $post->ID;
    $before = nibwp_surerank_post_record($post);
    $changed = [];
    $warnings = [];

    // general: read the whole stored group, change the named fields, write it
    // back. Writing a fresh array would drop auto_description and the generated
    // OG image, which SureRank fills in itself and would then have to rebuild.
    $general = nibwp_surerank_group($post_id, 'general');
    $general_map = [
        'title' => 'page_title',
        'description' => 'page_description',
        'canonical' => 'canonical_url',
        'focus_keyword' => 'focus_keyword',
    ];

    $general_touched = false;
    foreach ($general_map as $name => $field) {
        if (!array_key_exists($name, $input)) {
            continue;
        }

        if (!is_scalar($input[$name]) && $input[$name] !== null) {
            return nibwp_surerank_err(
                'bad_field',
                sprintf(
                    /* translators: %s: the input field name */
                    __('%s must be a string.', 'nibwp'),
                    $name
                ),
                422
            );
        }

        $value = $input[$name] === null ? '' : (string) $input[$name];

        if ($name === 'canonical' && $value !== '') {
            $clean = nibwp_surerank_url($value);
            if ($clean instanceof WP_Error) {
                return $clean;
            }
            $value = $clean;
            $warnings[] = __('A canonical URL tells search engines the real page is at that address. If it is wrong, this page drops out of search results.', 'nibwp');
        }

        $general[$field] = $value;
        $general_touched = true;
        $changed[$name] = ['from' => $before[$name] ?? '', 'to' => $value];
    }

    if ($general_touched) {
        update_post_meta($post_id, nibwp_surerank_meta_key('general'), $general);
    }

    // The three robots rows, each its own meta row and each its own string.
    foreach (nibwp_surerank_robots_rows() as $name => $group) {
        if (!array_key_exists($name, $input)) {
            continue;
        }

        $state = is_scalar($input[$name]) ? (string) $input[$name] : '';

        if (!in_array($state, ['default', 'yes', 'no'], true)) {
            return nibwp_surerank_err(
                'bad_robots',
                sprintf(
                    /* translators: 1: input field name, 2: the rejected value */
                    __('%1$s must be default, yes or no. SureRank stores three states, not a boolean, so "%2$s" would be ignored.', 'nibwp'),
                    $name,
                    $state
                ),
                422
            );
        }

        $key = nibwp_surerank_meta_key($group);

        if ($state === 'default') {
            delete_post_meta($post_id, $key);
        } else {
            update_post_meta($post_id, $key, $state);
        }

        $changed[$name] = ['from' => $before['robots'][$name] ?? 'default', 'to' => $state];

        if ($name === 'noindex' && $state === 'yes') {
            $warnings[] = __('noindex=yes removes this page from search results and from the XML sitemap.', 'nibwp');
        }
    }

    // social: same read-modify-write, same reason.
    if (array_key_exists('social', $input)) {
        if (!is_array($input['social'])) {
            return nibwp_surerank_err('bad_social', __('social must be an object of field => value.', 'nibwp'), 422);
        }

        $allowed = nibwp_surerank_meta_groups()['social'];
        $social = nibwp_surerank_group($post_id, 'social');
        $social_changed = [];

        foreach ($input['social'] as $field => $value) {
            $field = is_scalar($field) ? (string) $field : '';

            if (!in_array($field, $allowed, true)) {
                return nibwp_surerank_err(
                    'bad_social_field',
                    sprintf(
                        /* translators: 1: the rejected field name, 2: the accepted field names */
                        __('SureRank has no social field "%1$s". Accepted: %2$s.', 'nibwp'),
                        $field,
                        implode(', ', $allowed)
                    ),
                    422
                );
            }

            if (!is_scalar($value) && $value !== null) {
                return nibwp_surerank_err(
                    'bad_social_field',
                    sprintf(
                        /* translators: %s: the social field name */
                        __('social.%s must be a scalar.', 'nibwp'),
                        $field
                    ),
                    422
                );
            }

            $social[$field] = $value === null ? '' : (string) $value;
            $social_changed[$field] = $social[$field];
        }

        if ($social_changed !== []) {
            update_post_meta($post_id, nibwp_surerank_meta_key('social'), $social);
            $changed['social'] = $social_changed;
        }
    }

    if ($changed === []) {
        return nibwp_surerank_err(
            'nothing_to_write',
            __('Nothing was given to change. Send at least one of title, description, canonical, focus_keyword, noindex, nofollow, noarchive or social.', 'nibwp'),
            422
        );
    }

    // SureRank caches its own merged settings in a static and stamps a timestamp
    // the sitemap's checksum reads. Touching the stamp here keeps the sitemap from
    // serving a cached chunk that disagrees with what was just written.
    nibwp_surerank_touch($post_id);

    return [
        'post_id' => $post_id,
        'changed' => $changed,
        'warnings' => array_values(array_unique($warnings)),
        'written_to' => [
            'general' => nibwp_surerank_meta_key('general'),
            'social' => nibwp_surerank_meta_key('social'),
            'noindex' => nibwp_surerank_meta_key('post_no_index'),
            'nofollow' => nibwp_surerank_meta_key('post_no_follow'),
            'noarchive' => nibwp_surerank_meta_key('post_no_archive'),
        ],
    ];
}

/**
 * Clear one post's SEO fields so the site templates take over again.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_surerank_clear_post_seo(array $input): array|WP_Error
{
    $post = nibwp_surerank_post($input['post_id'] ?? null);
    if ($post instanceof WP_Error) {
        return $post;
    }

    $post_id = (int) $post->ID;
    $all = ['title', 'description', 'canonical', 'focus_keyword', 'robots', 'social'];
    $wanted = $all;

    if (array_key_exists('fields', $input)) {
        if (!is_array($input['fields'])) {
            return nibwp_surerank_err('bad_fields', __('fields must be an array of field names.', 'nibwp'), 422);
        }

        $wanted = [];
        foreach ($input['fields'] as $field) {
            $field = is_scalar($field) ? (string) $field : '';

            if (!in_array($field, $all, true)) {
                return nibwp_surerank_err(
                    'bad_fields',
                    sprintf(
                        /* translators: 1: the rejected field name, 2: the accepted names */
                        __('"%1$s" is not something this can clear. Accepted: %2$s.', 'nibwp'),
                        $field,
                        implode(', ', $all)
                    ),
                    422
                );
            }

            $wanted[] = $field;
        }

        if ($wanted === []) {
            return nibwp_surerank_err('bad_fields', __('fields was empty. Leave it out to clear everything.', 'nibwp'), 422);
        }
    }

    $general = nibwp_surerank_group($post_id, 'general');
    $general_map = [
        'title' => 'page_title',
        'description' => 'page_description',
        'canonical' => 'canonical_url',
        'focus_keyword' => 'focus_keyword',
    ];
    $cleared = [];
    $general_touched = false;

    foreach ($general_map as $name => $field) {
        if (!in_array($name, $wanted, true)) {
            continue;
        }

        $general[$field] = '';
        $general_touched = true;
        $cleared[] = $name;
    }

    if ($general_touched) {
        update_post_meta($post_id, nibwp_surerank_meta_key('general'), $general);
    }

    if (in_array('robots', $wanted, true)) {
        foreach (nibwp_surerank_robots_rows() as $group) {
            delete_post_meta($post_id, nibwp_surerank_meta_key($group));
        }
        $cleared[] = 'robots';
    }

    if (in_array('social', $wanted, true)) {
        delete_post_meta($post_id, nibwp_surerank_meta_key('social'));
        $cleared[] = 'social';
    }

    nibwp_surerank_touch($post_id);

    return [
        'post_id' => $post_id,
        'cleared' => $cleared,
        'note' => __('Cleared fields fall back to the site-wide templates. Robots falls back to the post type setting, which may itself be noindex.', 'nibwp'),
    ];
}

/**
 * Stamp a post as changed so SureRank's caches notice.
 *
 * The plugin keeps a last-updated stamp per object (constants.php:14) and the
 * sitemap's checksum is built from it (inc/sitemap/checksum.php). Writing meta
 * without the stamp leaves a cached sitemap chunk describing the old state.
 */
function nibwp_surerank_touch(int $post_id): void
{
    if (defined('SURERANK_SEO_LAST_UPDATED')) {
        update_post_meta($post_id, (string) SURERANK_SEO_LAST_UPDATED, time());
    }

    if (class_exists('SureRank\\Inc\\Functions\\Settings')
        && method_exists('SureRank\\Inc\\Functions\\Settings', 'clear_cache')) {
        try {
            SureRank\Inc\Functions\Settings::clear_cache();
        } catch (\Throwable $error) {
            // A stale cache is a wrong answer on the next read, not a failed
            // write — the write already happened and reporting it as failed
            // would be the bigger lie.
            return;
        }
    }
}

/**
 * Validate a URL from input before it becomes a canonical.
 *
 * A canonical is the one field here where a typo is indistinguishable from a
 * deliberate choice: SureRank stores whatever it is given and prints it, so a
 * malformed value ends up in a live <link rel="canonical">.
 */
function nibwp_surerank_url(string $raw): string|WP_Error
{
    $url = trim($raw);
    $clean = esc_url_raw($url);

    if ($clean === '' || filter_var($clean, FILTER_VALIDATE_URL) === false) {
        return nibwp_surerank_err(
            'bad_canonical',
            sprintf(
                /* translators: %s: the rejected value */
                __('"%s" is not a URL. A canonical must be absolute, including the scheme.', 'nibwp'),
                $url
            ),
            422
        );
    }

    return $clean;
}

/**
 * Which post types a bulk read or an audit should cover.
 *
 * @param array<string, mixed> $input
 * @return array<int, string>|WP_Error
 */
function nibwp_surerank_requested_types(array $input): array|WP_Error
{
    $supported = nibwp_surerank_post_types();

    if (!array_key_exists('post_type', $input) || $input['post_type'] === null || $input['post_type'] === '') {
        if ($supported === []) {
            return nibwp_surerank_err('no_post_types', __('No post type on this site keeps SureRank SEO fields.', 'nibwp'));
        }

        return $supported;
    }

    $wanted = is_scalar($input['post_type']) ? (string) $input['post_type'] : '';

    if (!in_array($wanted, $supported, true)) {
        return nibwp_surerank_err(
            'bad_post_type',
            sprintf(
                /* translators: 1: the rejected post type slug, 2: the supported slugs */
                __('"%1$s" is not a post type SureRank keeps SEO fields for. Supported: %2$s.', 'nibwp'),
                $wanted,
                implode(', ', $supported) !== '' ? implode(', ', $supported) : __('none', 'nibwp')
            ),
            422
        );
    }

    return [$wanted];
}

/**
 * Published post ids for a set of post types, bounded.
 *
 * @param array<int, string> $types
 * @return array<int, int>
 */
function nibwp_surerank_post_ids(array $types, int $limit, int $offset): array
{
    $rows = nibwp_surerank_post_rows($types, $limit, $offset);

    return array_map(static fn(array $row): int => (int) $row['ID'], $rows);
}

/**
 * Published posts for a set of post types, with the columns the audit needs.
 *
 * The post types reach SQL as prepared VALUES, never as part of the statement:
 * the table name comes from $wpdb and the placeholder list is built from the
 * COUNT of types, not from their text, so nothing a caller sends can become
 * syntax.
 *
 * paginated is computed in SQL rather than by pulling post_content back: an audit
 * may cover 500 posts and the bodies are the largest thing in the table, while all
 * that is wanted is whether a page break appears in one.
 *
 * @param array<int, string> $types
 * @return array<int, array<string, mixed>>
 */
function nibwp_surerank_post_rows(array $types, int $limit, int $offset): array
{
    global $wpdb;

    $types = array_values(array_filter(array_map(
        static fn($type) => is_scalar($type) ? (string) $type : '',
        $types
    ), static fn(string $type): bool => $type !== ''));

    if ($types === [] || !isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'prepare')) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($types), '%s'));

    // The LIKE pattern is the first placeholder in the statement because the
    // SELECT list is read before the WHERE clause.
    $values = array_merge(['%<!--nextpage-->%'], $types, [$limit, $offset]);

    $sql = $wpdb->prepare(
        'SELECT ID, post_title, post_type, (post_content LIKE %s) AS paginated'
        . " FROM {$wpdb->posts}"
        . " WHERE post_status = 'publish' AND post_type IN ({$placeholders})"
        . ' ORDER BY ID DESC LIMIT %d OFFSET %d',
        ...$values
    );

    if (!is_string($sql) || $sql === '') {
        return [];
    }

    $rows = $wpdb->get_results($sql, ARRAY_A);

    if (!is_array($rows)) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !isset($row['ID'])) {
            continue;
        }

        $out[] = [
            'ID' => (int) $row['ID'],
            'post_title' => isset($row['post_title']) && is_scalar($row['post_title']) ? (string) $row['post_title'] : '',
            'post_type' => isset($row['post_type']) && is_scalar($row['post_type']) ? (string) $row['post_type'] : '',
            'paginated' => !empty($row['paginated']),
        ];
    }

    return $out;
}

/* ──────────────────────────── site-wide reads ───────────────────────────── */

/**
 * Report a named set of settings, values for the allow-listed ones only.
 *
 * A key the allow-list does not name is still REPORTED — by name, under
 * `withheld`, with value_returned false — because an owner asking what is
 * configured deserves to know a setting exists even when its value must not
 * travel. That is also what makes a future SureRank release safe here: a key
 * nobody has classified appears, and does not leak.
 *
 * @param array<int, string>|null $only
 * @return array<string, mixed>
 */
function nibwp_surerank_report_settings(?array $only = null): array
{
    $stored = nibwp_surerank_settings();
    $allowed = nibwp_surerank_public_settings();

    $settings = [];
    $withheld = [];

    foreach ($allowed as $key => $type) {
        if ($only !== null && !in_array($key, $only, true)) {
            continue;
        }

        $settings[] = nibwp_surerank_setting_row($key, $stored[$key] ?? nibwp_surerank_default($key), $type)
            + ['stored' => array_key_exists($key, $stored)];
    }

    foreach (array_keys($stored) as $key) {
        $key = is_scalar($key) ? (string) $key : '';

        if ($key === '' || array_key_exists($key, $allowed)) {
            continue;
        }

        $withheld[] = ['key' => $key, 'value_returned' => false];
    }

    return [
        'settings' => $settings,
        'withheld' => $withheld,
        'withheld_note' => __('A setting this integration has not classified is named but never valued. Open SureRank in wp-admin to see it.', 'nibwp'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_surerank_get_settings(array $input): array|WP_Error
{
    $denied = nibwp_surerank_require_site(__('Reading the site-wide SureRank settings', 'nibwp'));
    if ($denied instanceof WP_Error) {
        return $denied;
    }

    return nibwp_surerank_report_settings() + [
        'version' => defined('SURERANK_VERSION') ? (string) SURERANK_VERSION : '',
        'option' => NIBWP_SURERANK_OPTION,
        'credentials_note' => __('The Google Search Console tokens, the site-verification token and the AI account record live in separate options and are never returned here.', 'nibwp'),
    ];
}

/**
 * The keys each site-wide update may write, and the matching read reports.
 *
 * Read and write share one list on purpose: a key that can be written but not
 * read back is a change nobody can verify, and a key that can be read but not
 * written looks like a bug from the caller's side.
 *
 * @return array<string, array<int, string>>
 */
function nibwp_surerank_settings_groups(): array
{
    return [
        'update_title_templates' => [
            'separator',
            'page_title',
            'page_description',
            'auto_generate_description',
            'home_page_title',
            'home_page_description',
        ],
        'update_robots_settings' => [
            'no_index',
            'no_follow',
            'no_archive',
            'author_archive',
            'date_archive',
            'noindex_paginated_pages',
            'index_home_page_paginated_pages',
            'paginated_link_relationships',
        ],
        'update_sitemap_settings' => [
            'enable_xml_sitemap',
            'enable_xml_image_sitemap',
            'sitemap_excluded_post_types',
            'sitemap_excluded_taxonomies',
        ],
        'update_social_defaults' => [
            'open_graph_tags',
            'facebook_meta_tags',
            'twitter_meta_tags',
            'twitter_card_type',
            'twitter_same_as_facebook',
            'facebook_page_url',
            'facebook_author_fallback',
            'twitter_profile_username',
            'twitter_profile_fallback',
            'fallback_image',
            'home_page_facebook_title',
            'home_page_facebook_description',
            'home_page_facebook_image_url',
            'home_page_twitter_title',
            'home_page_twitter_description',
            'home_page_twitter_image_url',
            'oembeded_scripts',
            'oembeded_og_title',
            'oembeded_social_images',
            'oembeded_remove_author_name',
        ],
    ];
}

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_surerank_get_title_templates(): array|WP_Error
{
    $denied = nibwp_surerank_require_site(__('Reading the site-wide title and description templates', 'nibwp'));
    if ($denied instanceof WP_Error) {
        return $denied;
    }

    return nibwp_surerank_report_settings(nibwp_surerank_settings_groups()['update_title_templates']) + [
        'variables_note' => __('Templates use SureRank variables wrapped in percent signs, among them title, site_name, tagline and content. The separator setting is what the sep variable renders as.', 'nibwp'),
    ];
}

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_surerank_get_robots_settings(): array|WP_Error
{
    $denied = nibwp_surerank_require_site(__('Reading the site-wide robots and indexing settings', 'nibwp'));
    if ($denied instanceof WP_Error) {
        return $denied;
    }

    return nibwp_surerank_report_settings(nibwp_surerank_settings_groups()['update_robots_settings']) + [
        'membership_note' => __('no_index, no_follow and no_archive hold post type and archive keys together — a slug such as post or page, and the archive keys author, date, search, post_format and attachment. A post type listed in no_index is excluded from search results site-wide.', 'nibwp'),
    ];
}

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_surerank_get_social_defaults(): array|WP_Error
{
    $denied = nibwp_surerank_require_site(__('Reading the site-wide social and Open Graph defaults', 'nibwp'));
    if ($denied instanceof WP_Error) {
        return $denied;
    }

    return nibwp_surerank_report_settings(nibwp_surerank_settings_groups()['update_social_defaults']) + [
        'fallback_note' => __('A post with no social image of its own falls back to its featured image, then to fallback_image. With neither set, a shared link renders with no picture.', 'nibwp'),
    ];
}

/**
 * The sitemap: whether it is on, where it is, and what it advertises.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_surerank_get_sitemap_state(): array|WP_Error
{
    $denied = nibwp_surerank_require_site(__('Reading the sitemap settings', 'nibwp'));
    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $stored = nibwp_surerank_settings();
    $enabled = (bool) ($stored['enable_xml_sitemap'] ?? nibwp_surerank_default('enable_xml_sitemap') ?? true);
    $excluded_types = nibwp_surerank_string_list($stored['sitemap_excluded_post_types'] ?? []);
    $excluded_taxes = nibwp_surerank_string_list($stored['sitemap_excluded_taxonomies'] ?? []);
    $noindexed = nibwp_surerank_string_list($stored['no_index'] ?? nibwp_surerank_default('no_index') ?? []);

    $included = [];
    foreach (nibwp_surerank_post_types() as $type) {
        if (in_array($type, $excluded_types, true)) {
            continue;
        }

        $counts = function_exists('wp_count_posts') ? wp_count_posts($type) : null;

        $included[] = [
            'post_type' => $type,
            'published' => is_object($counts) && isset($counts->publish) ? (int) $counts->publish : 0,
            // A type in no_index is excluded from search results while the
            // sitemap still advertises it, which is the contradiction
            // audit_noindex_in_sitemap exists to find.
            'post_type_noindexed' => in_array($type, $noindexed, true),
        ];
    }

    return nibwp_surerank_report_settings(nibwp_surerank_settings_groups()['update_sitemap_settings']) + [
        'enabled' => $enabled,
        // The slug is the plugin's own (inc/sitemap/xml-sitemap.php:67).
        'index_url' => $enabled && function_exists('home_url') ? home_url('/sitemap_index.xml') : '',
        'includes' => $included,
        'excluded_post_types' => $excluded_types,
        'excluded_taxonomies' => $excluded_taxes,
    ];
}

/**
 * The robots.txt body SureRank serves.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_surerank_get_robots_txt(): array|WP_Error
{
    $denied = nibwp_surerank_require_site(__('Reading robots.txt', 'nibwp'));
    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $content = get_option(NIBWP_SURERANK_ROBOTS_OPTION, '');
    $content = is_scalar($content) ? (string) $content : '';

    return [
        'option' => NIBWP_SURERANK_ROBOTS_OPTION,
        'content' => $content,
        'is_set' => $content !== '',
        'url' => function_exists('home_url') ? home_url('/robots.txt') : '',
        'note' => $content === ''
            ? __('Nothing is stored, so WordPress serves its own robots.txt.', 'nibwp')
            : __('A Disallow line here stops search engines crawling the paths it names.', 'nibwp'),
    ];
}

/**
 * Schema: what this build is configured to emit.
 *
 * Read-only by design. The stored structure is a list of type definitions the
 * plugin validates against its own rules (inc/schema/validator.php); writing one
 * from here means composing a nested graph blind, and a half-valid graph is worse
 * than none. The names and the enabled flag are what an owner is asking for.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_surerank_get_schema_settings(): array|WP_Error
{
    $denied = nibwp_surerank_require_site(__('Reading the schema settings', 'nibwp'));
    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $stored = nibwp_surerank_settings();
    $enabled = (bool) ($stored['enable_schemas'] ?? nibwp_surerank_default('enable_schemas') ?? true);
    $schemas = $stored['schemas'] ?? nibwp_surerank_default('schemas');
    $rows = [];

    if (is_array($schemas)) {
        foreach ($schemas as $key => $schema) {
            if (count($rows) >= NIBWP_SURERANK_MAX_ROWS) {
                break;
            }

            // Only the identifying fields travel. A schema definition carries
            // arbitrary nested properties, and dumping them would make this read
            // unbounded in a way no row limit controls.
            $rows[] = [
                'id' => is_scalar($key) ? (string) $key : '',
                'type' => is_array($schema) && isset($schema['@type']) && is_scalar($schema['@type'])
                    ? (string) $schema['@type']
                    : '',
                'title' => is_array($schema) && isset($schema['title']) && is_scalar($schema['title'])
                    ? (string) $schema['title']
                    : '',
                'fields' => is_array($schema) ? count($schema) : 0,
            ];
        }
    }

    return [
        'enabled' => $enabled,
        'schemas' => $rows,
        'post_meta_key' => nibwp_surerank_meta_key('schemas'),
        'note' => __('Schema is read here, never written. Compose it on SureRank\'s own schema screen, where its validator runs against what you type.', 'nibwp'),
    ];
}

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_surerank_get_feature_flags(): array|WP_Error
{
    $denied = nibwp_surerank_require_site(__('Reading the SureRank feature flags', 'nibwp'));
    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $flags = [
        'enable_page_level_seo',
        'enable_google_console',
        'enable_schemas',
        'enable_migration',
        'enable_mcp',
        'enable_headless_rest_api',
        'enable_woocommerce_integration',
        'enable_angie_integration',
        'enable_fluentcart_integration',
        'redirect_attachment_pages_to_post_parent',
        'auto_set_image_title',
        'auto_set_image_alt',
    ];

    return nibwp_surerank_report_settings($flags) + [
        'redirects_note' => __('This build has no redirect manager. The only redirect SureRank performs is attachment pages to their parent post, which is the flag above — there is nothing to list, create or delete.', 'nibwp'),
    ];
}

/**
 * Is a Google Search Console account attached?
 *
 * Connected or not, and nothing else. The option holds an access token and a
 * refresh token (inc/google-search-console/auth.php:17-25) plus the account's
 * name, email and avatar; none of that is needed to answer the question, and the
 * tokens would give their bearer the owner's Search Console account.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_surerank_get_search_console_status(): array|WP_Error
{
    $denied = nibwp_surerank_require_site(__('Reading the Search Console connection status', 'nibwp'));
    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $rows = [];
    foreach (nibwp_surerank_secret_options() as $option) {
        $value = get_option($option, null);
        $set = $value !== null && $value !== false && $value !== '' && $value !== [];

        $rows[] = ['option' => $option, 'is_set' => $set, 'value_returned' => false];
    }

    $stored = nibwp_surerank_settings();

    return [
        'feature_enabled' => (bool) ($stored['enable_google_console'] ?? nibwp_surerank_default('enable_google_console') ?? true),
        'credentials' => $rows,
        'note' => __('Only whether each credential is present. The access token, the refresh token, the site-verification token and the AI account record are never returned, by any action, however the request is phrased.', 'nibwp'),
    ];
}

/**
 * Which post types and taxonomies SureRank keeps SEO fields for.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_surerank_list_content_types(): array|WP_Error
{
    $denied = nibwp_surerank_require_site(__('Listing the content types SureRank covers', 'nibwp'));
    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $stored = nibwp_surerank_settings();
    $noindexed = nibwp_surerank_string_list($stored['no_index'] ?? nibwp_surerank_default('no_index') ?? []);
    $excluded = nibwp_surerank_string_list($stored['sitemap_excluded_post_types'] ?? []);

    $types = [];
    foreach (nibwp_surerank_post_types() as $type) {
        $object = function_exists('get_post_type_object') ? get_post_type_object($type) : null;

        $types[] = [
            'slug' => $type,
            'label' => is_object($object) && isset($object->label) && is_scalar($object->label)
                ? (string) $object->label
                : $type,
            'noindexed' => in_array($type, $noindexed, true),
            'in_sitemap' => !in_array($type, $excluded, true),
        ];
    }

    $taxonomies = [];
    if (function_exists('get_taxonomies')) {
        $all = get_taxonomies(['public' => true, 'show_ui' => true], 'names');

        foreach (is_array($all) ? $all : [] as $taxonomy) {
            $taxonomies[] = is_scalar($taxonomy) ? (string) $taxonomy : '';
        }
    }

    return [
        'post_types' => $types,
        'taxonomies' => array_values(array_filter($taxonomies, static fn(string $t): bool => $t !== '')),
        'excluded_note' => __('Attachments are left out: SureRank redirects attachment pages to their parent post by default, so an attachment\'s own SEO fields are stored and never rendered.', 'nibwp'),
    ];
}

/**
 * A stored list, reduced to the strings in it.
 *
 * @return array<int, string>
 */
function nibwp_surerank_string_list(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $out = [];
    foreach ($value as $item) {
        if (is_scalar($item)) {
            $out[] = (string) $item;
        }
    }

    return $out;
}

/* ──────────────────────────── site-wide writes ──────────────────────────── */

/**
 * Write one group of site-wide settings.
 *
 * Read-modify-write against the whole option: surerank_settings is a single row
 * holding every setting the plugin has, so writing a fresh array from the keys in
 * this request would silently delete everything not mentioned.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_surerank_update_settings_group(array $input, string $action): array|WP_Error
{
    $denied = nibwp_surerank_require_site(
        sprintf(
            /* translators: %s: the action name */
            __('Changing site-wide SureRank settings (%s)', 'nibwp'),
            $action
        )
    );
    if ($denied instanceof WP_Error) {
        return $denied;
    }

    $groups = nibwp_surerank_settings_groups();
    $allowed = $groups[$action] ?? [];

    if ($allowed === []) {
        return nibwp_surerank_err('unknown_action', __('That action writes no settings.', 'nibwp'));
    }

    // Robots settings decide whether whole post types appear in search at all, so
    // this one asks out loud before it does it.
    if ($action === 'update_robots_settings' && empty($input['confirm'])) {
        return nibwp_surerank_err(
            'confirm_required',
            __('Send confirm=true. Changing the robots settings can remove entire post types from search results.', 'nibwp'),
            422
        );
    }

    if (!isset($input['settings']) || !is_array($input['settings']) || $input['settings'] === []) {
        return nibwp_surerank_err(
            'missing_settings',
            sprintf(
                /* translators: %s: the setting keys this action accepts */
                __('Send settings as an object of key => value. This action accepts: %s.', 'nibwp'),
                implode(', ', $allowed)
            ),
            422
        );
    }

    $types = nibwp_surerank_public_settings();
    $stored = nibwp_surerank_settings();
    $changed = [];

    foreach ($input['settings'] as $key => $value) {
        $key = is_scalar($key) ? (string) $key : '';

        if (!in_array($key, $allowed, true)) {
            return nibwp_surerank_err(
                'bad_setting',
                sprintf(
                    /* translators: 1: the rejected setting key, 2: the accepted keys */
                    __('"%1$s" is not a setting this action may write. Accepted: %2$s.', 'nibwp'),
                    $key,
                    implode(', ', $allowed)
                ),
                422
            );
        }

        $clean = nibwp_surerank_coerce($key, $value, $types[$key] ?? 'string');
        if ($clean instanceof WP_Error) {
            return $clean;
        }

        $before = nibwp_surerank_setting_row($key, $stored[$key] ?? nibwp_surerank_default($key), $types[$key] ?? 'string');
        $stored[$key] = $clean;
        $changed[$key] = ['from' => $before['value'], 'to' => $clean];
    }

    update_option(NIBWP_SURERANK_OPTION, $stored);
    nibwp_surerank_clear_settings_cache();

    $warnings = [];
    if ($action === 'update_robots_settings') {
        $warnings[] = __('Anything now listed in no_index is excluded from search results and from the XML sitemap.', 'nibwp');
    }
    if ($action === 'update_sitemap_settings' && array_key_exists('enable_xml_sitemap', $changed) && $changed['enable_xml_sitemap']['to'] === false) {
        $warnings[] = __('With the XML sitemap off, search engines are no longer told which pages exist or when they changed.', 'nibwp');
    }

    return [
        'option' => NIBWP_SURERANK_OPTION,
        'changed' => $changed,
        'warnings' => $warnings,
    ];
}

/**
 * Coerce one setting value to the shape SureRank stores it in.
 *
 * On a WRITE a non-scalar under a scalar key is refused rather than dropped: a
 * read dropping it loses nothing, but a write dropping it reports success for a
 * change that did not happen.
 *
 * @return bool|int|string|array<int, string>|WP_Error
 */
function nibwp_surerank_coerce(string $key, mixed $value, string $type): bool|int|string|array|WP_Error
{
    if ($type === 'list') {
        if (!is_array($value)) {
            return nibwp_surerank_err(
                'bad_setting',
                sprintf(
                    /* translators: %s: the setting key */
                    __('%s must be an array of slugs. Send an empty array to clear it.', 'nibwp'),
                    $key
                ),
                422
            );
        }

        $out = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                return nibwp_surerank_err(
                    'bad_setting',
                    sprintf(
                        /* translators: %s: the setting key */
                        __('%s may only contain slugs, and one of the items was not one.', 'nibwp'),
                        $key
                    ),
                    422
                );
            }

            $slug = sanitize_key((string) $item);

            if ($slug !== '') {
                $out[] = $slug;
            }
        }

        return array_values(array_unique($out));
    }

    if (!is_scalar($value) && $value !== null) {
        return nibwp_surerank_err(
            'bad_setting',
            sprintf(
                /* translators: %s: the setting key */
                __('%s must be a single value, not a structure.', 'nibwp'),
                $key
            ),
            422
        );
    }

    return match ($type) {
        'bool' => (bool) $value,
        'int' => (int) $value,
        default => $value === null ? '' : sanitize_text_field((string) $value),
    };
}

/**
 * Replace the robots.txt body.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_surerank_update_robots_txt(array $input): array|WP_Error
{
    $denied = nibwp_surerank_require_site(__('Rewriting robots.txt', 'nibwp'));
    if ($denied instanceof WP_Error) {
        return $denied;
    }

    if (empty($input['confirm'])) {
        return nibwp_surerank_err(
            'confirm_required',
            __('Send confirm=true. A Disallow line in robots.txt stops search engines crawling the paths it names, which removes those pages from search results.', 'nibwp'),
            422
        );
    }

    if (!array_key_exists('content', $input) || !is_string($input['content'])) {
        return nibwp_surerank_err('missing_content', __('Send the whole robots.txt body as content. This replaces what is there; it does not append.', 'nibwp'), 422);
    }

    $content = $input['content'];

    // A robots.txt is a short directive file. A megabyte of it is a mistake or an
    // attempt to fill the options table, and either way it is not robots.txt.
    if (strlen($content) > 20000) {
        return nibwp_surerank_err('content_too_long', __('That is far longer than a robots.txt file. The limit here is 20000 bytes.', 'nibwp'), 422);
    }

    $previous = get_option(NIBWP_SURERANK_ROBOTS_OPTION, '');
    $previous = is_scalar($previous) ? (string) $previous : '';

    update_option(NIBWP_SURERANK_ROBOTS_OPTION, $content);

    $disallows = preg_match_all('/^\s*Disallow:\s*\S/mi', $content);

    return [
        'option' => NIBWP_SURERANK_ROBOTS_OPTION,
        'previous_length' => strlen($previous),
        'new_length' => strlen($content),
        'disallow_lines' => is_int($disallows) ? $disallows : 0,
        'warnings' => [
            __('robots.txt was replaced. Every Disallow line in it now blocks crawling of the paths it names.', 'nibwp'),
        ],
    ];
}

/** Drop SureRank's static settings cache after a write, when it offers one. */
function nibwp_surerank_clear_settings_cache(): void
{
    if (!class_exists('SureRank\\Inc\\Functions\\Settings')
        || !method_exists('SureRank\\Inc\\Functions\\Settings', 'clear_cache')) {
        return;
    }

    try {
        SureRank\Inc\Functions\Settings::clear_cache();
    } catch (\Throwable $error) {
        // The write landed. A stale cache is a wrong answer on the next read, not
        // a failed write, and reporting it as failed would be the bigger lie.
        return;
    }
}

/* ───────────────────────────────── the audit ────────────────────────────── */

/**
 * Walk a bounded set of published posts and collect what each one is missing.
 *
 * Shared by every audit action so they all measure the same posts with the same
 * ruler, and so a post the account cannot edit is skipped — and counted — in one
 * place rather than four.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_surerank_audit_scan(array $input): array|WP_Error
{
    $types = nibwp_surerank_requested_types($input);
    if ($types instanceof WP_Error) {
        return $types;
    }

    $limit = nibwp_surerank_limit($input['limit'] ?? null, 100, NIBWP_SURERANK_MAX_AUDIT);
    $offset = nibwp_surerank_offset($input['offset'] ?? null);

    $rows = nibwp_surerank_post_rows($types, $limit, $offset);
    $ids = array_map(static fn(array $row): int => (int) $row['ID'], $rows);

    // One meta query for the whole page of posts instead of six per post.
    if ($ids !== [] && function_exists('update_meta_cache')) {
        update_meta_cache('post', $ids);
    }

    $settings = nibwp_surerank_settings();
    $posts = [];
    $skipped = 0;

    foreach ($rows as $row) {
        $post_id = (int) $row['ID'];

        if (!current_user_can('edit_post', $post_id)) {
            $skipped++;
            continue;
        }

        $general = nibwp_surerank_group($post_id, 'general');
        $social = nibwp_surerank_group($post_id, 'social');

        $posts[] = [
            'post_id' => $post_id,
            'post_title' => (string) $row['post_title'],
            'post_type' => (string) $row['post_type'],
            'paginated' => (bool) $row['paginated'],
            'title' => nibwp_surerank_field($general, 'page_title'),
            'description' => nibwp_surerank_field($general, 'page_description'),
            'canonical' => nibwp_surerank_field($general, 'canonical_url'),
            'noindex' => nibwp_surerank_robots_state($post_id, 'post_no_index'),
            'facebook_image_url' => nibwp_surerank_field($social, 'facebook_image_url'),
            'twitter_image_url' => nibwp_surerank_field($social, 'twitter_image_url'),
            'thumbnail' => function_exists('get_post_thumbnail_id') ? (int) get_post_thumbnail_id($post_id) : 0,
        ];
    }

    return [
        'post_types' => $types,
        'limit' => $limit,
        'offset' => $offset,
        'posts_checked' => count($posts),
        'skipped' => $skipped,
        'posts' => $posts,
        'settings' => $settings,
    ];
}

/** One finding, shaped the same way wherever it comes from. */
function nibwp_surerank_finding(int $post_id, string $title, string $check, string $severity, string $detail, string $fix): array
{
    return [
        'post_id' => $post_id,
        'post_title' => $title,
        'check' => $check,
        'severity' => $severity,
        'detail' => $detail,
        'fix' => $fix,
    ];
}

/**
 * The per-post audit: everything one page can be missing.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_surerank_audit(array $input): array|WP_Error
{
    $scan = nibwp_surerank_audit_scan($input);
    if ($scan instanceof WP_Error) {
        return $scan;
    }

    $lengths = nibwp_surerank_lengths();
    $settings = $scan['settings'];
    $auto_description = (bool) ($settings['auto_generate_description'] ?? nibwp_surerank_default('auto_generate_description') ?? true);
    $noindex_paginated = (bool) ($settings['noindex_paginated_pages'] ?? nibwp_surerank_default('noindex_paginated_pages') ?? false);
    $fallback_image = nibwp_surerank_field(is_array($settings) ? $settings : [], 'fallback_image');

    $blockers = [];
    $warnings = [];
    $notices = [];

    foreach ($scan['posts'] as $post) {
        $id = (int) $post['post_id'];
        $name = (string) $post['post_title'];
        $title_length = nibwp_surerank_length($post['title']);
        $description_length = nibwp_surerank_length($post['description']);

        // No description. A blocker only when SureRank is not generating one:
        // with auto_generate_description on, the page still renders a description
        // taken from its content, which is weaker but not absent.
        if ($post['description'] === '') {
            $row = nibwp_surerank_finding(
                $id,
                $name,
                'no_meta_description',
                $auto_description ? 'warning' : 'blocker',
                $auto_description
                    ? __('No description of its own. SureRank is generating one from the page content, which rarely reads like a reason to click.', 'nibwp')
                    : __('No description, and SureRank is not generating one — search engines will invent a snippet from the page.', 'nibwp'),
                __('Write a description between 150 and 160 characters that says what the page is for.', 'nibwp')
            );

            if ($auto_description) {
                $warnings[] = $row;
            } else {
                $blockers[] = $row;
            }
        } elseif ($description_length > $lengths['description_max']) {
            $warnings[] = nibwp_surerank_finding(
                $id,
                $name,
                'description_too_long',
                'warning',
                sprintf(
                    /* translators: 1: character count, 2: the maximum that renders */
                    __('The description is %1$d characters; search results cut it off around %2$d.', 'nibwp'),
                    $description_length,
                    $lengths['description_max']
                ),
                __('Shorten it so the whole sentence survives.', 'nibwp')
            );
        } elseif ($description_length < $lengths['description_min']) {
            $warnings[] = nibwp_surerank_finding(
                $id,
                $name,
                'description_too_short',
                'warning',
                sprintf(
                    /* translators: 1: character count, 2: the length that fills a result */
                    __('The description is %1$d characters, short of the %2$d a search result will show.', 'nibwp'),
                    $description_length,
                    $lengths['description_min']
                ),
                __('Use the space — it is the only copy a searcher reads before deciding.', 'nibwp')
            );
        }

        // Titles. An empty stored title is not a fault: the site template fills
        // it, and measuring that template without rendering it would be a guess.
        if ($post['title'] !== '' && $title_length > $lengths['title_max']) {
            $warnings[] = nibwp_surerank_finding(
                $id,
                $name,
                'title_too_long',
                'warning',
                sprintf(
                    /* translators: 1: character count, 2: the maximum that renders */
                    __('The SEO title is %1$d characters; search results cut it off around %2$d.', 'nibwp'),
                    $title_length,
                    $lengths['title_max']
                ),
                __('Put the words that matter first and drop the rest.', 'nibwp')
            );
        } elseif ($post['title'] !== '' && $title_length < $lengths['title_min']) {
            $notices[] = nibwp_surerank_finding(
                $id,
                $name,
                'title_too_short',
                'notice',
                sprintf(
                    /* translators: 1: character count, 2: the length that fills a result */
                    __('The SEO title is %1$d characters, under the %2$d a search result will show.', 'nibwp'),
                    $title_length,
                    $lengths['title_min']
                ),
                __('There is room for a qualifier a searcher would type.', 'nibwp')
            );
        }

        // Paginated content with no canonical. A multi-page post serves the same
        // URL with a page number appended, and with nothing pointing at page one
        // those are duplicates competing with each other.
        if ($post['paginated'] && $post['canonical'] === '' && !$noindex_paginated) {
            $warnings[] = nibwp_surerank_finding(
                $id,
                $name,
                'missing_canonical_on_paginated',
                'warning',
                __('This post is split across pages, nothing sets a canonical URL, and paginated pages are not set to noindex — so every page competes with page one.', 'nibwp'),
                __('Either set noindex_paginated_pages site-wide, or give this post a canonical URL pointing at its first page.', 'nibwp')
            );
        }

        // No social image anywhere in the chain.
        if ($post['facebook_image_url'] === '' && $post['twitter_image_url'] === '' && $post['thumbnail'] === 0 && $fallback_image === '') {
            $warnings[] = nibwp_surerank_finding(
                $id,
                $name,
                'no_social_image',
                'warning',
                __('No social image, no featured image, and no site-wide fallback — shared on social this page renders as a bare link.', 'nibwp'),
                __('Set a featured image, or a site-wide fallback_image under the social defaults.', 'nibwp')
            );
        }

        if ($post['noindex'] === 'yes') {
            $notices[] = nibwp_surerank_finding(
                $id,
                $name,
                'noindex',
                'notice',
                __('This page is set to noindex, so it is not in search results and not in the sitemap.', 'nibwp'),
                __('Deliberate on a thank-you page; a mistake on anything meant to be found.', 'nibwp')
            );
        }
    }

    return [
        'post_types' => $scan['post_types'],
        'posts_checked' => $scan['posts_checked'],
        'skipped' => $scan['skipped'],
        'skipped_note' => $scan['skipped'] > 0
            ? __('Posts this account cannot edit were not audited.', 'nibwp')
            : '',
        'limit' => $scan['limit'],
        'offset' => $scan['offset'],
        'lengths' => $lengths,
        'verdict' => nibwp_surerank_verdict(count($blockers), count($warnings)),
        'blockers' => $blockers,
        'warnings' => $warnings,
        'notices' => $notices,
    ];
}

/**
 * Titles and descriptions used on more than one page.
 *
 * Only stored values are compared. Two posts both falling back to the same site
 * template are not duplicates of each other — they are untouched, which the main
 * audit already reports as a missing description.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_surerank_audit_duplicates(array $input): array|WP_Error
{
    $scan = nibwp_surerank_audit_scan($input);
    if ($scan instanceof WP_Error) {
        return $scan;
    }

    $titles = [];
    $descriptions = [];

    foreach ($scan['posts'] as $post) {
        $title = nibwp_surerank_normalise($post['title']);
        $description = nibwp_surerank_normalise($post['description']);

        if ($title !== '') {
            $titles[$title][] = ['post_id' => (int) $post['post_id'], 'post_title' => (string) $post['post_title']];
        }
        if ($description !== '') {
            $descriptions[$description][] = ['post_id' => (int) $post['post_id'], 'post_title' => (string) $post['post_title']];
        }
    }

    return [
        'post_types' => $scan['post_types'],
        'posts_checked' => $scan['posts_checked'],
        'skipped' => $scan['skipped'],
        'skipped_note' => $scan['skipped'] > 0
            ? __('Posts this account cannot edit were not compared, so a duplicate shared with one of them will not appear here.', 'nibwp')
            : '',
        'limit' => $scan['limit'],
        'offset' => $scan['offset'],
        'duplicate_titles' => nibwp_surerank_duplicate_groups($titles, 'title'),
        'duplicate_descriptions' => nibwp_surerank_duplicate_groups($descriptions, 'description'),
        'note' => __('Two pages with the same title and description give search engines no way to tell which one answers the query, so it often shows neither.', 'nibwp'),
    ];
}

/**
 * Keep the groups with more than one member, bounded.
 *
 * @param array<string, array<int, array<string, mixed>>> $groups
 * @return array<int, array<string, mixed>>
 */
function nibwp_surerank_duplicate_groups(array $groups, string $field): array
{
    $out = [];

    foreach ($groups as $value => $members) {
        if (count($members) < 2) {
            continue;
        }

        if (count($out) >= NIBWP_SURERANK_MAX_ROWS) {
            break;
        }

        $out[] = [
            'field' => $field,
            'value' => (string) $value,
            'count' => count($members),
            'posts' => array_slice($members, 0, 20),
        ];
    }

    return $out;
}

/** Compare the way a search engine would: case and spacing do not distinguish. */
function nibwp_surerank_normalise(string $text): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

    return function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
}

/**
 * Where the robots settings and the sitemap disagree with each other.
 *
 * SureRank's sitemap query excludes a post whose own post_no_index is 'yes'
 * (inc/sitemap/utils.php:366-377), so a single noindexed post is not advertised.
 * The contradictions that DO survive are structural, and both are here:
 *
 *   - a post type listed in no_index while the sitemap still advertises that
 *     type, so the sitemap invites crawling of pages the robots tag then refuses;
 *   - a post inside a globally noindexed type whose own setting forces indexing.
 *     For such a type SureRank only sitemaps posts whose value is literally 'no'
 *     (inc/sitemap/utils.php:357-365), so these are exactly the pages advertised
 *     against the type's own rule — deliberate sometimes, a leftover often.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_surerank_audit_noindex_in_sitemap(array $input): array|WP_Error
{
    $scan = nibwp_surerank_audit_scan($input);
    if ($scan instanceof WP_Error) {
        return $scan;
    }

    $settings = $scan['settings'];
    $sitemap_on = (bool) ($settings['enable_xml_sitemap'] ?? nibwp_surerank_default('enable_xml_sitemap') ?? true);
    $noindexed = nibwp_surerank_string_list($settings['no_index'] ?? nibwp_surerank_default('no_index') ?? []);
    $excluded = nibwp_surerank_string_list($settings['sitemap_excluded_post_types'] ?? []);

    $findings = [];

    if ($sitemap_on) {
        foreach ($scan['post_types'] as $type) {
            $type = (string) $type;

            if (in_array($type, $noindexed, true) && !in_array($type, $excluded, true)) {
                $findings[] = nibwp_surerank_finding(
                    0,
                    $type,
                    'post_type_noindexed_but_in_sitemap',
                    'blocker',
                    sprintf(
                        /* translators: %s: post type slug */
                        __('The "%s" post type is listed in no_index, so its pages carry a noindex tag, yet the XML sitemap still advertises that type.', 'nibwp'),
                        $type
                    ),
                    __('Either take the type out of no_index, or add it to sitemap_excluded_post_types so the sitemap stops inviting crawls it refuses.', 'nibwp')
                );
            }

            if (!in_array($type, $noindexed, true) && in_array($type, $excluded, true)) {
                $findings[] = nibwp_surerank_finding(
                    0,
                    $type,
                    'post_type_indexable_but_excluded_from_sitemap',
                    'warning',
                    sprintf(
                        /* translators: %s: post type slug */
                        __('The "%s" post type is indexable but excluded from the sitemap, so search engines are never told those pages exist or changed.', 'nibwp'),
                        $type
                    ),
                    __('Take it out of sitemap_excluded_post_types, or add it to no_index if it was never meant to be found.', 'nibwp')
                );
            }
        }

        foreach ($scan['posts'] as $post) {
            if (count($findings) >= NIBWP_SURERANK_MAX_ROWS) {
                break;
            }

            $type = (string) $post['post_type'];

            if ($post['noindex'] === 'no' && in_array($type, $noindexed, true)) {
                $findings[] = nibwp_surerank_finding(
                    (int) $post['post_id'],
                    (string) $post['post_title'],
                    'post_forced_into_sitemap_of_noindexed_type',
                    'warning',
                    sprintf(
                        /* translators: %s: post type slug */
                        __('Its own setting forces indexing while the "%s" post type is listed in no_index, so this is one of the few pages of that type the sitemap does advertise.', 'nibwp'),
                        $type
                    ),
                    __('Deliberate for one landing page kept out of a hidden type; otherwise set it back to default.', 'nibwp')
                );
            }
        }
    }

    return [
        'post_types' => $scan['post_types'],
        'posts_checked' => $scan['posts_checked'],
        'skipped' => $scan['skipped'],
        'limit' => $scan['limit'],
        'offset' => $scan['offset'],
        'sitemap_enabled' => $sitemap_on,
        'noindexed_types' => $noindexed,
        'excluded_from_sitemap' => $excluded,
        'findings' => $findings,
        'note' => $sitemap_on
            ? __('A post set to noindex on its own is already kept out of the sitemap, so it is not reported here. What is reported is where a post type setting and the sitemap contradict each other.', 'nibwp')
            : __('The XML sitemap is switched off, so nothing is advertised and there is nothing to contradict.', 'nibwp'),
    ];
}

/**
 * Everything the audit found, as counts.
 *
 * The first thing to call on a site nobody has looked at: it answers "what is
 * wrong here" in one response without returning several hundred findings.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_surerank_audit_summary(array $input): array|WP_Error
{
    $audit = nibwp_surerank_audit($input);
    if ($audit instanceof WP_Error) {
        return $audit;
    }

    $duplicates = nibwp_surerank_audit_duplicates($input);
    if ($duplicates instanceof WP_Error) {
        return $duplicates;
    }

    $sitemap = nibwp_surerank_audit_noindex_in_sitemap($input);
    if ($sitemap instanceof WP_Error) {
        return $sitemap;
    }

    $by_check = [];
    foreach (array_merge($audit['blockers'], $audit['warnings'], $audit['notices'], $sitemap['findings']) as $finding) {
        $check = (string) $finding['check'];
        $by_check[$check] = ($by_check[$check] ?? 0) + 1;
    }

    $blockers = count($audit['blockers']) + count(array_filter(
        $sitemap['findings'],
        static fn(array $finding): bool => $finding['severity'] === 'blocker'
    ));
    $warnings = count($audit['warnings']) + count($duplicates['duplicate_titles']) + count($duplicates['duplicate_descriptions']);

    return [
        'post_types' => $audit['post_types'],
        'posts_checked' => $audit['posts_checked'],
        'skipped' => $audit['skipped'],
        'skipped_note' => $audit['skipped'] > 0
            ? __('Posts this account cannot edit were not audited, so these counts cover only what it can see.', 'nibwp')
            : '',
        'limit' => $audit['limit'],
        'offset' => $audit['offset'],
        'lengths' => $audit['lengths'],
        'counts' => [
            'blockers' => $blockers,
            'warnings' => $warnings,
            'notices' => count($audit['notices']),
            'duplicate_title_groups' => count($duplicates['duplicate_titles']),
            'duplicate_description_groups' => count($duplicates['duplicate_descriptions']),
            'sitemap_contradictions' => count($sitemap['findings']),
        ],
        'by_check' => $by_check,
        'verdict' => nibwp_surerank_verdict($blockers, $warnings),
        'next' => __('Call audit for the findings themselves, audit_duplicates for the pages sharing copy, and audit_noindex_in_sitemap for the post type contradictions.', 'nibwp'),
    ];
}

/** One line an owner can act on, rather than a number they have to interpret. */
function nibwp_surerank_verdict(int $blockers, int $warnings): string
{
    if ($blockers > 0) {
        return __('Something here is costing traffic now — start with the blockers.', 'nibwp');
    }

    if ($warnings > 0) {
        return __('Nothing is broken, but there is room left on the table.', 'nibwp');
    }

    return __('Nothing to fix in what was checked.', 'nibwp');
}
