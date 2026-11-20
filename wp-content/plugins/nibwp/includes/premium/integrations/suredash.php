<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: SureDash — community portal.
 *
 * Verified against SureDash 1.12.1 (suredash.php:19).
 *
 * WHAT THIS PLUGIN IS. SureDash turns a WordPress site into a members' portal:
 * a left sidebar of "spaces", each space a discussion feed, a course, an events
 * list, a resource library or a plain link, grouped under sidebar "groups".
 * Members log in (optionally with Google or Facebook), write posts inside a
 * discussion space, comment on each other's posts, react, bookmark, and carry a
 * profile with badges. There is no custom database table anywhere in this build
 * — everything is WordPress primitives:
 *
 *   - a space is a `portal` post                      (loader.php:363)
 *   - a sidebar group is a `portal_group` term        (loader.php:364)
 *   - a member's post is a `community-post` post      (loader.php:365)
 *   - a space's feed is a `community-forum` term, pointed at by the space's own
 *     `feed_group_id` meta                            (backend.php:361, :406)
 *   - sub-content, e.g. course lessons, is `community-content` (loader.php:367)
 *   - every portal setting lives in one option, `portal_settings`
 *                                                     (loader.php:331)
 *   - a member is a WordPress user, by default in the `suredash_user` role
 *                                                     (backend.php:2651)
 *   - comments are ordinary WordPress comments on `community-post`
 *
 * WHAT IT DOES NOT HAVE, so there is no action for it here. There are no direct
 * or private messages in 1.12.1 — no inbox, no thread, no conversation store of
 * any kind (a grep for messaging across all 317 files finds an emoji name and a
 * lucide icon, nothing else). There is no space-membership record either: who
 * can reach a space is decided by the space's `visibility_scope` meta and by
 * SureMembers access groups (helper.php:2568), not by a join row, so "add a
 * member to a space" is not an operation this build has. And there is no
 * last-login tracking, so "members who never signed in" cannot be answered;
 * what CAN be answered, and is, is members who registered and then never posted
 * or commented, which is the plugin's own definition of a dormant member
 * (community-insights.php:296).
 *
 * MECHANISM. Where SureDash exposes a clean first-party call we take it:
 * Backend::handle_content_action() for moderation (backend.php:1399),
 * Backend::create_portal_group() and update_portal_group() for groups
 * (backend.php:194, :222), Helper::is_post_visible_to_user() for the access
 * question (helper.php:2568), Helper::get_option() for settings. Its REST
 * handlers are not usable from in here — most read `$_POST` directly and answer
 * with wp_send_json_*(), which would end the request in the middle of an
 * ability — so reads go through WP_Query, get_terms, WP_User_Query and prepared
 * SQL instead, over the same post types and meta keys the plugin itself writes.
 *
 * PRIVACY. This is a community of real people, so:
 *   - A member's row is built by an ALLOW-LIST (nibwp_sd_member_fields) and a
 *     non-scalar under an allow-listed key is DROPPED, never cast. A WordPress
 *     user row carries user_pass and user_activation_key; user meta carries
 *     session_tokens, _application_passwords and the capability map. An array
 *     is exactly how a token set travels under an innocent-looking key.
 *   - `portal_settings` holds live third-party credentials: Google and Facebook
 *     app secrets, two reCAPTCHA secrets, a Turnstile secret and a Giphy API key
 *     (settings.php:93-137). They leave as a name and whether they are set. An
 *     allow-list of value-bearing keys, not a denylist, so a credential a later
 *     version adds defaults to silent rather than to leaking.
 *   - Should a future build add messaging, nothing here will return a message
 *     body; a test parses this enum and fails on an action whose name reads like
 *     reading one.
 */

/**
 * The capability SureDash gates its own admin on — the Members screen included.
 *
 * loader.php:332 defines it, admin/menu.php:141 gates the entire portal menu and
 * every one of its submenus on it, and suredash_is_user_manager()
 * (functions.php:1457) is a user_can() against this and nothing else. So it is
 * the plugin's own answer to "may this account read the members list", and it is
 * the bar used here for reads as well as writes: the member list carries email
 * addresses and the audit names who has gone quiet.
 */
const NIBWP_SD_CAP = 'manage_portal_dashboard';

/** Rows any listing will return at most, whatever was asked for. */
const NIBWP_SD_MAX_ROWS = 200;

/** A space. loader.php:363. */
const NIBWP_SD_SPACE_TYPE = 'portal';

/** A sidebar group of spaces. loader.php:364. */
const NIBWP_SD_GROUP_TAX = 'portal_group';

/** A member's post. loader.php:365. */
const NIBWP_SD_POST_TYPE = 'community-post';

/** A space's feed, keyed from the space's feed_group_id meta. loader.php:366. */
const NIBWP_SD_FEED_TAX = 'community-forum';

/** Course lessons and similar. loader.php:367. */
const NIBWP_SD_SUB_TYPE = 'community-content';

/** The one option every portal setting lives in. loader.php:331. */
const NIBWP_SD_SETTINGS = 'portal_settings';

/** The role a new member lands in unless an admin changed it. backend.php:2651. */
const NIBWP_SD_MEMBER_ROLE = 'suredash_user';

/* ------------------------------------------------------------------ plumbing */

/**
 * Is SureDash actually loaded?
 *
 * The version constant is defined by the main plugin file before anything else
 * (suredash.php:19) and the loader class is what registers every post type, so
 * between them they separate "not installed" from "installed and half-booted".
 */
function nibwp_sd_guard(): ?WP_Error
{
    if (!defined('SUREDASHBOARD_VER') && !class_exists('SureDashboard\\Portals_Loader')) {
        return new WP_Error(
            'nibwp_sd_missing',
            __('SureDash is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/** A refusal about the request itself. */
function nibwp_sd_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_sd_' . $code, $message, ['status' => $status]);
}

/**
 * The hub's one capability gate, applied to reads as well as writes.
 *
 * A distinct code from the per-member gate below, on purpose: which door
 * stopped a call is the useful half of the answer, and one shared code makes a
 * deleted inner check indistinguishable from a working one.
 */
function nibwp_sd_require(string $what): ?WP_Error
{
    if (current_user_can(NIBWP_SD_CAP)) {
        return null;
    }

    return new WP_Error(
        'nibwp_sd_forbidden',
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name */
            __('%1$s needs the %2$s capability, which this account does not have.', 'nibwp'),
            $what,
            NIBWP_SD_CAP
        ),
        ['status' => 403]
    );
}

/**
 * The per-member gate.
 *
 * Touching one named person is not the same authority as reading the roster, so
 * it takes edit_user on that id — a check WordPress maps per user, not a
 * role-wide test. It runs BEFORE the member is looked up, so "may not read" and
 * "does not exist" answer identically and the refusal cannot be used to
 * enumerate which user ids are real.
 */
function nibwp_sd_member_gate(int $member_id, string $what): ?WP_Error
{
    if (current_user_can('edit_user', $member_id)) {
        return null;
    }

    return new WP_Error(
        'nibwp_sd_member_forbidden',
        sprintf(
            /* translators: %s: what was being attempted */
            __('%s needs edit_user on that member, which this account does not have.', 'nibwp'),
            $what
        ),
        ['status' => 403]
    );
}

/**
 * Read an id from input, or refuse on its TYPE.
 *
 * Checked before any capability, and before anything is written: a caller who
 * sent an array where an id belongs has a bug, and saying so is more useful than
 * a permission error — and, more to the point, nothing has happened yet when we
 * say it. Only an int or a string of digits is an id. A float, a bool, an array
 * and an object are not, and a numeric string with a fraction in it is a
 * different number from the one the caller has in mind.
 */
function nibwp_sd_id(array $input, string $key): int|WP_Error
{
    if (!array_key_exists($key, $input)) {
        return nibwp_sd_err(
            'bad_id',
            sprintf(
                /* translators: %s: parameter name */
                __('"%s" is required and must be a positive integer.', 'nibwp'),
                $key
            ),
            422
        );
    }

    $raw = $input[$key];

    $ok = is_int($raw) || (is_string($raw) && $raw !== '' && ctype_digit($raw));

    if (!$ok) {
        return nibwp_sd_err(
            'bad_id',
            sprintf(
                /* translators: 1: parameter name, 2: the type that arrived */
                __('"%1$s" must be a positive integer; a %2$s arrived.', 'nibwp'),
                $key,
                get_debug_type($raw)
            ),
            422
        );
    }

    $id = (int) $raw;

    if ($id < 1) {
        return nibwp_sd_err(
            'bad_id',
            sprintf(
                /* translators: %s: parameter name */
                __('"%s" must be a positive integer.', 'nibwp'),
                $key
            ),
            422
        );
    }

    return $id;
}

/** How many rows to return — clamped from input, never raised by it. */
function nibwp_sd_limit(array $input, int $fallback = 50): int
{
    $asked = isset($input['limit']) && is_numeric($input['limit']) ? (int) $input['limit'] : $fallback;

    return max(1, min(NIBWP_SD_MAX_ROWS, $asked));
}

/** Where a listing starts. */
function nibwp_sd_offset(array $input): int
{
    $asked = isset($input['offset']) && is_numeric($input['offset']) ? (int) $input['offset'] : 0;

    return max(0, $asked);
}

/** A day window, clamped. */
function nibwp_sd_days(array $input, int $fallback = 30): int
{
    $asked = isset($input['days']) && is_numeric($input['days']) ? (int) $input['days'] : $fallback;

    return max(1, min(365, $asked));
}

/**
 * Copy only the named keys, and only when the value is a scalar.
 *
 * The allow-list is the whole defence on anything user-shaped. A non-scalar is
 * DROPPED rather than cast or serialised: `session_tokens` and
 * `_application_passwords` are arrays, and a cast would put them into the
 * response as a string. Dropping also means a key whose shape changes in a later
 * version goes quiet instead of going out.
 *
 * @param array<string, mixed> $source
 * @param array<int, string> $allowed
 * @return array<string, mixed>
 */
function nibwp_sd_pick(array $source, array $allowed): array
{
    $out = [];

    foreach ($allowed as $key) {
        if (!array_key_exists($key, $source)) {
            continue;
        }

        $value = $source[$key];

        if ($value !== null && !is_scalar($value)) {
            continue;
        }

        $out[$key] = $value;
    }

    return $out;
}

/**
 * Flatten a value to its string members, bounded.
 *
 * Roles and badge names are legitimately lists, so they cannot go through
 * nibwp_sd_pick(). They come through here instead, which keeps strings and
 * integers and discards anything nested — a nested value under `roles` is not a
 * role.
 *
 * @return array<int, string>
 */
function nibwp_sd_strings(mixed $value, int $max = 25): array
{
    if (!is_array($value)) {
        return is_string($value) && $value !== '' ? [$value] : [];
    }

    $out = [];

    foreach ($value as $item) {
        if (is_string($item) && $item !== '') {
            $out[] = $item;
        } elseif (is_int($item)) {
            $out[] = (string) $item;
        }

        if (count($out) >= $max) {
            break;
        }
    }

    return $out;
}

/**
 * One portal setting, without letting SureDash's own accessor throw.
 *
 * Helper::get_option() reads the whole settings array through a static cache
 * (helper.php:38). On a half-updated install that class may be absent, so the
 * fallback reads the option the plugin writes.
 */
function nibwp_sd_option(string $key, mixed $fallback = null): mixed
{
    if (class_exists('SureDashboard\\Inc\\Utils\\Helper') && method_exists('SureDashboard\\Inc\\Utils\\Helper', 'get_option')) {
        try {
            $value = \SureDashboard\Inc\Utils\Helper::get_option($key, false);

            if ($value !== '' && $value !== false) {
                return $value;
            }
        } catch (\Throwable $error) {
            // Fall through to the raw option.
        }
    }

    $all = get_option(NIBWP_SD_SETTINGS, []);

    if (is_array($all) && array_key_exists($key, $all)) {
        return $all[$key];
    }

    return $fallback;
}

/**
 * Settings that hold a live credential. Reported as a name and whether set.
 *
 * settings.php:93-137. The site-side halves of the captcha pairs are public by
 * design, but they are listed here too — a caller has no use for the value, and
 * "configured or not" is the whole question an owner is asking.
 *
 * @return array<int, string>
 */
function nibwp_sd_secret_keys(): array
{
    return [
        'google_token_id',
        'google_token_secret',
        'facebook_token_id',
        'facebook_token_secret',
        'recaptcha_site_key_v2',
        'recaptcha_secret_key_v2',
        'recaptcha_site_key_v3',
        'recaptcha_secret_key_v3',
        'turnstile_site_key',
        'turnstile_secret_key',
        'giphy_api_key',
    ];
}

/**
 * Settings whose VALUE may be reported.
 *
 * An allow-list rather than "everything except the secrets": portal_settings is
 * one flat key space that every release adds to, and a denylist would hand out
 * the next credential by default.
 *
 * @return array<int, string>
 */
function nibwp_sd_settings_value_keys(): array
{
    return [
        'portal_name',
        'hide_branding',
        'hidden_community',
        'enable_feeds',
        'feeds_per_page',
        'feeds_content_type',
        'feeds_default_view',
        'user_upload_limit',
        'profile_upload_limit',
        'enable_lightbox',
        'bypass_wp_interactions',
        'notify_admins_on_new_post',
        'default_role',
        'override_wp_registration',
        'portal_as_homepage',
        'home_page',
        'first_space',
        'global_layout',
        'global_layout_style',
        'default_palette',
        'font_family',
        'container_padding',
        'aside_navigation_width',
        'email_from_mail_id',
        'usage_tracking',
        'surecart_customer_dashboard_space',
        'suredash_abilities_api',
        'suredash_abilities_api_edit',
        'suredash_abilities_api_delete',
        'suredash_mcp_server',
        'suredash_post_summary',
        'suredash_post_summary_provider',
    ];
}

/**
 * Space meta worth reporting. post-meta.php:28-215.
 *
 * @return array<int, string>
 */
function nibwp_sd_space_meta_keys(): array
{
    return [
        'integration',
        'item_emoji',
        'space_description',
        'post_content_type',
        'layout',
        'layout_style',
        'content_layout_style',
        'allow_members_to_post',
        'feed_group_id',
        'hidden_space',
        'hide_from_search',
        'show_like_button',
        'show_share_button',
        'default_list_view',
        'enforce_lesson_order',
        'show_add_to_calendar',
        'use_space_thumbnail',
        'link_url',
        'link_target',
        'portal_page_target',
        'single_post_id',
    ];
}

/**
 * Space meta an owner may change from here, and the type each takes.
 *
 * A subset of the list above on purpose: nothing structural — integration,
 * feed_group_id, single_post_id — can be rewritten, because changing what kind
 * of thing a space is halfway through its life orphans everything in it.
 *
 * @return array<string, string>
 */
function nibwp_sd_space_writable(): array
{
    return [
        'space_description' => 'string',
        'item_emoji' => 'string',
        'allow_members_to_post' => 'bool',
        'hidden_space' => 'bool',
        'hide_from_search' => 'bool',
        'show_like_button' => 'bool',
        'show_share_button' => 'bool',
        'default_list_view' => 'bool',
        'post_content_type' => 'string',
        'content_layout_style' => 'string',
    ];
}

/**
 * Fields of a member that may leave this hub.
 *
 * Everything else about a WordPress user stays here, and the ones that matter
 * are the ones NOT on this list: user_pass, user_activation_key, and the
 * session-token and application-password meta.
 *
 * @return array<int, string>
 */
function nibwp_sd_member_fields(): array
{
    return [
        'id',
        'username',
        'display_name',
        'email',
        'registered',
        'posts_count',
        'comments_count',
        'last_active',
        'inactive_days',
        'reachable_space_count',
    ];
}

/**
 * Shape a member row through the allow-list.
 *
 * @param array<string, mixed> $raw
 * @return array<string, mixed>
 */
function nibwp_sd_member_row(array $raw): array
{
    $row = nibwp_sd_pick($raw, nibwp_sd_member_fields());

    // Roles and badges are lists of names, so they cannot travel through the
    // scalar allow-list. They are rebuilt from strings only — a nested value
    // under either key is not a role and is not a badge.
    if (array_key_exists('roles', $raw)) {
        $row['roles'] = nibwp_sd_strings($raw['roles']);
    }

    if (array_key_exists('badges', $raw)) {
        $names = [];

        if (is_array($raw['badges'])) {
            foreach ($raw['badges'] as $badge) {
                if (is_array($badge) && isset($badge['name']) && is_string($badge['name'])) {
                    $names[] = $badge['name'];
                } elseif (is_string($badge)) {
                    $names[] = $badge;
                }
            }
        }

        $row['badges'] = array_slice($names, 0, 25);
    }

    return $row;
}

/** The `community-forum` term a space's posts live in. backend.php:361. */
function nibwp_sd_forum_term(int $space_id): int
{
    $term = get_post_meta($space_id, 'feed_group_id', true);

    return is_numeric($term) ? (int) $term : 0;
}

/**
 * How many member posts sit in a space, by status.
 *
 * @return array<string, int>
 */
function nibwp_sd_space_post_counts(int $space_id): array
{
    $out = ['publish' => 0, 'pending' => 0, 'draft' => 0, 'total' => 0];

    $term = nibwp_sd_forum_term($space_id);

    if ($term < 1) {
        return $out;
    }

    global $wpdb;

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT p.post_status AS st, COUNT(*) AS n
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE p.post_type = %s AND tt.taxonomy = %s AND tt.term_id = %d
             GROUP BY p.post_status",
            NIBWP_SD_POST_TYPE,
            NIBWP_SD_FEED_TAX,
            $term
        ),
        ARRAY_A
    );

    foreach (is_array($rows) ? $rows : [] as $row) {
        $status = isset($row['st']) ? (string) $row['st'] : '';
        $count = isset($row['n']) ? (int) $row['n'] : 0;

        if (array_key_exists($status, $out)) {
            $out[$status] = $count;
        }

        if ($status !== 'trash' && $status !== 'auto-draft') {
            $out['total'] += $count;
        }
    }

    return $out;
}

/**
 * A space's sidebar widget configuration. post-meta.php:201.
 *
 * @return array<string, mixed>
 */
function nibwp_sd_space_widgets(int $space_id): array
{
    $meta = get_post_meta($space_id, 'space_sidebar_widgets', true);
    $widgets = is_array($meta) && isset($meta['widgets']) && is_array($meta['widgets']) ? $meta['widgets'] : [];

    $slugs = [];

    foreach ($widgets as $widget) {
        if (is_array($widget)) {
            foreach (['slug', 'type', 'id'] as $candidate) {
                if (isset($widget[$candidate]) && is_string($widget[$candidate])) {
                    $slugs[] = $widget[$candidate];
                    break;
                }
            }
        } elseif (is_string($widget)) {
            $slugs[] = $widget;
        }
    }

    return [
        'count' => count($widgets),
        'slugs' => array_slice($slugs, 0, 25),
        // templates/parts/sidebar-widgets.php:28 treats a missing key as true,
        // so an untouched space inherits the portal-wide sidebar.
        'global_sidebar_enabled' => !is_array($meta) || !array_key_exists('global_sidebar_enabled', $meta)
            ? true
            : (bool) $meta['global_sidebar_enabled'],
    ];
}

/**
 * The widget kinds SureDash's own renderer knows.
 * class-widget-renderer.php:59-75.
 *
 * @return array<int, string>
 */
function nibwp_sd_widget_slugs(): array
{
    return ['about-space', 'recent-activities', 'content-list', 'info-box', 'profiles'];
}

/**
 * The portal-wide sidebar widgets. settings.php:484.
 *
 * @return array<string, mixed>
 */
function nibwp_sd_global_widgets(): array
{
    $widgets = nibwp_sd_option('global_sidebar_widgets', []);
    $widgets = is_array($widgets) ? $widgets : [];

    $slugs = [];

    foreach ($widgets as $widget) {
        if (is_array($widget)) {
            foreach (['slug', 'type', 'id'] as $candidate) {
                if (isset($widget[$candidate]) && is_string($widget[$candidate])) {
                    $slugs[] = $widget[$candidate];
                    break;
                }
            }
        } elseif (is_string($widget)) {
            $slugs[] = $widget;
        }
    }

    return ['count' => count($widgets), 'slugs' => array_slice($slugs, 0, 25)];
}

/**
 * Who a space is scoped to, as COUNTS plus access-group ids.
 *
 * Deliberately no member identities: the scope is personal data about who paid
 * for what, and get_member / member_access are the gated way to ask about a
 * named person. helper.php:2606 for the `user-<id>` / `ag-<id>` encoding.
 *
 * @return array<string, mixed>
 */
function nibwp_sd_space_access(int $space_id): array
{
    $scope = get_post_meta($space_id, 'visibility_scope', true);
    $scope = is_array($scope) ? $scope : [];

    $users = 0;
    $groups = [];

    foreach ($scope as $item) {
        if (!is_string($item)) {
            continue;
        }

        if (str_starts_with($item, 'user-')) {
            ++$users;
        } elseif (str_starts_with($item, 'ag-')) {
            $groups[] = (int) substr($item, 3);
        }
    }

    return [
        'open_to_everyone' => $scope === [],
        'named_member_count' => $users,
        'access_group_ids' => array_values(array_unique($groups)),
        'suremembers_active' => defined('SUREMEMBERS_VER') || defined('SUREMEMBERS_CORE_VER'),
    ];
}

/**
 * SureMembers access groups, when SureMembers is there. helper.php:2539.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_sd_access_groups(): array
{
    if (!class_exists('SureDashboard\\Inc\\Utils\\Helper') || !method_exists('SureDashboard\\Inc\\Utils\\Helper', 'get_suremembers_access_groups')) {
        return [];
    }

    try {
        $groups = \SureDashboard\Inc\Utils\Helper::get_suremembers_access_groups();
    } catch (\Throwable $error) {
        return [];
    }

    $out = [];

    foreach (is_array($groups) ? $groups : [] as $group) {
        if (is_array($group)) {
            $out[] = nibwp_sd_pick($group, ['value', 'label']);
        }
    }

    return array_slice($out, 0, NIBWP_SD_MAX_ROWS);
}

/** Can this member reach this space? helper.php:2568. Null when unknowable. */
function nibwp_sd_can_member_see(int $space_id, int $member_id): ?bool
{
    if (!class_exists('SureDashboard\\Inc\\Utils\\Helper') || !method_exists('SureDashboard\\Inc\\Utils\\Helper', 'is_post_visible_to_user')) {
        return null;
    }

    try {
        $visible = \SureDashboard\Inc\Utils\Helper::is_post_visible_to_user([
            'post_id' => $space_id,
            'user_id' => $member_id,
            'detail' => false,
        ]);
    } catch (\Throwable $error) {
        return null;
    }

    return (bool) $visible;
}

/** The capability meta key for the current blog, for the role LIKE queries. */
function nibwp_sd_cap_meta_key(): string
{
    global $wpdb;

    return $wpdb->get_blog_prefix() . 'capabilities';
}

/**
 * A role slug from input.
 *
 * It is never interpolated — it always travels as a prepared value — but it is
 * normalised anyway so a typo answers zero rather than something surprising.
 */
function nibwp_sd_role(array $input): string
{
    $raw = isset($input['role']) && is_string($input['role']) ? sanitize_key($input['role']) : '';

    return $raw === '' ? NIBWP_SD_MEMBER_ROLE : $raw;
}

/** How many users hold a role. */
function nibwp_sd_role_count(string $role): int
{
    global $wpdb;

    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(DISTINCT u.ID)
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID
             WHERE um.meta_key = %s AND um.meta_value LIKE %s",
            nibwp_sd_cap_meta_key(),
            '%' . $wpdb->esc_like('"' . $role . '"') . '%'
        )
    );
}

/** A post-status histogram for one post type. */
function nibwp_sd_status_counts(string $post_type): array
{
    global $wpdb;

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT post_status AS st, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = %s GROUP BY post_status",
            $post_type
        ),
        ARRAY_A
    );

    $out = [];

    foreach (is_array($rows) ? $rows : [] as $row) {
        $status = isset($row['st']) ? (string) $row['st'] : '';

        if ($status !== '' && $status !== 'auto-draft') {
            $out[$status] = isset($row['n']) ? (int) $row['n'] : 0;
        }
    }

    return $out;
}

/** Comment counts on member posts, by approval state. */
function nibwp_sd_comment_counts(): array
{
    global $wpdb;

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT c.comment_approved AS st, COUNT(*) AS n
             FROM {$wpdb->comments} c
             INNER JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
             WHERE p.post_type = %s
             GROUP BY c.comment_approved",
            NIBWP_SD_POST_TYPE
        ),
        ARRAY_A
    );

    $map = ['1' => 'approved', '0' => 'pending', 'spam' => 'spam', 'trash' => 'trash'];
    $out = ['approved' => 0, 'pending' => 0, 'spam' => 0, 'trash' => 0];

    foreach (is_array($rows) ? $rows : [] as $row) {
        $key = $map[(string) ($row['st'] ?? '')] ?? null;

        if ($key !== null) {
            $out[$key] = isset($row['n']) ? (int) $row['n'] : 0;
        }
    }

    return $out;
}

/** Summary of a space row, for listings. */
function nibwp_sd_space_brief(WP_Post $space): array
{
    $groups = wp_get_post_terms($space->ID, NIBWP_SD_GROUP_TAX, ['fields' => 'id=>name']);
    $counts = nibwp_sd_space_post_counts($space->ID);
    $widgets = nibwp_sd_space_widgets($space->ID);

    return [
        'id' => $space->ID,
        'title' => $space->post_title,
        'status' => $space->post_status,
        'type' => (string) get_post_meta($space->ID, 'integration', true),
        'emoji' => (string) get_post_meta($space->ID, 'item_emoji', true),
        'hidden' => (bool) get_post_meta($space->ID, 'hidden_space', true),
        'members_may_post' => (bool) get_post_meta($space->ID, 'allow_members_to_post', true),
        'group_ids' => is_wp_error($groups) ? [] : array_map('intval', array_keys($groups)),
        'group_names' => is_wp_error($groups) ? [] : array_values(array_map('strval', $groups)),
        'forum_term_id' => nibwp_sd_forum_term($space->ID),
        'post_count' => $counts['total'],
        'widget_count' => $widgets['count'],
        'access' => nibwp_sd_space_access($space->ID),
    ];
}

/** Fetch a space, or refuse. */
function nibwp_sd_space(int $space_id): WP_Post|WP_Error
{
    $space = get_post($space_id);

    if (!$space instanceof WP_Post || $space->post_type !== NIBWP_SD_SPACE_TYPE) {
        return nibwp_sd_err(
            'space_not_found',
            sprintf(
                /* translators: %d: space id */
                __('No SureDash space with id %d.', 'nibwp'),
                $space_id
            ),
            404
        );
    }

    return $space;
}

/** Fetch a member post, or refuse. */
function nibwp_sd_post(int $post_id): WP_Post|WP_Error
{
    $post = get_post($post_id);

    $types = [NIBWP_SD_POST_TYPE, NIBWP_SD_SUB_TYPE];

    if (!$post instanceof WP_Post || !in_array($post->post_type, $types, true)) {
        return nibwp_sd_err(
            'post_not_found',
            sprintf(
                /* translators: %d: post id */
                __('No SureDash community post with id %d.', 'nibwp'),
                $post_id
            ),
            404
        );
    }

    return $post;
}

/* ------------------------------------------------------------- registration */

/**
 * The actions this hub offers.
 *
 * @return array<int, string>
 */
function nibwp_sd_actions(): array
{
    return [
        // Reads.
        'community_summary',
        'get_settings',
        'list_spaces',
        'get_space',
        'list_groups',
        'list_widgets',
        'list_members',
        'get_member',
        'member_counts',
        'member_access',
        'list_posts',
        'get_post',
        'list_comments',
        'email_notifications',
        'community_audit',
        // Writes.
        'create_space',
        'update_space',
        'set_space_access',
        'create_group',
        'update_group',
        'reorder_groups',
        'reorder_spaces',
        'set_space_widgets',
        'moderate_post',
        'delete_post',
        'moderate_comment',
    ];
}

wp_register_ability('nibwp/suredash-manage', [
    'label' => __('SureDash – Community Portal', 'nibwp'),
    'description' => 'Read and run a SureDash members\' portal: its spaces and sidebar groups, the sidebar widgets on each space, who can reach what, '
        . 'the members and their contribution counts, the posts and comments members have written, the emails the portal sends, and an audit of what '
        . 'needs an owner\'s attention. Writes: create and update a space, change a space\'s access, create and update a sidebar group, reorder groups '
        . 'and spaces, set a space\'s widgets, moderate or delete a member\'s post, moderate a comment. '
        . 'Moderating or deleting destroys something a real member wrote, and changing a space\'s access changes what paying members can reach — both '
        . 'say so plainly and both report exactly what changed. '
        . 'This build has no direct or private messages, and no action here would return a message body if it did. '
        . 'No password hash, session token, application password or verification key leaves this hub, and the portal\'s stored third-party credentials '
        . '(Google, Facebook, reCAPTCHA, Turnstile, Giphy) are reported as a name and whether they are set, never as a value.',
    'category' => 'community',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_sd_actions(),
                'description' => 'Which operation to run. Start with community_summary.',
            ],
            'space_id' => ['type' => 'integer', 'description' => 'A space (a `portal` post id). From list_spaces.'],
            'group_id' => ['type' => 'integer', 'description' => 'A sidebar group (a `portal_group` term id). From list_groups.'],
            'member_id' => ['type' => 'integer', 'description' => 'A member (a WordPress user id). From list_members.'],
            'post_id' => ['type' => 'integer', 'description' => 'A member-written post (a `community-post` id). From list_posts.'],
            'comment_id' => ['type' => 'integer', 'description' => 'A comment id. From list_comments.'],
            'title' => ['type' => 'string', 'description' => 'For create_space / update_space / create_group / update_group.'],
            'description' => ['type' => 'string', 'description' => 'For create_space / update_space: the space description members see.'],
            'space_type' => [
                'type' => 'string',
                'description' => 'For create_space: posts_discussion (a feed members post in), link, single_post or portal_page. Course, events, resource_library and collection spaces need SureDash Pro and are refused here without it.',
            ],
            'status' => ['type' => 'string', 'description' => 'publish, draft, pending or any. For listings, and for create_space / update_space.'],
            'emoji' => ['type' => 'string', 'description' => 'For create_space / update_space: the space icon.'],
            'members_may_post' => ['type' => 'boolean', 'description' => 'For create_space / update_space: whether members may write in this space.'],
            'settings' => ['type' => 'object', 'description' => 'For update_space: display switches to change. Only allow-listed keys are accepted; an unknown key is reported back, not written.'],
            'access' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'For set_space_access: the FULL new scope, as "user-<id>" and "ag-<accessGroupId>" entries. An empty array opens the space to everyone.',
            ],
            'widgets' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'For set_space_widgets: the sidebar widgets, in order. Valid: about-space, recent-activities, content-list, info-box, profiles.',
            ],
            'use_global_sidebar' => ['type' => 'boolean', 'description' => 'For set_space_widgets: also show the portal-wide sidebar widgets on this space.'],
            'order' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'description' => 'For reorder_groups: group ids top to bottom. For reorder_spaces: space ids within one group.',
            ],
            'hide_label' => ['type' => 'boolean', 'description' => 'For create_group / update_group: hide the group heading in the sidebar.'],
            'operation' => ['type' => 'string', 'description' => 'For moderate_post: publish, draft, pending, trash or restore. For moderate_comment: approve, unapprove, spam, unspam, trash or untrash.'],
            'confirm' => ['type' => 'boolean', 'description' => 'For delete_post: must be true. A permanent delete has no undo.'],
            'role' => ['type' => 'string', 'description' => 'For list_members / member_counts: a role slug. Default suredash_user, the role new members land in.'],
            'search' => ['type' => 'string', 'description' => 'For list_spaces / list_members / list_posts.'],
            'space_type_filter' => ['type' => 'string', 'description' => 'For list_spaces: only spaces of this integration type.'],
            'author_id' => ['type' => 'integer', 'description' => 'For list_posts: only posts by this member.'],
            'days' => ['type' => 'integer', 'description' => 'For member_counts / community_audit: the window, in days. Default 30, max 365.'],
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
    'execute_callback' => 'nibwp_suredash_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'SureDash: a members\' portal of spaces, member posts and comments.',
                '',
                'VOCABULARY. A SPACE is one entry in the portal sidebar — a discussion feed,',
                'a course, an events list, a resource library or a plain link. A GROUP is a',
                'heading in that sidebar holding an ordered list of spaces. A MEMBER is a',
                'WordPress user, normally in the suredash_user role. A POST is written by a',
                'member inside a discussion space. WIDGETS are the panels in a space\'s',
                'sidebar, set per space and/or portal-wide.',
                '',
                'START WITH community_summary. It answers how big the portal is and what is',
                'turned on, in one call. Then community_audit for what needs attention.',
                '',
                'WHAT THIS BUILD DOES NOT HAVE, so do not go looking for an action:',
                '- No direct or private messages. There is no inbox and no message store, and',
                '  no action here would return a message body if one existed.',
                '- No space membership rows. Who can reach a space is the space\'s access',
                '  scope plus SureMembers access groups — read it with get_space, change it',
                '  with set_space_access. "Add this member to that space" is not an operation;',
                '  naming them in the scope, or granting them an access group, is.',
                '- No last-login record. "Never signed in" cannot be answered. community_audit',
                '  reports members who registered and then never posted or commented, which is',
                '  the plugin\'s own definition of a dormant member.',
                '',
                'THE WRITES THAT HURT, and what to say before running them:',
                '- moderate_post and delete_post act on something a real member wrote.',
                '  delete_post is permanent and needs confirm: true. moderate_post with',
                '  operation: trash is the reversible one — prefer it.',
                '- moderate_comment with spam or trash takes a member\'s comment out of view.',
                '- set_space_access replaces the WHOLE scope and changes what paying members',
                '  can reach. Read get_space first, say which members or access groups are',
                '  about to lose or gain the space, and pass the complete new list — an',
                '  omitted entry is a revoked entry. An empty array opens the space to',
                '  everyone.',
                'Each of these reports what changed; say it back to the user.',
                '',
                'PRIVACY. Member rows carry an email address and nothing secret: no password',
                'hash, no session token, no application password, no verification key — and',
                'asking differently will not change that. get_member and member_access need',
                'edit_user on that person, so they can refuse where list_members did not, and',
                'a listing says how many rows it dropped for that reason. get_settings reports',
                'the portal\'s stored Google, Facebook, reCAPTCHA, Turnstile and Giphy',
                'credentials as a name and whether they are set, never as a value.',
                '',
                'LISTINGS are capped at 200 rows and report total, returned and skipped. Page',
                'with offset rather than asking for a bigger limit.',
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
function nibwp_suredash_manage(array $input): array|WP_Error
{
    $guard = nibwp_sd_guard();
    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = isset($input['action']) && is_string($input['action']) ? $input['action'] : '';

    if (!in_array($action, nibwp_sd_actions(), true)) {
        return nibwp_sd_err(
            'unknown_action',
            sprintf(
                /* translators: 1: action name, 2: list of valid actions */
                __('Unknown SureDash action "%1$s". Valid actions: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_sd_actions())
            )
        );
    }

    // SureDash's own helpers reach the database and, on a half-updated install,
    // may not be the shape this file expects. One catch turns any of that into a
    // named refusal rather than a fatal that takes the whole REST request down.
    try {
        return nibwp_sd_dispatch($action, $input);
    } catch (\Throwable $error) {
        return nibwp_sd_err(
            'plugin_error',
            sprintf(
                /* translators: 1: action name, 2: error message */
                __('SureDash could not complete %1$s: %2$s', 'nibwp'),
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
function nibwp_sd_dispatch(string $action, array $input): array|WP_Error
{
    $result = match ($action) {
        'community_summary' => nibwp_sd_community_summary(),
        'get_settings' => nibwp_sd_get_settings(),
        'list_spaces' => nibwp_sd_list_spaces($input),
        'get_space' => nibwp_sd_get_space($input),
        'list_groups' => nibwp_sd_list_groups($input),
        'list_widgets' => nibwp_sd_list_widgets($input),
        'list_members' => nibwp_sd_list_members($input),
        'get_member' => nibwp_sd_get_member($input),
        'member_counts' => nibwp_sd_member_counts($input),
        'member_access' => nibwp_sd_member_access($input),
        'list_posts' => nibwp_sd_list_posts($input),
        'get_post' => nibwp_sd_get_post($input),
        'list_comments' => nibwp_sd_list_comments($input),
        'email_notifications' => nibwp_sd_email_notifications(),
        'community_audit' => nibwp_sd_community_audit($input),
        'create_space' => nibwp_sd_create_space($input),
        'update_space' => nibwp_sd_update_space($input),
        'set_space_access' => nibwp_sd_set_space_access($input),
        'create_group' => nibwp_sd_create_group($input),
        'update_group' => nibwp_sd_update_group($input),
        'reorder_groups' => nibwp_sd_reorder_groups($input),
        'reorder_spaces' => nibwp_sd_reorder_spaces($input),
        'set_space_widgets' => nibwp_sd_set_space_widgets($input),
        'moderate_post' => nibwp_sd_moderate_post($input),
        'delete_post' => nibwp_sd_delete_post($input),
        'moderate_comment' => nibwp_sd_moderate_comment($input),
        default => nibwp_sd_err('unhandled_action', __('Unhandled action.', 'nibwp')),
    };

    return $result instanceof WP_Error ? $result : ['action' => $action] + $result;
}

/* -------------------------------------------------------------------- reads */

/**
 * How big the portal is and what is switched on.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_community_summary(): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Reading the SureDash community summary', 'nibwp'))) {
        return $denied;
    }

    $spaces = nibwp_sd_status_counts(NIBWP_SD_SPACE_TYPE);
    $posts = nibwp_sd_status_counts(NIBWP_SD_POST_TYPE);
    $groups = get_terms(['taxonomy' => NIBWP_SD_GROUP_TAX, 'hide_empty' => false, 'fields' => 'ids']);

    $configured = [];

    foreach (nibwp_sd_secret_keys() as $key) {
        $value = nibwp_sd_option($key, '');

        if (is_scalar($value) && (string) $value !== '') {
            $configured[] = $key;
        }
    }

    return [
        'version' => defined('SUREDASHBOARD_VER') ? (string) SUREDASHBOARD_VER : null,
        'pro_active' => defined('SUREDASH_PRO_VER'),
        'suremembers_active' => defined('SUREMEMBERS_VER') || defined('SUREMEMBERS_CORE_VER'),
        'portal_name' => (string) nibwp_sd_option('portal_name', ''),
        'portal_is_public' => !nibwp_sd_option('hidden_community', false),
        'feeds_enabled' => (bool) nibwp_sd_option('enable_feeds', false),
        'default_member_role' => (string) nibwp_sd_option('default_role', NIBWP_SD_MEMBER_ROLE),
        'spaces' => ['by_status' => $spaces, 'total' => array_sum($spaces)],
        'groups' => is_wp_error($groups) ? 0 : count($groups),
        'member_posts' => ['by_status' => $posts, 'total' => array_sum($posts)],
        'comments' => nibwp_sd_comment_counts(),
        'members' => nibwp_sd_role_count(NIBWP_SD_MEMBER_ROLE),
        'portal_managers' => nibwp_sd_role_count('portal_manager'),
        'global_sidebar_widgets' => nibwp_sd_global_widgets()['count'],
        'access_groups' => count(nibwp_sd_access_groups()),
        'abilities_api' => [
            'enabled' => (bool) nibwp_sd_option('suredash_abilities_api', true),
            'mcp_server' => (bool) nibwp_sd_option('suredash_mcp_server', false),
        ],
        // Names only. The value of one of these never leaves this hub.
        'credentials_configured' => $configured,
        'has_private_messaging' => false,
    ];
}

/**
 * The portal settings, by allow-list, with credentials named but not shown.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_get_settings(): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Reading the SureDash portal settings', 'nibwp'))) {
        return $denied;
    }

    $values = [];

    foreach (nibwp_sd_settings_value_keys() as $key) {
        $value = nibwp_sd_option($key, null);

        // A non-scalar setting is a structure, not something an agent can read
        // usefully, and it is also how something unexpected would travel. The
        // collection sizes below report their shape instead.
        if ($value !== null && is_scalar($value)) {
            $values[$key] = $value;
        }
    }

    $credentials = [];

    foreach (nibwp_sd_secret_keys() as $key) {
        $value = nibwp_sd_option($key, '');
        $credentials[$key] = is_scalar($value) && (string) $value !== '';
    }

    $collections = [];

    foreach (['global_sidebar_widgets', 'user_badges', 'profile_links', 'endpoint_slugs', 'suredash_register_user_access_groups', 'admin_notification_recipients'] as $key) {
        $value = nibwp_sd_option($key, []);
        $collections[$key] = is_array($value) ? count($value) : 0;
    }

    return [
        'option_name' => NIBWP_SD_SETTINGS,
        'settings' => $values,
        // Name and is-set only, never the value. settings.php:93-137.
        'credentials' => $credentials,
        'collection_sizes' => $collections,
    ];
}

/**
 * The spaces in the portal sidebar.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_list_spaces(array $input): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Listing SureDash spaces', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_sd_limit($input);
    $offset = nibwp_sd_offset($input);
    $status = isset($input['status']) && is_string($input['status']) ? sanitize_key($input['status']) : 'any';

    $args = [
        'post_type' => NIBWP_SD_SPACE_TYPE,
        'post_status' => $status === 'any' ? ['publish', 'draft', 'pending', 'private'] : [$status],
        'posts_per_page' => $limit,
        'offset' => $offset,
        'orderby' => 'title',
        'order' => 'ASC',
    ];

    if (isset($input['search']) && is_string($input['search']) && $input['search'] !== '') {
        $args['s'] = sanitize_text_field($input['search']);
    }

    if (isset($input['group_id']) && is_numeric($input['group_id']) && (int) $input['group_id'] > 0) {
        $args['tax_query'] = [[
            'taxonomy' => NIBWP_SD_GROUP_TAX,
            'field' => 'term_id',
            'terms' => (int) $input['group_id'],
        ]];
    }

    $query = new WP_Query($args);

    $wanted = isset($input['space_type_filter']) && is_string($input['space_type_filter'])
        ? sanitize_key($input['space_type_filter'])
        : '';

    $rows = [];
    $skipped = 0;

    foreach ($query->posts as $space) {
        if (!$space instanceof WP_Post) {
            continue;
        }

        // A listing never hands back a row the account could not open on its
        // own, and it says how many it dropped rather than quietly shrinking.
        if (!current_user_can('read_post', $space->ID)) {
            ++$skipped;
            continue;
        }

        $brief = nibwp_sd_space_brief($space);

        if ($wanted !== '' && $brief['type'] !== $wanted) {
            continue;
        }

        $rows[] = $brief;
    }

    return [
        'spaces' => $rows,
        'total' => (int) $query->found_posts,
        'returned' => count($rows),
        'skipped_unreadable' => $skipped,
        'limit' => $limit,
        'offset' => $offset,
        'type_filter' => $wanted,
    ];
}

/**
 * One space in full: its settings, its feed, its widgets, who can reach it.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_get_space(array $input): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Reading a SureDash space', 'nibwp'))) {
        return $denied;
    }

    $space_id = nibwp_sd_id($input, 'space_id');
    if ($space_id instanceof WP_Error) {
        return $space_id;
    }


    $space = nibwp_sd_space($space_id);
    if ($space instanceof WP_Error) {
        return $space;
    }

    $meta = [];

    foreach (nibwp_sd_space_meta_keys() as $key) {
        $value = get_post_meta($space_id, $key, true);

        if ($value !== '' && is_scalar($value)) {
            $meta[$key] = $value;
        }
    }

    $groups = wp_get_post_terms($space_id, NIBWP_SD_GROUP_TAX, ['fields' => 'id=>name']);

    return [
        'space' => [
            'id' => $space_id,
            'title' => $space->post_title,
            'slug' => $space->post_name,
            'status' => $space->post_status,
            'author_id' => (int) $space->post_author,
            'created' => $space->post_date_gmt,
            'modified' => $space->post_modified_gmt,
            'permalink' => (string) get_permalink($space_id),
        ],
        'settings' => $meta,
        'groups' => is_wp_error($groups) ? [] : array_map('strval', $groups),
        'feed' => [
            'forum_term_id' => nibwp_sd_forum_term($space_id),
            'post_counts' => nibwp_sd_space_post_counts($space_id),
        ],
        'widgets' => nibwp_sd_space_widgets($space_id),
        'global_widgets' => nibwp_sd_global_widgets(),
        // Counts and access-group ids, never the members themselves — use
        // member_access, which asks for edit_user on the person in question.
        'access' => nibwp_sd_space_access($space_id),
    ];
}

/**
 * The sidebar groups, in display order, with the spaces each holds.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_list_groups(array $input): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Listing SureDash space groups', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_sd_limit($input);
    $offset = nibwp_sd_offset($input);

    $terms = get_terms([
        'taxonomy' => NIBWP_SD_GROUP_TAX,
        'hide_empty' => false,
        'number' => $limit,
        'offset' => $offset,
        'orderby' => 'meta_value_num',
        'meta_key' => 'group_tax_position',
        'order' => 'ASC',
    ]);

    if (is_wp_error($terms)) {
        return nibwp_sd_err('group_query_failed', $terms->get_error_message(), 500);
    }

    $rows = [];

    foreach ($terms as $term) {
        if (!$term instanceof WP_Term) {
            continue;
        }

        // The ordered space list is a CSV in term meta, written by
        // Backend::update_item_order_by_group(). backend.php:54.
        $order = get_term_meta($term->term_id, '_link_order', true);
        $space_ids = [];

        if (is_string($order) && $order !== '') {
            foreach (explode(',', $order) as $piece) {
                $piece = trim($piece);

                if ($piece !== '' && ctype_digit($piece)) {
                    $space_ids[] = (int) $piece;
                }
            }
        }

        $rows[] = [
            'id' => $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'description' => $term->description,
            'position' => (int) get_term_meta($term->term_id, 'group_tax_position', true),
            'hide_label' => (bool) get_term_meta($term->term_id, 'hide_label', true),
            'space_count' => (int) $term->count,
            'space_ids' => array_values(array_unique($space_ids)),
        ];
    }

    $total = wp_count_terms(['taxonomy' => NIBWP_SD_GROUP_TAX, 'hide_empty' => false]);

    return [
        'groups' => $rows,
        'total' => is_wp_error($total) ? count($rows) : (int) $total,
        'returned' => count($rows),
        'skipped_unreadable' => 0,
        'limit' => $limit,
        'offset' => $offset,
        'ungrouped_space_ids' => nibwp_sd_ungrouped_space_ids(),
    ];
}

/**
 * Spaces that belong to no group — they sit under no sidebar heading at all.
 *
 * @return array<int, int>
 */
function nibwp_sd_ungrouped_space_ids(): array
{
    global $wpdb;

    $ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             WHERE p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft')
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->term_relationships} tr
                   INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                   WHERE tr.object_id = p.ID AND tt.taxonomy = %s
               )
             LIMIT %d",
            NIBWP_SD_SPACE_TYPE,
            NIBWP_SD_GROUP_TAX,
            NIBWP_SD_MAX_ROWS
        )
    );

    return array_map('intval', is_array($ids) ? $ids : []);
}

/**
 * Sidebar widgets: portal-wide, and per space when a space is named.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_list_widgets(array $input): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Reading SureDash sidebar widgets', 'nibwp'))) {
        return $denied;
    }

    $space_id = 0;

    if (array_key_exists('space_id', $input)) {
        $space_id = nibwp_sd_id($input, 'space_id');

        if ($space_id instanceof WP_Error) {
            return $space_id;
        }
    }


    $out = [
        'available_widgets' => nibwp_sd_widget_slugs(),
        'global' => nibwp_sd_global_widgets(),
    ];

    if ($space_id > 0) {
        $space = nibwp_sd_space($space_id);

        if ($space instanceof WP_Error) {
            return $space;
        }

        $out['space'] = ['id' => $space_id, 'title' => $space->post_title] + nibwp_sd_space_widgets($space_id);

        return $out;
    }

    // No space named: a bounded sweep of every space's widget count, which is
    // what "which spaces have no panels at all" needs.
    $limit = nibwp_sd_limit($input);
    $offset = nibwp_sd_offset($input);

    $query = new WP_Query([
        'post_type' => NIBWP_SD_SPACE_TYPE,
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => $limit,
        'offset' => $offset,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);

    $rows = [];
    $skipped = 0;

    foreach ($query->posts as $space) {
        if (!$space instanceof WP_Post) {
            continue;
        }

        if (!current_user_can('read_post', $space->ID)) {
            ++$skipped;
            continue;
        }

        $rows[] = ['id' => $space->ID, 'title' => $space->post_title] + nibwp_sd_space_widgets($space->ID);
    }

    $out['spaces'] = $rows;
    $out['total'] = (int) $query->found_posts;
    $out['returned'] = count($rows);
    $out['skipped_unreadable'] = $skipped;
    $out['limit'] = $limit;
    $out['offset'] = $offset;

    return $out;
}

/* ------------------------------------------------------------------ members */

/**
 * Contribution counts for one member — the two numbers the plugin's own members
 * screen shows. backend.php:2727.
 *
 * @return array<string, mixed>
 */
function nibwp_sd_member_activity(int $member_id): array
{
    global $wpdb;

    $posts = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_author = %d AND post_type = %s AND post_status IN ('publish', 'draft', 'pending')",
            $member_id,
            NIBWP_SD_POST_TYPE
        )
    );

    $comments = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE user_id = %d AND comment_approved = '1'",
            $member_id
        )
    );

    $last_post = (string) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT MAX(post_date_gmt) FROM {$wpdb->posts}
             WHERE post_author = %d AND post_type = %s AND post_status = 'publish'",
            $member_id,
            NIBWP_SD_POST_TYPE
        )
    );

    $last_comment = (string) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT MAX(comment_date_gmt) FROM {$wpdb->comments} WHERE user_id = %d AND comment_approved = '1'",
            $member_id
        )
    );

    // SureDash has no last-login record, so "active" means last wrote something.
    // community-insights.php:296 computes it the same way.
    $stamps = array_filter([strtotime($last_post) ?: 0, strtotime($last_comment) ?: 0]);
    $last = $stamps === [] ? 0 : max($stamps);

    return [
        'posts_count' => $posts,
        'comments_count' => $comments,
        'last_active' => $last > 0 ? gmdate('Y-m-d H:i:s', $last) : null,
        'inactive_days' => $last > 0 ? (int) floor((time() - $last) / DAY_IN_SECONDS) : null,
    ];
}

/**
 * The members of the portal.
 *
 * Rows the account may not edit are dropped, not redacted, and the count of
 * dropped rows travels with the answer: a silently short list reads as "we have
 * four members" to anyone who does not know to ask.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_list_members(array $input): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Listing SureDash members', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_sd_limit($input);
    $offset = nibwp_sd_offset($input);
    $role = nibwp_sd_role($input);

    $args = [
        'role' => $role,
        'number' => $limit,
        'offset' => $offset,
        'orderby' => 'registered',
        'order' => 'DESC',
        'count_total' => true,
        'fields' => 'all',
    ];

    if (isset($input['search']) && is_string($input['search']) && $input['search'] !== '') {
        $args['search'] = '*' . sanitize_text_field($input['search']) . '*';
        $args['search_columns'] = ['user_login', 'user_email', 'display_name'];
    }

    $query = new WP_User_Query($args);

    $rows = [];
    $skipped = 0;

    foreach ($query->get_results() as $user) {
        if (!$user instanceof WP_User) {
            continue;
        }

        // One named person is a higher bar than the roster, and the row below
        // carries their email address.
        if (!current_user_can('edit_user', $user->ID)) {
            ++$skipped;
            continue;
        }

        $rows[] = nibwp_sd_member_row([
            'id' => $user->ID,
            'username' => $user->user_login,
            'display_name' => $user->display_name,
            'email' => $user->user_email,
            'registered' => $user->user_registered,
            'roles' => $user->roles,
        ] + nibwp_sd_member_activity($user->ID));
    }

    return [
        'members' => $rows,
        'role' => $role,
        'total' => (int) $query->get_total(),
        'returned' => count($rows),
        'skipped_unreadable' => $skipped,
        'limit' => $limit,
        'offset' => $offset,
        'fields_returned' => nibwp_sd_member_fields(),
    ];
}

/**
 * One member.
 *
 * Order matters here. The id is checked on its TYPE first, so a malformed call
 * is told what is wrong before any authority is consulted. Then the hub's own
 * capability. Then edit_user on that person — and only then is the member looked
 * up, so "you may not read this one" and "there is no such one" are the same
 * answer and the refusal cannot be walked to enumerate user ids.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_get_member(array $input): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Reading a SureDash member', 'nibwp'))) {
        return $denied;
    }

    $member_id = nibwp_sd_id($input, 'member_id');
    if ($member_id instanceof WP_Error) {
        return $member_id;
    }


    if ($denied = nibwp_sd_member_gate($member_id, __('Reading one SureDash member', 'nibwp'))) {
        return $denied;
    }

    $user = get_userdata($member_id);

    if (!$user instanceof WP_User) {
        return nibwp_sd_err(
            'member_not_found',
            sprintf(
                /* translators: %d: member id */
                __('No member with id %d.', 'nibwp'),
                $member_id
            ),
            404
        );
    }

    $badges = get_user_meta($member_id, 'portal_badges', true);

    return [
        'member' => nibwp_sd_member_row([
            'id' => $user->ID,
            'username' => $user->user_login,
            'display_name' => $user->display_name,
            'email' => $user->user_email,
            'registered' => $user->user_registered,
            'roles' => $user->roles,
            'badges' => $badges,
        ] + nibwp_sd_member_activity($member_id)),
        'is_portal_manager' => user_can($user, NIBWP_SD_CAP),
        'fields_returned' => nibwp_sd_member_fields(),
    ];
}

/**
 * Members by the states an owner asks about.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_member_counts(array $input): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Counting SureDash members', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    $role = nibwp_sd_role($input);
    $days = nibwp_sd_days($input);
    $like = '%' . $wpdb->esc_like('"' . $role . '"') . '%';
    $cap_key = nibwp_sd_cap_meta_key();
    $since = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

    $total = nibwp_sd_role_count($role);

    $recent = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(DISTINCT u.ID)
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID
             WHERE um.meta_key = %s AND um.meta_value LIKE %s AND u.user_registered >= %s",
            $cap_key,
            $like,
            $since
        )
    );

    $posters = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(DISTINCT u.ID)
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID
             INNER JOIN {$wpdb->posts} p ON p.post_author = u.ID AND p.post_type = %s AND p.post_status = 'publish'
             WHERE um.meta_key = %s AND um.meta_value LIKE %s",
            NIBWP_SD_POST_TYPE,
            $cap_key,
            $like
        )
    );

    $commenters = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(DISTINCT u.ID)
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID
             INNER JOIN {$wpdb->comments} c ON c.user_id = u.ID AND c.comment_approved = '1'
             WHERE um.meta_key = %s AND um.meta_value LIKE %s",
            $cap_key,
            $like
        )
    );

    $silent = nibwp_sd_silent_member_count($role);

    return [
        'role' => $role,
        'window_days' => $days,
        'total' => $total,
        'registered_in_window' => $recent,
        'have_posted' => $posters,
        'have_commented' => $commenters,
        'never_posted_or_commented' => $silent,
        // There is no last-login record in this build, so this is as close as the
        // data gets to "signed up and never came back".
        'note' => __('SureDash records no sign-in, so dormancy is measured by posts and comments, not by logins.', 'nibwp'),
    ];
}

/** Members in a role who have never published a post nor had a comment approved. */
function nibwp_sd_silent_member_count(string $role): int
{
    global $wpdb;

    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(DISTINCT u.ID)
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID
             WHERE um.meta_key = %s AND um.meta_value LIKE %s
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->posts} p
                   WHERE p.post_author = u.ID AND p.post_type = %s AND p.post_status IN ('publish', 'pending', 'draft')
               )
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->comments} c WHERE c.user_id = u.ID AND c.comment_approved = '1'
               )",
            nibwp_sd_cap_meta_key(),
            '%' . $wpdb->esc_like('"' . $role . '"') . '%',
            NIBWP_SD_POST_TYPE
        )
    );
}

/**
 * What one member can actually reach.
 *
 * The question an owner asks when a member says "I paid and I cannot see the
 * course". Answered through SureDash's own access check (helper.php:2568) so the
 * answer matches what that member sees on the site.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_member_access(array $input): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Reading what a SureDash member can reach', 'nibwp'))) {
        return $denied;
    }

    $member_id = nibwp_sd_id($input, 'member_id');
    if ($member_id instanceof WP_Error) {
        return $member_id;
    }


    if ($denied = nibwp_sd_member_gate($member_id, __('Reading what one SureDash member can reach', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_sd_limit($input);
    $offset = nibwp_sd_offset($input);

    $query = new WP_Query([
        'post_type' => NIBWP_SD_SPACE_TYPE,
        'post_status' => 'publish',
        'posts_per_page' => $limit,
        'offset' => $offset,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);

    $reachable = [];
    $blocked = [];
    $unknown = 0;
    $skipped = 0;

    foreach ($query->posts as $space) {
        if (!$space instanceof WP_Post) {
            continue;
        }

        if (!current_user_can('read_post', $space->ID)) {
            ++$skipped;
            continue;
        }

        $can = nibwp_sd_can_member_see($space->ID, $member_id);

        if ($can === null) {
            ++$unknown;
            continue;
        }

        $row = ['id' => $space->ID, 'title' => $space->post_title];

        if ($can) {
            $reachable[] = $row;
        } else {
            $blocked[] = $row + ['access' => nibwp_sd_space_access($space->ID)];
        }
    }

    return [
        'member_id' => $member_id,
        'reachable_spaces' => $reachable,
        'blocked_spaces' => $blocked,
        'reachable_space_count' => count($reachable),
        'undetermined' => $unknown,
        'total' => (int) $query->found_posts,
        'returned' => count($reachable) + count($blocked),
        'skipped_unreadable' => $skipped,
        'limit' => $limit,
        'offset' => $offset,
        'access_groups' => nibwp_sd_access_groups(),
    ];
}

/* --------------------------------------------- member-written content, reads */

/** A bounded excerpt of something a member wrote. */
function nibwp_sd_excerpt(string $content, int $max = 400): string
{
    $plain = trim(wp_strip_all_tags($content));

    if (strlen($plain) <= $max) {
        return $plain;
    }

    return substr($plain, 0, $max) . '…';
}

/**
 * Posts members have written.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_list_posts(array $input): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Listing SureDash member posts', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_sd_limit($input);
    $offset = nibwp_sd_offset($input);
    $status = isset($input['status']) && is_string($input['status']) ? sanitize_key($input['status']) : 'any';

    $args = [
        'post_type' => NIBWP_SD_POST_TYPE,
        'post_status' => $status === 'any' ? ['publish', 'pending', 'draft', 'private'] : [$status],
        'posts_per_page' => $limit,
        'offset' => $offset,
        'orderby' => 'date',
        'order' => 'DESC',
    ];

    if (isset($input['search']) && is_string($input['search']) && $input['search'] !== '') {
        $args['s'] = sanitize_text_field($input['search']);
    }

    if (isset($input['author_id']) && is_numeric($input['author_id']) && (int) $input['author_id'] > 0) {
        $args['author'] = (int) $input['author_id'];
    }

    // A space's posts are the posts in that space's community-forum term.
    if (array_key_exists('space_id', $input)) {
        $space_id = nibwp_sd_id($input, 'space_id');

        if ($space_id instanceof WP_Error) {
            return $space_id;
        }

        $term = nibwp_sd_forum_term($space_id);

        if ($term < 1) {
            return nibwp_sd_err(
                'space_has_no_feed',
                sprintf(
                    /* translators: %d: space id */
                    __('Space %d has no feed, so it holds no member posts. Only a posts_discussion space does.', 'nibwp'),
                    $space_id
                ),
                422
            );
        }

        $args['tax_query'] = [[
            'taxonomy' => NIBWP_SD_FEED_TAX,
            'field' => 'term_id',
            'terms' => $term,
        ]];
    }

    $query = new WP_Query($args);

    $rows = [];
    $skipped = 0;

    foreach ($query->posts as $post) {
        if (!$post instanceof WP_Post) {
            continue;
        }

        if (!current_user_can('read_post', $post->ID)) {
            ++$skipped;
            continue;
        }

        $rows[] = [
            'id' => $post->ID,
            'title' => $post->post_title,
            'status' => $post->post_status,
            'author_id' => (int) $post->post_author,
            'author_name' => (string) get_the_author_meta('display_name', (int) $post->post_author),
            'created' => $post->post_date_gmt,
            'comment_count' => (int) $post->comment_count,
            'excerpt' => nibwp_sd_excerpt((string) $post->post_content),
        ];
    }

    return [
        'posts' => $rows,
        'total' => (int) $query->found_posts,
        'returned' => count($rows),
        'skipped_unreadable' => $skipped,
        'limit' => $limit,
        'offset' => $offset,
    ];
}

/**
 * One member post, with enough of it to judge.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_get_post(array $input): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Reading a SureDash member post', 'nibwp'))) {
        return $denied;
    }

    $post_id = nibwp_sd_id($input, 'post_id');
    if ($post_id instanceof WP_Error) {
        return $post_id;
    }


    $post = nibwp_sd_post($post_id);
    if ($post instanceof WP_Error) {
        return $post;
    }

    if (!current_user_can('read_post', $post_id)) {
        return nibwp_sd_err(
            'post_unreadable',
            __('This account may not read that post.', 'nibwp'),
            403
        );
    }

    $content = (string) $post->post_content;
    $spaces = wp_get_post_terms($post_id, NIBWP_SD_FEED_TAX, ['fields' => 'id=>name']);

    return [
        'post' => [
            'id' => $post_id,
            'type' => $post->post_type,
            'title' => $post->post_title,
            'status' => $post->post_status,
            'author_id' => (int) $post->post_author,
            'author_name' => (string) get_the_author_meta('display_name', (int) $post->post_author),
            'created' => $post->post_date_gmt,
            'modified' => $post->post_modified_gmt,
            'permalink' => (string) get_permalink($post_id),
            'comment_count' => (int) $post->comment_count,
            'content' => strlen($content) > 5000 ? substr($content, 0, 5000) . '…' : $content,
            'content_truncated' => strlen($content) > 5000,
            'content_length' => strlen($content),
        ],
        'feeds' => is_wp_error($spaces) ? [] : array_map('strval', $spaces),
    ];
}

/**
 * Comments members have left on each other's posts.
 *
 * These are public community comments on `community-post`, not private
 * correspondence — there is no private correspondence in this build. A bounded
 * excerpt travels because a moderator cannot judge a comment without reading it.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_list_comments(array $input): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Listing SureDash comments', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_sd_limit($input);
    $offset = nibwp_sd_offset($input);

    $status = isset($input['status']) && is_string($input['status']) ? sanitize_key($input['status']) : 'all';
    $map = ['approved' => 'approve', 'approve' => 'approve', 'pending' => 'hold', 'hold' => 'hold', 'spam' => 'spam', 'trash' => 'trash', 'all' => 'all'];

    $args = [
        'post_type' => NIBWP_SD_POST_TYPE,
        'status' => $map[$status] ?? 'all',
        'number' => $limit,
        'offset' => $offset,
        'orderby' => 'comment_date_gmt',
        'order' => 'DESC',
    ];

    if (array_key_exists('post_id', $input)) {
        $post_id = nibwp_sd_id($input, 'post_id');

        if ($post_id instanceof WP_Error) {
            return $post_id;
        }

        $args['post_id'] = $post_id;
    }

    $comments = get_comments($args);

    $rows = [];
    $skipped = 0;

    foreach (is_array($comments) ? $comments : [] as $comment) {
        if (!$comment instanceof WP_Comment) {
            continue;
        }

        // The post is what carries the reading permission; a comment on a post
        // this account cannot open does not travel.
        if (!current_user_can('read_post', (int) $comment->comment_post_ID)) {
            ++$skipped;
            continue;
        }

        $rows[] = [
            'id' => (int) $comment->comment_ID,
            'post_id' => (int) $comment->comment_post_ID,
            'post_title' => (string) get_the_title((int) $comment->comment_post_ID),
            'member_id' => (int) $comment->user_id,
            'author_name' => (string) $comment->comment_author,
            'created' => $comment->comment_date_gmt,
            'status' => $comment->comment_approved === '1' ? 'approved' : (string) $comment->comment_approved,
            'excerpt' => nibwp_sd_excerpt((string) $comment->comment_content),
        ];
    }

    return [
        'comments' => $rows,
        'status_filter' => $args['status'],
        'counts' => nibwp_sd_comment_counts(),
        'returned' => count($rows),
        'skipped_unreadable' => $skipped,
        'limit' => $limit,
        'offset' => $offset,
    ];
}

/**
 * The emails this portal sends, and who is told about new posts.
 *
 * Triggers live in their own option, one row per configured email
 * (email-notifications.php:129). The bodies are long owner-authored HTML, so
 * what travels is the subject, the roles it goes to and whether it is on — not
 * the body.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_email_notifications(): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Reading the SureDash email notifications', 'nibwp'))) {
        return $denied;
    }

    $configured = get_option('suredash_emails_triggers_data', []);
    $rows = [];

    foreach (is_array($configured) ? $configured : [] as $id => $config) {
        if (!is_array($config)) {
            continue;
        }

        $template = isset($config['template']) && is_array($config['template']) ? $config['template'] : [];
        $body = isset($template['body']) && is_string($template['body']) ? $template['body'] : '';

        $rows[] = [
            'config_id' => is_string($id) ? $id : (string) $id,
            'trigger' => isset($config['trigger_key']) && is_scalar($config['trigger_key']) ? (string) $config['trigger_key'] : '',
            'title' => isset($config['title']) && is_scalar($config['title']) ? (string) $config['title'] : '',
            'custom_title' => isset($config['custom_title']) && is_scalar($config['custom_title']) ? (string) $config['custom_title'] : '',
            'subject' => isset($template['subject']) && is_string($template['subject']) ? $template['subject'] : '',
            'body_length' => strlen($body),
            'enabled' => !empty($config['status']),
            'user_roles' => nibwp_sd_strings($config['user_roles'] ?? []),
            'created' => isset($config['created_at']) && is_scalar($config['created_at']) ? (string) $config['created_at'] : '',
        ];

        if (count($rows) >= NIBWP_SD_MAX_ROWS) {
            break;
        }
    }

    $available = [];

    if (class_exists('SureDashboard\\Inc\\Modules\\EmailNotifications\\Email_Notifications')) {
        try {
            $module = \SureDashboard\Inc\Modules\EmailNotifications\Email_Notifications::get_instance();

            if (is_object($module) && method_exists($module, 'get_available_email_triggers')) {
                $triggers = $module->get_available_email_triggers();

                foreach (is_array($triggers) ? $triggers : [] as $key => $definition) {
                    $available[] = [
                        'trigger' => (string) $key,
                        'title' => is_array($definition) && isset($definition['title']) && is_scalar($definition['title'])
                            ? (string) $definition['title']
                            : '',
                    ];
                }
            }
        } catch (\Throwable $error) {
            // A missing or renamed module is not a failure of this read.
            $available = [];
        }
    }

    $recipients = nibwp_sd_option('admin_notification_recipients', []);

    return [
        'configured_emails' => $rows,
        'configured_count' => count($rows),
        'enabled_count' => count(array_filter($rows, static fn (array $row): bool => (bool) $row['enabled'])),
        'available_triggers' => $available,
        'notify_admins_on_new_post' => (bool) nibwp_sd_option('notify_admins_on_new_post', false),
        'admin_recipient_count' => is_array($recipients) ? count($recipients) : 0,
        'from_address' => (string) nibwp_sd_option('email_from_mail_id', ''),
        // In-portal notifications are rendered live from posts and comments
        // (core/notifier/base.php:160) — there is no queue or table to report.
        'in_portal_notifications' => 'derived at render time from posts and comments; nothing stored to report',
    ];
}

/**
 * What an owner should look at.
 *
 * Every item here is something a person would act on: a space nobody writes in,
 * a space with no sidebar panels, a space that no sidebar heading contains, a
 * heading with nothing under it, posts and comments sitting unapproved, and
 * members who signed up and never said anything.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_community_audit(array $input): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Auditing the SureDash community', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_sd_limit($input, 50);
    $days = nibwp_sd_days($input);

    $query = new WP_Query([
        'post_type' => NIBWP_SD_SPACE_TYPE,
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => $limit,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);

    $empty_spaces = [];
    $widgetless = [];
    $single_voice = [];
    $locked_discussions = [];
    $skipped = 0;

    foreach ($query->posts as $space) {
        if (!$space instanceof WP_Post) {
            continue;
        }

        if (!current_user_can('read_post', $space->ID)) {
            ++$skipped;
            continue;
        }

        $type = (string) get_post_meta($space->ID, 'integration', true);
        $row = ['id' => $space->ID, 'title' => $space->post_title, 'type' => $type];

        $widgets = nibwp_sd_space_widgets($space->ID);
        $global = nibwp_sd_global_widgets();

        if ($widgets['count'] === 0 && (!$widgets['global_sidebar_enabled'] || $global['count'] === 0)) {
            $widgetless[] = $row;
        }

        if ($type !== 'posts_discussion') {
            continue;
        }

        $counts = nibwp_sd_space_post_counts($space->ID);

        if ($counts['total'] === 0) {
            $empty_spaces[] = $row;
        } else {
            $authors = nibwp_sd_space_author_count($space->ID);

            if ($authors === 1) {
                $single_voice[] = $row + ['post_count' => $counts['total'], 'distinct_authors' => 1];
            }
        }

        if (!get_post_meta($space->ID, 'allow_members_to_post', true)) {
            $locked_discussions[] = $row;
        }
    }

    $groups = get_terms(['taxonomy' => NIBWP_SD_GROUP_TAX, 'hide_empty' => false, 'number' => $limit]);
    $empty_groups = [];

    foreach (is_wp_error($groups) ? [] : $groups as $term) {
        if ($term instanceof WP_Term && (int) $term->count === 0) {
            $empty_groups[] = ['id' => $term->term_id, 'name' => $term->name];
        }
    }

    $post_statuses = nibwp_sd_status_counts(NIBWP_SD_POST_TYPE);
    $comments = nibwp_sd_comment_counts();
    $role = nibwp_sd_role($input);

    return [
        'window_days' => $days,
        'spaces_examined' => count($query->posts) - $skipped,
        'skipped_unreadable' => $skipped,
        'discussion_spaces_with_no_posts' => $empty_spaces,
        'spaces_with_no_sidebar_panels' => $widgetless,
        'discussion_spaces_with_one_voice' => $single_voice,
        'discussion_spaces_members_cannot_post_in' => $locked_discussions,
        'spaces_in_no_group' => nibwp_sd_ungrouped_space_ids(),
        'groups_with_no_spaces' => $empty_groups,
        'posts_awaiting_moderation' => $post_statuses['pending'] ?? 0,
        'posts_in_draft' => $post_statuses['draft'] ?? 0,
        'comments_awaiting_moderation' => $comments['pending'],
        'comments_marked_spam' => $comments['spam'],
        'members_never_posted_or_commented' => nibwp_sd_silent_member_count($role),
        'member_role_audited' => $role,
        'limit' => $limit,
        'note' => __('SureDash records no sign-in, so a member who never posted or commented is the closest this data comes to one who never came back.', 'nibwp'),
    ];
}

/** How many distinct members have written in a space. */
function nibwp_sd_space_author_count(int $space_id): int
{
    $term = nibwp_sd_forum_term($space_id);

    if ($term < 1) {
        return 0;
    }

    global $wpdb;

    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.post_author)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE p.post_type = %s AND p.post_status = 'publish' AND tt.taxonomy = %s AND tt.term_id = %d",
            NIBWP_SD_POST_TYPE,
            NIBWP_SD_FEED_TAX,
            $term
        )
    );
}

/* ------------------------------------------------------------------- writes */

/** SureDash's own backend router, when it is there to be called. */
function nibwp_sd_backend(): ?object
{
    if (!class_exists('SureDashboard\\Core\\Routers\\Backend')) {
        return null;
    }

    try {
        $backend = \SureDashboard\Core\Routers\Backend::get_instance();
    } catch (\Throwable $error) {
        return null;
    }

    return is_object($backend) ? $backend : null;
}

/** A non-empty title from input. */
function nibwp_sd_title(array $input, bool $required): string|WP_Error|null
{
    if (!array_key_exists('title', $input)) {
        if ($required) {
            return nibwp_sd_err('title_required', __('"title" is required.', 'nibwp'), 422);
        }

        return null;
    }

    if (!is_string($input['title'])) {
        return nibwp_sd_err(
            'bad_title',
            sprintf(
                /* translators: %s: the type that arrived */
                __('"title" must be a string; a %s arrived.', 'nibwp'),
                get_debug_type($input['title'])
            ),
            422
        );
    }

    $title = trim(sanitize_text_field($input['title']));

    if ($title === '') {
        return nibwp_sd_err('bad_title', __('"title" may not be empty.', 'nibwp'), 422);
    }

    return $title;
}

/** The post status a write may set. */
function nibwp_sd_status(array $input, string $fallback): string|WP_Error
{
    if (!isset($input['status'])) {
        return $fallback;
    }

    if (!is_string($input['status'])) {
        return nibwp_sd_err('bad_status', __('"status" must be a string.', 'nibwp'), 422);
    }

    $status = sanitize_key($input['status']);
    $allowed = ['publish', 'draft', 'pending', 'private'];

    if (!in_array($status, $allowed, true)) {
        return nibwp_sd_err(
            'bad_status',
            sprintf(
                /* translators: 1: the rejected status, 2: the allowed statuses */
                __('"%1$s" is not a status a space may have. Valid: %2$s.', 'nibwp'),
                $status,
                implode(', ', $allowed)
            ),
            422
        );
    }

    return $status;
}

/**
 * Create a space.
 *
 * Follows the plugin's own path (backend.php:251): a `portal` post, a group term
 * assigned, the group's ordered space list appended to, the space meta written,
 * and — for a discussion space — a `community-forum` term created so members
 * have somewhere to post. A group is required, exactly as the plugin requires
 * one; a space in no group appears under no sidebar heading.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_create_space(array $input): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Creating a SureDash space', 'nibwp'))) {
        return $denied;
    }

    $group_id = nibwp_sd_id($input, 'group_id');
    if ($group_id instanceof WP_Error) {
        return $group_id;
    }

    $title = nibwp_sd_title($input, true);
    if ($title instanceof WP_Error) {
        return $title;
    }

    $status = nibwp_sd_status($input, 'draft');
    if ($status instanceof WP_Error) {
        return $status;
    }


    $type = isset($input['space_type']) && is_string($input['space_type'])
        ? sanitize_key($input['space_type'])
        : 'posts_discussion';

    // ability.php:31 lists the four space types that need SureDash Pro. The
    // remaining non-discussion types each need a target — a URL, a WordPress
    // post, a built-in portal page — that this hub does not take, and a space
    // created without one is a broken sidebar entry. So one type is creatable
    // here, and the refusal says which screen does the rest.
    $pro = ['course', 'resource_library', 'collection', 'events'];

    if (in_array($type, $pro, true) && !defined('SUREDASH_PRO_VER')) {
        return nibwp_sd_err(
            'needs_pro',
            sprintf(
                /* translators: %s: space type */
                __('A "%s" space needs SureDash Pro, which is not active on this site.', 'nibwp'),
                $type
            ),
            422
        );
    }

    if ($type !== 'posts_discussion') {
        return nibwp_sd_err(
            'space_type_unsupported',
            sprintf(
                /* translators: %s: space type */
                __('Only a posts_discussion space can be created from here; "%s" needs a target (a URL, a post or a portal page) that this hub does not take. Create it on the portal\'s own Spaces screen.', 'nibwp'),
                $type
            ),
            422
        );
    }

    $term = get_term($group_id, NIBWP_SD_GROUP_TAX);

    if (!$term instanceof WP_Term) {
        return nibwp_sd_err(
            'group_not_found',
            sprintf(
                /* translators: %d: group id */
                __('No SureDash space group with id %d. Use list_groups.', 'nibwp'),
                $group_id
            ),
            404
        );
    }

    $space_id = wp_insert_post([
        'post_title' => $title,
        'post_name' => sanitize_title($title),
        'post_type' => NIBWP_SD_SPACE_TYPE,
        'post_status' => $status,
        'post_author' => get_current_user_id(),
    ], true);

    if (is_wp_error($space_id)) {
        return nibwp_sd_err('create_failed', $space_id->get_error_message(), 500);
    }

    $space_id = (int) $space_id;

    // wp_set_post_terms rather than tax_input: the portal_group taxonomy gates
    // assignment on its own capability, which an account with the portal
    // capability does not necessarily hold. backend.php:322 does the same.
    wp_set_post_terms($space_id, [$group_id], NIBWP_SD_GROUP_TAX);

    // The group's ordered space list is a CSV in term meta. backend.php:446.
    $order = get_term_meta($group_id, '_link_order', true);
    $order = is_string($order) && $order !== '' ? $order . ',' . $space_id : (string) $space_id;
    update_term_meta($group_id, '_link_order', $order);

    update_post_meta($space_id, 'integration', $type);
    update_post_meta($space_id, 'item_emoji', isset($input['emoji']) && is_string($input['emoji']) ? sanitize_text_field($input['emoji']) : 'MessageCircle');
    update_post_meta($space_id, 'space_description', isset($input['description']) && is_string($input['description']) ? sanitize_textarea_field($input['description']) : '');
    update_post_meta($space_id, 'allow_members_to_post', !empty($input['members_may_post']));

    // A discussion space needs a forum term or members have nowhere to post.
    $forum_id = 0;
    $backend = nibwp_sd_backend();

    if ($backend !== null && method_exists($backend, 'create_forum_category')) {
        $forum_id = (int) $backend->create_forum_category($space_id, $title);
    } else {
        $created = wp_insert_term($title, NIBWP_SD_FEED_TAX);

        if (!is_wp_error($created) && isset($created['term_id'])) {
            $forum_id = (int) $created['term_id'];
            update_term_meta($forum_id, 'space_id', $space_id);
        }
    }

    if ($forum_id > 0) {
        update_post_meta($space_id, 'feed_group_id', $forum_id);
    }

    return [
        'created' => true,
        'space_id' => $space_id,
        'title' => $title,
        'status' => $status,
        'type' => $type,
        'group_id' => $group_id,
        'group_name' => $term->name,
        'forum_term_id' => $forum_id,
        'permalink' => (string) get_permalink($space_id),
        'warning' => $forum_id > 0
            ? null
            : __('The space was created but its feed term could not be, so members cannot post in it yet.', 'nibwp'),
    ];
}

/**
 * Change a space.
 *
 * Only the allow-listed display switches can be written (nibwp_sd_space_writable).
 * An unrecognised key in `settings` is reported back rather than written: a typo
 * that silently became a new meta key would read as success and change nothing.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_update_space(array $input): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Changing a SureDash space', 'nibwp'))) {
        return $denied;
    }

    $space_id = nibwp_sd_id($input, 'space_id');
    if ($space_id instanceof WP_Error) {
        return $space_id;
    }

    $title = nibwp_sd_title($input, false);
    if ($title instanceof WP_Error) {
        return $title;
    }

    $status = array_key_exists('status', $input) ? nibwp_sd_status($input, 'draft') : null;
    if ($status instanceof WP_Error) {
        return $status;
    }


    $space = nibwp_sd_space($space_id);
    if ($space instanceof WP_Error) {
        return $space;
    }

    $changed = [];
    $post_update = ['ID' => $space_id];

    if ($title !== null && $title !== $space->post_title) {
        $post_update['post_title'] = $title;
        $changed['title'] = ['from' => $space->post_title, 'to' => $title];
    }

    if ($status !== null && $status !== $space->post_status) {
        $post_update['post_status'] = $status;
        $changed['status'] = ['from' => $space->post_status, 'to' => $status];
    }

    if (count($post_update) > 1) {
        $updated = wp_update_post($post_update, true);

        if (is_wp_error($updated)) {
            return nibwp_sd_err('update_failed', $updated->get_error_message(), 500);
        }
    }

    $settings = isset($input['settings']) && is_array($input['settings']) ? $input['settings'] : [];

    if (isset($input['description']) && is_string($input['description'])) {
        $settings['space_description'] = $input['description'];
    }

    if (isset($input['emoji']) && is_string($input['emoji'])) {
        $settings['item_emoji'] = $input['emoji'];
    }

    if (array_key_exists('members_may_post', $input)) {
        $settings['allow_members_to_post'] = $input['members_may_post'];
    }

    $writable = nibwp_sd_space_writable();
    $refused = [];

    foreach ($settings as $key => $value) {
        if (!is_string($key) || !array_key_exists($key, $writable)) {
            $refused[] = is_string($key) ? $key : get_debug_type($key);
            continue;
        }

        if (is_array($value) || is_object($value)) {
            $refused[] = $key;
            continue;
        }

        $before = get_post_meta($space_id, $key, true);
        $after = $writable[$key] === 'bool' ? (bool) $value : sanitize_textarea_field((string) $value);

        update_post_meta($space_id, $key, $after);
        $changed[$key] = ['from' => is_scalar($before) ? $before : null, 'to' => $after];
    }

    return [
        'updated' => $changed !== [],
        'space_id' => $space_id,
        'changed' => $changed,
        'refused_keys' => array_values(array_unique($refused)),
        'writable_keys' => array_keys($writable),
    ];
}

/**
 * Replace a space's access scope.
 *
 * THIS CHANGES WHAT PAYING MEMBERS CAN REACH. The scope is replaced whole, the
 * way the plugin stores it (helper.php:2597), so an entry left out of the new
 * list is an entry revoked — and an empty list opens the space to everyone. The
 * before and after both come back so the caller can say out loud what moved.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_set_space_access(array $input): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Changing what a SureDash space is open to', 'nibwp'))) {
        return $denied;
    }

    $space_id = nibwp_sd_id($input, 'space_id');
    if ($space_id instanceof WP_Error) {
        return $space_id;
    }

    if (!array_key_exists('access', $input) || !is_array($input['access'])) {
        return nibwp_sd_err(
            'bad_access',
            __('"access" must be an array of "user-<id>" and "ag-<accessGroupId>" strings. An empty array opens the space to everyone.', 'nibwp'),
            422
        );
    }


    $space = nibwp_sd_space($space_id);
    if ($space instanceof WP_Error) {
        return $space;
    }

    $scope = [];
    $rejected = [];

    foreach ($input['access'] as $entry) {
        if (!is_string($entry)) {
            $rejected[] = get_debug_type($entry);
            continue;
        }

        $entry = trim($entry);

        if (preg_match('/^(user|ag)-[1-9][0-9]*$/', $entry) !== 1) {
            $rejected[] = $entry;
            continue;
        }

        $scope[] = $entry;
    }

    if ($rejected !== []) {
        return nibwp_sd_err(
            'bad_access',
            sprintf(
                /* translators: %s: the rejected entries */
                __('These access entries are not "user-<id>" or "ag-<id>": %s. Nothing was changed.', 'nibwp'),
                implode(', ', array_slice($rejected, 0, 10))
            ),
            422
        );
    }

    $scope = array_values(array_unique($scope));
    $before = nibwp_sd_space_access($space_id);
    $previous = get_post_meta($space_id, 'visibility_scope', true);
    $previous = is_array($previous) ? array_values(array_filter($previous, 'is_string')) : [];

    if ($scope === []) {
        delete_post_meta($space_id, 'visibility_scope');
    } else {
        update_post_meta($space_id, 'visibility_scope', $scope);
    }

    $after = nibwp_sd_space_access($space_id);

    return [
        'space_id' => $space_id,
        'space_title' => $space->post_title,
        'access_before' => $before,
        'access_after' => $after,
        'entries_added' => array_values(array_diff($scope, $previous)),
        'entries_removed' => array_values(array_diff($previous, $scope)),
        'now_open_to_everyone' => $after['open_to_everyone'],
        'warning' => $after['open_to_everyone'] && !$before['open_to_everyone']
            ? __('This space is now open to everyone who can see the portal. It was restricted before this call.', 'nibwp')
            : null,
    ];
}

/**
 * Create a sidebar group.
 *
 * Through the plugin's own Backend::create_portal_group() (backend.php:194) so
 * the term meta it expects is written, plus the display position that method
 * leaves unset — without it the new group sorts as position 0 and jumps to the
 * top of the sidebar.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_create_group(array $input): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Creating a SureDash space group', 'nibwp'))) {
        return $denied;
    }

    $title = nibwp_sd_title($input, true);
    if ($title instanceof WP_Error) {
        return $title;
    }


    $hide_label = !empty($input['hide_label']) ? 1 : 0;
    $backend = nibwp_sd_backend();
    $group_id = 0;

    if ($backend !== null && method_exists($backend, 'create_portal_group')) {
        $group_id = (int) $backend->create_portal_group($title, $hide_label, []);
    } else {
        $existing = term_exists($title, NIBWP_SD_GROUP_TAX);

        if (is_array($existing) && isset($existing['term_id'])) {
            $group_id = (int) $existing['term_id'];
        } else {
            $created = wp_insert_term($title, NIBWP_SD_GROUP_TAX);

            if (is_wp_error($created)) {
                return nibwp_sd_err('create_failed', $created->get_error_message(), 500);
            }

            $group_id = (int) ($created['term_id'] ?? 0);
            update_term_meta($group_id, 'hide_label', $hide_label);
            update_term_meta($group_id, 'homegrid_spaces', []);
        }
    }

    if ($group_id < 1) {
        return nibwp_sd_err('create_failed', __('SureDash did not create the group.', 'nibwp'), 500);
    }

    if (get_term_meta($group_id, 'group_tax_position', true) === '') {
        update_term_meta($group_id, 'group_tax_position', nibwp_sd_next_group_position());
    }

    return [
        'created' => true,
        'group_id' => $group_id,
        'name' => $title,
        'hide_label' => (bool) $hide_label,
        'position' => (int) get_term_meta($group_id, 'group_tax_position', true),
    ];
}

/** One past the last group's position. */
function nibwp_sd_next_group_position(): int
{
    $terms = get_terms(['taxonomy' => NIBWP_SD_GROUP_TAX, 'hide_empty' => false, 'fields' => 'ids']);

    if (is_wp_error($terms)) {
        return 0;
    }

    $max = -1;

    foreach ($terms as $term_id) {
        $position = (int) get_term_meta((int) $term_id, 'group_tax_position', true);
        $max = max($max, $position);
    }

    return $max + 1;
}

/**
 * Rename a sidebar group, or hide its heading.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_update_group(array $input): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Changing a SureDash space group', 'nibwp'))) {
        return $denied;
    }

    $group_id = nibwp_sd_id($input, 'group_id');
    if ($group_id instanceof WP_Error) {
        return $group_id;
    }

    $title = nibwp_sd_title($input, false);
    if ($title instanceof WP_Error) {
        return $title;
    }


    $term = get_term($group_id, NIBWP_SD_GROUP_TAX);

    if (!$term instanceof WP_Term) {
        return nibwp_sd_err(
            'group_not_found',
            sprintf(
                /* translators: %d: group id */
                __('No SureDash space group with id %d.', 'nibwp'),
                $group_id
            ),
            404
        );
    }

    $changed = [];
    $name = $title ?? $term->name;
    $hide_label = array_key_exists('hide_label', $input)
        ? (!empty($input['hide_label']) ? 1 : 0)
        : ((bool) get_term_meta($group_id, 'hide_label', true) ? 1 : 0);

    if ($title !== null && $title !== $term->name) {
        $changed['name'] = ['from' => $term->name, 'to' => $title];
    }

    $before_hide = (bool) get_term_meta($group_id, 'hide_label', true);

    if ((bool) $hide_label !== $before_hide) {
        $changed['hide_label'] = ['from' => $before_hide, 'to' => (bool) $hide_label];
    }

    $backend = nibwp_sd_backend();

    if ($backend !== null && method_exists($backend, 'update_portal_group')) {
        $homegrid = get_term_meta($group_id, 'homegrid_spaces', true);
        $result = (int) $backend->update_portal_group($group_id, $name, $hide_label, is_array($homegrid) ? $homegrid : []);

        if ($result < 1) {
            return nibwp_sd_err('update_failed', __('SureDash did not update the group.', 'nibwp'), 500);
        }
    } else {
        $updated = wp_update_term($group_id, NIBWP_SD_GROUP_TAX, ['name' => $name, 'slug' => sanitize_title($name)]);

        if (is_wp_error($updated)) {
            return nibwp_sd_err('update_failed', $updated->get_error_message(), 500);
        }

        update_term_meta($group_id, 'hide_label', $hide_label);
    }

    return [
        'updated' => $changed !== [],
        'group_id' => $group_id,
        'name' => $name,
        'changed' => $changed,
    ];
}

/**
 * Put the sidebar headings in a given order.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_reorder_groups(array $input): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Reordering the SureDash space groups', 'nibwp'))) {
        return $denied;
    }

    $order = nibwp_sd_int_list($input, 'order');
    if ($order instanceof WP_Error) {
        return $order;
    }


    $missing = [];

    foreach ($order as $group_id) {
        if (!get_term($group_id, NIBWP_SD_GROUP_TAX) instanceof WP_Term) {
            $missing[] = $group_id;
        }
    }

    if ($missing !== []) {
        return nibwp_sd_err(
            'group_not_found',
            sprintf(
                /* translators: %s: the ids that are not groups */
                __('These are not SureDash space groups: %s. Nothing was reordered.', 'nibwp'),
                implode(', ', $missing)
            ),
            404
        );
    }

    $applied = [];

    foreach ($order as $position => $group_id) {
        update_term_meta($group_id, 'group_tax_position', $position);
        $applied[] = ['group_id' => $group_id, 'position' => $position];
    }

    nibwp_sd_clear_first_space_cache();

    return [
        'reordered' => true,
        'groups' => $applied,
        'count' => count($applied),
        'note' => __('Groups not named in the order keep their stored position and may now sort among these.', 'nibwp'),
    ];
}

/**
 * Put the spaces inside one sidebar heading in a given order.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_reorder_spaces(array $input): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Reordering the spaces in a SureDash group', 'nibwp'))) {
        return $denied;
    }

    $group_id = nibwp_sd_id($input, 'group_id');
    if ($group_id instanceof WP_Error) {
        return $group_id;
    }

    $order = nibwp_sd_int_list($input, 'order');
    if ($order instanceof WP_Error) {
        return $order;
    }


    if (!get_term($group_id, NIBWP_SD_GROUP_TAX) instanceof WP_Term) {
        return nibwp_sd_err(
            'group_not_found',
            sprintf(
                /* translators: %d: group id */
                __('No SureDash space group with id %d.', 'nibwp'),
                $group_id
            ),
            404
        );
    }

    $wrong = [];

    foreach ($order as $space_id) {
        $space = get_post($space_id);

        if (!$space instanceof WP_Post || $space->post_type !== NIBWP_SD_SPACE_TYPE) {
            $wrong[] = $space_id;
            continue;
        }

        if (!has_term($group_id, NIBWP_SD_GROUP_TAX, $space_id)) {
            $wrong[] = $space_id;
        }
    }

    if ($wrong !== []) {
        return nibwp_sd_err(
            'space_not_in_group',
            sprintf(
                /* translators: 1: the offending ids, 2: group id */
                __('These are not spaces in group %2$d: %1$s. Nothing was reordered.', 'nibwp'),
                implode(', ', $wrong),
                $group_id
            ),
            422
        );
    }

    $before = (string) get_term_meta($group_id, '_link_order', true);
    update_term_meta($group_id, '_link_order', implode(',', $order));
    nibwp_sd_clear_first_space_cache();

    return [
        'reordered' => true,
        'group_id' => $group_id,
        'order_before' => $before,
        'order_after' => implode(',', $order),
        'count' => count($order),
    ];
}

/**
 * A list of positive integers from input, or a refusal on the type.
 *
 * @return array<int, int>|WP_Error
 */
function nibwp_sd_int_list(array $input, string $key): array|WP_Error
{
    if (!array_key_exists($key, $input) || !is_array($input[$key]) || $input[$key] === []) {
        return nibwp_sd_err(
            'bad_order',
            sprintf(
                /* translators: %s: parameter name */
                __('"%s" must be a non-empty array of positive integers.', 'nibwp'),
                $key
            ),
            422
        );
    }

    $out = [];

    foreach ($input[$key] as $item) {
        $ok = is_int($item) || (is_string($item) && $item !== '' && ctype_digit($item));

        if (!$ok || (int) $item < 1) {
            return nibwp_sd_err(
                'bad_order',
                sprintf(
                    /* translators: 1: parameter name, 2: the type that arrived */
                    __('Every entry in "%1$s" must be a positive integer; a %2$s arrived.', 'nibwp'),
                    $key,
                    get_debug_type($item)
                ),
                422
            );
        }

        $out[] = (int) $item;
    }

    return array_values(array_unique($out));
}

/** The plugin caches which space is first in the sidebar; a reorder invalidates it. */
function nibwp_sd_clear_first_space_cache(): void
{
    $backend = nibwp_sd_backend();

    if ($backend !== null && method_exists($backend, 'clear_first_space_option')) {
        try {
            $backend->clear_first_space_option();
        } catch (\Throwable $error) {
            // A stale cached first space is cosmetic; it is not worth failing on.
        }
    }
}

/**
 * Set the sidebar panels on one space.
 *
 * The widget list is replaced whole, in the order given, in the shape the
 * plugin's own template reads (sidebar-widgets.php:48 sorts on `order` and
 * switches on `slug`). An unknown slug is refused rather than stored: the
 * renderer would silently draw nothing for it.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_set_space_widgets(array $input): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Setting the sidebar panels on a SureDash space', 'nibwp'))) {
        return $denied;
    }

    $space_id = nibwp_sd_id($input, 'space_id');
    if ($space_id instanceof WP_Error) {
        return $space_id;
    }

    if (!array_key_exists('widgets', $input) || !is_array($input['widgets'])) {
        return nibwp_sd_err(
            'bad_widgets',
            sprintf(
                /* translators: %s: the valid widget slugs */
                __('"widgets" must be an array of widget slugs, in order. Valid: %s. An empty array removes every panel from this space.', 'nibwp'),
                implode(', ', nibwp_sd_widget_slugs())
            ),
            422
        );
    }


    $space = nibwp_sd_space($space_id);
    if ($space instanceof WP_Error) {
        return $space;
    }

    $valid = nibwp_sd_widget_slugs();
    $slugs = [];
    $unknown = [];

    foreach ($input['widgets'] as $slug) {
        if (!is_string($slug)) {
            $unknown[] = get_debug_type($slug);
            continue;
        }

        $slug = sanitize_key($slug);

        if (!in_array($slug, $valid, true)) {
            $unknown[] = $slug;
            continue;
        }

        $slugs[] = $slug;
    }

    if ($unknown !== []) {
        return nibwp_sd_err(
            'bad_widgets',
            sprintf(
                /* translators: 1: the rejected slugs, 2: the valid slugs */
                __('These are not SureDash sidebar widgets: %1$s. Valid: %2$s. Nothing was changed.', 'nibwp'),
                implode(', ', array_slice($unknown, 0, 10)),
                implode(', ', $valid)
            ),
            422
        );
    }

    $before = nibwp_sd_space_widgets($space_id);
    $existing = get_post_meta($space_id, 'space_sidebar_widgets', true);
    $existing = is_array($existing) ? $existing : [];

    $widgets = [];

    foreach (array_values($slugs) as $position => $slug) {
        $widgets[] = ['slug' => $slug, 'order' => $position, 'settings' => []];
    }

    $use_global = array_key_exists('use_global_sidebar', $input)
        ? (bool) $input['use_global_sidebar']
        : $before['global_sidebar_enabled'];

    update_post_meta($space_id, 'space_sidebar_widgets', [
        'global_sidebar_enabled' => $use_global,
        'widgets' => $widgets,
    ]);

    $after = nibwp_sd_space_widgets($space_id);

    return [
        'updated' => true,
        'space_id' => $space_id,
        'space_title' => $space->post_title,
        'widgets_before' => $before,
        'widgets_after' => $after,
        'global_sidebar_enabled' => $use_global,
        'settings_discarded' => $existing !== [] && $before['count'] > 0,
        'note' => $before['count'] > 0
            ? __('Any per-panel settings the previous panels carried were replaced along with the list.', 'nibwp')
            : null,
    ];
}

/* --------------------------------------------------------------- moderation */

/**
 * A refusal about one piece of somebody's writing.
 *
 * Its own code, not the hub's: an owner reading the transcript should be able to
 * tell "this account cannot moderate at all" from "this account cannot touch
 * that particular post".
 */
function nibwp_sd_content_forbidden(string $what, string $capability): WP_Error
{
    return new WP_Error(
        'nibwp_sd_content_forbidden',
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name */
            __('%1$s needs %2$s on that item, which this account does not have.', 'nibwp'),
            $what,
            $capability
        ),
        ['status' => 403]
    );
}

/**
 * Publish, unpublish, hold or bin a post a member wrote.
 *
 * THIS IS SOMEBODY'S WRITING. trash is reversible — restore brings it back —
 * and is the one to prefer; delete_post is the permanent one and lives
 * separately so it cannot be reached by a typo in `operation`.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_moderate_post(array $input): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Moderating a SureDash member post', 'nibwp'))) {
        return $denied;
    }

    $post_id = nibwp_sd_id($input, 'post_id');
    if ($post_id instanceof WP_Error) {
        return $post_id;
    }

    $allowed = ['publish', 'draft', 'pending', 'trash', 'restore'];
    $operation = isset($input['operation']) && is_string($input['operation']) ? sanitize_key($input['operation']) : '';

    if (!in_array($operation, $allowed, true)) {
        return nibwp_sd_err(
            'bad_operation',
            sprintf(
                /* translators: 1: the rejected operation, 2: the allowed operations */
                __('"%1$s" is not a moderation operation. Valid: %2$s. Permanent deletion is delete_post, deliberately not one of these.', 'nibwp'),
                $operation,
                implode(', ', $allowed)
            ),
            422
        );
    }


    $post = nibwp_sd_post($post_id);
    if ($post instanceof WP_Error) {
        return $post;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return nibwp_sd_content_forbidden(__('Moderating this post', 'nibwp'), 'edit_post');
    }

    $before = $post->post_status;

    // handle_content_action() is the plugin's own moderation path
    // (backend.php:1399) and answers with a message array rather than ending the
    // request, so it is safe to call from in here. `pending` is not one of its
    // branches, so that one case is a plain status update.
    $backend = nibwp_sd_backend();
    $message = '';

    if ($operation === 'pending') {
        $updated = wp_update_post(['ID' => $post_id, 'post_status' => 'pending'], true);

        if (is_wp_error($updated)) {
            return nibwp_sd_err('moderate_failed', $updated->get_error_message(), 500);
        }
    } elseif ($backend !== null && method_exists($backend, 'handle_content_action')) {
        $result = $backend->handle_content_action($operation, $post_id, $post, $post->post_type);

        if (is_array($result) && empty($result['updated'])) {
            return nibwp_sd_err(
                'moderate_failed',
                isset($result['error_message']) && is_string($result['error_message']) && $result['error_message'] !== ''
                    ? $result['error_message']
                    : __('SureDash refused the moderation.', 'nibwp'),
                500
            );
        }

        $message = is_array($result) && isset($result['success_message']) && is_string($result['success_message'])
            ? $result['success_message']
            : '';
    } else {
        $fallback = match ($operation) {
            'trash' => wp_trash_post($post_id),
            'restore' => wp_untrash_post($post_id),
            default => wp_update_post(['ID' => $post_id, 'post_status' => $operation], true),
        };

        if (is_wp_error($fallback) || $fallback === false || $fallback === null) {
            return nibwp_sd_err('moderate_failed', __('The post status could not be changed.', 'nibwp'), 500);
        }
    }

    $after = get_post_status($post_id);

    return [
        'moderated' => true,
        'post_id' => $post_id,
        'post_title' => $post->post_title,
        'author_id' => (int) $post->post_author,
        'operation' => $operation,
        'status_before' => $before,
        'status_after' => is_string($after) ? $after : null,
        // Every operation here is undoable; delete_post is the one that is not.
        'reversible' => true,
        'plugin_message' => $message,
        'warning' => $operation === 'trash'
            ? __('A member\'s post is now in the bin and has disappeared from their feed. moderate_post with operation: restore puts it back.', 'nibwp')
            : null,
    ];
}

/**
 * Delete a member's post permanently.
 *
 * There is no undo. It takes confirm: true as well as the capability, so a model
 * cannot reach it by guessing an `operation` value, and it reports what it
 * destroyed — title and author — because after the call there is nothing left to
 * look up.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_delete_post(array $input): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Deleting a SureDash member post', 'nibwp'))) {
        return $denied;
    }

    $post_id = nibwp_sd_id($input, 'post_id');
    if ($post_id instanceof WP_Error) {
        return $post_id;
    }

    if (empty($input['confirm'])) {
        return nibwp_sd_err(
            'needs_confirm',
            __('Permanently deleting a member\'s post needs confirm: true. There is no undo. moderate_post with operation: trash is the reversible way to take it out of view.', 'nibwp'),
            422
        );
    }


    $post = nibwp_sd_post($post_id);
    if ($post instanceof WP_Error) {
        return $post;
    }

    if (!current_user_can('delete_post', $post_id)) {
        return nibwp_sd_content_forbidden(__('Deleting this post', 'nibwp'), 'delete_post');
    }

    // Read everything worth reporting BEFORE it stops existing.
    $record = [
        'post_id' => $post_id,
        'post_title' => $post->post_title,
        'author_id' => (int) $post->post_author,
        'author_name' => (string) get_the_author_meta('display_name', (int) $post->post_author),
        'created' => $post->post_date_gmt,
        'status_before' => $post->post_status,
        'comment_count' => (int) $post->comment_count,
    ];

    $deleted = wp_delete_post($post_id, true);

    if ($deleted === false || $deleted === null) {
        return nibwp_sd_err('delete_failed', __('SureDash did not delete the post.', 'nibwp'), 500);
    }

    return $record + [
        'deleted' => true,
        'permanent' => true,
        'warning' => __('This post and its comments are gone for good. Tell the user what was deleted and who wrote it.', 'nibwp'),
    ];
}

/**
 * Approve, hold, spam or bin a comment a member left.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sd_moderate_comment(array $input): array|WP_Error
{
    if ($denied = nibwp_sd_require(__('Moderating a SureDash comment', 'nibwp'))) {
        return $denied;
    }

    $comment_id = nibwp_sd_id($input, 'comment_id');
    if ($comment_id instanceof WP_Error) {
        return $comment_id;
    }

    $allowed = ['approve', 'unapprove', 'spam', 'unspam', 'trash', 'untrash'];
    $operation = isset($input['operation']) && is_string($input['operation']) ? sanitize_key($input['operation']) : '';

    if (!in_array($operation, $allowed, true)) {
        return nibwp_sd_err(
            'bad_operation',
            sprintf(
                /* translators: 1: the rejected operation, 2: the allowed operations */
                __('"%1$s" is not a comment moderation operation. Valid: %2$s.', 'nibwp'),
                $operation,
                implode(', ', $allowed)
            ),
            422
        );
    }


    $comment = get_comment($comment_id);

    if (!$comment instanceof WP_Comment) {
        return nibwp_sd_err(
            'comment_not_found',
            sprintf(
                /* translators: %d: comment id */
                __('No comment with id %d.', 'nibwp'),
                $comment_id
            ),
            404
        );
    }

    if (!current_user_can('edit_comment', $comment_id)) {
        return nibwp_sd_content_forbidden(__('Moderating this comment', 'nibwp'), 'edit_comment');
    }

    $before = $comment->comment_approved === '1' ? 'approved' : (string) $comment->comment_approved;

    $done = match ($operation) {
        'approve' => wp_set_comment_status($comment_id, 'approve'),
        'unapprove' => wp_set_comment_status($comment_id, 'hold'),
        'spam' => wp_spam_comment($comment_id),
        'unspam' => wp_unspam_comment($comment_id),
        'trash' => wp_trash_comment($comment_id),
        'untrash' => wp_untrash_comment($comment_id),
        default => false,
    };

    if ($done === false) {
        return nibwp_sd_err('moderate_failed', __('The comment status could not be changed.', 'nibwp'), 500);
    }

    $after = get_comment($comment_id);

    return [
        'moderated' => true,
        'comment_id' => $comment_id,
        'post_id' => (int) $comment->comment_post_ID,
        'post_title' => (string) get_the_title((int) $comment->comment_post_ID),
        'member_id' => (int) $comment->user_id,
        'author_name' => (string) $comment->comment_author,
        'operation' => $operation,
        'status_before' => $before,
        'status_after' => $after instanceof WP_Comment
            ? ($after->comment_approved === '1' ? 'approved' : (string) $after->comment_approved)
            : 'gone',
        'warning' => in_array($operation, ['spam', 'trash'], true)
            ? __('A member\'s comment is no longer visible on the post. unspam or untrash puts it back.', 'nibwp')
            : null,
    ];
}
