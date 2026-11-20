<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * ThirstyAffiliates integration for NIBWP (Pro tier).
 *
 * An affiliate link here is an ordinary post of the plugin's own type,
 * 'thirstylink', with everything that matters stored as post meta under the
 * '_ta_' prefix. Reading and writing that meta directly is how the plugin's own
 * model saves (Affiliate_Link::update_metas()), so a link written from here
 * reads back identically to one typed in wp-admin, and we do not have to stand
 * up the plugin's singleton to touch a value.
 *
 * TRAFFIC SAFETY: a cloaked link is a URL other people have already published.
 * Two fields decide where that traffic ends up — the destination URL and the
 * cloaked slug — and one of them cannot be walked back. The plugin's Link Fixer
 * resolves an already-published href back to a link by post_name, so renaming a
 * slug leaves every existing href pointing at nothing that can be repaired. That
 * is why set_slug is confirm-gated and reports what it changed, while
 * set_destination reports the old value so the change is visible.
 *
 * Verified against ThirstyAffiliates 3.12.0. Notable for this version: there is
 * no "sponsored" flag — nofollow is its own three-state property and anything
 * else you want in rel= is free text in rel_tags. Link health is not part of
 * this plugin either: the link_health_issue properties are declared deprecated
 * and read back empty, so audit_destinations does its own bounded fetch rather
 * than reporting a stored verdict that is always blank.
 *
 * REQUIRES: ThirstyAffiliates active. Click reporting additionally requires its
 * statistics module, which creates the table the counts come from.
 */

/**
 * One of the plugin's class constants, or our own copy of the value.
 *
 * Asking the plugin keeps us honest if a future version renames the post type,
 * and the fallback keeps the file loadable on a site that has never had it.
 */
function nibwp_ta_const(string $name, string $fallback): string
{
    $ref = 'ThirstyAffiliates\\Helpers\\Plugin_Constants::' . $name;

    return defined($ref) ? (string) constant($ref) : $fallback;
}

/** The affiliate link post type. */
function nibwp_ta_cpt(): string
{
    return nibwp_ta_const('AFFILIATE_LINKS_CPT', 'thirstylink');
}

/** The link category taxonomy. */
function nibwp_ta_tax(): string
{
    return nibwp_ta_const('AFFILIATE_LINKS_TAX', 'thirstylink-category');
}

/** The meta key a link property is stored under. */
function nibwp_ta_meta(string $prop): string
{
    return nibwp_ta_const('META_DATA_PREFIX', '_ta_') . $prop;
}

/** One guard for every action: is ThirstyAffiliates even here? */
function nibwp_ta_guard(): ?WP_Error
{
    if (!function_exists('ThirstyAffiliates') && !class_exists('ThirstyAffiliates\\Helpers\\Plugin_Constants')) {
        return new WP_Error(
            'nibwp_ta_missing',
            __('ThirstyAffiliates is not active on this site.', 'nibwp')
        );
    }

    return null;
}

/** A refusal that is about authority rather than about the request. */
function nibwp_ta_forbidden(string $message): WP_Error
{
    return new WP_Error('nibwp_ta_forbidden', $message, ['status' => 403]);
}

/**
 * Resolve an id an agent handed us into an affiliate link it may act on.
 *
 * The post type is checked first and on its own. An id arriving over MCP is a
 * claim, not a fact, and the delete action must not be usable by handing it the
 * id of a page — so "is this really a thirstylink" is answered before the
 * capability question and before anything is read off the row.
 */
function nibwp_ta_link(int $id, string $cap): WP_Post|WP_Error
{
    $post = $id > 0 ? get_post($id) : null;

    if (!$post instanceof WP_Post || $post->post_type !== nibwp_ta_cpt()) {
        return new WP_Error('nibwp_ta_not_a_link', sprintf(
            /* translators: %d: the post ID that was passed. */
            __('%d is not a ThirstyAffiliates affiliate link.', 'nibwp'),
            $id
        ));
    }

    // Per-post, not edit_posts: a link carries an affiliate relationship and a
    // payout, and "may edit something of this type" is not "may edit this one".
    if (!current_user_can($cap, $post->ID)) {
        return nibwp_ta_forbidden(__('You are not allowed to work on this affiliate link.', 'nibwp'));
    }

    return $post;
}

/**
 * A destination URL from input, validated and sanitized for storage.
 *
 * The scheme is checked against the raw input rather than against the escaped
 * result, because esc_url_raw() reduces a javascript: URL to an empty string —
 * which would come back to the caller as "you sent no destination" instead of
 * "that is not allowed". Only http and https: this value is both written into a
 * redirect the whole internet can trigger and fetched by audit_destinations.
 */
function nibwp_ta_url(mixed $value, bool $required = true): string|WP_Error
{
    $raw = trim((string) ($value ?? ''));

    if ($raw === '') {
        return $required
            ? new WP_Error('nibwp_ta_no_destination', __('A destination URL is required.', 'nibwp'))
            : '';
    }

    $scheme = strtolower((string) parse_url($raw, PHP_URL_SCHEME));

    if (!in_array($scheme, ['http', 'https'], true)) {
        return new WP_Error('nibwp_ta_bad_destination', sprintf(
            /* translators: %s: the destination URL that was refused. */
            __('A destination must be an absolute http:// or https:// URL. Refused: %s', 'nibwp'),
            $raw
        ));
    }

    // esc_url_raw is what ThirstyAffiliates stores a destination with itself, so
    // using it here keeps our writes byte-identical to the plugin's own.
    $clean = esc_url_raw($raw);

    if ($clean === '' || parse_url($clean, PHP_URL_HOST) === null) {
        return new WP_Error('nibwp_ta_bad_destination', sprintf(
            /* translators: %s: the destination URL that was refused. */
            __('That destination URL could not be read as an absolute http:// or https:// address. Refused: %s', 'nibwp'),
            $raw
        ));
    }

    return $clean;
}

/**
 * The three-state value the plugin uses for nofollow, new window, query-string
 * passthrough and uncloaking: 'global' defers to the site setting.
 */
function nibwp_ta_flag(string $field, mixed $value): string|WP_Error
{
    $flag = strtolower(trim((string) ($value ?? '')));

    if (!in_array($flag, ['global', 'yes', 'no'], true)) {
        return new WP_Error('nibwp_ta_bad_flag', sprintf(
            /* translators: %s: the name of the flag. */
            __('%s must be one of: global, yes, no.', 'nibwp'),
            $field
        ));
    }

    return $flag;
}

/**
 * Redirect codes this install accepts.
 *
 * The plugin publishes its own list through ta_redirect_types, so a site that
 * added one is not told its own setting is invalid.
 */
function nibwp_ta_redirect_types(): array
{
    $types = apply_filters('ta_redirect_types', ['301' => '301', '302' => '302', '307' => '307']);

    if (!is_array($types) || $types === []) {
        return ['301', '302', '307'];
    }

    return array_map('strval', array_keys($types));
}

/** The prefix a cloaked URL sits under, resolved the way the plugin resolves it. */
function nibwp_ta_link_prefix(): string
{
    $prefix = (string) get_option('ta_link_prefix', 'recommends');

    if ($prefix === 'custom') {
        $prefix = (string) get_option('ta_link_prefix_custom', 'recommends');
    }

    return $prefix !== '' ? $prefix : 'recommends';
}

/**
 * The category slug that appears in a link's URL when that setting is on: the
 * explicitly chosen primary category, else the link's first category.
 */
function nibwp_ta_category_slug(int $id): string
{
    $chosen = (string) get_post_meta($id, nibwp_ta_meta('category_slug'), true);

    if ($chosen !== '') {
        return $chosen;
    }

    $terms = get_the_terms($id, nibwp_ta_tax());

    if (!is_array($terms) || $terms === []) {
        return '';
    }

    $first = reset($terms);

    return is_object($first) ? (string) ($first->slug ?? '') : '';
}

/** The site-relative path a cloaked link answers on. */
function nibwp_ta_cloaked_path(int $id, string $slug): string
{
    $path = nibwp_ta_link_prefix();

    if (get_option('ta_show_cat_in_slug') === 'yes') {
        $category = nibwp_ta_category_slug($id);

        if ($category !== '') {
            $path .= '/' . $category;
        }
    }

    return $path . '/' . $slug;
}

/** Trim a link down to something an agent can read without drowning. */
function nibwp_ta_shape(WP_Post $post, bool $full = false): array
{
    $id = (int) $post->ID;

    $row = [
        'id'              => $id,
        'title'           => (string) $post->post_title,
        'slug'            => (string) $post->post_name,
        'status'          => (string) $post->post_status,
        'cloaked_url'     => get_permalink($id) ?: null,
        'destination_url' => (string) get_post_meta($id, nibwp_ta_meta('destination_url'), true),
        'redirect_type'   => (string) get_post_meta($id, nibwp_ta_meta('redirect_type'), true) ?: 'global',
        'no_follow'       => (string) get_post_meta($id, nibwp_ta_meta('no_follow'), true) ?: 'global',
    ];

    if (!$full) {
        return $row;
    }

    $terms = get_the_terms($id, nibwp_ta_tax());

    return $row + [
        'new_window'       => (string) get_post_meta($id, nibwp_ta_meta('new_window'), true) ?: 'global',
        'pass_query_str'   => (string) get_post_meta($id, nibwp_ta_meta('pass_query_str'), true) ?: 'global',
        'uncloak_link'     => (string) get_post_meta($id, nibwp_ta_meta('uncloak_link'), true) ?: 'global',
        'rel_tags'         => (string) get_post_meta($id, nibwp_ta_meta('rel_tags'), true),
        'css_classes'      => (string) get_post_meta($id, nibwp_ta_meta('css_classes'), true),
        'categories'       => is_array($terms)
            ? array_map(static fn($t) => ['term_id' => (int) $t->term_id, 'name' => (string) $t->name, 'slug' => (string) $t->slug], $terms)
            : [],
        'primary_category' => nibwp_ta_category_slug($id),
        'cloaked_path'     => nibwp_ta_cloaked_path($id, (string) $post->post_name),
        'created'          => (string) $post->post_date,
        'modified'         => (string) $post->post_modified,
    ];
}

/** Shape a link category. */
function nibwp_ta_shape_term(object $term): array
{
    return [
        'term_id'     => (int) ($term->term_id ?? 0),
        'name'        => (string) ($term->name ?? ''),
        'slug'        => (string) ($term->slug ?? ''),
        'parent'      => (int) ($term->parent ?? 0),
        'count'       => (int) ($term->count ?? 0),
        'description' => (string) ($term->description ?? ''),
    ];
}

/** The click table, but only if the statistics module actually created it. */
function nibwp_ta_clicks_table(): ?string
{
    global $wpdb;

    if (!isset($wpdb) || !is_object($wpdb)) {
        return null;
    }

    $table = $wpdb->prefix . 'ta_link_clicks';
    $found = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

    return $found === $table ? $table : null;
}

/**
 * Ask a destination what it answers with.
 *
 * This leaves the site, so it is deliberately small: one bounded request, no
 * redirects followed, and the caller has to be an administrator. Not following
 * is the point — a 301 sitting on an affiliate destination is the finding, and
 * following it would hide the very thing being audited.
 */
function nibwp_ta_probe(string $url, int $timeout): array
{
    $args = [
        'timeout'     => $timeout,
        'redirection' => 0,
        'sslverify'   => true,
        'user-agent'  => 'NibWP affiliate link audit',
    ];

    $response = wp_remote_head($url, $args);
    $code     = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);

    // Plenty of affiliate networks refuse HEAD outright. Retrying once with GET
    // stops the audit reporting a perfectly good link as broken.
    if (is_wp_error($response) || in_array($code, [0, 403, 405, 501], true)) {
        $response = wp_remote_get($url, $args);
        $code     = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
    }

    if (is_wp_error($response)) {
        return [
            'status'  => 0,
            'problem' => 'unreachable',
            'detail'  => $response->get_error_message(),
        ];
    }

    // A header sent more than once comes back as an array; casting that to a
    // string is a warning rather than a value.
    $location = wp_remote_retrieve_header($response, 'location');
    $location = is_array($location) ? (string) reset($location) : (string) $location;

    if ($code >= 300 && $code < 400) {
        return ['status' => $code, 'problem' => 'redirects', 'redirects_to' => $location];
    }

    if ($code >= 400 || $code === 0) {
        return ['status' => $code, 'problem' => 'error'];
    }

    return ['status' => $code, 'problem' => null];
}

/**
 * Actions whose effect the caller has to say out loud first, and why.
 *
 * Deleting is unrecoverable; renaming a slug is worse than it looks, because the
 * plugin's Link Fixer matches an already-published href back to a link by its
 * slug, so a rename cannot be repaired for hrefs that are already out there.
 */
function nibwp_ta_needs_confirm(string $action): ?string
{
    switch ($action) {
        case 'delete_link':
        case 'delete_category':
            return __('This cannot be undone from here. Re-issue the call with confirm set to true if that is intended.', 'nibwp');

        case 'set_slug':
            return __('Renaming the slug changes the cloaked URL, so links already published in posts, emails and social posts stop resolving and cannot be repaired automatically. Re-issue with confirm set to true if that is intended.', 'nibwp');
    }

    return null;
}

wp_register_ability('nibwp/thirstyaffiliates-manage', [
    'label' => __('ThirstyAffiliates – Affiliate Links', 'nibwp'),
    'description' => 'Manage cloaked affiliate links: list, read, create, update and delete them, change a destination URL, cloaked slug, redirect type and nofollow/new-window/query-string flags, run the link categories and the site-wide link settings, find which posts use a link, read click counts, and audit links whose destination now redirects or errors, links with no destination, and cloaked slugs that shadow a real page.',
    'category' => 'marketing',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => [
                    // Links
                    'list_links', 'get_link', 'create_link', 'update_link', 'delete_link',
                    'set_destination', 'set_slug', 'set_redirect_type', 'set_link_flags',
                    // Link categories
                    'list_categories', 'create_category', 'update_category', 'delete_category',
                    'set_link_categories',
                    // Site-wide settings
                    'get_settings', 'update_settings',
                    // Where a link is used
                    'find_link_usage',
                    // Audit
                    'audit_missing_destinations', 'audit_slug_collisions', 'audit_destinations',
                    // Clicks and overview
                    'link_clicks', 'top_clicked_links', 'status',
                ],
                'description' => 'The affiliate link operation to perform.',
            ],

            'link_id' => ['type' => 'integer', 'description' => 'Affiliate link post ID.'],
            'term_id' => ['type' => 'integer', 'description' => 'Link category term ID.'],

            'title'           => ['type' => 'string', 'description' => 'Affiliate link title.'],
            'slug'            => ['type' => 'string', 'description' => 'Cloaked slug — the last segment of the public URL.'],
            'destination_url' => ['type' => 'string', 'description' => 'Where the cloaked link sends people. Must be an absolute http:// or https:// URL.'],
            'status'          => ['type' => 'string', 'description' => 'publish or draft.'],
            'redirect_type'   => ['type' => 'string', 'description' => '301, 302, 307, or global to follow the site default.'],
            'no_follow'       => ['type' => 'string', 'description' => 'global, yes or no.'],
            'new_window'      => ['type' => 'string', 'description' => 'global, yes or no.'],
            'pass_query_str'  => ['type' => 'string', 'description' => 'global, yes or no — pass query strings on to the destination.'],
            'uncloak_link'    => ['type' => 'string', 'description' => 'global, yes or no. Only has an effect while the plugin\'s Uncloak Links module is on.'],
            'rel_tags'        => ['type' => 'string', 'description' => 'Extra rel= values for this link, space separated. There is no separate sponsored flag in this plugin; put sponsored here if you want it.'],
            'css_classes'     => ['type' => 'string', 'description' => 'Extra CSS classes for this link.'],

            'categories'       => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Link category term IDs to assign.'],
            'primary_category' => ['type' => 'integer', 'description' => 'Which assigned category appears in the URL when that setting is on.'],
            'name'             => ['type' => 'string', 'description' => 'Link category name.'],
            'parent'           => ['type' => 'integer', 'description' => 'Parent link category term ID.'],
            'description'      => ['type' => 'string', 'description' => 'Link category description.'],

            'settings' => [
                'type' => 'object',
                'description' => 'Site-wide settings to write. Only the keys you send are touched.',
                'properties' => [
                    'link_prefix'            => ['type' => 'string', 'description' => 'The segment before every cloaked slug, e.g. recommends. Send custom to use link_prefix_custom.'],
                    'link_prefix_custom'     => ['type' => 'string', 'description' => 'The prefix to use when link_prefix is custom.'],
                    'category_in_url'        => ['type' => 'string', 'enum' => ['yes', 'no'], 'description' => 'Put the primary link category in the cloaked URL.'],
                    'default_redirect_type'  => ['type' => 'string', 'description' => 'Redirect code used by every link set to global.'],
                    'no_follow'              => ['type' => 'string', 'enum' => ['yes', 'no', 'category'], 'description' => 'Site default for nofollow.'],
                    'new_window'             => ['type' => 'string', 'enum' => ['yes', 'no', 'category'], 'description' => 'Site default for opening in a new tab.'],
                    'pass_query_str'         => ['type' => 'string', 'enum' => ['yes', 'no', 'category'], 'description' => 'Site default for passing query strings through.'],
                    'additional_rel_tags'    => ['type' => 'string', 'description' => 'Extra rel= values added to every inserted link.'],
                    'additional_css_classes' => ['type' => 'string', 'description' => 'Extra CSS classes added to every inserted link.'],
                ],
                'additionalProperties' => false,
            ],

            'search'   => ['type' => 'string', 'description' => 'Free-text filter on link titles.'],
            'category' => ['type' => 'string', 'description' => 'Link category slug to filter a list by.'],
            'rescan'   => ['type' => 'boolean', 'default' => false, 'description' => 'find_link_usage: re-scan the site now instead of reading the last scan.'],
            'days'     => ['type' => 'integer', 'default' => 30, 'description' => 'Window for link_clicks and top_clicked_links.'],
            'timeout'  => ['type' => 'integer', 'default' => 5, 'description' => 'audit_destinations: seconds to wait per destination, 1 to 15.'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page'     => ['type' => 'integer', 'default' => 1],

            'force' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'delete_link: bypass the trash and delete permanently.',
            ],
            'confirm' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Required for delete_link, delete_category, set_slug, and for any settings or category change that moves existing cloaked URLs.',
            ],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'nibwp_thirstyaffiliates_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage cloaked affiliate links.',
                '',
                'A CLOAKED LINK IS A PUBLISHED URL. Other people have it in posts, emails',
                'and social posts.',
                '- set_slug changes that URL. Every href already out there stops resolving,',
                '  and the plugin cannot repair them, so it needs confirm=true.',
                '- set_destination keeps the URL but changes where it lands. It reports the',
                '  old destination; quote that back before moving on.',
                '- update_settings can change the link prefix or turn the category into part',
                '  of the URL. Both move every cloaked URL on the site at once, so those keys',
                '  need confirm=true too.',
                '',
                'DESTINATIONS are http:// or https:// only. Anything else is refused outright,',
                'including a URL with no scheme.',
                '',
                'audit_destinations LEAVES THIS SITE — it requests each destination to see what',
                'it answers with. It never follows the redirect it finds, and it needs',
                'manage_options. Keep per_page small.',
                '',
                'FLAGS are three-state, not boolean: global, yes or no. "global" means follow',
                'the site setting. There is no sponsored flag in this plugin; rel_tags is the',
                'free-text field for that.',
                '',
                'Clicks come from the statistics module. With that module off the table does',
                'not exist and link_clicks says so rather than reporting zero.',
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
function nibwp_thirstyaffiliates_manage(array $input): array|WP_Error
{
    if ($guard = nibwp_ta_guard()) {
        return $guard;
    }

    $action   = (string) ($input['action'] ?? '');
    $per_page = max(1, min(100, (int) ($input['per_page'] ?? 20)));
    $page     = max(1, (int) ($input['page'] ?? 1));

    $needs = nibwp_ta_needs_confirm($action);

    if ($needs !== null && !(bool) ($input['confirm'] ?? false)) {
        return new WP_Error('nibwp_ta_unconfirmed', $needs);
    }

    try {
        return nibwp_ta_dispatch($action, $input, $per_page, $page);
    } catch (\Throwable $e) {
        // The plugin's own model reaches for its singleton and throws from deep
        // inside it when the bootstrap has not run. A stack trace helps nobody;
        // swallowing the reason helps less.
        return new WP_Error('nibwp_ta_failed', sprintf(
            /* translators: 1: action name, 2: error message. */
            __('ThirstyAffiliates could not complete %1$s: %2$s', 'nibwp'),
            $action,
            $e->getMessage()
        ));
    }
}

/**
 * @param array<string, mixed> $in
 * @return array<string, mixed>|WP_Error
 */
function nibwp_ta_dispatch(string $action, array $in, int $per_page, int $page): array|WP_Error
{
    $offset = ($page - 1) * $per_page;

    switch ($action) {
        case 'list_links':
        case 'get_link':
        case 'create_link':
        case 'update_link':
        case 'delete_link':
            return nibwp_ta_links($action, $in, $per_page, $offset);

        case 'set_destination':
        case 'set_slug':
        case 'set_redirect_type':
        case 'set_link_flags':
        case 'set_link_categories':
            return nibwp_ta_link_writes($action, $in);

        case 'list_categories':
        case 'create_category':
        case 'update_category':
        case 'delete_category':
            return nibwp_ta_categories($action, $in);

        case 'get_settings':
        case 'update_settings':
            return nibwp_ta_settings($action, $in);

        case 'find_link_usage':
            return nibwp_ta_usage($in, $per_page);

        case 'audit_missing_destinations':
        case 'audit_slug_collisions':
        case 'audit_destinations':
            return nibwp_ta_audit($action, $in, $per_page, $offset);

        case 'link_clicks':
        case 'top_clicked_links':
            return nibwp_ta_clicks($action, $in, $per_page);

        case 'status':
            if (!current_user_can('manage_options')) {
                return nibwp_ta_forbidden(__('Reading the site-wide ThirstyAffiliates state needs manage_options.', 'nibwp'));
            }

            $counts = wp_count_posts(nibwp_ta_cpt());
            $terms  = get_terms(['taxonomy' => nibwp_ta_tax(), 'hide_empty' => false, 'fields' => 'ids']);

            return [
                'version'      => nibwp_ta_const('VERSION', ''),
                'post_type'    => nibwp_ta_cpt(),
                'taxonomy'     => nibwp_ta_tax(),
                'meta_prefix'  => nibwp_ta_const('META_DATA_PREFIX', '_ta_'),
                'link_prefix'  => nibwp_ta_link_prefix(),
                'used_prefixes' => (array) maybe_unserialize(get_option('ta_used_link_prefixes', [])),
                'counts'       => [
                    'published'  => (int) ($counts->publish ?? 0),
                    'drafts'     => (int) ($counts->draft ?? 0),
                    'trashed'    => (int) ($counts->trash ?? 0),
                    'categories' => is_array($terms) ? count($terms) : 0,
                ],
                'modules' => [
                    'statistics'       => (string) get_option('ta_enable_stats_reporting_module', 'yes'),
                    'link_fixer'       => (string) get_option('ta_enable_link_fixer', 'yes'),
                    'uncloak_per_link' => (string) get_option('ta_uncloak_link_per_link', 'no'),
                ],
                'clicks_table' => nibwp_ta_clicks_table(),
            ];
    }

    return new WP_Error('nibwp_ta_unknown_action', sprintf(
        /* translators: %s: the action that was requested. */
        __('Unknown ThirstyAffiliates action: %s', 'nibwp'),
        $action
    ));
}

/**
 * List, read, create, update and delete affiliate links.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>|WP_Error
 */
function nibwp_ta_links(string $action, array $in, int $per_page, int $offset): array|WP_Error
{
    if ($action === 'list_links') {
        $args = [
            'post_type'      => nibwp_ta_cpt(),
            'post_status'    => isset($in['status']) ? (string) $in['status'] : 'any',
            'posts_per_page' => $per_page,
            'offset'         => $offset,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        if (!empty($in['search'])) {
            $args['s'] = (string) $in['search'];
        }
        if (!empty($in['category'])) {
            $args['tax_query'] = [[
                'taxonomy' => nibwp_ta_tax(),
                'field'    => 'slug',
                'terms'    => (string) $in['category'],
            ]];
        }

        // A list is still object-level: an editor who cannot open a given link
        // does not get its destination URL out of a listing either.
        $links = [];
        foreach (get_posts($args) as $post) {
            if ($post instanceof WP_Post && current_user_can('edit_post', $post->ID)) {
                $links[] = nibwp_ta_shape($post, false);
            }
        }

        $counts = wp_count_posts(nibwp_ta_cpt());

        return [
            'links'     => $links,
            'published' => (int) ($counts->publish ?? 0),
            'page'      => (int) floor($offset / $per_page) + 1,
        ];
    }

    if ($action === 'create_link') {
        $title = trim((string) ($in['title'] ?? ''));

        if ($title === '') {
            return new WP_Error('nibwp_ta_no_title', __('An affiliate link needs a title.', 'nibwp'));
        }

        $destination = nibwp_ta_url($in['destination_url'] ?? '', true);

        if ($destination instanceof WP_Error) {
            return $destination;
        }

        // No object exists yet, so the question is create-level authority on the
        // type. Read it off the registered type rather than assuming a cap name.
        $type = get_post_type_object(nibwp_ta_cpt());
        $cap  = is_object($type) && isset($type->cap->create_posts)
            ? (string) $type->cap->create_posts
            : 'edit_posts';

        if (!current_user_can($cap)) {
            return nibwp_ta_forbidden(__('You are not allowed to create affiliate links.', 'nibwp'));
        }

        $post = [
            'post_type'   => nibwp_ta_cpt(),
            'post_title'  => $title,
            'post_status' => (string) ($in['status'] ?? 'publish'),
        ];

        if (!empty($in['slug'])) {
            $post['post_name'] = sanitize_title((string) $in['slug']);
        }

        $id = wp_insert_post($post, true);

        if (is_wp_error($id)) {
            return $id;
        }

        $id = (int) $id;
        update_post_meta($id, nibwp_ta_meta('destination_url'), $destination);

        $written = nibwp_ta_write_props($id, $in);

        if ($written instanceof WP_Error) {
            return $written;
        }

        if (isset($in['categories'])) {
            $assigned = nibwp_ta_assign_categories($id, $in);

            if ($assigned instanceof WP_Error) {
                return $assigned;
            }
        }

        $fresh = get_post($id);

        return [
            'created' => true,
            'link'    => $fresh instanceof WP_Post ? nibwp_ta_shape($fresh, true) : ['id' => $id],
        ];
    }

    $cap  = $action === 'delete_link' ? 'delete_post' : 'edit_post';
    $post = nibwp_ta_link((int) ($in['link_id'] ?? 0), $cap);

    if ($post instanceof WP_Error) {
        return $post;
    }

    if ($action === 'get_link') {
        return ['link' => nibwp_ta_shape($post, true)];
    }

    if ($action === 'delete_link') {
        $force = (bool) ($in['force'] ?? false);
        $was   = nibwp_ta_shape($post, true);
        $gone  = $force ? wp_delete_post($post->ID, true) : wp_trash_post($post->ID);

        if (!$gone) {
            return new WP_Error('nibwp_ta_delete_failed', __('That affiliate link could not be deleted.', 'nibwp'), ['status' => 500]);
        }

        return [
            'deleted'   => true,
            'permanent' => $force,
            'was'       => $was,
            'note'      => $force
                ? __('Gone for good. The cloaked URL now 404s wherever it was published.', 'nibwp')
                : __('Moved to the trash. The cloaked URL stops working straight away; restoring the post brings it back.', 'nibwp'),
        ];
    }

    // update_link — the fields that do not move traffic. The slug and the
    // destination have their own actions so the warning lives with the action
    // that carries the risk instead of being buried in a generic update.
    foreach (['slug' => 'set_slug', 'destination_url' => 'set_destination'] as $field => $dedicated) {
        if (isset($in[$field])) {
            return new WP_Error('nibwp_ta_wrong_action', sprintf(
                /* translators: 1: field name, 2: the action to use instead. */
                __('update_link does not change %1$s, because that changes where existing traffic goes. Use %2$s.', 'nibwp'),
                $field,
                $dedicated
            ));
        }
    }

    $patch   = ['ID' => $post->ID];
    $changed = [];

    foreach (['title' => 'post_title', 'status' => 'post_status'] as $key => $column) {
        if (isset($in[$key])) {
            $patch[$column] = (string) $in[$key];
            $changed[$key]  = ['from' => (string) $post->{$column}, 'to' => (string) $in[$key]];
        }
    }

    $text = [];
    foreach (['rel_tags', 'css_classes'] as $field) {
        if (isset($in[$field])) {
            $text[$field] = sanitize_text_field((string) $in[$field]);
            $changed[$field] = [
                'from' => (string) get_post_meta($post->ID, nibwp_ta_meta($field), true),
                'to'   => $text[$field],
            ];
        }
    }

    if ($changed === []) {
        return new WP_Error('nibwp_ta_nothing', __('Nothing to update — send title, status, rel_tags or css_classes.', 'nibwp'));
    }

    if (count($patch) > 1) {
        $updated = wp_update_post($patch, true);

        if (is_wp_error($updated)) {
            return $updated;
        }
    }

    foreach ($text as $field => $value) {
        update_post_meta($post->ID, nibwp_ta_meta($field), $value);
    }

    $fresh = get_post($post->ID);

    return [
        'updated' => true,
        'changed' => $changed,
        'link'    => $fresh instanceof WP_Post ? nibwp_ta_shape($fresh, true) : nibwp_ta_shape($post, true),
    ];
}

/**
 * The link properties that are plain meta writes, shared by create and the
 * flag setters.
 *
 * A property left out is not written at all: the plugin's model falls back to
 * 'global' when the meta is empty, so an absent key and a stored 'global' mean
 * exactly the same thing to it.
 *
 * @param array<string, mixed> $in
 * @return array<string, string>|WP_Error
 */
function nibwp_ta_write_props(int $id, array $in): array|WP_Error
{
    $written = [];

    if (isset($in['redirect_type'])) {
        $type    = (string) $in['redirect_type'];
        $allowed = array_merge(['global'], nibwp_ta_redirect_types());

        if (!in_array($type, $allowed, true)) {
            return new WP_Error('nibwp_ta_bad_redirect', sprintf(
                /* translators: %s: comma-separated list of allowed redirect types. */
                __('Redirect type must be one of: %s', 'nibwp'),
                implode(', ', $allowed)
            ));
        }

        update_post_meta($id, nibwp_ta_meta('redirect_type'), $type);
        $written['redirect_type'] = $type;
    }

    foreach (['no_follow', 'new_window', 'pass_query_str', 'uncloak_link'] as $field) {
        if (!isset($in[$field])) {
            continue;
        }

        $flag = nibwp_ta_flag($field, $in[$field]);

        if ($flag instanceof WP_Error) {
            return $flag;
        }

        update_post_meta($id, nibwp_ta_meta($field), $flag);
        $written[$field] = $flag;
    }

    foreach (['rel_tags', 'css_classes'] as $field) {
        if (isset($in[$field])) {
            $value = sanitize_text_field((string) $in[$field]);
            update_post_meta($id, nibwp_ta_meta($field), $value);
            $written[$field] = $value;
        }
    }

    return $written;
}

/**
 * Assign categories to a link and, optionally, pick which one shows in the URL.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>|WP_Error
 */
function nibwp_ta_assign_categories(int $id, array $in): array|WP_Error
{
    $terms = array_values(array_filter(array_map('intval', (array) ($in['categories'] ?? []))));

    $result = wp_set_post_terms($id, $terms, nibwp_ta_tax());

    if (is_wp_error($result)) {
        return $result;
    }

    $primary = (int) ($in['primary_category'] ?? 0);

    if ($primary > 0) {
        if ($terms !== [] && !in_array($primary, $terms, true)) {
            return new WP_Error(
                'nibwp_ta_primary_not_assigned',
                __('The primary category has to be one of the categories assigned to the link.', 'nibwp')
            );
        }

        $term = get_term($primary, nibwp_ta_tax());

        if (!is_object($term) || is_wp_error($term)) {
            return new WP_Error('nibwp_ta_no_category', __('No link category with that term ID.', 'nibwp'));
        }

        // Both keys, because the plugin stores the id for its picker and the
        // slug for the URL, and a mismatch shows as the wrong URL on the front.
        update_post_meta($id, nibwp_ta_meta('category_slug_id'), $primary);
        update_post_meta($id, nibwp_ta_meta('category_slug'), (string) $term->slug);
    }

    return [
        'categories' => $terms,
        'primary'    => $primary > 0 ? $primary : null,
    ];
}

/**
 * The writes that change where a link sends people, or how.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>|WP_Error
 */
function nibwp_ta_link_writes(string $action, array $in): array|WP_Error
{
    $post = nibwp_ta_link((int) ($in['link_id'] ?? 0), 'edit_post');

    if ($post instanceof WP_Error) {
        return $post;
    }

    $id = (int) $post->ID;

    if ($action === 'set_destination') {
        $destination = nibwp_ta_url($in['destination_url'] ?? '', true);

        if ($destination instanceof WP_Error) {
            return $destination;
        }

        $was = (string) get_post_meta($id, nibwp_ta_meta('destination_url'), true);

        if ($was === $destination) {
            return ['updated' => false, 'destination_url' => $destination, 'note' => __('Already pointing there; nothing changed.', 'nibwp')];
        }

        update_post_meta($id, nibwp_ta_meta('destination_url'), $destination);

        return [
            'updated' => true,
            'link_id' => $id,
            'was'     => $was,
            'now'     => $destination,
            'note'    => __('The cloaked URL is unchanged, so every link already published now sends people somewhere else. Say the old and the new destination out loud.', 'nibwp'),
        ];
    }

    if ($action === 'set_slug') {
        $slug = sanitize_title((string) ($in['slug'] ?? ''));

        if ($slug === '') {
            return new WP_Error('nibwp_ta_no_slug', __('set_slug needs a slug.', 'nibwp'));
        }

        $was = (string) $post->post_name;

        if ($was === $slug) {
            return ['updated' => false, 'slug' => $slug, 'note' => __('Already that slug; nothing changed.', 'nibwp')];
        }

        $updated = wp_update_post(['ID' => $id, 'post_name' => $slug], true);

        if (is_wp_error($updated)) {
            return $updated;
        }

        $fresh = get_post($id);
        $now   = $fresh instanceof WP_Post ? (string) $fresh->post_name : $slug;

        return [
            'updated'  => true,
            'link_id'  => $id,
            'was'      => $was,
            'now'      => $now,
            'was_path' => nibwp_ta_cloaked_path($id, $was),
            'now_path' => nibwp_ta_cloaked_path($id, $now),
            'note'     => __('The cloaked URL has moved. Anything published with the old URL now 404s, and the plugin matches published links back by slug so it cannot repair them. Run find_link_usage and fix those posts.', 'nibwp'),
        ];
    }

    if ($action === 'set_link_categories') {
        $assigned = nibwp_ta_assign_categories($id, $in);

        if ($assigned instanceof WP_Error) {
            return $assigned;
        }

        $fresh = get_post($id);
        $out   = ['updated' => true] + $assigned;

        if (get_option('ta_show_cat_in_slug') === 'yes') {
            $out['note'] = __('The category is part of the cloaked URL on this site, so changing it moves the URL for this link.', 'nibwp');
            $out['path'] = nibwp_ta_cloaked_path($id, $fresh instanceof WP_Post ? (string) $fresh->post_name : (string) $post->post_name);
        }

        return $out;
    }

    // set_redirect_type and set_link_flags are both plain property writes.
    $before  = nibwp_ta_shape($post, true);
    $written = nibwp_ta_write_props($id, $in);

    if ($written instanceof WP_Error) {
        return $written;
    }

    if ($written === []) {
        return new WP_Error('nibwp_ta_nothing', $action === 'set_redirect_type'
            ? __('Send redirect_type: 301, 302, 307 or global.', 'nibwp')
            : __('Send at least one of no_follow, new_window, pass_query_str, uncloak_link, rel_tags, css_classes.', 'nibwp'));
    }

    $out = ['updated' => true, 'link_id' => $id, 'changed' => $written];

    // Worth saying rather than leaving them to wonder why nothing happened: the
    // per-link uncloak value is ignored until the module is switched on.
    if (isset($written['uncloak_link']) && get_option('ta_uncloak_link_per_link') !== 'yes') {
        $out['note'] = __('Stored, but the plugin\'s Uncloak Links module is off, so this value has no effect yet.', 'nibwp');
    }

    $out['was'] = array_intersect_key($before, $written);

    return $out;
}

/**
 * The link categories.
 *
 * The taxonomy registers manage_terms as 'read' so the plugin can control its
 * own menu visibility, which means the taxonomy's own capability is no gate at
 * all. Writes here take manage_options instead.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>|WP_Error
 */
function nibwp_ta_categories(string $action, array $in): array|WP_Error
{
    $tax = nibwp_ta_tax();

    if ($action === 'list_categories') {
        $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => false]);

        if (is_wp_error($terms)) {
            return $terms;
        }

        return [
            'categories' => array_map('nibwp_ta_shape_term', array_filter((array) $terms, 'is_object')),
        ];
    }

    if (!current_user_can('manage_options')) {
        return nibwp_ta_forbidden(__('Changing link categories needs manage_options.', 'nibwp'));
    }

    if ($action === 'create_category') {
        $name = trim((string) ($in['name'] ?? ''));

        if ($name === '') {
            return new WP_Error('nibwp_ta_no_name', __('A link category needs a name.', 'nibwp'));
        }

        $args = [];
        if (!empty($in['slug'])) {
            $args['slug'] = sanitize_title((string) $in['slug']);
        }
        if (!empty($in['parent'])) {
            $args['parent'] = (int) $in['parent'];
        }
        if (isset($in['description'])) {
            $args['description'] = sanitize_text_field((string) $in['description']);
        }

        $created = wp_insert_term($name, $tax, $args);

        if (is_wp_error($created)) {
            return $created;
        }

        $term = get_term((int) ($created['term_id'] ?? 0), $tax);

        return [
            'created'  => true,
            'category' => is_object($term) && !is_wp_error($term) ? nibwp_ta_shape_term($term) : ['term_id' => (int) ($created['term_id'] ?? 0)],
        ];
    }

    $term_id = (int) ($in['term_id'] ?? 0);
    $term    = $term_id > 0 ? get_term($term_id, $tax) : null;

    if (!is_object($term) || is_wp_error($term)) {
        return new WP_Error('nibwp_ta_no_category', __('No link category with that term ID.', 'nibwp'));
    }

    if ($action === 'delete_category') {
        $was     = nibwp_ta_shape_term($term);
        $deleted = wp_delete_term($term_id, $tax);

        if (is_wp_error($deleted)) {
            return $deleted;
        }

        return [
            'deleted' => true,
            'was'     => $was,
            'note'    => get_option('ta_show_cat_in_slug') === 'yes'
                ? __('The category was part of the cloaked URL on this site, so every link that used it has moved. Check those links.', 'nibwp')
                : __('Links that used it are now uncategorised.', 'nibwp'),
        ];
    }

    // update_category
    $patch   = [];
    $changed = [];

    foreach (['name' => 'name', 'description' => 'description'] as $key => $field) {
        if (isset($in[$key])) {
            $patch[$field]  = sanitize_text_field((string) $in[$key]);
            $changed[$key]  = ['from' => (string) ($term->{$field} ?? ''), 'to' => $patch[$field]];
        }
    }
    if (isset($in['parent'])) {
        $patch['parent']   = (int) $in['parent'];
        $changed['parent'] = ['from' => (int) ($term->parent ?? 0), 'to' => $patch['parent']];
    }
    if (isset($in['slug'])) {
        $slug = sanitize_title((string) $in['slug']);

        // The slug is a URL segment whenever the site puts the category in the
        // link path, so renaming it moves every cloaked URL in that category.
        if ($slug !== (string) $term->slug
            && get_option('ta_show_cat_in_slug') === 'yes'
            && !(bool) ($in['confirm'] ?? false)) {
            return new WP_Error(
                'nibwp_ta_unconfirmed',
                __('This site puts the link category in the cloaked URL, so renaming this slug moves the URL of every link in it and those published links stop resolving. Re-issue with confirm set to true if that is intended.', 'nibwp')
            );
        }

        $patch['slug']   = $slug;
        $changed['slug'] = ['from' => (string) $term->slug, 'to' => $slug];
    }

    if ($changed === []) {
        return new WP_Error('nibwp_ta_nothing', __('Nothing to update — send name, slug, parent or description.', 'nibwp'));
    }

    $updated = wp_update_term($term_id, $tax, $patch);

    if (is_wp_error($updated)) {
        return $updated;
    }

    $fresh = get_term($term_id, $tax);

    return [
        'updated'  => true,
        'changed'  => $changed,
        'category' => is_object($fresh) && !is_wp_error($fresh) ? nibwp_ta_shape_term($fresh) : nibwp_ta_shape_term($term),
    ];
}

/**
 * The site-wide settings.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>|WP_Error
 */
function nibwp_ta_settings(string $action, array $in): array|WP_Error
{
    if (!current_user_can('manage_options')) {
        return nibwp_ta_forbidden(__('The site-wide ThirstyAffiliates settings need manage_options.', 'nibwp'));
    }

    $map = [
        'link_prefix'            => 'ta_link_prefix',
        'link_prefix_custom'     => 'ta_link_prefix_custom',
        'category_in_url'        => 'ta_show_cat_in_slug',
        'default_redirect_type'  => 'ta_link_redirect_type',
        'no_follow'              => 'ta_no_follow',
        'new_window'             => 'ta_new_window',
        'pass_query_str'         => 'ta_pass_query_str',
        'additional_rel_tags'    => 'ta_additional_rel_tags',
        'additional_css_classes' => 'ta_additional_css_classes',
    ];

    if ($action === 'get_settings') {
        $settings = [];
        foreach ($map as $key => $option) {
            $settings[$key] = get_option($option, '');
        }

        return [
            'settings'          => $settings,
            'effective_prefix'  => nibwp_ta_link_prefix(),
            'example_url'       => home_url('/' . nibwp_ta_link_prefix() . '/your-link-name'),
            'used_prefixes'     => (array) maybe_unserialize(get_option('ta_used_link_prefixes', [])),
            'default_redirect'  => (string) get_option('ta_link_redirect_type', '302'),
            'modules' => [
                'statistics'       => (string) get_option('ta_enable_stats_reporting_module', 'yes'),
                'link_fixer'       => (string) get_option('ta_enable_link_fixer', 'yes'),
                'uncloak_per_link' => (string) get_option('ta_uncloak_link_per_link', 'no'),
            ],
        ];
    }

    $wanted = (array) ($in['settings'] ?? []);

    if ($wanted === []) {
        return new WP_Error('nibwp_ta_nothing', sprintf(
            /* translators: %s: comma-separated list of setting keys. */
            __('Send a settings object with at least one of: %s', 'nibwp'),
            implode(', ', array_keys($map))
        ));
    }

    // These three decide the shape of every cloaked URL on the site at once.
    $moves_urls = array_intersect(['link_prefix', 'link_prefix_custom', 'category_in_url'], array_keys($wanted));

    if ($moves_urls !== [] && !(bool) ($in['confirm'] ?? false)) {
        return new WP_Error('nibwp_ta_unconfirmed', sprintf(
            /* translators: %s: comma-separated list of setting keys. */
            __('Changing %s moves every cloaked URL on this site, and links already published under the old shape break. Re-issue with confirm set to true if that is intended.', 'nibwp'),
            implode(', ', $moves_urls)
        ));
    }

    $changed = [];

    foreach ($wanted as $key => $value) {
        if (!isset($map[$key])) {
            return new WP_Error('nibwp_ta_unknown_setting', sprintf(
                /* translators: %s: the setting key that was sent. */
                __('Unknown setting: %s', 'nibwp'),
                (string) $key
            ));
        }

        $clean = sanitize_text_field((string) $value);

        if ($key === 'category_in_url' && !in_array($clean, ['yes', 'no'], true)) {
            return new WP_Error('nibwp_ta_bad_setting', __('category_in_url must be yes or no.', 'nibwp'));
        }
        if ($key === 'default_redirect_type' && !in_array($clean, nibwp_ta_redirect_types(), true)) {
            return new WP_Error('nibwp_ta_bad_setting', sprintf(
                /* translators: %s: comma-separated list of allowed redirect types. */
                __('default_redirect_type must be one of: %s', 'nibwp'),
                implode(', ', nibwp_ta_redirect_types())
            ));
        }
        if (in_array($key, ['no_follow', 'new_window', 'pass_query_str'], true)
            && !in_array($clean, ['yes', 'no', 'category'], true)) {
            return new WP_Error('nibwp_ta_bad_setting', sprintf(
                /* translators: %s: the setting key. */
                __('%s must be yes, no or category.', 'nibwp'),
                (string) $key
            ));
        }
        if ($key === 'link_prefix_custom' || ($key === 'link_prefix' && $clean !== 'custom')) {
            $clean = sanitize_title($clean);

            if ($clean === '') {
                return new WP_Error('nibwp_ta_bad_setting', __('A link prefix has to be a usable URL segment.', 'nibwp'));
            }
        }

        $was = get_option($map[$key], '');

        // update_option, not a direct write: the plugin hangs its own
        // bookkeeping off pre_update_option_ta_link_prefix — it remembers the
        // old prefixes so Link Fixer can still repair links published under
        // them, and it sets the transient that reflushes the rewrite rules.
        update_option($map[$key], $clean);

        $changed[$key] = ['from' => $was, 'to' => $clean];
    }

    $out = ['updated' => true, 'changed' => $changed];

    if ($moves_urls !== []) {
        $out['effective_prefix'] = nibwp_ta_link_prefix();
        $out['example_url']      = home_url('/' . nibwp_ta_link_prefix() . '/your-link-name');
        $out['note']             = __('Every cloaked URL has moved. The plugin remembers the previous prefix so its Link Fixer can repair hrefs in content it can see; anything outside this site, such as an email already sent, cannot be repaired.', 'nibwp');
    }

    return $out;
}

/**
 * Where a link is actually used.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>|WP_Error
 */
function nibwp_ta_usage(array $in, int $per_page): array|WP_Error
{
    $post = nibwp_ta_link((int) ($in['link_id'] ?? 0), 'edit_post');

    if ($post instanceof WP_Error) {
        return $post;
    }

    $id      = (int) $post->ID;
    $rescan  = (bool) ($in['rescan'] ?? false);
    $source  = 'cached';
    $ids     = array_map('intval', (array) get_post_meta($id, nibwp_ta_meta('inserted_to'), true));
    $scanned = (string) get_post_meta($id, nibwp_ta_meta('scanned_inserted'), true);

    if ($rescan || ($ids === [] && $scanned === '')) {
        // The plugin already knows how to answer this and caches the result in
        // its own meta, so reuse it rather than inventing a second answer that
        // disagrees with what wp-admin shows.
        $model = 'ThirstyAffiliates\\Models\\Affiliate_Link';

        if (class_exists($model) && method_exists($model, 'scan_where_links_inserted')) {
            try {
                $link = new $model($id);
                $ids  = array_map('intval', (array) $link->scan_where_links_inserted());
                $source = 'scanned';
                $scanned = (string) get_post_meta($id, nibwp_ta_meta('scanned_inserted'), true);
            } catch (\Throwable $e) {
                $ids    = [];
                $source = 'unavailable';
            }
        } else {
            $source = 'unavailable';
        }

        if ($source === 'unavailable') {
            // Fall back to a search rather than refusing: approximate is more
            // use than nothing, and the response says which one it is.
            $permalink = (string) get_permalink($id);
            $found     = get_posts([
                'post_type'      => 'any',
                'post_status'    => 'publish',
                's'              => $permalink,
                'posts_per_page' => $per_page,
                'fields'         => 'ids',
            ]);
            $ids    = array_map('intval', (array) $found);
            $source = 'approximate';
        }
    }

    $used = [];
    foreach (array_slice(array_unique($ids), 0, $per_page) as $used_in) {
        $where = get_post($used_in);

        if (!$where instanceof WP_Post) {
            continue;
        }

        $used[] = [
            'id'        => (int) $where->ID,
            'title'     => (string) $where->post_title,
            'post_type' => (string) $where->post_type,
            'status'    => (string) $where->post_status,
            'permalink' => get_permalink($where->ID) ?: null,
            'editable'  => current_user_can('edit_post', $where->ID),
        ];
    }

    return [
        'link_id'      => $id,
        'cloaked_url'  => get_permalink($id) ?: null,
        'used_in'      => $used,
        'total'        => count(array_unique($ids)),
        'source'       => $source,
        'last_scanned' => $scanned !== '' ? $scanned : null,
        'note'         => $source === 'approximate'
            ? __('The plugin\'s own scanner was unavailable, so this is a content search and may miss or over-report. Treat it as a starting point.', 'nibwp')
            : null,
    ];
}

/**
 * The three audits worth having on a set of cloaked links.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>|WP_Error
 */
function nibwp_ta_audit(string $action, array $in, int $per_page, int $offset): array|WP_Error
{
    if ($action === 'audit_missing_destinations') {
        $links = get_posts([
            'post_type'      => nibwp_ta_cpt(),
            'post_status'    => 'any',
            'posts_per_page' => $per_page,
            'offset'         => $offset,
            'meta_query'     => [
                'relation' => 'OR',
                ['key' => nibwp_ta_meta('destination_url'), 'compare' => 'NOT EXISTS'],
                ['key' => nibwp_ta_meta('destination_url'), 'value' => '', 'compare' => '='],
            ],
        ]);

        $broken = [];
        foreach ($links as $post) {
            if (!$post instanceof WP_Post || !current_user_can('edit_post', $post->ID)) {
                continue;
            }

            $broken[] = [
                'id'          => (int) $post->ID,
                'title'       => (string) $post->post_title,
                'status'      => (string) $post->post_status,
                'cloaked_url' => get_permalink($post->ID) ?: null,
            ];
        }

        return [
            'without_destination' => $broken,
            'count'               => count($broken),
            'note'                => __('A link with no destination does not redirect — the plugin 404s it. Every click on one is lost.', 'nibwp'),
        ];
    }

    if ($action === 'audit_slug_collisions') {
        $links = get_posts([
            'post_type'      => nibwp_ta_cpt(),
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'offset'         => $offset,
        ]);

        $collisions = [];
        foreach ($links as $post) {
            if (!$post instanceof WP_Post || !current_user_can('edit_post', $post->ID)) {
                continue;
            }

            $path  = nibwp_ta_cloaked_path((int) $post->ID, (string) $post->post_name);
            $other = get_page_by_path($path, OBJECT, ['page', 'post']);

            if (!$other instanceof WP_Post || $other->post_type === nibwp_ta_cpt()) {
                continue;
            }

            // The plugin registers its rewrite rule at 'top', so the affiliate
            // redirect wins and the real page at that path is unreachable.
            $collisions[] = [
                'link_id'     => (int) $post->ID,
                'link_title'  => (string) $post->post_title,
                'path'        => $path,
                'shadowed'    => [
                    'id'        => (int) $other->ID,
                    'title'     => (string) $other->post_title,
                    'post_type' => (string) $other->post_type,
                ],
            ];
        }

        return [
            'collisions' => $collisions,
            'count'      => count($collisions),
            'note'       => __('The plugin adds its redirect rule at the top of the rewrite list, so where a cloaked path matches a real page the redirect wins and that page cannot be reached. Rename one of the two.', 'nibwp'),
        ];
    }

    // audit_destinations — this one leaves the site.
    if (!current_user_can('manage_options')) {
        return nibwp_ta_forbidden(__('Contacting every destination is a site-wide outbound action and needs manage_options.', 'nibwp'));
    }

    $timeout = max(1, min(15, (int) ($in['timeout'] ?? 5)));
    $batch   = max(1, min(25, $per_page));

    $args = [
        'post_type'      => nibwp_ta_cpt(),
        'post_status'    => 'publish',
        'posts_per_page' => $batch,
        'offset'         => $offset,
    ];

    if (!empty($in['category'])) {
        $args['tax_query'] = [[
            'taxonomy' => nibwp_ta_tax(),
            'field'    => 'slug',
            'terms'    => (string) $in['category'],
        ]];
    }

    $findings = [];
    $healthy  = 0;

    foreach (get_posts($args) as $post) {
        if (!$post instanceof WP_Post || !current_user_can('edit_post', $post->ID)) {
            continue;
        }

        $raw = (string) get_post_meta($post->ID, nibwp_ta_meta('destination_url'), true);

        // The stored value may predate any validation, so it goes through the
        // same gate as an incoming one before we request it.
        $destination = nibwp_ta_url($raw, false);

        if ($destination instanceof WP_Error) {
            $findings[] = [
                'link_id'         => (int) $post->ID,
                'title'           => (string) $post->post_title,
                'destination_url' => $raw,
                'problem'         => 'not_fetchable',
                'detail'          => $destination->get_error_message(),
            ];
            continue;
        }

        if ($destination === '') {
            $findings[] = [
                'link_id'         => (int) $post->ID,
                'title'           => (string) $post->post_title,
                'destination_url' => '',
                'problem'         => 'missing',
            ];
            continue;
        }

        $probe = nibwp_ta_probe($destination, $timeout);

        if ($probe['problem'] === null) {
            $healthy++;
            continue;
        }

        $findings[] = [
            'link_id'         => (int) $post->ID,
            'title'           => (string) $post->post_title,
            'destination_url' => $destination,
        ] + $probe;
    }

    return [
        'findings'     => $findings,
        'healthy'      => $healthy,
        'checked'      => $healthy + count($findings),
        'timeout'      => $timeout,
        'page'         => (int) floor($offset / $batch) + 1,
        'note'         => __('Each destination was requested once, with redirects left unfollowed so a redirect shows up as the finding it is. A single failure can be the network rather than the link — re-check before changing anything.', 'nibwp'),
    ];
}

/**
 * Click counts, from the table the statistics module creates.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>|WP_Error
 */
function nibwp_ta_clicks(string $action, array $in, int $per_page): array|WP_Error
{
    global $wpdb;

    // The id is resolved first, before the table is even looked for: an id from
    // an agent is a claim, and "that is not one of our links" is the answer to
    // give whether or not click statistics happen to exist here.
    $post = null;

    if ($action === 'link_clicks') {
        $post = nibwp_ta_link((int) ($in['link_id'] ?? 0), 'edit_post');

        if ($post instanceof WP_Error) {
            return $post;
        }
    }

    $table = nibwp_ta_clicks_table();

    if ($table === null) {
        return new WP_Error('nibwp_ta_no_stats', __('There are no click statistics on this site — the plugin\'s statistics module creates that table, and it has not run here.', 'nibwp'));
    }

    $days  = max(1, min(365, (int) ($in['days'] ?? 30)));
    $since = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

    if ($post instanceof WP_Post) {
        // $table came from SHOW TABLES against our own prefix, so it is a known
        // identifier rather than input; the values are still prepared.
        $window = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE link_id = %d AND date_clicked >= %s",
            $post->ID,
            $since
        ));
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE link_id = %d",
            $post->ID
        ));

        return [
            'link_id'      => (int) $post->ID,
            'title'        => (string) $post->post_title,
            'window_days'  => $days,
            'clicks'       => $window,
            'clicks_total' => $total,
        ];
    }

    // top_clicked_links
    if (!current_user_can('manage_options')) {
        return nibwp_ta_forbidden(__('Reading the whole site\'s click ranking needs manage_options.', 'nibwp'));
    }

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT link_id, COUNT(*) AS clicks FROM {$table} WHERE date_clicked >= %s GROUP BY link_id ORDER BY clicks DESC LIMIT %d",
        $since,
        $per_page
    ));

    $links = [];
    foreach ((array) $rows as $row) {
        $post = get_post((int) ($row->link_id ?? 0));

        if (!$post instanceof WP_Post || $post->post_type !== nibwp_ta_cpt()) {
            continue;
        }

        $links[] = [
            'link_id'         => (int) $post->ID,
            'title'           => (string) $post->post_title,
            'clicks'          => (int) ($row->clicks ?? 0),
            'destination_url' => (string) get_post_meta($post->ID, nibwp_ta_meta('destination_url'), true),
            'cloaked_url'     => get_permalink($post->ID) ?: null,
        ];
    }

    return ['window_days' => $days, 'links' => $links];
}
