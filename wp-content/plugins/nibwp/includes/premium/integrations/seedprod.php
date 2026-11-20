<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: SeedProd (coming soon, maintenance mode, landing pages).
 *
 * Verified against SeedProd 6.20.10 (coming-soon/coming-soon.php:27, which
 * defines SEEDPROD_VERSION, and :25 which defines SEEDPROD_BUILD as 'lite').
 *
 * THE ONE EXPENSIVE SWITCH. Turning coming soon or maintenance mode on takes the
 * entire public site offline: render-csp-mm.php:49-85 hooks template_redirect and
 * exits on every front-end request, and maintenance mode sends 503 with a
 * Retry-After of a day (render-csp-mm.php:85-88), so search engines stop indexing
 * too. That is one `update_option` away at all times, which is why set_mode says
 * so in plain words, takes manage_options, reports exactly what it changed, and is
 * the only action in this file that can flip a mode. Nothing else does it as a
 * side effect — not assign_mode_page, not create_page, not set_page_status.
 *
 * WHAT THIS BUILD ACTUALLY ENFORCES. The free build stores four mode flags
 * (resources/data-templates/default-settings.php:5-8) but only hooks two of them.
 * Coming soon and maintenance mode are enforced in render-csp-mm.php:49. The
 * login-page and 404-page flags are written and read back by the plugin's own
 * screens (admin/includes/settings-functions.php:48-61) and nothing in this build
 * hooks login_init, the login_url filter, or an is_404 template_redirect — the
 * plugin's own abilities file labels both "(Pro)"
 * (includes/class-seedprod-abilities.php:101, :109). So modes_status reports all
 * four and marks which two are inert here, rather than implying a dark site that
 * is not dark or a custom 404 that never renders.
 *
 * CONTENT IS READ-ONLY, deliberately, for the same reason MetForm's is. A page
 * lives in two columns that have to agree: post_content holds HTML the builder's
 * JavaScript rendered and post-processed (app/lpage.php:663-687 strips Vue
 * helpers, contenteditable attributes and marker spans out of what the browser
 * posted), and post_content_filtered holds the builder's own JSON — a
 * section > rows > cols > blocks tree where every block carries dozens of
 * generated style keys (resources/data-templates/basic-page.php:47-120). PHP
 * cannot produce the HTML half, and SeedProd itself has to go around the post API
 * with direct SQL to write the JSON half at all, because KSES mangles it
 * (includes/class-seedprod-abilities.php:819-828, app/lpage.php:535-547). A write
 * we could only do half of, through a hole the plugin had to cut, on a page that
 * may be the only thing a visitor sees — so no. Layout, blocks, SEO fields,
 * scripts, bypass phrases and role lists all read out of that JSON and none of
 * them are written back. Build in SeedProd's builder; the reads here tell you
 * what is there and what is wrong with it.
 *
 * What IS written: the four mode flags (option seedprod_settings, a flat
 * five-key JSON), which page each mode is bound to (four plain options), and
 * WordPress's own fields on a page — title, status, trash, untrash, delete,
 * duplicate. Those are WordPress's to own, and the builder re-seeds its mirrored
 * copies of title and status from the post every time it loads
 * (resources/views/builder.php:302, :304), so renaming through here does not drift.
 *
 * SECRETS: option seedprod_api_key and the api_key inside seedprod_settings are
 * the licence key; option seedprod_api_token and seedprod_token are service
 * tokens; seedprod_app_settings holds Facebook, Google Places and Yelp keys
 * (app/settings.php:157-159); a page's bypass_phrase is the shared secret that
 * walks past coming soon mode (app/render-csp-mm.php:245). None of those values
 * leave here. They are reported as a name and whether they are set, through an
 * allow-list of fields that may travel — an allow-list because a later SeedProd
 * version adding a key would otherwise default to leaking it.
 *
 * PERSONAL DATA: the subscriber table is real people's email addresses. Counts
 * are preferred; the one action that returns addresses is bounded, takes
 * manage_options, and copies only allow-listed scalar fields.
 */

/** Every action here reads or writes site-wide configuration. One bar. */
const NIBWP_SEEDPROD_CAP = 'manage_options';

/** The ceiling on any listing, whatever was asked for. */
const NIBWP_SEEDPROD_MAX_ROWS = 200;

/** What a listing returns when the caller says nothing. Bounded too. */
const NIBWP_SEEDPROD_DEFAULT_ROWS = 50;

/**
 * Is SeedProd actually loaded?
 *
 * SEEDPROD_VERSION is defined by the main plugin file before anything else
 * (coming-soon.php:27); the post type registration is what every page read here
 * depends on. Between them they tell "not installed" from "installed and broken".
 */
function nibwp_seedprod_guard(): ?WP_Error
{
    if (!defined('SEEDPROD_VERSION') && !function_exists('seedprod_lite_post_type')) {
        return new WP_Error(
            'nibwp_seedprod_missing',
            __('SeedProd is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/** A refusal about authority rather than about the request. */
function nibwp_seedprod_forbidden(string $what): WP_Error
{
    return new WP_Error(
        'nibwp_seedprod_forbidden',
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name */
            __('%1$s needs the %2$s capability, which this account does not have.', 'nibwp'),
            $what,
            NIBWP_SEEDPROD_CAP
        ),
        ['status' => 403]
    );
}

/**
 * The one capability gate, and it runs before anything looks at the input.
 *
 * Before, not after: a refusal that names the page id it could not find, or the
 * mode it did not recognise, has already told an unauthorised caller what exists
 * on this site. So every handler's first statement is this.
 */
function nibwp_seedprod_require(string $what): ?WP_Error
{
    return current_user_can(NIBWP_SEEDPROD_CAP) ? null : nibwp_seedprod_forbidden($what);
}

/** A refusal about the request itself. */
function nibwp_seedprod_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_seedprod_' . $code, $message, ['status' => $status]);
}

/**
 * The four mode flags, where each one's state and page live, and whether this
 * build enforces it.
 *
 * Flags live inside the seedprod_settings JSON; page bindings are four separate
 * options (app/settings.php:37-42).
 *
 * @return array<string, array<string, mixed>>
 */
function nibwp_seedprod_modes(): array
{
    return [
        'coming_soon' => [
            'flag' => 'enable_coming_soon_mode',
            'option' => 'seedprod_coming_soon_page_id',
            'template_type' => 'cs',
            'label' => __('Coming soon mode', 'nibwp'),
            'enforced' => true,
            'takes_site_offline' => true,
        ],
        'maintenance' => [
            'flag' => 'enable_maintenance_mode',
            'option' => 'seedprod_maintenance_mode_page_id',
            'template_type' => 'mm',
            'label' => __('Maintenance mode', 'nibwp'),
            'enforced' => true,
            'takes_site_offline' => true,
        ],
        'login' => [
            'flag' => 'enable_login_mode',
            'option' => 'seedprod_login_page_id',
            'template_type' => 'loginp',
            'label' => __('Custom login page', 'nibwp'),
            'enforced' => false,
            'takes_site_offline' => false,
        ],
        '404' => [
            'flag' => 'enable_404_mode',
            'option' => 'seedprod_404_page_id',
            'template_type' => 'p404',
            'label' => __('Custom 404 page', 'nibwp'),
            'enforced' => false,
            'takes_site_offline' => false,
        ],
    ];
}

/**
 * The seedprod_settings option, decoded.
 *
 * Stored as a JSON string rather than an array (coming-soon.php:85 seeds it from
 * resources/data-templates/default-settings.php), and it carries the licence key
 * in api_key, so callers get this through the readers below and never whole.
 *
 * @return array<string, mixed>
 */
function nibwp_seedprod_settings(): array
{
    $raw = get_option('seedprod_settings');

    if (is_array($raw)) {
        return $raw;
    }

    if (!is_string($raw) || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

/** SeedProd stores these flags as true, "true", 1 and "1" over its lifetime. */
function nibwp_seedprod_flag_on(mixed $value): bool
{
    if (is_string($value)) {
        return $value !== '' && $value !== '0' && $value !== 'false';
    }

    return !empty($value);
}

/** Is a secret set, without saying what it is? */
function nibwp_seedprod_is_set(mixed $value): bool
{
    if (is_string($value)) {
        return trim($value) !== '';
    }

    return !empty($value);
}

/**
 * One page's builder JSON, decoded — read only, and never returned whole.
 *
 * post_content_filtered is the builder's document plus its page settings
 * (app/lpage.php:179). Everything this file reports about a page comes out of
 * here through a named allow-list.
 *
 * @return array<string, mixed>
 */
function nibwp_seedprod_page_settings(int $id): array
{
    $raw = get_post_field('post_content_filtered', $id);

    if (!is_string($raw) || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * Turn a caller's page id into a page we are willing to touch.
 *
 * An id from a caller is a claim, not a fact. The post type check comes first and
 * alone: current_user_can('edit_post', $id) on somebody else's private post of a
 * different type answers a question nobody asked, and answering it leaks whether
 * that id exists. Only once the row is established as SeedProd's does the
 * per-object capability run.
 *
 * The hub capability has already been checked by the handler before this is
 * called — this is the second, narrower gate, not the first.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_seedprod_resolve_page(mixed $raw, string $object_cap, string $what): array|WP_Error
{
    $id = absint($raw);

    if ($id < 1) {
        return nibwp_seedprod_err('bad_page_id', __('page_id must be a positive integer.', 'nibwp'), 422);
    }

    $post = get_post($id);

    if (!is_object($post) || empty($post->ID)) {
        return nibwp_seedprod_err('page_not_found', __('No post exists with that id.', 'nibwp'), 404);
    }

    $type = isset($post->post_type) ? (string) $post->post_type : '';

    // SeedProd's own pages are either its private 'seedprod' post type (app/cpt.php:29,
    // used for coming soon, maintenance, 404 and login pages) or an ordinary
    // 'page' marked with _seedprod_page (app/lpage.php:181).
    $is_seedprod = $type === 'seedprod'
        || ($type === 'page' && nibwp_seedprod_is_set(get_post_meta($id, '_seedprod_page', true)))
        || ($type === 'page' && nibwp_seedprod_is_set(get_post_meta($id, '_seedprod_page_uuid', true)));

    if (!$is_seedprod) {
        return nibwp_seedprod_err(
            'not_seedprod_page',
            sprintf(
                /* translators: 1: post id, 2: post type */
                __('Post %1$d is a "%2$s" that SeedProd did not build, so nothing here will touch it.', 'nibwp'),
                $id,
                $type === '' ? 'unknown' : $type
            ),
            422
        );
    }

    if ($object_cap !== '' && !current_user_can($object_cap, $id)) {
        return new WP_Error(
            'nibwp_seedprod_page_forbidden',
            sprintf(
                /* translators: 1: what was being attempted, 2: capability name, 3: post id */
                __('%1$s needs the %2$s capability on page %3$d, which this account does not have.', 'nibwp'),
                $what,
                $object_cap,
                $id
            ),
            ['status' => 403]
        );
    }

    return [
        'id' => $id,
        'post' => $post,
        'post_type' => $type,
        'template_type' => (string) get_post_meta($id, '_seedprod_page_template_type', true),
    ];
}

/** Which mode, if any, this page is bound to. */
function nibwp_seedprod_bound_mode(int $id): string
{
    foreach (nibwp_seedprod_modes() as $mode => $spec) {
        if (absint(get_option($spec['option'])) === $id) {
            return $mode;
        }
    }

    return '';
}

/** A bounded row count. The default is bounded as well as the request. */
function nibwp_seedprod_limit(array $input): int
{
    $limit = isset($input['limit']) ? absint($input['limit']) : NIBWP_SEEDPROD_DEFAULT_ROWS;

    if ($limit < 1) {
        $limit = NIBWP_SEEDPROD_DEFAULT_ROWS;
    }

    return min($limit, NIBWP_SEEDPROD_MAX_ROWS);
}

/** Where a listing starts. */
function nibwp_seedprod_offset(array $input): int
{
    return isset($input['offset']) ? absint($input['offset']) : 0;
}

/**
 * Subscriber columns that may leave this site, and nothing else.
 *
 * The table carries exactly these (admin/includes/subscriber-functions.php:63-86).
 * An allow-list rather than a denylist: a column a later version adds would
 * otherwise travel by default, and the table is personal data.
 *
 * @return array<int, string>
 */
function nibwp_seedprod_subscriber_fields(): array
{
    return ['id', 'email', 'fname', 'lname', 'created', 'page_id', 'page_uuid'];
}

/**
 * Page-settings keys whose VALUE may be reported, grouped by the action that
 * reports them. Everything else in the builder JSON stays here.
 *
 * @return array<string, array<int, string>>
 */
function nibwp_seedprod_public_page_settings(): array
{
    return [
        'seo' => ['seo_title', 'seo_description', 'no_index', 'favicon', 'social_thumbnail', 'show_powered_by_link'],
        'access' => [
            'access_by_role',
            'bypass_expires',
            'bypass_cookie',
            'include_exclude_type',
            'include_list',
            'exclude_list',
            'exclude_list_404',
            'disable_default_excluded_urls',
            'redirect_mode',
            'redirect_url',
        ],
        'analytics' => ['enable_recaptcha', 'no_conflict_mode'],
    ];
}

/**
 * Page-settings keys that are reported as set-or-not and never by value.
 *
 * bypass_phrase is the phrase that walks a stranger past coming soon mode
 * (app/render-csp-mm.php:245); access_by_ip is a visitor's address;
 * facebook_app_id is a third-party key; the four script fields are whatever the
 * owner pasted in, which is routinely an analytics or conversion key.
 *
 * @return array<int, string>
 */
function nibwp_seedprod_withheld_page_settings(): array
{
    return [
        'bypass_phrase',
        'access_by_ip',
        'facebook_app_id',
        'header_scripts',
        'body_scripts',
        'footer_scripts',
        'conversion_scripts',
        'email_integration_id',
        'domain_mapping',
    ];
}

/**
 * Report a key as a name and whether it is set.
 *
 * @param array<string, mixed> $source
 * @param array<int, string> $keys
 * @return array<int, array<string, mixed>>
 */
function nibwp_seedprod_describe_withheld(array $source, array $keys): array
{
    $out = [];

    foreach ($keys as $key) {
        $out[] = [
            'name' => $key,
            'is_set' => array_key_exists($key, $source) && nibwp_seedprod_is_set($source[$key]),
            'value_returned' => false,
        ];
    }

    return $out;
}

/**
 * Copy allow-listed keys, and drop anything that is not a scalar.
 *
 * Dropped rather than cast: json_encode of an unexpected array under a key a
 * caller believes is a string is how a nested structure leaks past a field
 * allow-list, and serialising it would be worse again.
 *
 * @param array<string, mixed> $source
 * @param array<int, string> $keys
 * @return array<string, mixed>
 */
function nibwp_seedprod_pick_scalars(array $source, array $keys): array
{
    $out = [];

    foreach ($keys as $key) {
        if (!array_key_exists($key, $source)) {
            continue;
        }

        $value = $source[$key];

        if (is_scalar($value) || $value === null) {
            $out[$key] = $value;
        }
    }

    return $out;
}

/** The subscriber table, which SeedProd has carried under this name since v3. */
function nibwp_seedprod_subscriber_table(): string
{
    global $wpdb;

    return $wpdb->prefix . 'csp3_subscribers';
}

/** Does the subscriber table exist? Lite installs that never captured one lack it. */
function nibwp_seedprod_has_subscriber_table(): bool
{
    global $wpdb;

    $table = nibwp_seedprod_subscriber_table();
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

    return is_string($found) && $found !== '';
}

/**
 * The actions this hub offers.
 *
 * @return array<int, string>
 */
function nibwp_seedprod_actions(): array
{
    return [
        // Reads.
        'summary',
        'modes_status',
        'list_pages',
        'get_page',
        'list_templates',
        'access_settings',
        'seo_settings',
        'analytics_settings',
        'app_settings',
        'subscriber_stats',
        'list_subscribers',
        'audit',
        // Writes.
        'set_mode',
        'assign_mode_page',
        'create_page',
        'rename_page',
        'duplicate_page',
        'set_page_status',
        'trash_page',
        'restore_page',
        'delete_page_permanently',
    ];
}

wp_register_ability('nibwp/seedprod-manage', [
    'label' => __('SeedProd – Coming Soon, Maintenance Mode & Landing Pages', 'nibwp'),
    'description' => 'Read and manage SeedProd: whether coming soon or maintenance mode is on and which page each uses, '
        . 'the landing pages SeedProd built, their SEO, access and bypass settings, the email subscribers captured, '
        . 'and an audit of the misconfigurations that cost the most. '
        . 'Turning coming soon or maintenance mode on takes the entire public site offline for visitors and search engines; set_mode is the only action that can do it, it needs manage_options, and it reports exactly what changed. '
        . 'Page content is read-only: the layout lives in SeedProd\'s own builder format across two columns that must agree, and only the builder can write it. '
        . 'No licence key, service token, third-party API key or coming-soon bypass phrase is ever returned.',
    'category' => 'utilities',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_seedprod_actions(),
                'description' => 'Which operation to run. Start with summary.',
            ],
            'mode' => [
                'type' => 'string',
                'description' => 'For set_mode / assign_mode_page: coming_soon, maintenance, login or 404.',
            ],
            'enabled' => [
                'type' => 'boolean',
                'description' => 'For set_mode: true turns the mode on, false turns it off. Required — there is no default.',
            ],
            'page_id' => [
                'type' => 'integer',
                'description' => 'The SeedProd page to act on, or to bind to a mode.',
            ],
            'title' => [
                'type' => 'string',
                'description' => 'For create_page / rename_page / duplicate_page: the page title.',
            ],
            'type' => [
                'type' => 'string',
                'description' => 'For create_page: lp (landing page), cs, mm, p404 or loginp.',
            ],
            'slug' => [
                'type' => 'string',
                'description' => 'For create_page: the URL slug. Landing pages only; the special pages have fixed slugs.',
            ],
            'status' => [
                'type' => 'string',
                'description' => 'For set_page_status: publish, draft, pending or private.',
            ],
            'confirm' => [
                'type' => 'boolean',
                'description' => 'For delete_page_permanently: must be true. There is no undo.',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Rows to return. Default 50, hard maximum 200.',
            ],
            'offset' => [
                'type' => 'integer',
                'description' => 'Where a listing starts.',
            ],
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
    'execute_callback' => 'nibwp_seedprod_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'SeedProd: coming soon mode, maintenance mode and landing pages.',
                '',
                'START WITH summary, then audit. Between them they say whether the public',
                'site is currently dark, which page each mode uses, and what is misconfigured.',
                '',
                'THE SITE-OFFLINE SWITCH:',
                'set_mode with mode=coming_soon or mode=maintenance and enabled=true takes the',
                'WHOLE PUBLIC SITE offline. Every visitor who is not logged in sees the',
                'assigned page instead of the site, and maintenance mode answers 503, so search',
                'engines stop indexing. Never turn one on because a task merely implied it; say',
                'out loud what will happen and let the person decide. No other action in this',
                'hub flips a mode, and none does it as a side effect.',
                'The two are mutually exclusive: turn the other one off first rather than',
                'expecting this to do it for you.',
                '',
                'WHAT THIS BUILD ENFORCES:',
                'Coming soon and maintenance mode are live. The login-page and 404-page flags',
                'are stored and reported but this build hooks neither, so switching them on',
                'changes a stored value and nothing a visitor can see. modes_status marks them.',
                '',
                'CONTENT IS READ-ONLY, and that is the design:',
                'A page body lives in SeedProd\'s builder format spread over two columns that',
                'have to agree — rendered HTML the builder\'s JavaScript produced, and a deep',
                'JSON block tree the plugin can only write by going around the post API. There',
                'is no action that writes page content, SEO fields, scripts, bypass phrases or',
                'role lists, and there will not be one. Build in SeedProd\'s builder. What you',
                'CAN change here is WordPress\'s own side of a page: title, status, trash,',
                'untrash, delete, duplicate — plus the mode flags and their page bindings.',
                '',
                'IRREVERSIBLE: delete_page_permanently deletes the page and its revisions with',
                'no undo and needs confirm=true. trash_page is the reversible one.',
                '',
                'SECRETS: the licence key, SeedProd service tokens, the Facebook, Google Places',
                'and Yelp keys, and a page\'s coming-soon bypass phrase are reported as a name',
                'and whether they are set. The values never travel, and asking differently will',
                'not change that.',
                '',
                'PERSONAL DATA: list_subscribers returns real people\'s email addresses. Prefer',
                'subscriber_stats, which is counts. Both need manage_options, and the listing is',
                'bounded whether or not a limit is given.',
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
function nibwp_seedprod_manage(array $input): array|WP_Error
{
    $guard = nibwp_seedprod_guard();
    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = isset($input['action']) ? (string) $input['action'] : '';

    if (!in_array($action, nibwp_seedprod_actions(), true)) {
        return nibwp_seedprod_err(
            'unknown_action',
            sprintf(
                /* translators: 1: action name, 2: list of valid actions */
                __('Unknown SeedProd action "%1$s". Valid actions: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_seedprod_actions())
            )
        );
    }

    // SeedProd's own helpers reach its API, its template files and the posts
    // table, and they move between versions. One catch here turns any of that
    // into a named refusal instead of a fatal that takes the REST request down.
    try {
        return nibwp_seedprod_dispatch($action, $input);
    } catch (\Throwable $error) {
        return nibwp_seedprod_err(
            'plugin_error',
            sprintf(
                /* translators: 1: action name, 2: error message from SeedProd */
                __('SeedProd could not complete %1$s: %2$s', 'nibwp'),
                $action,
                $error->getMessage()
            ),
            500
        );
    }
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_seedprod_dispatch(string $action, array $input): array|WP_Error
{
    $result = match ($action) {
        'summary' => nibwp_seedprod_summary(),
        'modes_status' => nibwp_seedprod_modes_status(),
        'list_pages' => nibwp_seedprod_list_pages($input),
        'get_page' => nibwp_seedprod_get_page($input),
        'list_templates' => nibwp_seedprod_list_templates(),
        'access_settings' => nibwp_seedprod_access_settings($input),
        'seo_settings' => nibwp_seedprod_seo_settings($input),
        'analytics_settings' => nibwp_seedprod_analytics_settings($input),
        'app_settings' => nibwp_seedprod_app_settings(),
        'subscriber_stats' => nibwp_seedprod_subscriber_stats(),
        'list_subscribers' => nibwp_seedprod_list_subscribers($input),
        'audit' => nibwp_seedprod_audit(),
        'set_mode' => nibwp_seedprod_set_mode($input),
        'assign_mode_page' => nibwp_seedprod_assign_mode_page($input),
        'create_page' => nibwp_seedprod_create_page($input),
        'rename_page' => nibwp_seedprod_rename_page($input),
        'duplicate_page' => nibwp_seedprod_duplicate_page($input),
        'set_page_status' => nibwp_seedprod_set_page_status($input),
        'trash_page' => nibwp_seedprod_trash_page($input),
        'restore_page' => nibwp_seedprod_restore_page($input),
        'delete_page_permanently' => nibwp_seedprod_delete_page($input),
        default => nibwp_seedprod_err('unhandled_action', __('Unhandled action.', 'nibwp')),
    };

    return $result instanceof WP_Error ? $result : ['action' => $action] + $result;
}

/* -------------------------------------------------------------------- reads */

/**
 * Is the public site dark right now, and what is this install made of?
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_seedprod_summary(): array|WP_Error
{
    if ($denied = nibwp_seedprod_require(__('Reading the SeedProd summary', 'nibwp'))) {
        return $denied;
    }

    $modes = nibwp_seedprod_modes_status();

    if ($modes instanceof WP_Error) {
        return $modes;
    }

    $offline = [];

    foreach (nibwp_seedprod_modes() as $mode => $spec) {
        if ($spec['takes_site_offline'] && !empty($modes['modes'][$mode]['enabled'])) {
            $offline[] = $mode;
        }
    }

    return [
        'version' => defined('SEEDPROD_VERSION') ? SEEDPROD_VERSION : '',
        'build' => defined('SEEDPROD_BUILD') ? SEEDPROD_BUILD : '',
        'public_site_offline' => $offline !== [],
        'offline_because' => $offline,
        'modes' => $modes['modes'],
        'page_counts' => nibwp_seedprod_page_counts(),
        'subscribers' => nibwp_seedprod_subscriber_counts(),
        'content_writes' => 'read-only',
        'note' => __('Page content is read-only here. Coming soon and maintenance mode are the two modes this build enforces; set_mode is the only action that can switch one.', 'nibwp'),
    ];
}

/**
 * Which modes are on, which page each uses, and whether this build enforces it.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_seedprod_modes_status(): array|WP_Error
{
    if ($denied = nibwp_seedprod_require(__('Reading SeedProd mode status', 'nibwp'))) {
        return $denied;
    }

    $settings = nibwp_seedprod_settings();
    $out = [];

    foreach (nibwp_seedprod_modes() as $mode => $spec) {
        $page_id = absint(get_option($spec['option']));
        $page = $page_id > 0 ? get_post($page_id) : null;

        $out[$mode] = [
            'label' => $spec['label'],
            'enabled' => nibwp_seedprod_flag_on($settings[$spec['flag']] ?? false),
            'stored_in' => 'option seedprod_settings -> ' . $spec['flag'],
            'page_option' => $spec['option'],
            'page_id' => $page_id,
            'page_exists' => is_object($page) && !empty($page->ID),
            'page_title' => is_object($page) && isset($page->post_title) ? (string) $page->post_title : '',
            'page_status' => is_object($page) && isset($page->post_status) ? (string) $page->post_status : '',
            'enforced_by_this_build' => $spec['enforced'],
            'takes_site_offline' => $spec['takes_site_offline'],
        ];

        if (!$spec['enforced']) {
            $out[$mode]['note'] = __('This build stores the flag but hooks nothing for it, so switching it on changes a stored value and nothing a visitor can see.', 'nibwp');
        }
    }

    return [
        'modes' => $out,
        'mutually_exclusive' => ['coming_soon', 'maintenance'],
    ];
}

/**
 * Counts by kind and status, so a caller can tell what it is looking at before
 * it asks for rows.
 *
 * @return array<string, int>
 */
function nibwp_seedprod_page_counts(): array
{
    $counts = [
        'landing_pages' => 0,
        'special_pages' => 0,
        'published' => 0,
        'draft' => 0,
        'trashed' => 0,
    ];

    foreach (nibwp_seedprod_query_pages(['any'], NIBWP_SEEDPROD_MAX_ROWS, 0) as $post) {
        $type = isset($post->post_type) ? (string) $post->post_type : '';
        $status = isset($post->post_status) ? (string) $post->post_status : '';

        if ($type === 'seedprod') {
            ++$counts['special_pages'];
        } else {
            ++$counts['landing_pages'];
        }

        if ($status === 'publish') {
            ++$counts['published'];
        } elseif ($status === 'trash') {
            ++$counts['trashed'];
        } elseif ($status === 'draft') {
            ++$counts['draft'];
        }
    }

    return $counts;
}

/**
 * Every page SeedProd built, bounded.
 *
 * @param array<int, string> $statuses
 * @return array<int, object>
 */
function nibwp_seedprod_query_pages(array $statuses, int $limit, int $offset): array
{
    $posts = get_posts([
        'post_type' => ['page', 'seedprod'],
        'post_status' => $statuses,
        'posts_per_page' => $limit,
        'offset' => $offset,
        'orderby' => 'modified',
        'order' => 'DESC',
        'no_found_rows' => true,
        'ignore_sticky_posts' => true,
        'suppress_filters' => false,
        'meta_query' => [
            [
                'key' => '_seedprod_page',
                'compare' => 'EXISTS',
            ],
        ],
    ]);

    return is_array($posts) ? $posts : [];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_seedprod_list_pages(array $input): array|WP_Error
{
    if ($denied = nibwp_seedprod_require(__('Listing SeedProd pages', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_seedprod_limit($input);
    $offset = nibwp_seedprod_offset($input);
    $rows = [];

    foreach (nibwp_seedprod_query_pages(['any'], $limit, $offset) as $post) {
        $id = absint($post->ID ?? 0);

        if ($id < 1) {
            continue;
        }

        $content = isset($post->post_content) ? (string) $post->post_content : '';

        $rows[] = [
            'id' => $id,
            'title' => isset($post->post_title) ? (string) $post->post_title : '',
            'slug' => isset($post->post_name) ? (string) $post->post_name : '',
            'status' => isset($post->post_status) ? (string) $post->post_status : '',
            'post_type' => isset($post->post_type) ? (string) $post->post_type : '',
            'template_type' => (string) get_post_meta($id, '_seedprod_page_template_type', true),
            'bound_to_mode' => nibwp_seedprod_bound_mode($id),
            'has_rendered_content' => trim($content) !== '',
            'modified' => isset($post->post_modified_gmt) ? (string) $post->post_modified_gmt : '',
        ];
    }

    return [
        'pages' => $rows,
        'returned' => count($rows),
        'limit' => $limit,
        'offset' => $offset,
        'max_limit' => NIBWP_SEEDPROD_MAX_ROWS,
        'note' => __('Pages are matched on the _seedprod_page marker SeedProd writes when it creates one. A page converted from an older version without that marker will not appear.', 'nibwp'),
    ];
}

/**
 * One page's metadata. Not its content.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_seedprod_get_page(array $input): array|WP_Error
{
    if ($denied = nibwp_seedprod_require(__('Reading a SeedProd page', 'nibwp'))) {
        return $denied;
    }

    $page = nibwp_seedprod_resolve_page($input['page_id'] ?? null, '', '');

    if ($page instanceof WP_Error) {
        return $page;
    }

    $id = $page['id'];
    $post = $page['post'];
    $settings = nibwp_seedprod_page_settings($id);
    $content = isset($post->post_content) ? (string) $post->post_content : '';

    $sections = [];
    if (isset($settings['document']['sections']) && is_array($settings['document']['sections'])) {
        $sections = $settings['document']['sections'];
    }

    return [
        'id' => $id,
        'title' => isset($post->post_title) ? (string) $post->post_title : '',
        'slug' => isset($post->post_name) ? (string) $post->post_name : '',
        'status' => isset($post->post_status) ? (string) $post->post_status : '',
        'post_type' => $page['post_type'],
        'template_type' => $page['template_type'],
        'uuid_is_set' => nibwp_seedprod_is_set(get_post_meta($id, '_seedprod_page_uuid', true)),
        'bound_to_mode' => nibwp_seedprod_bound_mode($id),
        'modified' => isset($post->post_modified_gmt) ? (string) $post->post_modified_gmt : '',
        'rendered_content_bytes' => strlen($content),
        'has_rendered_content' => trim($content) !== '',
        'builder_sections' => count($sections),
        'has_builder_json' => $settings !== [],
        'content_writes' => 'read-only',
        'content_note' => __('The body lives in SeedProd\'s builder format across post_content and post_content_filtered, which only the builder can write consistently. Nothing here writes it.', 'nibwp'),
    ];
}

/**
 * What a page can be started from.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_seedprod_list_templates(): array|WP_Error
{
    if ($denied = nibwp_seedprod_require(__('Reading SeedProd template availability', 'nibwp'))) {
        return $denied;
    }

    // The template gallery is not on this site. SeedProd fetches it from its own
    // service on every request, authenticated with the site and API tokens
    // (admin/includes/template-functions.php:90-130, :252-291), and the template
    // body that comes back is builder JSON we would not write anyway. So the
    // honest answer is: one local starting point, and a pointer at the gallery.
    return [
        'local' => [
            [
                'id' => 'blank',
                'label' => __('Blank page, SeedProd\'s own defaults', 'nibwp'),
                'source' => 'resources/data-templates/basic-page.php',
                'usable_by' => 'create_page',
            ],
        ],
        'gallery' => [
            'location' => 'remote',
            'reachable_from_here' => false,
            'api_token_is_set' => nibwp_seedprod_is_set(get_option('seedprod_api_token', '')),
            'site_token_is_set' => nibwp_seedprod_is_set(get_option('seedprod_token', '')),
            'free_templates_subscribed' => nibwp_seedprod_is_set(get_option('seedprod_free_templates_subscribed', '')),
        ],
        'note' => __('SeedProd\'s template gallery lives on its own service and is fetched per request; this hub does not proxy it, and would not write the builder JSON a template returns. create_page makes an empty page with SeedProd\'s own defaults, which you then design in its builder.', 'nibwp'),
    ];
}

/**
 * Who gets past a mode page, and how.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_seedprod_access_settings(array $input): array|WP_Error
{
    if ($denied = nibwp_seedprod_require(__('Reading SeedProd access settings', 'nibwp'))) {
        return $denied;
    }

    $page = nibwp_seedprod_resolve_page($input['page_id'] ?? null, '', '');

    if ($page instanceof WP_Error) {
        return $page;
    }

    $settings = nibwp_seedprod_page_settings($page['id']);
    $allowed = nibwp_seedprod_public_page_settings()['access'];
    $values = nibwp_seedprod_pick_scalars($settings, $allowed);

    // access_by_role is the one list a caller genuinely needs, and it is role
    // names rather than anything private — so it travels, with non-scalar members
    // dropped rather than cast.
    $roles = [];
    $stored_roles = $settings['access_by_role'] ?? [];

    foreach (is_array($stored_roles) ? $stored_roles : [$stored_roles] as $role) {
        if (is_scalar($role) && (string) $role !== '') {
            $roles[] = (string) $role;
        }
    }
    unset($values['access_by_role']);

    return [
        'page_id' => $page['id'],
        'bound_to_mode' => nibwp_seedprod_bound_mode($page['id']),
        'settings' => $values,
        'access_by_role' => $roles,
        'logged_in_users_always_pass' => $roles === [],
        'withheld' => nibwp_seedprod_describe_withheld($settings, ['bypass_phrase', 'access_by_ip', 'domain_mapping']),
        'writes' => 'read-only',
        'note' => __('With no roles listed, any logged-in user sees the site and everyone else sees the mode page (app/render-csp-mm.php:245-258). The bypass phrase is a shared secret and is never returned.', 'nibwp'),
    ];
}

/**
 * What search engines would be told about this page.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_seedprod_seo_settings(array $input): array|WP_Error
{
    if ($denied = nibwp_seedprod_require(__('Reading SeedProd SEO settings', 'nibwp'))) {
        return $denied;
    }

    $page = nibwp_seedprod_resolve_page($input['page_id'] ?? null, '', '');

    if ($page instanceof WP_Error) {
        return $page;
    }

    $settings = nibwp_seedprod_page_settings($page['id']);

    return [
        'page_id' => $page['id'],
        'settings' => nibwp_seedprod_pick_scalars($settings, nibwp_seedprod_public_page_settings()['seo']),
        'writes' => 'read-only',
        'note' => __('These live inside the builder JSON and are changed in SeedProd\'s builder.', 'nibwp'),
    ];
}

/**
 * Whether anything is injected into the page, without reporting what.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_seedprod_analytics_settings(array $input): array|WP_Error
{
    if ($denied = nibwp_seedprod_require(__('Reading SeedProd analytics settings', 'nibwp'))) {
        return $denied;
    }

    $page = nibwp_seedprod_resolve_page($input['page_id'] ?? null, '', '');

    if ($page instanceof WP_Error) {
        return $page;
    }

    $settings = nibwp_seedprod_page_settings($page['id']);

    $withheld_keys = [
        'header_scripts',
        'body_scripts',
        'footer_scripts',
        'conversion_scripts',
        'facebook_app_id',
        'email_integration_id',
    ];

    return [
        'page_id' => $page['id'],
        'settings' => nibwp_seedprod_pick_scalars($settings, nibwp_seedprod_public_page_settings()['analytics']),
        'withheld' => nibwp_seedprod_describe_withheld($settings, $withheld_keys),
        'writes' => 'read-only',
        'note' => __('The script fields routinely hold analytics, pixel and conversion keys, so they are reported as set or not and never by value.', 'nibwp'),
    ];
}

/**
 * The plugin's own integration keys, as names only.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_seedprod_app_settings(): array|WP_Error
{
    if ($denied = nibwp_seedprod_require(__('Reading SeedProd application settings', 'nibwp'))) {
        return $denied;
    }

    $raw = get_option('seedprod_app_settings');
    $app = is_string($raw) ? json_decode($raw, true) : $raw;

    if (!is_array($app)) {
        $app = [];
    }

    $settings = nibwp_seedprod_settings();

    // Everything in here that is a key stays in here. The three app keys are
    // third-party credentials (app/settings.php:157-159); api_key and the two
    // tokens are the licence and the service tokens.
    $withheld = nibwp_seedprod_describe_withheld($app, ['facebook_g_app_id', 'google_places_app_key', 'yelp_app_api_key']);
    $withheld = array_merge($withheld, nibwp_seedprod_describe_withheld($settings, ['api_key']));
    $withheld = array_merge($withheld, nibwp_seedprod_describe_withheld([
        'seedprod_api_key' => get_option('seedprod_api_key', ''),
        'seedprod_api_token' => get_option('seedprod_api_token', ''),
        'seedprod_token' => get_option('seedprod_token', ''),
    ], ['seedprod_api_key', 'seedprod_api_token', 'seedprod_token']));

    return [
        'settings' => nibwp_seedprod_pick_scalars($app, [
            'disable_seedprod_button',
            'disable_seedprod_notification',
            'enable_usage_tracking',
        ]),
        'usage_tracking_allowed' => nibwp_seedprod_flag_on(get_option('seedprod_allow_usage_tracking', false)),
        'licence_active' => nibwp_seedprod_flag_on(get_option('seedprod_a', false)),
        'licence_name' => (string) get_option('seedprod_license_name', ''),
        'withheld' => $withheld,
        'writes' => 'read-only',
        'note' => __('The licence key, the service tokens and the three third-party keys are reported as a name and whether they are set. The values do not leave this site.', 'nibwp'),
    ];
}

/**
 * How many people have been captured, with nobody named.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_seedprod_subscriber_stats(): array|WP_Error
{
    if ($denied = nibwp_seedprod_require(__('Reading SeedProd subscriber counts', 'nibwp'))) {
        return $denied;
    }

    return nibwp_seedprod_subscriber_counts();
}

/**
 * The counts themselves, for callers that have already cleared the capability.
 *
 * Separate from the action so the action can refuse with a WP_Error like
 * everything else, rather than smuggling a refusal back as data.
 *
 * @return array<string, mixed>
 */
function nibwp_seedprod_subscriber_counts(): array
{
    if (!nibwp_seedprod_has_subscriber_table()) {
        return [
            'table_exists' => false,
            'total' => 0,
            'note' => __('SeedProd has not created its subscriber table on this site, so nothing has been captured.', 'nibwp'),
        ];
    }

    global $wpdb;

    $table = nibwp_seedprod_subscriber_table();

    $total = $wpdb->get_var(
        $wpdb->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE %d = %d', 1, 1)
    );
    $recent = $wpdb->get_var(
        $wpdb->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE created >= DATE_SUB(NOW(), INTERVAL %d DAY)', 7)
    );
    $monthly = $wpdb->get_var(
        $wpdb->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE created >= DATE_SUB(NOW(), INTERVAL %d DAY)', 30)
    );

    return [
        'table_exists' => true,
        'total' => absint($total),
        'last_7_days' => absint($recent),
        'last_30_days' => absint($monthly),
    ];
}

/**
 * Real people's email addresses. Bounded, allow-listed, manage_options.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_seedprod_list_subscribers(array $input): array|WP_Error
{
    if ($denied = nibwp_seedprod_require(__('Listing SeedProd email subscribers', 'nibwp'))) {
        return $denied;
    }

    if (!nibwp_seedprod_has_subscriber_table()) {
        return [
            'table_exists' => false,
            'subscribers' => [],
            'returned' => 0,
            'note' => __('SeedProd has not created its subscriber table on this site.', 'nibwp'),
        ];
    }

    global $wpdb;

    $limit = nibwp_seedprod_limit($input);
    $offset = nibwp_seedprod_offset($input);
    $table = nibwp_seedprod_subscriber_table();
    $fields = nibwp_seedprod_subscriber_fields();

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT * FROM `' . $table . '` ORDER BY created DESC LIMIT %d OFFSET %d',
            $limit,
            $offset
        ),
        ARRAY_A
    );

    $out = [];

    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }

        // Allow-listed, and a non-scalar is dropped rather than cast: a column
        // that is unexpectedly structured must not be flattened into a string
        // that looks like data a caller can trust.
        $out[] = nibwp_seedprod_pick_scalars($row, $fields);
    }

    return [
        'table_exists' => true,
        'subscribers' => $out,
        'returned' => count($out),
        'limit' => $limit,
        'offset' => $offset,
        'max_limit' => NIBWP_SEEDPROD_MAX_ROWS,
        'fields_returned' => $fields,
        'note' => __('These are personal data. Only the fields listed in fields_returned leave this site, and anything that is not a scalar is dropped.', 'nibwp'),
    ];
}

/**
 * The misconfigurations this plugin makes easy and expensive.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_seedprod_audit(): array|WP_Error
{
    if ($denied = nibwp_seedprod_require(__('Auditing SeedProd', 'nibwp'))) {
        return $denied;
    }

    $settings = nibwp_seedprod_settings();
    $findings = [];
    $site_is_dark = false;

    foreach (nibwp_seedprod_modes() as $mode => $spec) {
        $on = nibwp_seedprod_flag_on($settings[$spec['flag']] ?? false);
        $page_id = absint(get_option($spec['option']));
        $page = $page_id > 0 ? get_post($page_id) : null;
        $page_exists = is_object($page) && !empty($page->ID);

        // The single most expensive state this plugin allows. Everything else in
        // this audit is a detail next to a site nobody can see.
        if ($on && $spec['takes_site_offline']) {
            $site_is_dark = true;
            $findings[] = [
                'severity' => 'critical',
                'code' => $mode . '_is_on',
                'message' => sprintf(
                    /* translators: %s: the mode's name */
                    __('%s is switched on, so the entire public site is dark: every visitor who is not logged in sees the mode page instead of the site, and search engines are being turned away. If this was meant to be temporary, it has outlasted its purpose.', 'nibwp'),
                    $spec['label']
                ),
                'mode' => $mode,
                'fix' => 'set_mode with mode=' . $mode . ' and enabled=false',
            ];
        }

        // A mode on with no page is worse than it sounds: SeedProd wp_die()s on
        // every front-end request (app/render-csp-mm.php:187-189).
        if ($on && !$page_exists) {
            $findings[] = [
                'severity' => 'critical',
                'code' => $mode . '_on_without_page',
                'message' => sprintf(
                    /* translators: %s: the mode's name */
                    __('%s is on but no page is assigned to it. SeedProd stops the request with an error rather than showing anything.', 'nibwp'),
                    $spec['label']
                ),
                'mode' => $mode,
                'fix' => 'assign_mode_page, or set_mode with enabled=false',
            ];
        }

        if ($on && $page_exists && trim((string) ($page->post_content ?? '')) === '') {
            $findings[] = [
                'severity' => 'critical',
                'code' => $mode . '_page_is_empty',
                'message' => sprintf(
                    /* translators: 1: the mode's name, 2: page id */
                    __('%1$s is on and its page (%2$d) has no rendered content, so visitors are getting a blank page. Open it in SeedProd\'s builder and save it.', 'nibwp'),
                    $spec['label'],
                    $page_id
                ),
                'mode' => $mode,
                'page_id' => $page_id,
            ];
        }

        if ($on && !$spec['enforced']) {
            $findings[] = [
                'severity' => 'notice',
                'code' => $mode . '_on_but_inert',
                'message' => sprintf(
                    /* translators: %s: the mode's name */
                    __('%s is switched on, but this build hooks nothing for it — the flag is stored and no visitor sees a difference.', 'nibwp'),
                    $spec['label']
                ),
                'mode' => $mode,
            ];
        }
    }

    $published_unreachable = [];
    $published_empty = [];

    foreach (nibwp_seedprod_query_pages(['publish'], NIBWP_SEEDPROD_MAX_ROWS, 0) as $post) {
        $id = absint($post->ID ?? 0);

        if ($id < 1) {
            continue;
        }

        if (trim((string) ($post->post_content ?? '')) === '') {
            $published_empty[] = $id;
        }

        // The 'seedprod' post type is registered public => false (app/cpt.php:20),
        // so one of its pages is reachable only through the mode it is bound to.
        // Published, bound to nothing, is a page nobody can get to.
        if ((string) ($post->post_type ?? '') === 'seedprod' && nibwp_seedprod_bound_mode($id) === '') {
            $published_unreachable[] = $id;
        }
    }

    if ($published_empty !== []) {
        $findings[] = [
            'severity' => 'warning',
            'code' => 'published_pages_without_content',
            'message' => sprintf(
                /* translators: %d: number of pages */
                _n(
                    '%d published SeedProd page has no rendered content, so it serves a blank page.',
                    '%d published SeedProd pages have no rendered content, so they serve blank pages.',
                    count($published_empty),
                    'nibwp'
                ),
                count($published_empty)
            ),
            'page_ids' => $published_empty,
        ];
    }

    if ($published_unreachable !== []) {
        $findings[] = [
            'severity' => 'warning',
            'code' => 'published_but_unreachable',
            'message' => sprintf(
                /* translators: %d: number of pages */
                _n(
                    '%d published page uses SeedProd\'s private post type but is not bound to any mode, so nothing routes to it.',
                    '%d published pages use SeedProd\'s private post type but are not bound to any mode, so nothing routes to them.',
                    count($published_unreachable),
                    'nibwp'
                ),
                count($published_unreachable)
            ),
            'page_ids' => $published_unreachable,
            'fix' => 'assign_mode_page, or set_page_status to draft',
        ];
    }

    if ($site_is_dark) {
        $subscribers = nibwp_seedprod_subscriber_counts();

        if (!empty($subscribers['table_exists']) && absint($subscribers['total'] ?? 0) === 0) {
            $findings[] = [
                'severity' => 'notice',
                'code' => 'dark_site_capturing_nobody',
                'message' => __('The site is dark and not one email address has been captured, so the downtime is buying nothing.', 'nibwp'),
            ];
        }
    }

    return [
        'public_site_offline' => $site_is_dark,
        'findings' => $findings,
        'counts' => [
            'critical' => count(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'critical')),
            'warning' => count(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'warning')),
            'notice' => count(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'notice')),
        ],
        'looks_reasonable' => $findings === [],
        'pages_examined' => NIBWP_SEEDPROD_MAX_ROWS,
    ];
}

/* ------------------------------------------------------------------- writes */

/**
 * Turn one mode on or off.
 *
 * TURNING COMING SOON OR MAINTENANCE MODE ON TAKES THE ENTIRE PUBLIC SITE
 * OFFLINE. Not a section, not a page — every front-end request from anyone who
 * is not logged in, and a 503 to search engines in maintenance mode. This is the
 * only function in this file that changes a mode flag, and nothing calls it.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_seedprod_set_mode(array $input): array|WP_Error
{
    if ($denied = nibwp_seedprod_require(__('Switching a SeedProd mode on or off', 'nibwp'))) {
        return $denied;
    }

    $modes = nibwp_seedprod_modes();
    $mode = isset($input['mode']) ? (string) $input['mode'] : '';

    if (!isset($modes[$mode])) {
        return nibwp_seedprod_err(
            'bad_mode',
            sprintf(
                /* translators: 1: rejected value, 2: list of valid modes */
                __('"%1$s" is not a SeedProd mode. Valid modes: %2$s.', 'nibwp'),
                $mode,
                implode(', ', array_keys($modes))
            ),
            422
        );
    }

    // No default. A caller that forgot to say which way round it wanted the
    // switch does not get the site turned off on a guess.
    if (!array_key_exists('enabled', $input) || !is_bool($input['enabled'])) {
        return nibwp_seedprod_err(
            'missing_enabled',
            __('enabled must be given explicitly as true or false. There is no default, because the wrong guess takes the site offline.', 'nibwp'),
            422
        );
    }

    $spec = $modes[$mode];
    $enabled = (bool) $input['enabled'];
    $settings = nibwp_seedprod_settings();
    $was = nibwp_seedprod_flag_on($settings[$spec['flag']] ?? false);
    $page_id = absint(get_option($spec['option']));
    $page = $page_id > 0 ? get_post($page_id) : null;
    $page_exists = is_object($page) && !empty($page->ID);

    if ($enabled && !$page_exists) {
        return nibwp_seedprod_err(
            'no_page_assigned',
            sprintf(
                /* translators: %s: the mode's name */
                __('%s has no page assigned. Switching it on in that state makes SeedProd stop every front-end request with an error, so assign a page first with assign_mode_page.', 'nibwp'),
                $spec['label']
            ),
            409
        );
    }

    // Coming soon and maintenance are mutually exclusive, and SeedProd's own
    // screens resolve that by silently switching the other one off
    // (admin/includes/settings-functions.php:93-120). We refuse instead: turning
    // a mode off is a decision, and it is not this call's decision to make.
    if ($enabled && $spec['takes_site_offline']) {
        foreach ($modes as $other => $other_spec) {
            if ($other === $mode || !$other_spec['takes_site_offline']) {
                continue;
            }

            if (nibwp_seedprod_flag_on($settings[$other_spec['flag']] ?? false)) {
                return nibwp_seedprod_err(
                    'mode_conflict',
                    sprintf(
                        /* translators: 1: the mode being turned on, 2: the mode already on */
                        __('%1$s cannot be switched on while %2$s is on; they are mutually exclusive. Turn the other one off first, deliberately, with its own call.', 'nibwp'),
                        $spec['label'],
                        $other_spec['label']
                    ),
                    409
                );
            }
        }
    }

    if ($was === $enabled) {
        return [
            'changed' => false,
            'mode' => $mode,
            'enabled' => $enabled,
            'page_id' => $page_id,
            'note' => __('Already in that state. Nothing was written.', 'nibwp'),
        ];
    }

    $settings[$spec['flag']] = $enabled;
    $encoded = wp_json_encode($settings);

    if (!is_string($encoded)) {
        return nibwp_seedprod_err('encode_failed', __('SeedProd\'s settings could not be re-encoded, so nothing was written.', 'nibwp'), 500);
    }

    update_option('seedprod_settings', $encoded);

    // SeedProd keeps the mode page's post_status in step with its flag
    // (app/settings.php:60-69). Matching that is part of switching the mode, not
    // a separate act, and it is reported.
    $status_changed = '';
    $expected = $enabled ? 'publish' : 'draft';

    if ($page_exists && (string) ($page->post_status ?? '') !== $expected && current_user_can('edit_post', $page_id)) {
        wp_update_post([
            'ID' => $page_id,
            'post_status' => $expected,
        ]);
        $status_changed = $expected;
    }

    $effect = $spec['takes_site_offline']
        ? ($enabled
            ? __('The entire public site is now offline. Every visitor who is not logged in sees this page instead of the site, and search engines are being turned away.', 'nibwp')
            : __('The public site is visible again.', 'nibwp'))
        : __('This build hooks nothing for that mode, so the stored flag changed and no visitor sees a difference.', 'nibwp');

    return [
        'changed' => true,
        'mode' => $mode,
        'was' => $was,
        'enabled' => $enabled,
        'page_id' => $page_id,
        'page_status_changed_to' => $status_changed,
        'public_site_offline' => $enabled && $spec['takes_site_offline'],
        'effect' => $effect,
        'note' => __('Say this back to whoever asked, in the same words.', 'nibwp'),
    ];
}

/**
 * Bind a page to a mode. Does not switch the mode.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_seedprod_assign_mode_page(array $input): array|WP_Error
{
    if ($denied = nibwp_seedprod_require(__('Assigning a SeedProd mode page', 'nibwp'))) {
        return $denied;
    }

    $modes = nibwp_seedprod_modes();
    $mode = isset($input['mode']) ? (string) $input['mode'] : '';

    if (!isset($modes[$mode])) {
        return nibwp_seedprod_err(
            'bad_mode',
            sprintf(
                /* translators: 1: rejected value, 2: list of valid modes */
                __('"%1$s" is not a SeedProd mode. Valid modes: %2$s.', 'nibwp'),
                $mode,
                implode(', ', array_keys($modes))
            ),
            422
        );
    }

    $page = nibwp_seedprod_resolve_page($input['page_id'] ?? null, 'edit_post', __('Assigning a SeedProd mode page', 'nibwp'));

    if ($page instanceof WP_Error) {
        return $page;
    }

    $spec = $modes[$mode];
    $previous = absint(get_option($spec['option']));

    update_option($spec['option'], $page['id']);

    $settings = nibwp_seedprod_settings();

    return [
        'mode' => $mode,
        'page_id' => $page['id'],
        'previous_page_id' => $previous,
        'mode_enabled' => nibwp_seedprod_flag_on($settings[$spec['flag']] ?? false),
        'template_type_matches' => $page['template_type'] === '' || $page['template_type'] === $spec['template_type'],
        'note' => __('Only the binding changed. The mode is where it was — switching it is set_mode\'s job and nothing else\'s.', 'nibwp'),
    ];
}

/**
 * Create an empty SeedProd page.
 *
 * It is empty on purpose: the layout is builder JSON we will not author, so what
 * this does is what SeedProd's own "blank" path does — insert the post, seed it
 * with the plugin's bundled default settings, and hand back the builder URL.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_seedprod_create_page(array $input): array|WP_Error
{
    if ($denied = nibwp_seedprod_require(__('Creating a SeedProd page', 'nibwp'))) {
        return $denied;
    }

    $title = isset($input['title']) ? sanitize_text_field((string) $input['title']) : '';

    if ($title === '') {
        return nibwp_seedprod_err('missing_title', __('title is required.', 'nibwp'), 422);
    }

    // The special types carry fixed slugs that SeedProd identifies them by
    // (app/lpage.php:140-159); 'lp' is an ordinary page.
    $types = [
        'lp' => ['post_type' => 'page', 'slug' => ''],
        'cs' => ['post_type' => 'seedprod', 'slug' => 'sp-cs'],
        'mm' => ['post_type' => 'seedprod', 'slug' => 'sp-mm'],
        'p404' => ['post_type' => 'seedprod', 'slug' => 'sp-p404'],
        'loginp' => ['post_type' => 'seedprod', 'slug' => 'sp-login'],
    ];

    $type = isset($input['type']) ? (string) $input['type'] : 'lp';

    if (!isset($types[$type])) {
        return nibwp_seedprod_err(
            'bad_type',
            sprintf(
                /* translators: 1: rejected value, 2: list of valid types */
                __('"%1$s" is not a SeedProd page type. Valid types: %2$s.', 'nibwp'),
                $type,
                implode(', ', array_keys($types))
            ),
            422
        );
    }

    if (!current_user_can('publish_pages')) {
        return nibwp_seedprod_forbidden(__('Creating a SeedProd page', 'nibwp'));
    }

    $slug = $types[$type]['slug'];

    if ($slug === '' && isset($input['slug'])) {
        $slug = sanitize_title((string) $input['slug']);
    }

    $defaults = nibwp_seedprod_default_page_settings($type);
    $post_id = wp_insert_post([
        'post_type' => $types[$type]['post_type'],
        'post_title' => $title,
        'post_name' => $slug,
        'post_status' => 'draft',
        'post_content' => '',
        'comment_status' => 'closed',
        'ping_status' => 'closed',
        'meta_input' => [
            '_seedprod_page' => true,
            '_seedprod_page_uuid' => wp_generate_uuid4(),
            '_seedprod_page_template_type' => $type,
        ],
    ], true);

    if ($post_id instanceof WP_Error) {
        return $post_id;
    }

    $post_id = absint($post_id);

    if ($post_id < 1) {
        return nibwp_seedprod_err('create_failed', __('WordPress did not create the page.', 'nibwp'), 500);
    }

    // The settings JSON goes in with direct SQL, which is how SeedProd itself
    // writes this column: KSES rewrites it on the way through the post API, and
    // the plugin had to cut the same hole to make its own saves survive
    // (app/lpage.php:535-547, includes/class-seedprod-abilities.php:819-828).
    // Safe here only because the row is one we just made and nothing is in it.
    $seeded = false;

    if ($defaults !== '') {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $wpdb->posts . ' SET post_content_filtered = %s WHERE ID = %d',
                $defaults,
                $post_id
            )
        );
        clean_post_cache($post_id);
        $seeded = true;
    }

    return [
        'created' => true,
        'page_id' => $post_id,
        'title' => $title,
        'slug' => $slug,
        'type' => $type,
        'post_type' => $types[$type]['post_type'],
        'status' => 'draft',
        'defaults_seeded' => $seeded,
        'builder_url' => admin_url('admin.php?page=seedprod_lite_builder&id=' . $post_id),
        'note' => __('The page is an empty draft. Its layout is built in SeedProd\'s builder — nothing here writes page content. It is not bound to any mode and no mode was switched.', 'nibwp'),
    ];
}

/**
 * SeedProd's own bundled default page settings, if its file is where it says.
 *
 * Read from the plugin rather than invented, so a new page starts life with the
 * exact shape the builder expects.
 */
function nibwp_seedprod_default_page_settings(string $type): string
{
    if (!defined('SEEDPROD_PLUGIN_PATH')) {
        return '';
    }

    $file = SEEDPROD_PLUGIN_PATH . 'resources/data-templates/basic-page.php';

    if (!is_readable($file)) {
        return '';
    }

    $seedprod_basic_lpage = '';
    require $file;

    if (!is_string($seedprod_basic_lpage) || $seedprod_basic_lpage === '') {
        return '';
    }

    $decoded = json_decode($seedprod_basic_lpage, true);

    if (!is_array($decoded)) {
        return '';
    }

    $decoded['page_type'] = $type;
    $decoded['is_new'] = true;

    if (in_array($type, ['cs', 'mm', 'p404', 'loginp'], true)) {
        $decoded['no_conflict_mode'] = true;
    }

    $encoded = wp_json_encode($decoded);

    return is_string($encoded) ? $encoded : '';
}

/**
 * Change a page's title. WordPress's field, not the builder's.
 *
 * The builder keeps a mirrored copy of the title inside its JSON but re-seeds it
 * from the post every time it loads (resources/views/builder.php:302), so this
 * does not drift and does not need the JSON touched.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_seedprod_rename_page(array $input): array|WP_Error
{
    if ($denied = nibwp_seedprod_require(__('Renaming a SeedProd page', 'nibwp'))) {
        return $denied;
    }

    $page = nibwp_seedprod_resolve_page($input['page_id'] ?? null, 'edit_post', __('Renaming a SeedProd page', 'nibwp'));

    if ($page instanceof WP_Error) {
        return $page;
    }

    $title = isset($input['title']) ? sanitize_text_field((string) $input['title']) : '';

    if ($title === '') {
        return nibwp_seedprod_err('missing_title', __('title is required.', 'nibwp'), 422);
    }

    $was = (string) ($page['post']->post_title ?? '');

    $updated = wp_update_post([
        'ID' => $page['id'],
        'post_title' => $title,
    ], true);

    if ($updated instanceof WP_Error) {
        return $updated;
    }

    return [
        'renamed' => true,
        'page_id' => $page['id'],
        'was' => $was,
        'title' => $title,
        'note' => __('Only the title changed. The slug and the page content are untouched.', 'nibwp'),
    ];
}

/**
 * Copy a page, content and all, as a draft.
 *
 * Both content columns are copied byte for byte with SQL rather than passed
 * through the post API, because the post API's filters rewrite them and the copy
 * would come out subtly different from the page it claims to be.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_seedprod_duplicate_page(array $input): array|WP_Error
{
    if ($denied = nibwp_seedprod_require(__('Duplicating a SeedProd page', 'nibwp'))) {
        return $denied;
    }

    $page = nibwp_seedprod_resolve_page($input['page_id'] ?? null, 'edit_post', __('Duplicating a SeedProd page', 'nibwp'));

    if ($page instanceof WP_Error) {
        return $page;
    }

    if (!current_user_can('publish_pages')) {
        return nibwp_seedprod_forbidden(__('Duplicating a SeedProd page', 'nibwp'));
    }

    $source = $page['post'];
    $title = isset($input['title']) ? sanitize_text_field((string) $input['title']) : '';

    if ($title === '') {
        $title = (string) ($source->post_title ?? '') . ' - Copy';
    }

    $new_id = wp_insert_post([
        'post_type' => $page['post_type'],
        'post_title' => $title,
        'post_name' => '',
        'post_status' => 'draft',
        'post_content' => '',
        'comment_status' => 'closed',
        'ping_status' => 'closed',
        'meta_input' => [
            '_seedprod_page' => true,
            '_seedprod_page_uuid' => wp_generate_uuid4(),
            '_seedprod_page_template_type' => $page['template_type'] !== '' ? $page['template_type'] : 'lp',
        ],
    ], true);

    if ($new_id instanceof WP_Error) {
        return $new_id;
    }

    $new_id = absint($new_id);

    if ($new_id < 1) {
        return nibwp_seedprod_err('duplicate_failed', __('WordPress did not create the copy.', 'nibwp'), 500);
    }

    global $wpdb;

    $wpdb->query(
        $wpdb->prepare(
            'UPDATE ' . $wpdb->posts . ' SET post_content = %s, post_content_filtered = %s WHERE ID = %d',
            (string) ($source->post_content ?? ''),
            (string) ($source->post_content_filtered ?? ''),
            $new_id
        )
    );
    clean_post_cache($new_id);

    return [
        'duplicated' => true,
        'source_page_id' => $page['id'],
        'page_id' => $new_id,
        'title' => $title,
        'status' => 'draft',
        'builder_url' => admin_url('admin.php?page=seedprod_lite_builder&id=' . $new_id),
        'note' => __('The copy is a draft, bound to no mode. Nothing about the original changed.', 'nibwp'),
    ];
}

/**
 * Publish, unpublish or privatise a page.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_seedprod_set_page_status(array $input): array|WP_Error
{
    if ($denied = nibwp_seedprod_require(__('Changing a SeedProd page status', 'nibwp'))) {
        return $denied;
    }

    $page = nibwp_seedprod_resolve_page($input['page_id'] ?? null, 'edit_post', __('Changing a SeedProd page status', 'nibwp'));

    if ($page instanceof WP_Error) {
        return $page;
    }

    $allowed = ['publish', 'draft', 'pending', 'private'];
    $status = isset($input['status']) ? (string) $input['status'] : '';

    if (!in_array($status, $allowed, true)) {
        return nibwp_seedprod_err(
            'bad_status',
            sprintf(
                /* translators: 1: rejected value, 2: list of valid statuses */
                __('"%1$s" is not a status this will set. Valid: %2$s. Use trash_page to trash and restore_page to bring one back.', 'nibwp'),
                $status,
                implode(', ', $allowed)
            ),
            422
        );
    }

    $was = (string) ($page['post']->post_status ?? '');

    if ($was === $status) {
        return [
            'changed' => false,
            'page_id' => $page['id'],
            'status' => $status,
            'note' => __('Already in that status. Nothing was written.', 'nibwp'),
        ];
    }

    $updated = wp_update_post([
        'ID' => $page['id'],
        'post_status' => $status,
    ], true);

    if ($updated instanceof WP_Error) {
        return $updated;
    }

    $bound = nibwp_seedprod_bound_mode($page['id']);

    return [
        'changed' => true,
        'page_id' => $page['id'],
        'was' => $was,
        'status' => $status,
        'bound_to_mode' => $bound,
        'note' => $bound !== ''
            ? __('This page is bound to a mode. Its status does not switch the mode on or off — that is set_mode only.', 'nibwp')
            : __('Only the status changed.', 'nibwp'),
    ];
}

/**
 * Trash a page. Reversible with restore_page.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_seedprod_trash_page(array $input): array|WP_Error
{
    if ($denied = nibwp_seedprod_require(__('Trashing a SeedProd page', 'nibwp'))) {
        return $denied;
    }

    $page = nibwp_seedprod_resolve_page($input['page_id'] ?? null, 'delete_post', __('Trashing a SeedProd page', 'nibwp'));

    if ($page instanceof WP_Error) {
        return $page;
    }

    $bound = nibwp_seedprod_bound_mode($page['id']);

    if ($bound !== '') {
        $settings = nibwp_seedprod_settings();
        $spec = nibwp_seedprod_modes()[$bound];

        if (nibwp_seedprod_flag_on($settings[$spec['flag']] ?? false)) {
            return nibwp_seedprod_err(
                'page_in_use',
                sprintf(
                    /* translators: %s: the mode's name */
                    __('That page is the one %s is currently serving. Trashing it would leave the mode on with nothing to show, which stops every front-end request with an error. Switch the mode off first.', 'nibwp'),
                    $spec['label']
                ),
                409
            );
        }
    }

    $trashed = wp_trash_post($page['id']);

    if (empty($trashed)) {
        return nibwp_seedprod_err('trash_failed', __('WordPress did not trash the page.', 'nibwp'), 500);
    }

    return [
        'trashed' => true,
        'page_id' => $page['id'],
        'was_bound_to_mode' => $bound,
        'note' => __('Reversible: restore_page brings it back.', 'nibwp'),
    ];
}

/**
 * Bring a trashed page back.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_seedprod_restore_page(array $input): array|WP_Error
{
    if ($denied = nibwp_seedprod_require(__('Restoring a SeedProd page', 'nibwp'))) {
        return $denied;
    }

    $page = nibwp_seedprod_resolve_page($input['page_id'] ?? null, 'edit_post', __('Restoring a SeedProd page', 'nibwp'));

    if ($page instanceof WP_Error) {
        return $page;
    }

    if ((string) ($page['post']->post_status ?? '') !== 'trash') {
        return nibwp_seedprod_err('not_trashed', __('That page is not in the trash.', 'nibwp'), 409);
    }

    $restored = wp_untrash_post($page['id']);

    if (empty($restored)) {
        return nibwp_seedprod_err('restore_failed', __('WordPress did not restore the page.', 'nibwp'), 500);
    }

    return [
        'restored' => true,
        'page_id' => $page['id'],
        'note' => __('WordPress restores a page to draft, not to published. Use set_page_status if it should be live.', 'nibwp'),
    ];
}

/**
 * Delete a page for good. There is no undo.
 *
 * Named the long way round on purpose: nobody should be able to reach for this
 * while meaning trash_page.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_seedprod_delete_page(array $input): array|WP_Error
{
    if ($denied = nibwp_seedprod_require(__('Permanently deleting a SeedProd page', 'nibwp'))) {
        return $denied;
    }

    $page = nibwp_seedprod_resolve_page($input['page_id'] ?? null, 'delete_post', __('Permanently deleting a SeedProd page', 'nibwp'));

    if ($page instanceof WP_Error) {
        return $page;
    }

    if (empty($input['confirm']) || !is_bool($input['confirm'])) {
        return nibwp_seedprod_err(
            'confirm_required',
            __('Permanently deleting a page destroys it and its revisions with no undo. Pass confirm=true if that is really what was asked for, or use trash_page.', 'nibwp'),
            409
        );
    }

    $bound = nibwp_seedprod_bound_mode($page['id']);

    if ($bound !== '') {
        $spec = nibwp_seedprod_modes()[$bound];

        return nibwp_seedprod_err(
            'page_in_use',
            sprintf(
                /* translators: %s: the mode's name */
                __('That page is bound to %s. Unbind it with assign_mode_page, or switch the mode off, before destroying it.', 'nibwp'),
                $spec['label']
            ),
            409
        );
    }

    $title = (string) ($page['post']->post_title ?? '');
    $deleted = wp_delete_post($page['id'], true);

    if (empty($deleted)) {
        return nibwp_seedprod_err('delete_failed', __('WordPress did not delete the page.', 'nibwp'), 500);
    }

    return [
        'deleted' => true,
        'page_id' => $page['id'],
        'title' => $title,
        'recoverable' => false,
        'note' => __('Gone, with its revisions. Say which page by name to whoever asked.', 'nibwp'),
    ];
}
