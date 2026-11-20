<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: BuddyPress.
 *
 * A community of real people, which changes what an agent may be handed.
 * Verified against BuddyPress 14.5.2 (bp-loader.php:25).
 *
 * COMPONENTS. BuddyPress is not one plugin, it is a set of components switched
 * on and off per site (bp-core/bp-core-functions.php:1111). On a site running
 * Members and Activity only, groups_get_groups() does not exist — calling it is
 * an uncaught Error, not a refusal. So every action names the component it needs
 * and bp_is_active() answers before any of that component's functions are
 * touched (bp-core/bp-core-template.php:1995), with its own error code so
 * "Groups is switched off" is never reported as "BuddyPress is not installed".
 *
 * PRIVATE MESSAGES. Never returned, at any depth. message_counts reports counts
 * and thread metadata — how many threads, how many messages, how many people,
 * when the last one was sent — and neither the body nor the subject, because a
 * member writes the subject too and it carries content just as readily
 * (bp-core/admin/bp-core-admin-schema.php:257-266 is the whole table; `subject`
 * and `message` are the two columns nothing here selects). A private message
 * between two members is not something an agent reads. There is no action that
 * sends one either.
 *
 * PROFILE FIELD VISIBILITY. A member can mark a field public, loggedin, friends
 * or adminsonly (bp-xprofile/classes/class-bp-xprofile-component.php:175-191).
 * The choice lives in the user meta `bp_xprofile_visibility_levels` as field id
 * => level (bp-xprofile/bp-xprofile-functions.php:542-563), and an administrator
 * can forbid the override, in which case the field's own `default_visibility`
 * wins (bp-xprofile/bp-xprofile-functions.php:584-594). Only `public` travels
 * out of here. BuddyPress itself shows everything to a user who can
 * `bp_moderate` (bp-xprofile/bp-xprofile-functions.php:1307), which is right for
 * a moderation screen and wrong for an agent: a response goes into a transcript,
 * a log and whatever the client does next, so a field a member hid stays hidden
 * however much authority the caller has.
 *
 * PERSONAL DATA. Members leave here through an allow-list of fields. The
 * password hash, the activation key, session tokens and application passwords
 * are not on it and never will be; an email address is not on it either, and
 * travels only when the caller asks for it AND can edit that specific member.
 */

/**
 * The capability BuddyPress gates its own community screens on
 * (bp-core/bp-core-caps.php:329). It is granted to every role holding
 * manage_options, and to Super Admins on multisite
 * (bp-core/bp-core-caps.php:381-415, 454-472).
 */
const NIBWP_BUDDYPRESS_CAP = 'bp_moderate';

/**
 * What BuddyPress accepts in its place on the Members admin screen, where
 * BP_Members_Admin::$capability is edit_users (or manage_network_users on a
 * network) and bp_moderate is trusted alongside it
 * (bp-members/classes/class-bp-members-admin.php:241, 498-500).
 */
const NIBWP_BUDDYPRESS_CAP_ALT = 'edit_users';

/** How many rows any listing returns, whatever was asked for. */
const NIBWP_BUDDYPRESS_MAX_ROWS = 200;

/** The one profile-field visibility level whose value may leave this site. */
const NIBWP_BUDDYPRESS_PUBLIC_LEVEL = 'public';

/**
 * Is BuddyPress loaded at all?
 *
 * The class is the plugin's own singleton (class-buddypress.php) and
 * bp_is_active() is what every component gate below goes through, so between
 * them they separate "not installed" from "installed and half-loaded".
 */
function nibwp_buddypress_guard(): ?WP_Error
{
    if (!class_exists('BuddyPress') && !function_exists('bp_is_active')) {
        return new WP_Error(
            'nibwp_bp_missing',
            __('BuddyPress is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/**
 * Is one component switched on?
 *
 * Deliberately a different error code from the plugin-absent guard. "The Groups
 * component is switched off" and "BuddyPress is not installed" have different
 * answers — one is a checkbox under Settings > BuddyPress > Components, the
 * other is an install — and an agent told the wrong one gives the wrong advice.
 */
function nibwp_buddypress_component(string $component): ?WP_Error
{
    // No bp_is_active() means no way to tell, and guessing is how an integration
    // calls groups_get_groups() on a site that has no Groups component.
    if (!function_exists('bp_is_active')) {
        return new WP_Error(
            'nibwp_bp_component_inactive',
            sprintf(
                /* translators: %s: BuddyPress component name */
                __('This version of BuddyPress does not report whether its %s component is active, so nothing here will call into it.', 'nibwp'),
                $component
            ),
            ['status' => 400]
        );
    }

    try {
        $active = (bool) bp_is_active($component);
    } catch (\Throwable $error) {
        $active = false;
    }

    if (!$active) {
        return new WP_Error(
            'nibwp_bp_component_inactive',
            sprintf(
                /* translators: %s: BuddyPress component name */
                __('The BuddyPress %s component is switched off on this site, so there is nothing here to read or change. Switch it on under Settings > BuddyPress > Components first.', 'nibwp'),
                $component
            ),
            ['status' => 400]
        );
    }

    return null;
}

/** A refusal about authority over the community as a whole. */
function nibwp_buddypress_forbidden(string $what): WP_Error
{
    return new WP_Error(
        'nibwp_bp_forbidden',
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name, 3: alternative capability name */
            __('%1$s needs the %2$s or %3$s capability, which this account does not have.', 'nibwp'),
            $what,
            NIBWP_BUDDYPRESS_CAP,
            NIBWP_BUDDYPRESS_CAP_ALT
        ),
        ['status' => 403]
    );
}

/** The community-wide gate, applied to reads as well as writes. */
function nibwp_buddypress_require(string $what): ?WP_Error
{
    // Reading is not a lower bar than writing here: a member list is a list of
    // real people and an activity stream is what they said to each other.
    if (current_user_can(NIBWP_BUDDYPRESS_CAP) || current_user_can(NIBWP_BUDDYPRESS_CAP_ALT)) {
        return null;
    }

    return nibwp_buddypress_forbidden($what);
}

/**
 * The gate for anything touching one named member.
 *
 * A role-wide capability says this account administers the community; it does
 * not say it may open this person's record. WordPress answers that per user and
 * per site, which is the check that holds on multisite and the one BuddyPress's
 * own profile screen uses (bp-members/classes/class-bp-members-admin.php:938,
 * 1613). Its own error code, so the per-member refusal cannot be mistaken for
 * the community-wide one.
 */
function nibwp_buddypress_require_member(int $user_id, string $what): ?WP_Error
{
    if ($denied = nibwp_buddypress_require($what)) {
        return $denied;
    }

    if (!current_user_can('edit_user', $user_id)) {
        return new WP_Error(
            'nibwp_bp_forbidden_member',
            sprintf(
                /* translators: 1: what was being attempted, 2: member ID */
                __('%1$s is refused: this account cannot edit member %2$d, so it does not get that member\'s record.', 'nibwp'),
                $what,
                $user_id
            ),
            ['status' => 403]
        );
    }

    return null;
}

/** A refusal about the request itself. */
function nibwp_buddypress_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_bp_' . $code, $message, ['status' => $status]);
}

/** Clamp a row count that arrived over the wire. */
function nibwp_buddypress_limit(mixed $value, int $default = 20): int
{
    $limit = is_numeric($value) ? (int) $value : $default;

    return max(1, min(NIBWP_BUDDYPRESS_MAX_ROWS, $limit));
}

/** Clamp a page number that arrived over the wire. */
function nibwp_buddypress_page(mixed $value): int
{
    $page = is_numeric($value) ? (int) $value : 1;

    return max(1, $page);
}

/** A positive id from input, or zero when there was not one. */
function nibwp_buddypress_id(mixed $value): int
{
    return is_numeric($value) ? max(0, (int) $value) : 0;
}

/**
 * One of BuddyPress's own tables, for the handful of questions its API cannot
 * answer.
 *
 * A table name is an identifier, which $wpdb->prepare() cannot bind, so it is
 * never taken from input: the key is matched against this list and the resulting
 * name is checked character by character before it is interpolated. Prefixes
 * come from the plugin itself, because on multisite BuddyPress's tables live on
 * the root blog and carry base_prefix, not the current site's prefix
 * (bp-core/bp-core-functions.php and the component globals at
 * bp-activity/classes/class-bp-activity-component.php:201).
 */
function nibwp_buddypress_table(string $key): string
{
    global $wpdb;

    $bp = function_exists('buddypress') ? buddypress() : null;

    $from_plugin = match ($key) {
        'activity' => is_object($bp) && isset($bp->activity->table_name) ? (string) $bp->activity->table_name : '',
        'groups' => is_object($bp) && isset($bp->groups->table_name) ? (string) $bp->groups->table_name : '',
        'group_members' => is_object($bp) && isset($bp->groups->table_name_members) ? (string) $bp->groups->table_name_members : '',
        'groupmeta' => is_object($bp) && isset($bp->groups->table_name_groupmeta) ? (string) $bp->groups->table_name_groupmeta : '',
        'messages' => is_object($bp) && isset($bp->messages->table_name_messages) ? (string) $bp->messages->table_name_messages : '',
        'recipients' => is_object($bp) && isset($bp->messages->table_name_recipients) ? (string) $bp->messages->table_name_recipients : '',
        'friends' => is_object($bp) && isset($bp->friends->table_name) ? (string) $bp->friends->table_name : '',
        default => '',
    };

    if ($from_plugin === '') {
        $suffix = match ($key) {
            'activity' => 'bp_activity',
            'groups' => 'bp_groups',
            'group_members' => 'bp_groups_members',
            'groupmeta' => 'bp_groups_groupmeta',
            'messages' => 'bp_messages_messages',
            'recipients' => 'bp_messages_recipients',
            'friends' => 'bp_friends',
            default => '',
        };

        // An empty name is the caller's cue to refuse rather than to guess: a
        // query against a table whose name we invented is either an error or,
        // worse, a hit on somebody else's table.
        if ($suffix === '') {
            return '';
        }

        $prefix = function_exists('bp_core_get_table_prefix')
            ? (string) bp_core_get_table_prefix()
            : (is_object($wpdb) && isset($wpdb->base_prefix) ? (string) $wpdb->base_prefix : '');

        $from_plugin = $prefix . $suffix;
    }

    return preg_match('/^[A-Za-z0-9_]+$/', $from_plugin) === 1 ? $from_plugin : '';
}

/**
 * The fields of a member that may leave this site.
 *
 * An allow-list, not a denylist. A WP_User row carries user_pass and
 * user_activation_key, and its meta carries session_tokens, wp_capabilities and
 * _application_passwords; a denylist of the names somebody remembered would
 * default to leaking the next one. The email address is deliberately absent —
 * see nibwp_buddypress_member_row().
 *
 * @return array<int, string>
 */
function nibwp_buddypress_member_fields(): array
{
    return ['id', 'user_login', 'display_name', 'user_nicename', 'user_registered', 'registered', 'last_activity', 'member_type'];
}

/**
 * Read a user object's properties by name, never wholesale.
 *
 * get_object_vars() is wrong here twice over. On a WP_User it returns `data`,
 * `caps`, `allcaps` and `roles` and none of the fields anybody wants, because
 * user_login and the rest arrive through __get. And on any object it returns
 * everything there is, which on a WP_User's `data` includes user_pass. So the
 * property names are listed, and a name not on the list is never even read.
 *
 * @return array<string, mixed>
 */
function nibwp_buddypress_user_props(object $user): array
{
    $raw = [];

    foreach ([
        'ID', 'id', 'user_id',
        'user_login', 'user_nicename', 'display_name', 'user_email', 'user_registered',
        'registered', 'last_activity', 'member_type',
        'is_admin', 'is_mod', 'is_banned', 'is_confirmed',
    ] as $property) {
        // __isset on a WP_User answers for the mapped fields, so this works on a
        // WP_User and on the plain object BP's own queries hand back.
        if (isset($user->$property)) {
            $raw[$property] = $user->$property;
        }
    }

    return $raw;
}

/**
 * Field names that must never travel, whatever they are asked for.
 *
 * A second layer rather than the only one: the allow-list above already governs
 * what leaves. A name appearing in both lists is a mistake worth failing loudly
 * on, which the check asserts.
 *
 * @return array<int, string>
 */
function nibwp_buddypress_member_secret_fields(): array
{
    return [
        'user_pass',
        'user_activation_key',
        'session_tokens',
        '_application_passwords',
        'wp_capabilities',
        'wp_user_level',
        'password_reset_key',
    ];
}

/**
 * Build one member row through the allow-list.
 *
 * @param array<string, mixed> $raw
 * @return array<string, mixed>
 */
function nibwp_buddypress_member_row(array $raw, bool $with_email = false): array
{
    $row = [];

    foreach (nibwp_buddypress_member_fields() as $field) {
        if (!array_key_exists($field, $raw)) {
            continue;
        }

        // Dropped, not cast. (string) on an array is a notice and the word
        // "Array"; on an object it runs __toString and can carry anything out
        // with it. A serialised capability map or token set arriving under an
        // innocent key is exactly the shape this refuses.
        if (!is_scalar($raw[$field])) {
            continue;
        }

        $row[$field] = $field === 'id' ? (int) $raw[$field] : (string) $raw[$field];
    }

    // An email address is a contact detail for a real person and the handle on
    // every account they own, so it needs asking for, and asking is not enough:
    // the caller must be able to edit this member, which the caller's own gate
    // has already established before $with_email can be true.
    $email = isset($raw['user_email']) && is_scalar($raw['user_email']) ? (string) $raw['user_email'] : '';

    if ($with_email && $email !== '') {
        $row['user_email'] = $email;
    }

    $row['email_returned'] = $with_email && $email !== '';

    return $row;
}

/**
 * May this caller be handed an email address for this member?
 */
function nibwp_buddypress_email_allowed(mixed $asked, int $user_id): bool
{
    if ($asked !== true) {
        return false;
    }

    // The capability for handing out an address is the one for editing the
    // account it belongs to, named explicitly rather than inherited from the
    // community-wide gate.
    return current_user_can(NIBWP_BUDDYPRESS_CAP_ALT) && current_user_can('edit_user', $user_id);
}

/**
 * The visibility level a member chose for one field.
 *
 * Resolution order matches BuddyPress's own
 * (bp-xprofile/bp-xprofile-functions.php:574-597): the member's stored choice,
 * unless the administrator disabled the override, in which case the field's
 * default wins. An unresolvable level is reported as unknown and the value is
 * withheld — the one safe direction to fail in.
 */
function nibwp_buddypress_field_visibility(int $field_id, int $user_id, ?object $field = null): string
{
    if (function_exists('xprofile_get_field_visibility_level')) {
        try {
            $level = (string) xprofile_get_field_visibility_level($field_id, $user_id);

            if ($level !== '') {
                return $level;
            }
        } catch (\Throwable $error) {
            // Fall through to the meta read below.
        }
    }

    // The same meta BuddyPress writes, read directly, for the case where the
    // helper is absent or threw.
    if (function_exists('get_user_meta')) {
        $levels = get_user_meta($user_id, 'bp_xprofile_visibility_levels', true);

        if (is_array($levels) && isset($levels[$field_id]) && is_scalar($levels[$field_id])) {
            $stored = (string) $levels[$field_id];

            // An administrator who forbade the override means the member's stored
            // choice no longer applies — the field's own default does.
            $locked = is_object($field) && isset($field->allow_custom_visibility)
                && $field->allow_custom_visibility === 'disabled';

            if (!$locked && $stored !== '') {
                return $stored;
            }
        }
    }

    if (is_object($field) && isset($field->default_visibility) && is_scalar($field->default_visibility)) {
        $default = (string) $field->default_visibility;

        if ($default !== '') {
            return $default;
        }
    }

    return 'unknown';
}

/**
 * One member's profile fields, with everything they hid left behind.
 *
 * Only NIBWP_BUDDYPRESS_PUBLIC_LEVEL travels. Not a denylist of the three
 * private levels: a site can register a level of its own through the
 * bp_xprofile_get_visibility_levels filter
 * (bp-xprofile/bp-xprofile-functions.php:1227), and a denylist would hand that
 * one out. Two independent reasons withhold a field — the level this integration
 * resolved, and BuddyPress's own hidden-field list where it is available — and
 * either is enough.
 *
 * @return array<string, mixed>
 */
function nibwp_buddypress_profile_fields(int $user_id): array
{
    $visible = [];
    $withheld = [];

    if (!function_exists('bp_xprofile_get_groups')) {
        return [
            'fields' => [],
            'withheld' => [],
            'withheld_count' => 0,
            'note' => __('This version of BuddyPress does not expose its profile field groups where this integration can read them.', 'nibwp'),
        ];
    }

    // Logged out rather than as the caller: BuddyPress hides nothing from a user
    // who can bp_moderate (bp-xprofile/bp-xprofile-functions.php:1307), and a
    // response that leaves this site must not depend on who asked.
    $hidden_by_plugin = [];

    if (function_exists('bp_xprofile_get_fields_by_visibility_levels')) {
        try {
            $hidden_by_plugin = array_map('intval', (array) bp_xprofile_get_fields_by_visibility_levels(
                $user_id,
                ['friends', 'loggedin', 'adminsonly']
            ));
        } catch (\Throwable $error) {
            $hidden_by_plugin = [];
        }
    }

    $groups = (array) bp_xprofile_get_groups([
        'user_id' => $user_id,
        'fetch_fields' => true,
        'fetch_field_data' => true,
        'fetch_visibility_level' => true,
    ]);

    foreach ($groups as $group) {
        if (!is_object($group)) {
            continue;
        }

        foreach ((array) ($group->fields ?? []) as $field) {
            if (!is_object($field)) {
                continue;
            }

            $field_id = isset($field->id) ? (int) $field->id : 0;
            $name = isset($field->name) && is_scalar($field->name) ? (string) $field->name : '';

            // The level BuddyPress itself attached when asked for it, else the
            // one resolved from the member's meta and the field's default.
            $level = isset($field->visibility_level) && is_scalar($field->visibility_level)
                ? (string) $field->visibility_level
                : nibwp_buddypress_field_visibility($field_id, $user_id, $field);

            $public = $level === NIBWP_BUDDYPRESS_PUBLIC_LEVEL && !in_array($field_id, $hidden_by_plugin, true);

            if (!$public) {
                // The name and the level travel so an owner can see that a field
                // exists and was withheld. The value does not.
                $withheld[] = [
                    'field_id' => $field_id,
                    'field' => $name,
                    'visibility' => $level,
                    'value_returned' => false,
                ];
                continue;
            }

            $value = isset($field->data->value) ? $field->data->value : null;

            $visible[] = [
                'field_id' => $field_id,
                'field' => $name,
                'group' => isset($group->name) && is_scalar($group->name) ? (string) $group->name : '',
                'type' => isset($field->type) && is_scalar($field->type) ? (string) $field->type : '',
                'visibility' => $level,
                // Non-scalar dropped rather than cast, same reason as a member row.
                'value' => is_scalar($value) ? (string) $value : null,
            ];
        }
    }

    return [
        'fields' => $visible,
        'withheld' => $withheld,
        'withheld_count' => count($withheld),
        'note' => __('Only fields the member left public are returned. A field marked friends-only, logged-in-only or admins-only is named with its level and nothing else, however much authority the caller has.', 'nibwp'),
    ];
}

/**
 * One group, or a refusal.
 *
 * Typed `object` rather than `object|WP_Error`: PHP refuses a union of `object`
 * with a class type at compile time, which is a white screen rather than a test
 * failure. WP_Error is an object, so the caller checks with instanceof.
 */
function nibwp_buddypress_fetch_group(mixed $raw): object
{
    $group_id = nibwp_buddypress_id($raw);

    if ($group_id <= 0) {
        return nibwp_buddypress_err('missing_group_id', __('Give the group as group_id.', 'nibwp'), 422);
    }

    if (!function_exists('groups_get_group')) {
        return nibwp_buddypress_err('no_groups_api', __('This version of BuddyPress does not expose its groups where this integration can read them.', 'nibwp'), 500);
    }

    $group = groups_get_group($group_id);

    if (!is_object($group) || empty($group->id)) {
        return nibwp_buddypress_err(
            'group_not_found',
            sprintf(
                /* translators: %d: group ID */
                __('There is no group %d.', 'nibwp'),
                $group_id
            ),
            404
        );
    }

    return $group;
}

/**
 * One activity item, or a refusal. Typed `object` for the reason above.
 */
function nibwp_buddypress_fetch_activity(mixed $raw): object
{
    $activity_id = nibwp_buddypress_id($raw);

    if ($activity_id <= 0) {
        return nibwp_buddypress_err('missing_activity_id', __('Give the activity item as activity_id.', 'nibwp'), 422);
    }

    if (!function_exists('bp_activity_get_specific')) {
        return nibwp_buddypress_err('no_activity_api', __('This version of BuddyPress does not expose single activity items where this integration can read them.', 'nibwp'), 500);
    }

    $result = (array) bp_activity_get_specific([
        'activity_ids' => [$activity_id],
        'show_hidden' => true,
        'per_page' => 1,
    ]);

    $items = (array) ($result['activities'] ?? []);
    $item = $items === [] ? null : reset($items);

    if (!is_object($item) || empty($item->id)) {
        return nibwp_buddypress_err(
            'activity_not_found',
            sprintf(
                /* translators: %d: activity ID */
                __('There is no activity item %d.', 'nibwp'),
                $activity_id
            ),
            404
        );
    }

    return $item;
}

/**
 * One group, as this integration reports a group.
 *
 * @return array<string, mixed>
 */
function nibwp_buddypress_group_row(object $group): array
{
    return [
        'id' => isset($group->id) ? (int) $group->id : 0,
        'name' => isset($group->name) && is_scalar($group->name) ? (string) $group->name : '',
        'slug' => isset($group->slug) && is_scalar($group->slug) ? (string) $group->slug : '',
        'description' => isset($group->description) && is_scalar($group->description) ? (string) $group->description : '',
        'status' => isset($group->status) && is_scalar($group->status) ? (string) $group->status : '',
        'parent_id' => isset($group->parent_id) ? (int) $group->parent_id : 0,
        'creator_id' => isset($group->creator_id) ? (int) $group->creator_id : 0,
        'date_created' => isset($group->date_created) && is_scalar($group->date_created) ? (string) $group->date_created : '',
        'total_member_count' => isset($group->total_member_count) ? (int) $group->total_member_count : null,
    ];
}

/**
 * One activity item, as this integration reports one.
 *
 * @return array<string, mixed>
 */
function nibwp_buddypress_activity_row(object $activity): array
{
    return [
        'id' => isset($activity->id) ? (int) $activity->id : 0,
        'user_id' => isset($activity->user_id) ? (int) $activity->user_id : 0,
        'component' => isset($activity->component) && is_scalar($activity->component) ? (string) $activity->component : '',
        'type' => isset($activity->type) && is_scalar($activity->type) ? (string) $activity->type : '',
        'action' => isset($activity->action) && is_scalar($activity->action) ? (string) $activity->action : '',
        'content' => isset($activity->content) && is_scalar($activity->content) ? (string) $activity->content : '',
        'item_id' => isset($activity->item_id) ? (int) $activity->item_id : 0,
        'secondary_item_id' => isset($activity->secondary_item_id) ? (int) $activity->secondary_item_id : 0,
        'hidden_sitewide' => isset($activity->hide_sitewide) ? (bool) $activity->hide_sitewide : false,
        'date_recorded' => isset($activity->date_recorded) && is_scalar($activity->date_recorded) ? (string) $activity->date_recorded : '',
    ];
}

/**
 * The group statuses BuddyPress accepts (bp-groups/bp-groups-functions.php:515).
 *
 * @return array<int, string>
 */
function nibwp_buddypress_group_statuses(): array
{
    return ['public', 'private', 'hidden'];
}

/** The roles a group member can be put into from here. */
function nibwp_buddypress_group_roles(): array
{
    return ['member', 'mod', 'admin'];
}

/**
 * The actions this hub offers.
 *
 * The first seven names are the ones this integration has always had; they are
 * kept exactly, because a workflow somebody already wrote asks for them by name.
 *
 * @return array<int, string>
 */
function nibwp_buddypress_actions(): array
{
    return [
        // Overview.
        'community_summary',
        'community_audit',
        // Members.
        'list_members',
        'search_members',
        'get_member',
        'member_counts',
        // Profile fields.
        'list_profile_fields',
        'get_member_profile',
        // Groups.
        'list_groups',
        'get_group',
        'create_group',
        'update_group',
        'set_group_privacy',
        'delete_group',
        'list_group_members',
        'add_group_member',
        'remove_group_member',
        'set_group_member_role',
        // Activity.
        'list_activity',
        'get_activity',
        'post_activity',
        'delete_activity',
        'hide_activity',
        'unhide_activity',
        // Counts that stand in for contents.
        'friendship_counts',
        'message_counts',
    ];
}

wp_register_ability('nibwp/buddypress-manage', [
    'label' => __('BuddyPress – Community Management', 'nibwp'),
    'description' => 'Read and manage a BuddyPress community: members and their public profile fields, groups and their membership, the activity stream, and friendship and private-message counts. '
        . 'Every action is gated on the BuddyPress component it needs, so an action against a switched-off component refuses and says which component to switch on. '
        . 'The contents of a private message are never returned — not the body and not the subject, counts and thread metadata only, and nothing here sends one. '
        . 'A profile field a member marked friends-only, logged-in-only or admins-only never travels. '
        . 'No password hash, session token, application password or activation key leaves here, and an email address only when the caller asks for it and can edit that member. '
        . 'post_activity publishes to the real community feed; delete_activity destroys somebody\'s post and delete_group destroys a group with its membership and history.',
    'category' => 'community',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_buddypress_actions(),
                'description' => 'Which operation to run. Start with community_summary.',
            ],
            'user_id' => ['type' => 'integer', 'description' => 'A member. Anything touching one member also needs edit_user on that member.'],
            'group_id' => ['type' => 'integer', 'description' => 'A group.'],
            'activity_id' => ['type' => 'integer', 'description' => 'One activity item.'],
            // The validators' own lists, not copies of them: a schema that says one
            // thing while the dispatcher accepts another is a refusal the caller was
            // told to expect.
            'role' => ['type' => 'string', 'enum' => nibwp_buddypress_group_roles(), 'description' => 'For set_group_member_role.'],
            'status' => ['type' => 'string', 'enum' => nibwp_buddypress_group_statuses(), 'description' => 'For set_group_privacy, and for create_group.'],
            'search' => ['type' => 'string', 'description' => 'For search_members and list_activity: what to search for.'],
            'component' => ['type' => 'string', 'description' => 'For list_activity: filter to one BuddyPress component, e.g. groups or activity.'],
            'type' => ['type' => 'string', 'description' => 'For list_activity: filter to one activity type, e.g. activity_update.'],
            'days' => ['type' => 'integer', 'description' => 'For community_audit: how many days of silence counts as dormant. Default 180, max 3650.'],
            'include_email' => ['type' => 'boolean', 'description' => 'For get_member: ask for the email address. Granted only to a caller who can edit that member.'],
            'confirm' => ['type' => 'boolean', 'description' => 'Required by delete_group and delete_activity. Nothing is destroyed without it.'],
            'group_data' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'enum' => nibwp_buddypress_group_statuses()],
                ],
                'description' => 'For create_group and update_group.',
            ],
            'activity_data' => [
                'type' => 'object',
                'properties' => [
                    'content' => ['type' => 'string'],
                    'user_id' => ['type' => 'integer'],
                    'component' => ['type' => 'string'],
                    'type' => ['type' => 'string'],
                    'group_id' => ['type' => 'integer'],
                ],
                'description' => 'For post_activity. This publishes to the community feed.',
            ],
            'per_page' => ['type' => 'integer', 'description' => 'Rows to return. Default 20, max 200.'],
            'page' => ['type' => 'integer', 'description' => 'Which page of results.'],
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
    'execute_callback' => 'nibwp_buddypress_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'BuddyPress — a community of real people, which changes what you may ask for.',
                '',
                'START WITH community_summary. It says which components this site actually runs',
                'and gives the counts, so you do not ask about Groups on a site that has none.',
                'community_audit is the follow-up: groups with a single member, groups dormant',
                'for months, public groups that look like they were meant to be private, and',
                'activity left behind by a deleted account.',
                '',
                'THE ACTIONS, by component:',
                '- members: list_members, search_members, get_member, member_counts.',
                '- xprofile: list_profile_fields (the shape of the profile),',
                '  get_member_profile (one member\'s public answers).',
                '- groups: list_groups, get_group, create_group, update_group,',
                '  set_group_privacy, delete_group, list_group_members, add_group_member,',
                '  remove_group_member, set_group_member_role.',
                '- activity: list_activity, get_activity, post_activity, delete_activity,',
                '  hide_activity, unhide_activity.',
                '- friends: friendship_counts. messages: message_counts.',
                '',
                'COMPONENTS: members, xprofile, groups, activity, friends and messages are each',
                'switched on or off per site. An action against a component that is off refuses',
                'and names the component. That is not a bug and there is no other action that',
                'gets round it — tell the user to switch the component on.',
                '',
                'PRIVATE MESSAGES: message_counts gives counts and thread metadata. There is no',
                'action that returns the text of a private message, or its subject, and no',
                'action that sends one. Asking differently will not produce one. If someone',
                'wants to know what two members said to each other, the answer is no.',
                '',
                'PROFILE FIELDS: get_member and get_member_profile return only the fields a',
                'member left public. A field marked friends-only, logged-in-only or',
                'admins-only comes back as a name and its level, with no value, however much',
                'authority you have. Say that plainly rather than implying the field is empty.',
                '',
                'PERSONAL DATA: no password hash, session token, application password or',
                'activation key is ever returned. An email address needs include_email AND a',
                'caller who can edit that member; otherwise the record says email_returned',
                'false. Listings drop members the account may not read and report how many',
                'they dropped in skipped_unreadable, so a count can be lower than the total.',
                '',
                'WRITES THAT REAL PEOPLE SEE:',
                '- post_activity publishes to the live community feed, under a member\'s name.',
                '  Everyone following that feed sees it. Confirm the wording first.',
                '- delete_activity destroys somebody\'s post. It cannot be undone and needs',
                '  confirm true. hide_activity is the reversible alternative: it takes an item',
                '  out of the site-wide stream and unhide_activity puts it back.',
                '- delete_group destroys a group with its membership and its history, and needs',
                '  confirm true.',
                '- set_group_privacy changes who can see a group. Making a private group public',
                '  exposes everything in it that was private until then.',
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
function nibwp_buddypress_manage(array $input): array|WP_Error
{
    $guard = nibwp_buddypress_guard();
    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = isset($input['action']) ? (string) $input['action'] : '';

    if (!in_array($action, nibwp_buddypress_actions(), true)) {
        return nibwp_buddypress_err(
            'unknown_action',
            sprintf(
                /* translators: 1: action name, 2: list of valid actions */
                __('Unknown BuddyPress action "%1$s". Valid actions: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_buddypress_actions())
            )
        );
    }

    // BuddyPress's internals move between majors and throw on a half-upgraded
    // site. One catch here turns any of that into a named refusal rather than a
    // fatal that takes the whole REST request down with it.
    try {
        return nibwp_buddypress_dispatch($action, $input);
    } catch (\Throwable $error) {
        return nibwp_buddypress_err(
            'plugin_error',
            sprintf(
                /* translators: 1: action name, 2: error message from BuddyPress */
                __('BuddyPress could not complete %1$s: %2$s', 'nibwp'),
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
function nibwp_buddypress_dispatch(string $action, array $input): array|WP_Error
{
    $result = match ($action) {
        'community_summary' => nibwp_buddypress_community_summary(),
        'community_audit' => nibwp_buddypress_community_audit($input),
        'list_members' => nibwp_buddypress_list_members($input),
        'search_members' => nibwp_buddypress_search_members($input),
        'get_member' => nibwp_buddypress_get_member($input),
        'member_counts' => nibwp_buddypress_member_counts(),
        'list_profile_fields' => nibwp_buddypress_list_profile_fields($input),
        'get_member_profile' => nibwp_buddypress_get_member_profile($input),
        'list_groups' => nibwp_buddypress_list_groups($input),
        'get_group' => nibwp_buddypress_get_group($input),
        'create_group' => nibwp_buddypress_create_group($input),
        'update_group' => nibwp_buddypress_update_group($input),
        'set_group_privacy' => nibwp_buddypress_set_group_privacy($input),
        'delete_group' => nibwp_buddypress_delete_group($input),
        'list_group_members' => nibwp_buddypress_list_group_members($input),
        'add_group_member' => nibwp_buddypress_add_group_member($input),
        'remove_group_member' => nibwp_buddypress_remove_group_member($input),
        'set_group_member_role' => nibwp_buddypress_set_group_member_role($input),
        'list_activity' => nibwp_buddypress_list_activity($input),
        'get_activity' => nibwp_buddypress_get_activity($input),
        'post_activity' => nibwp_buddypress_post_activity($input),
        'delete_activity' => nibwp_buddypress_delete_activity($input),
        'hide_activity' => nibwp_buddypress_hide_activity($input),
        'unhide_activity' => nibwp_buddypress_unhide_activity($input),
        'friendship_counts' => nibwp_buddypress_friendship_counts($input),
        'message_counts' => nibwp_buddypress_message_counts($input),
        default => nibwp_buddypress_err('unhandled_action', __('Unhandled action.', 'nibwp')),
    };

    return $result instanceof WP_Error ? $result : ['action' => $action] + $result;
}

/* ------------------------------------------------------------------ overview */

/**
 * What this community is and which of its parts are switched on.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_community_summary(): array|WP_Error
{
    if ($denied = nibwp_buddypress_require(__('Reading the BuddyPress community summary', 'nibwp'))) {
        return $denied;
    }

    // Gated on Members because without it there is no community to summarise.
    // It is one of BuddyPress's required components, so on a working site this
    // never refuses (bp-core/bp-core-functions.php:2788).
    if ($off = nibwp_buddypress_component('members')) {
        return $off;
    }

    $components = [];

    foreach (['members', 'xprofile', 'groups', 'activity', 'friends', 'messages', 'notifications', 'settings', 'blogs'] as $component) {
        $components[$component] = nibwp_buddypress_component($component) === null;
    }

    $counts = nibwp_buddypress_member_counts();
    $groups = null;

    if ($components['groups'] && function_exists('groups_get_total_group_count')) {
        $groups = (int) groups_get_total_group_count();
    }

    $activity = null;

    if ($components['activity'] && function_exists('bp_activity_get')) {
        $stream = (array) bp_activity_get(['per_page' => 1, 'count_total' => true]);
        $activity = (int) ($stream['total'] ?? 0);
    }

    $notes = [];

    foreach (['groups' => 'Groups', 'activity' => 'Activity', 'xprofile' => 'Extended Profiles', 'messages' => 'Private Messaging', 'friends' => 'Friend Connections'] as $key => $label) {
        if (!$components[$key]) {
            $notes[] = sprintf(
                /* translators: %s: BuddyPress component label */
                __('The %s component is switched off, so nothing here reads or writes it.', 'nibwp'),
                $label
            );
        }
    }

    return [
        'plugin_version' => defined('BP_VERSION') ? (string) constant('BP_VERSION') : null,
        'components' => $components,
        'members' => $counts instanceof WP_Error ? null : $counts,
        'groups' => $groups,
        'activity_items' => $activity,
        'notes' => $notes,
        'private_messages_returned' => false,
    ];
}

/**
 * Four things worth an owner's attention, none of which a screen shows.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_community_audit(array $input): array|WP_Error
{
    if ($denied = nibwp_buddypress_require(__('Auditing the BuddyPress community', 'nibwp'))) {
        return $denied;
    }

    // Three of the four findings are about groups, so Groups gates the action.
    // The orphaned-activity section says so for itself when Activity is off.
    if ($off = nibwp_buddypress_component('groups')) {
        return $off;
    }

    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
        return nibwp_buddypress_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    $limit = nibwp_buddypress_limit($input['per_page'] ?? null, 50);
    $days = isset($input['days']) && is_numeric($input['days']) ? (int) $input['days'] : 180;
    $days = max(1, min(3650, $days));

    $groups_table = nibwp_buddypress_table('groups');
    $members_table = nibwp_buddypress_table('group_members');
    $meta_table = nibwp_buddypress_table('groupmeta');

    if ($groups_table === '' || $members_table === '' || $meta_table === '') {
        return nibwp_buddypress_err('no_tables', __('BuddyPress\'s own table names could not be resolved, so the audit will not guess at them.', 'nibwp'), 500);
    }

    // A group of one is usually an abandoned creation rather than a community.
    // Only confirmed, unbanned memberships count, which is how BuddyPress counts
    // them (bp-groups/bp-groups-functions.php:897).
    $lonely = (array) $wpdb->get_results(
        $wpdb->prepare(
            'SELECT g.id, g.name, g.status, COUNT(m.id) AS members'
            . ' FROM ' . $groups_table . ' g'
            . ' LEFT JOIN ' . $members_table . ' m ON (m.group_id = g.id AND m.is_confirmed = %d AND m.is_banned = %d)'
            . ' GROUP BY g.id, g.name, g.status HAVING members <= %d ORDER BY g.id ASC LIMIT %d',
            1,
            0,
            1,
            $limit
        ),
        ARRAY_A
    );

    // BuddyPress keeps a group's last activity in groupmeta, not on the group
    // row (bp-groups/bp-groups-functions.php:746), and a group that never had
    // any has no row at all — hence the LEFT JOIN and the IS NULL.
    $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

    $dormant = (array) $wpdb->get_results(
        $wpdb->prepare(
            'SELECT g.id, g.name, g.status, m.meta_value AS last_activity'
            . ' FROM ' . $groups_table . ' g'
            . ' LEFT JOIN ' . $meta_table . ' m ON (m.group_id = g.id AND m.meta_key = %s)'
            . ' WHERE (m.meta_value IS NULL OR m.meta_value < %s)'
            . ' ORDER BY g.id ASC LIMIT %d',
            'last_activity',
            $cutoff,
            $limit
        ),
        ARRAY_A
    );

    // A group whose invitations are restricted to its organisers, or whose own
    // name says it is internal, but which anybody can read. The keywords are
    // matched here rather than in SQL so the query stays bound and bounded, and
    // the response says how many groups were looked at.
    $public_groups = (array) $wpdb->get_results(
        $wpdb->prepare(
            'SELECT g.id, g.name, g.description, m.meta_value AS invite_status'
            . ' FROM ' . $groups_table . ' g'
            . ' LEFT JOIN ' . $meta_table . ' m ON (m.group_id = g.id AND m.meta_key = %s)'
            . ' WHERE g.status = %s ORDER BY g.id ASC LIMIT %d',
            'invite_status',
            'public',
            $limit
        ),
        ARRAY_A
    );

    $words = ['private', 'confidential', 'internal', 'staff', 'secret', 'members only', 'do not share'];
    $exposed = [];

    foreach ($public_groups as $row) {
        $haystack = strtolower((string) ($row['name'] ?? '') . ' ' . (string) ($row['description'] ?? ''));
        $reasons = [];

        if ((string) ($row['invite_status'] ?? '') === 'admins') {
            $reasons[] = __('only organisers can invite people, yet anybody can read it', 'nibwp');
        }

        foreach ($words as $word) {
            if (str_contains($haystack, $word)) {
                $reasons[] = sprintf(
                    /* translators: %s: the word found in the group name or description */
                    __('its own description calls it "%s"', 'nibwp'),
                    $word
                );
                break;
            }
        }

        if ($reasons !== []) {
            $exposed[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'status' => 'public',
                'why' => $reasons,
            ];
        }
    }

    // Activity whose author no longer has an account. BuddyPress deletes a
    // member's activity when WordPress deletes the user, but a user removed by
    // SQL, an import or a plugin that skipped the hooks leaves it behind.
    $orphans = [];
    $orphan_note = null;

    if (nibwp_buddypress_component('activity') === null) {
        $activity_table = nibwp_buddypress_table('activity');

        if ($activity_table === '') {
            $orphan_note = __('The activity table name could not be resolved, so orphaned activity was not looked for.', 'nibwp');
        } else {
            $users_table = is_object($wpdb) && isset($wpdb->users) ? (string) $wpdb->users : '';

            if (preg_match('/^[A-Za-z0-9_]+$/', $users_table) !== 1) {
                $orphan_note = __('The users table name could not be resolved, so orphaned activity was not looked for.', 'nibwp');
            } else {
                $orphans = (array) $wpdb->get_results(
                    $wpdb->prepare(
                        'SELECT a.id, a.user_id, a.component, a.type, a.date_recorded'
                        . ' FROM ' . $activity_table . ' a'
                        . ' LEFT JOIN ' . $users_table . ' u ON u.ID = a.user_id'
                        . ' WHERE a.user_id > %d AND u.ID IS NULL'
                        . ' ORDER BY a.id ASC LIMIT %d',
                        0,
                        $limit
                    ),
                    ARRAY_A
                );
            }
        }
    } else {
        $orphan_note = __('The Activity component is switched off, so orphaned activity was not looked for.', 'nibwp');
    }

    return [
        'dormant_after_days' => $days,
        'inspected_per_section' => $limit,
        'groups_with_one_member' => array_map(
            static fn(array $row): array => [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'members' => (int) ($row['members'] ?? 0),
            ],
            $lonely
        ),
        'dormant_groups' => array_map(
            static fn(array $row): array => [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'last_activity' => isset($row['last_activity']) && is_scalar($row['last_activity'])
                    ? (string) $row['last_activity']
                    : null,
            ],
            $dormant
        ),
        'public_groups_that_look_private' => $exposed,
        'public_groups_inspected' => count($public_groups),
        'activity_orphaned_from_deleted_members' => array_map(
            static fn(array $row): array => [
                'id' => (int) ($row['id'] ?? 0),
                'user_id' => (int) ($row['user_id'] ?? 0),
                'component' => (string) ($row['component'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
                'date_recorded' => (string) ($row['date_recorded'] ?? ''),
            ],
            $orphans
        ),
        'orphan_note' => $orphan_note,
        'note' => __('Each section is bounded, so a long list is a sample rather than the whole truth. Nothing here is acted on: a group that looks mislabelled may be deliberate, and deleting one is a decision for a person.', 'nibwp'),
    ];
}

/* ------------------------------------------------------------------- members */

/**
 * The community's members.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_list_members(array $input): array|WP_Error
{
    if ($denied = nibwp_buddypress_require(__('Listing BuddyPress members', 'nibwp'))) {
        return $denied;
    }

    if ($off = nibwp_buddypress_component('members')) {
        return $off;
    }

    return nibwp_buddypress_member_query($input, '');
}

/**
 * Members matching a search.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_search_members(array $input): array|WP_Error
{
    if ($denied = nibwp_buddypress_require(__('Searching BuddyPress members', 'nibwp'))) {
        return $denied;
    }

    if ($off = nibwp_buddypress_component('members')) {
        return $off;
    }

    $search = isset($input['search']) ? trim((string) $input['search']) : '';

    if ($search === '') {
        return nibwp_buddypress_err('missing_search', __('Give something to search for as search.', 'nibwp'), 422);
    }

    return nibwp_buddypress_member_query($input, $search);
}

/**
 * The one member query, bounded, allow-listed, and honest about what it dropped.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_member_query(array $input, string $search): array|WP_Error
{
    if (!function_exists('bp_core_get_users')) {
        return nibwp_buddypress_err('no_members_api', __('This version of BuddyPress does not expose its member query where this integration can read it.', 'nibwp'), 500);
    }

    $per_page = nibwp_buddypress_limit($input['per_page'] ?? null);
    $page = nibwp_buddypress_page($input['page'] ?? null);

    $args = [
        'per_page' => $per_page,
        'page' => $page,
        'type' => $search === '' ? 'active' : 'alphabetical',
    ];

    if ($search !== '') {
        $args['search_terms'] = $search;
    }

    $result = (array) bp_core_get_users($args);

    $members = [];
    $skipped = 0;

    foreach ((array) ($result['users'] ?? []) as $user) {
        $raw = is_object($user) ? nibwp_buddypress_user_props($user) : (array) $user;
        $id = (int) ($raw['ID'] ?? $raw['id'] ?? 0);

        // A listing drops what this account may not read rather than refusing
        // wholesale, and reports the number dropped so a short list is never
        // mistaken for a complete one.
        if ($id <= 0 || !current_user_can('edit_user', $id)) {
            $skipped++;
            continue;
        }

        $raw['id'] = $id;
        $members[] = nibwp_buddypress_member_row($raw);
    }

    return [
        'members' => $members,
        'returned' => count($members),
        'skipped_unreadable' => $skipped,
        'total' => (int) ($result['total'] ?? 0),
        'per_page' => $per_page,
        'page' => $page,
        'search' => $search === '' ? null : $search,
        'note' => $skipped > 0
            ? __('Some members were left out because this account cannot edit them. The total counts them; the list does not.', 'nibwp')
            : null,
    ];
}

/**
 * One member: their account, and the profile fields they left public.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_get_member(array $input): array|WP_Error
{
    $user_id = nibwp_buddypress_id($input['user_id'] ?? null);

    if ($user_id <= 0) {
        return nibwp_buddypress_err('missing_user_id', __('Give the member as user_id.', 'nibwp'), 422);
    }

    if ($denied = nibwp_buddypress_require_member($user_id, __('Reading a BuddyPress member', 'nibwp'))) {
        return $denied;
    }

    if ($off = nibwp_buddypress_component('members')) {
        return $off;
    }

    $user = get_userdata($user_id);

    if (!is_object($user) || empty($user->ID)) {
        return nibwp_buddypress_err(
            'member_not_found',
            sprintf(
                /* translators: %d: member ID */
                __('There is no member %d.', 'nibwp'),
                $user_id
            ),
            404
        );
    }

    $raw = nibwp_buddypress_user_props($user);
    $raw['id'] = (int) $user->ID;

    if (function_exists('bp_get_user_last_activity')) {
        $raw['last_activity'] = (string) bp_get_user_last_activity($user_id);
    }

    $member = nibwp_buddypress_member_row($raw, nibwp_buddypress_email_allowed($input['include_email'] ?? null, $user_id));

    $profile = null;

    // Profile fields only exist when the Extended Profiles component is on; the
    // member's account details are still worth returning when it is not.
    if (nibwp_buddypress_component('xprofile') === null) {
        $profile = nibwp_buddypress_profile_fields($user_id);
    }

    return [
        'member' => $member,
        'profile' => $profile,
        'profile_note' => $profile === null
            ? __('The Extended Profiles component is switched off, so this member has no profile fields.', 'nibwp')
            : null,
        'secrets_returned' => false,
    ];
}

/**
 * How many members there are, by the states BuddyPress itself distinguishes.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_member_counts(): array|WP_Error
{
    if ($denied = nibwp_buddypress_require(__('Counting BuddyPress members', 'nibwp'))) {
        return $denied;
    }

    if ($off = nibwp_buddypress_component('members')) {
        return $off;
    }

    global $wpdb;

    // "Total" here is BuddyPress's own total, which already excludes spammers and
    // deleted accounts through bp_core_get_status_sql()
    // (bp-members/bp-members-functions.php:583-601).
    $total = function_exists('bp_core_get_total_member_count') ? (int) bp_core_get_total_member_count() : null;

    // "Active" means has ever recorded activity, which is what BuddyPress counts
    // (bp-members/bp-members-functions.php:613-640) — not "online now".
    $active = function_exists('bp_core_get_active_member_count') ? (int) bp_core_get_active_member_count() : null;

    $suspended = null;

    if (is_object($wpdb) && method_exists($wpdb, 'get_var') && isset($wpdb->users)) {
        $users_table = (string) $wpdb->users;

        if (preg_match('/^[A-Za-z0-9_]+$/', $users_table) === 1) {
            // user_status is the column BuddyPress itself excludes on, and 0 is a
            // normal account. Bound rather than inlined so every query in this
            // file goes through prepare().
            $suspended = (int) $wpdb->get_var(
                $wpdb->prepare('SELECT COUNT(ID) FROM ' . $users_table . ' WHERE user_status != %d', 0)
            );
        }
    }

    $pending = null;

    if (class_exists('BP_Signup') && method_exists('BP_Signup', 'count_signups')) {
        $pending = (int) BP_Signup::count_signups();
    }

    return [
        'total' => $total,
        'active' => $active,
        'never_active' => $total === null || $active === null ? null : max(0, $total - $active),
        'pending_activation' => $pending,
        'spam_or_deleted' => $suspended,
        'note' => __('"Active" means the member has recorded activity at some point, not that they are online. "Pending activation" counts sign-ups that never confirmed, and is null on a site where BuddyPress does not keep that table.', 'nibwp'),
    ];
}

/* ------------------------------------------------------------ profile fields */

/**
 * The shape of the profile: which field groups exist and which fields are in them.
 *
 * No member's values — this is the schema, which is what an agent needs before
 * it can ask a sensible question about one person.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_list_profile_fields(array $input = []): array|WP_Error
{
    if ($denied = nibwp_buddypress_require(__('Reading the BuddyPress profile field groups', 'nibwp'))) {
        return $denied;
    }

    if ($off = nibwp_buddypress_component('xprofile')) {
        return $off;
    }

    if (!function_exists('bp_xprofile_get_groups')) {
        return nibwp_buddypress_err('no_xprofile_api', __('This version of BuddyPress does not expose its profile field groups where this integration can read them.', 'nibwp'), 500);
    }

    // BuddyPress has no pagination for field groups, so the bound is applied
    // here. A site with hundreds of custom fields exists, and an unbounded
    // response is the one that times out rather than the one that refuses.
    $limit = nibwp_buddypress_limit($input['per_page'] ?? null, 50);

    $groups = (array) bp_xprofile_get_groups(['fetch_fields' => true]);
    $rows = [];
    $fields = 0;
    $skipped = 0;

    foreach ($groups as $group) {
        if (!is_object($group)) {
            continue;
        }

        $group_fields = [];

        foreach ((array) ($group->fields ?? []) as $field) {
            if (!is_object($field)) {
                continue;
            }

            if ($fields >= $limit) {
                $skipped++;
                continue;
            }

            $fields++;
            $group_fields[] = [
                'id' => isset($field->id) ? (int) $field->id : 0,
                'name' => isset($field->name) && is_scalar($field->name) ? (string) $field->name : '',
                'type' => isset($field->type) && is_scalar($field->type) ? (string) $field->type : '',
                'required' => isset($field->is_required) ? (bool) $field->is_required : false,
                'default_visibility' => isset($field->default_visibility) && is_scalar($field->default_visibility)
                    ? (string) $field->default_visibility
                    : null,
                'members_may_override_visibility' => isset($field->allow_custom_visibility)
                    ? $field->allow_custom_visibility !== 'disabled'
                    : null,
            ];
        }

        $rows[] = [
            'id' => isset($group->id) ? (int) $group->id : 0,
            'name' => isset($group->name) && is_scalar($group->name) ? (string) $group->name : '',
            'description' => isset($group->description) && is_scalar($group->description) ? (string) $group->description : '',
            'fields' => $group_fields,
        ];
    }

    return [
        'field_groups' => $rows,
        'group_count' => count($rows),
        'field_count' => $fields,
        'skipped_over_limit' => $skipped,
        'per_page' => $limit,
        'values_returned' => false,
        'note' => __('This is the shape of the profile, not anybody\'s answers. default_visibility is what a field starts as, and members_may_override_visibility says whether a member is allowed to change it.', 'nibwp'),
    ];
}

/**
 * One member's profile field values, minus everything they hid.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_get_member_profile(array $input): array|WP_Error
{
    $user_id = nibwp_buddypress_id($input['user_id'] ?? null);

    if ($user_id <= 0) {
        return nibwp_buddypress_err('missing_user_id', __('Give the member as user_id.', 'nibwp'), 422);
    }

    if ($denied = nibwp_buddypress_require_member($user_id, __('Reading a BuddyPress member\'s profile fields', 'nibwp'))) {
        return $denied;
    }

    if ($off = nibwp_buddypress_component('xprofile')) {
        return $off;
    }

    $profile = nibwp_buddypress_profile_fields($user_id);

    return [
        'user_id' => $user_id,
        'fields' => $profile['fields'],
        'withheld' => $profile['withheld'],
        'withheld_count' => $profile['withheld_count'],
        'note' => $profile['note'],
    ];
}

/* -------------------------------------------------------------------- groups */

/**
 * The community's groups.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_list_groups(array $input): array|WP_Error
{
    if ($denied = nibwp_buddypress_require(__('Listing BuddyPress groups', 'nibwp'))) {
        return $denied;
    }

    if ($off = nibwp_buddypress_component('groups')) {
        return $off;
    }

    if (!function_exists('groups_get_groups')) {
        return nibwp_buddypress_err('no_groups_api', __('This version of BuddyPress does not expose its group query where this integration can read it.', 'nibwp'), 500);
    }

    $per_page = nibwp_buddypress_limit($input['per_page'] ?? null);
    $page = nibwp_buddypress_page($input['page'] ?? null);

    $args = [
        'per_page' => $per_page,
        'page' => $page,
        'orderby' => 'date_created',
        'order' => 'DESC',
        // Hidden groups are included because the caller administers this
        // community and a hidden group it cannot see is a hidden group it cannot
        // fix. What is in the group still needs its own read.
        'show_hidden' => true,
    ];

    $search = isset($input['search']) ? trim((string) $input['search']) : '';

    if ($search !== '') {
        $args['search_terms'] = $search;
    }

    $result = (array) groups_get_groups($args);
    $groups = [];

    foreach ((array) ($result['groups'] ?? []) as $group) {
        if (!is_object($group)) {
            continue;
        }

        $groups[] = nibwp_buddypress_group_row($group);
    }

    return [
        'groups' => $groups,
        'returned' => count($groups),
        'skipped_unreadable' => 0,
        'total' => (int) ($result['total'] ?? 0),
        'per_page' => $per_page,
        'page' => $page,
    ];
}

/**
 * One group, with its settings and how many people are in it.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_get_group(array $input): array|WP_Error
{
    if ($denied = nibwp_buddypress_require(__('Reading a BuddyPress group', 'nibwp'))) {
        return $denied;
    }

    if ($off = nibwp_buddypress_component('groups')) {
        return $off;
    }

    $group = nibwp_buddypress_fetch_group($input['group_id'] ?? null);

    if ($group instanceof WP_Error) {
        return $group;
    }

    $group_id = (int) $group->id;
    $row = nibwp_buddypress_group_row($group);

    if ($row['total_member_count'] === null && function_exists('groups_get_total_member_count')) {
        $row['total_member_count'] = (int) groups_get_total_member_count($group_id);
    }

    $settings = [];

    if (function_exists('groups_get_groupmeta')) {
        foreach (['invite_status', 'last_activity', 'total_member_count'] as $key) {
            $value = groups_get_groupmeta($group_id, $key);
            $settings[$key] = is_scalar($value) ? (string) $value : null;
        }
    }

    $organisers = [];

    if (function_exists('groups_get_group_admins')) {
        foreach ((array) groups_get_group_admins($group_id) as $admin) {
            if (!is_object($admin)) {
                continue;
            }

            $organisers[] = (int) ($admin->user_id ?? 0);
        }
    }

    return [
        'group' => $row,
        'settings' => $settings,
        'organiser_ids' => $organisers,
        'enable_forum' => isset($group->enable_forum) ? (bool) $group->enable_forum : null,
        'note' => $row['status'] === 'public'
            ? __('This group is public: anybody can read it, including people who are not members and visitors who are not logged in.', 'nibwp')
            : null,
    ];
}

/**
 * Create a group.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_create_group(array $input): array|WP_Error
{
    if ($denied = nibwp_buddypress_require(__('Creating a BuddyPress group', 'nibwp'))) {
        return $denied;
    }

    if ($off = nibwp_buddypress_component('groups')) {
        return $off;
    }

    if (!function_exists('groups_create_group')) {
        return nibwp_buddypress_err('no_groups_api', __('This version of BuddyPress does not expose a way to create a group from here.', 'nibwp'), 500);
    }

    $data = is_array($input['group_data'] ?? null) ? (array) $input['group_data'] : [];
    $name = isset($data['name']) ? sanitize_text_field((string) $data['name']) : '';

    if ($name === '') {
        return nibwp_buddypress_err('missing_name', __('Give the group a name as group_data.name.', 'nibwp'), 422);
    }

    $status = isset($data['status']) ? strtolower(trim((string) $data['status'])) : 'private';

    if (!in_array($status, nibwp_buddypress_group_statuses(), true)) {
        return nibwp_buddypress_err(
            'bad_status',
            sprintf(
                /* translators: %s: list of valid group statuses */
                __('A group\'s status must be one of: %s.', 'nibwp'),
                implode(', ', nibwp_buddypress_group_statuses())
            ),
            422
        );
    }

    // Private by default rather than public. A group created the wrong way round
    // is a privacy incident that nobody notices until somebody reads it, and
    // widening a group afterwards is one call; narrowing it does not take back
    // what was already read.
    $group_id = groups_create_group([
        'name' => $name,
        'description' => isset($data['description']) ? sanitize_text_field((string) $data['description']) : '',
        'slug' => isset($data['slug']) && function_exists('groups_check_slug')
            ? groups_check_slug(sanitize_title((string) $data['slug']))
            : '',
        'status' => $status,
        'creator_id' => get_current_user_id(),
        'date_created' => function_exists('bp_core_current_time') ? bp_core_current_time() : gmdate('Y-m-d H:i:s'),
    ]);

    if (!$group_id) {
        return nibwp_buddypress_err('create_failed', __('BuddyPress did not create the group and did not say why.', 'nibwp'), 500);
    }

    return [
        'created' => true,
        'group' => ['id' => (int) $group_id, 'name' => $name, 'status' => $status],
        'note' => $status === 'public'
            ? __('This group is public, so anybody can read it. Say that back to whoever asked.', 'nibwp')
            : __('Created private, which is this integration\'s default when no status was given. set_group_privacy widens it.', 'nibwp'),
    ];
}

/**
 * Change a group's name, description or slug.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_update_group(array $input): array|WP_Error
{
    if ($denied = nibwp_buddypress_require(__('Updating a BuddyPress group', 'nibwp'))) {
        return $denied;
    }

    if ($off = nibwp_buddypress_component('groups')) {
        return $off;
    }

    $group = nibwp_buddypress_fetch_group($input['group_id'] ?? null);

    if ($group instanceof WP_Error) {
        return $group;
    }

    if (!function_exists('groups_edit_base_group_details')) {
        return nibwp_buddypress_err('no_groups_api', __('This version of BuddyPress does not expose a way to edit a group from here.', 'nibwp'), 500);
    }

    $data = is_array($input['group_data'] ?? null) ? (array) $input['group_data'] : [];
    $args = ['group_id' => (int) $group->id, 'notify_members' => false];
    $changed = [];

    foreach (['name', 'description'] as $key) {
        if (isset($data[$key]) && trim((string) $data[$key]) !== '') {
            $args[$key] = sanitize_text_field((string) $data[$key]);
            $changed[] = $key;
        }
    }

    if (isset($data['slug']) && trim((string) $data['slug']) !== '') {
        $args['slug'] = sanitize_title((string) $data['slug']);
        $changed[] = 'slug';
    }

    if ($changed === []) {
        return nibwp_buddypress_err('nothing_to_change', __('Give at least one of group_data.name, group_data.description or group_data.slug.', 'nibwp'), 422);
    }

    $ok = (bool) groups_edit_base_group_details($args);

    if (!$ok) {
        return nibwp_buddypress_err('update_failed', __('BuddyPress did not save the group and did not say why.', 'nibwp'), 500);
    }

    return [
        'updated' => true,
        'group_id' => (int) $group->id,
        'changed' => $changed,
        'note' => in_array('slug', $changed, true)
            ? __('The slug changed, so the group\'s old address no longer resolves. BuddyPress keeps the previous one as a redirect, but anything that hardcoded the old URL will need updating.', 'nibwp')
            : null,
    ];
}

/**
 * Change who can see a group.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_set_group_privacy(array $input): array|WP_Error
{
    if ($denied = nibwp_buddypress_require(__('Changing a BuddyPress group\'s privacy', 'nibwp'))) {
        return $denied;
    }

    if ($off = nibwp_buddypress_component('groups')) {
        return $off;
    }

    $group = nibwp_buddypress_fetch_group($input['group_id'] ?? null);

    if ($group instanceof WP_Error) {
        return $group;
    }

    $status = isset($input['status']) ? strtolower(trim((string) $input['status'])) : '';

    if (!in_array($status, nibwp_buddypress_group_statuses(), true)) {
        return nibwp_buddypress_err(
            'bad_status',
            sprintf(
                /* translators: %s: list of valid group statuses */
                __('status must be one of: %s.', 'nibwp'),
                implode(', ', nibwp_buddypress_group_statuses())
            ),
            422
        );
    }

    if (!function_exists('groups_edit_group_settings')) {
        return nibwp_buddypress_err('no_groups_api', __('This version of BuddyPress does not expose a way to change a group\'s settings from here.', 'nibwp'), 500);
    }

    $was = isset($group->status) ? (string) $group->status : '';
    $forum = isset($group->enable_forum) ? (int) $group->enable_forum : 0;

    // Positional, because groups_edit_group_settings() takes positional
    // parameters and a named argument on a function whose signature changes
    // between majors is a runtime fatal, not a warning
    // (bp-groups/bp-groups-functions.php:424).
    $ok = (bool) groups_edit_group_settings((int) $group->id, $forum, $status);

    if (!$ok) {
        return nibwp_buddypress_err('update_failed', __('BuddyPress did not change the group\'s privacy and did not say why.', 'nibwp'), 500);
    }

    $warnings = [];

    if ($was !== 'public' && $status === 'public') {
        $warnings[] = __('This group was not public and now is. Everything already in it — every post, every file, every member\'s name — is readable by anybody, including visitors who are not logged in. Tell whoever asked.', 'nibwp');
    }

    // Widening a private group is not only a visibility change: BuddyPress
    // accepts every outstanding request to join on the way through
    // (bp-groups/bp-groups-functions.php:433), so the membership grows as a side
    // effect of a call that did not mention membership.
    if ($was === 'private' && $status === 'public') {
        $warnings[] = __('BuddyPress accepts every outstanding request to join when a private group becomes public, so the membership may have grown as a side effect of this change.', 'nibwp');
    }

    return [
        'updated' => true,
        'group_id' => (int) $group->id,
        'was' => $was,
        'now' => $status,
        'warnings' => $warnings,
    ];
}

/**
 * Delete a group.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_delete_group(array $input): array|WP_Error
{
    if ($denied = nibwp_buddypress_require(__('Deleting a BuddyPress group', 'nibwp'))) {
        return $denied;
    }

    if ($off = nibwp_buddypress_component('groups')) {
        return $off;
    }

    $group = nibwp_buddypress_fetch_group($input['group_id'] ?? null);

    if ($group instanceof WP_Error) {
        return $group;
    }

    // A group is other people's history: its membership, its activity and its
    // uploads go with it and none of it comes back. An explicit confirm, because
    // an agent reasoning its way to "and then delete the empty ones" is exactly
    // the failure this costs most.
    if (($input['confirm'] ?? null) !== true) {
        return nibwp_buddypress_err(
            'confirm_required',
            sprintf(
                /* translators: 1: group name, 2: group ID */
                __('Deleting "%1$s" (group %2$d) destroys its membership, its activity and its uploads, and cannot be undone. Pass confirm true to go ahead.', 'nibwp'),
                isset($group->name) && is_scalar($group->name) ? (string) $group->name : '',
                (int) $group->id
            ),
            428
        );
    }

    if (!function_exists('groups_delete_group')) {
        return nibwp_buddypress_err('no_groups_api', __('This version of BuddyPress does not expose a way to delete a group from here.', 'nibwp'), 500);
    }

    $ok = (bool) groups_delete_group((int) $group->id);

    if (!$ok) {
        return nibwp_buddypress_err('delete_failed', __('BuddyPress did not delete the group and did not say why.', 'nibwp'), 500);
    }

    return [
        'deleted' => true,
        'group_id' => (int) $group->id,
        'note' => __('The group and everything in it are gone. There is no undo.', 'nibwp'),
    ];
}

/**
 * Who is in a group.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_list_group_members(array $input): array|WP_Error
{
    if ($denied = nibwp_buddypress_require(__('Listing a BuddyPress group\'s members', 'nibwp'))) {
        return $denied;
    }

    if ($off = nibwp_buddypress_component('groups')) {
        return $off;
    }

    $group = nibwp_buddypress_fetch_group($input['group_id'] ?? null);

    if ($group instanceof WP_Error) {
        return $group;
    }

    if (!function_exists('groups_get_group_members')) {
        return nibwp_buddypress_err('no_groups_api', __('This version of BuddyPress does not expose a group\'s membership where this integration can read it.', 'nibwp'), 500);
    }

    $per_page = nibwp_buddypress_limit($input['per_page'] ?? null);
    $page = nibwp_buddypress_page($input['page'] ?? null);

    $result = (array) groups_get_group_members([
        'group_id' => (int) $group->id,
        'per_page' => $per_page,
        'page' => $page,
        // Organisers and moderators are members too, and an owner asking who is
        // in a group means everybody (bp-groups/bp-groups-functions.php:832).
        'exclude_admins_mods' => false,
        'exclude_banned' => false,
    ]);

    $members = [];
    $skipped = 0;

    foreach ((array) ($result['members'] ?? []) as $member) {
        $raw = is_object($member) ? nibwp_buddypress_user_props($member) : (array) $member;
        $id = (int) ($raw['ID'] ?? $raw['id'] ?? $raw['user_id'] ?? 0);

        if ($id <= 0 || !current_user_can('edit_user', $id)) {
            $skipped++;
            continue;
        }

        $raw['id'] = $id;
        $row = nibwp_buddypress_member_row($raw);
        $row['role'] = !empty($raw['is_admin']) ? 'admin' : (!empty($raw['is_mod']) ? 'mod' : 'member');
        $row['banned'] = !empty($raw['is_banned']);
        $row['confirmed'] = !isset($raw['is_confirmed']) || (bool) $raw['is_confirmed'];
        $members[] = $row;
    }

    return [
        'group_id' => (int) $group->id,
        'members' => $members,
        'returned' => count($members),
        'skipped_unreadable' => $skipped,
        'total' => (int) ($result['count'] ?? count($members)),
        'per_page' => $per_page,
        'page' => $page,
        'note' => $skipped > 0
            ? __('Some members were left out because this account cannot edit them. The total counts them; the list does not.', 'nibwp')
            : null,
    ];
}

/**
 * Put somebody in a group.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_add_group_member(array $input): array|WP_Error
{
    $user_id = nibwp_buddypress_id($input['user_id'] ?? null);

    if ($user_id <= 0) {
        return nibwp_buddypress_err('missing_user_id', __('Give the member as user_id.', 'nibwp'), 422);
    }

    if ($denied = nibwp_buddypress_require_member($user_id, __('Adding a member to a BuddyPress group', 'nibwp'))) {
        return $denied;
    }

    if ($off = nibwp_buddypress_component('groups')) {
        return $off;
    }

    $group = nibwp_buddypress_fetch_group($input['group_id'] ?? null);

    if ($group instanceof WP_Error) {
        return $group;
    }

    if (!function_exists('groups_join_group')) {
        return nibwp_buddypress_err('no_groups_api', __('This version of BuddyPress does not expose a way to add a member to a group from here.', 'nibwp'), 500);
    }

    if (function_exists('groups_is_user_member') && groups_is_user_member($user_id, (int) $group->id)) {
        return [
            'added' => false,
            'already_a_member' => true,
            'group_id' => (int) $group->id,
            'user_id' => $user_id,
        ];
    }

    $ok = (bool) groups_join_group((int) $group->id, $user_id);

    if (!$ok) {
        return nibwp_buddypress_err('join_failed', __('BuddyPress did not add that member to the group and did not say why.', 'nibwp'), 500);
    }

    return [
        'added' => true,
        'group_id' => (int) $group->id,
        'user_id' => $user_id,
        'note' => __('This joins the member without asking them, and BuddyPress records it in the group\'s activity, so other members will see it.', 'nibwp'),
    ];
}

/**
 * Take somebody out of a group.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_remove_group_member(array $input): array|WP_Error
{
    $user_id = nibwp_buddypress_id($input['user_id'] ?? null);

    if ($user_id <= 0) {
        return nibwp_buddypress_err('missing_user_id', __('Give the member as user_id.', 'nibwp'), 422);
    }

    if ($denied = nibwp_buddypress_require_member($user_id, __('Removing a member from a BuddyPress group', 'nibwp'))) {
        return $denied;
    }

    if ($off = nibwp_buddypress_component('groups')) {
        return $off;
    }

    $group = nibwp_buddypress_fetch_group($input['group_id'] ?? null);

    if ($group instanceof WP_Error) {
        return $group;
    }

    if (!function_exists('groups_remove_member')) {
        return nibwp_buddypress_err('no_groups_api', __('This version of BuddyPress does not expose a way to remove a member from a group from here.', 'nibwp'), 500);
    }

    $ok = (bool) groups_remove_member($user_id, (int) $group->id);

    if (!$ok) {
        return nibwp_buddypress_err('remove_failed', __('BuddyPress did not remove that member and did not say why — check they were a member to begin with.', 'nibwp'), 500);
    }

    return [
        'removed' => true,
        'group_id' => (int) $group->id,
        'user_id' => $user_id,
        'note' => __('The member loses access to the group. Anything they posted in it stays.', 'nibwp'),
    ];
}

/**
 * Make somebody an organiser, a moderator, or neither.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_set_group_member_role(array $input): array|WP_Error
{
    $user_id = nibwp_buddypress_id($input['user_id'] ?? null);

    if ($user_id <= 0) {
        return nibwp_buddypress_err('missing_user_id', __('Give the member as user_id.', 'nibwp'), 422);
    }

    if ($denied = nibwp_buddypress_require_member($user_id, __('Changing a member\'s role in a BuddyPress group', 'nibwp'))) {
        return $denied;
    }

    if ($off = nibwp_buddypress_component('groups')) {
        return $off;
    }

    $group = nibwp_buddypress_fetch_group($input['group_id'] ?? null);

    if ($group instanceof WP_Error) {
        return $group;
    }

    $role = isset($input['role']) ? strtolower(trim((string) $input['role'])) : '';

    if (!in_array($role, nibwp_buddypress_group_roles(), true)) {
        return nibwp_buddypress_err(
            'bad_role',
            sprintf(
                /* translators: %s: list of valid group roles */
                __('role must be one of: %s.', 'nibwp'),
                implode(', ', nibwp_buddypress_group_roles())
            ),
            422
        );
    }

    if (function_exists('groups_is_user_member') && !groups_is_user_member($user_id, (int) $group->id)) {
        return nibwp_buddypress_err('not_a_member', __('That member is not in this group, so there is no role to change. add_group_member first.', 'nibwp'), 409);
    }

    if ($role === 'member') {
        if (!function_exists('groups_demote_member')) {
            return nibwp_buddypress_err('no_groups_api', __('This version of BuddyPress does not expose a way to demote a group member from here.', 'nibwp'), 500);
        }

        $ok = (bool) groups_demote_member($user_id, (int) $group->id);
    } else {
        if (!function_exists('groups_promote_member')) {
            return nibwp_buddypress_err('no_groups_api', __('This version of BuddyPress does not expose a way to promote a group member from here.', 'nibwp'), 500);
        }

        // Positional, for the same reason as groups_edit_group_settings()
        // (bp-groups/bp-groups-functions.php:2017).
        $ok = (bool) groups_promote_member($user_id, (int) $group->id, $role);
    }

    if (!$ok) {
        return nibwp_buddypress_err('role_failed', __('BuddyPress did not change that member\'s role and did not say why.', 'nibwp'), 500);
    }

    return [
        'updated' => true,
        'group_id' => (int) $group->id,
        'user_id' => $user_id,
        'role' => $role,
        'note' => $role === 'admin'
            ? __('An organiser can change the group\'s settings, remove other members and delete the group.', 'nibwp')
            : null,
    ];
}

/* ------------------------------------------------------------------ activity */

/**
 * The activity stream.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_list_activity(array $input): array|WP_Error
{
    if ($denied = nibwp_buddypress_require(__('Reading the BuddyPress activity stream', 'nibwp'))) {
        return $denied;
    }

    if ($off = nibwp_buddypress_component('activity')) {
        return $off;
    }

    if (!function_exists('bp_activity_get')) {
        return nibwp_buddypress_err('no_activity_api', __('This version of BuddyPress does not expose its activity query where this integration can read it.', 'nibwp'), 500);
    }

    $per_page = nibwp_buddypress_limit($input['per_page'] ?? null);
    $page = nibwp_buddypress_page($input['page'] ?? null);

    // BuddyPress takes these as one nested 'filter' array rather than as
    // top-level arguments (bp-activity/bp-activity-functions.php:1911-1917).
    $filter = [];

    if (nibwp_buddypress_id($input['user_id'] ?? null) > 0) {
        $filter['user_id'] = nibwp_buddypress_id($input['user_id']);
    }
    if (isset($input['component']) && trim((string) $input['component']) !== '') {
        $filter['object'] = sanitize_key((string) $input['component']);
    }
    if (isset($input['type']) && trim((string) $input['type']) !== '') {
        $filter['action'] = sanitize_key((string) $input['type']);
    }
    if (nibwp_buddypress_id($input['group_id'] ?? null) > 0) {
        $filter['primary_id'] = nibwp_buddypress_id($input['group_id']);
    }

    $args = [
        'per_page' => $per_page,
        'page' => $page,
        'count_total' => true,
        'filter' => $filter,
        // Items an administrator hid from the site-wide stream are included, with
        // hidden_sitewide on each row, because "what did you hide" is a question
        // the person who hid it is entitled to ask.
        'show_hidden' => true,
    ];

    $search = isset($input['search']) ? trim((string) $input['search']) : '';

    if ($search !== '') {
        $args['search_terms'] = $search;
    }

    $result = (array) bp_activity_get($args);
    $items = [];

    foreach ((array) ($result['activities'] ?? []) as $activity) {
        if (!is_object($activity)) {
            continue;
        }

        $items[] = nibwp_buddypress_activity_row($activity);
    }

    return [
        'activities' => $items,
        'returned' => count($items),
        'skipped_unreadable' => 0,
        'total' => (int) ($result['total'] ?? 0),
        'per_page' => $per_page,
        'page' => $page,
        'filter' => $filter,
        'note' => __('This is the public activity stream. Private messages are a different component and are not part of it.', 'nibwp'),
    ];
}

/**
 * One activity item.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_get_activity(array $input): array|WP_Error
{
    if ($denied = nibwp_buddypress_require(__('Reading a BuddyPress activity item', 'nibwp'))) {
        return $denied;
    }

    if ($off = nibwp_buddypress_component('activity')) {
        return $off;
    }

    $activity = nibwp_buddypress_fetch_activity($input['activity_id'] ?? null);

    if ($activity instanceof WP_Error) {
        return $activity;
    }

    return [
        'activity' => nibwp_buddypress_activity_row($activity),
        'note' => __('An activity item is something a member published. Changing or deleting it is visible to everybody who could see it.', 'nibwp'),
    ];
}

/**
 * Publish an update to the community feed.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_post_activity(array $input): array|WP_Error
{
    if ($denied = nibwp_buddypress_require(__('Posting to the BuddyPress activity stream', 'nibwp'))) {
        return $denied;
    }

    if ($off = nibwp_buddypress_component('activity')) {
        return $off;
    }

    if (!function_exists('bp_activity_add')) {
        return nibwp_buddypress_err('no_activity_api', __('This version of BuddyPress does not expose a way to post activity from here.', 'nibwp'), 500);
    }

    $data = is_array($input['activity_data'] ?? null) ? (array) $input['activity_data'] : [];
    $content = isset($data['content']) ? trim((string) $data['content']) : '';

    if ($content === '') {
        return nibwp_buddypress_err('missing_content', __('Give what to post as activity_data.content.', 'nibwp'), 422);
    }

    // Posting as somebody else puts words in a real person's mouth in front of
    // their community, so it takes the per-member gate, not the community one.
    $author = nibwp_buddypress_id($data['user_id'] ?? null);

    if ($author <= 0) {
        $author = (int) get_current_user_id();
    }

    if ($denied = nibwp_buddypress_require_member($author, __('Posting activity as another member', 'nibwp'))) {
        return $denied;
    }

    $args = [
        'content' => $content,
        'user_id' => $author,
        'component' => isset($data['component']) && trim((string) $data['component']) !== ''
            ? sanitize_key((string) $data['component'])
            : 'activity',
        'type' => isset($data['type']) && trim((string) $data['type']) !== ''
            ? sanitize_key((string) $data['type'])
            : 'activity_update',
    ];

    $group_id = nibwp_buddypress_id($data['group_id'] ?? null);

    if ($group_id > 0) {
        if ($off = nibwp_buddypress_component('groups')) {
            return $off;
        }

        $group = nibwp_buddypress_fetch_group($group_id);

        if ($group instanceof WP_Error) {
            return $group;
        }

        $args['component'] = 'groups';
        $args['item_id'] = (int) $group->id;
    }

    $activity_id = bp_activity_add($args);

    if (!$activity_id) {
        return nibwp_buddypress_err('post_failed', __('BuddyPress did not post the update and did not say why.', 'nibwp'), 500);
    }

    return [
        'posted' => true,
        'activity' => ['id' => (int) $activity_id, 'user_id' => $author],
        'note' => __('This is now live in the community feed under that member\'s name, and anybody following the feed can see it. delete_activity removes it; hide_activity takes it out of the site-wide stream without destroying it.', 'nibwp'),
    ];
}

/**
 * Destroy an activity item.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_delete_activity(array $input): array|WP_Error
{
    if ($denied = nibwp_buddypress_require(__('Deleting a BuddyPress activity item', 'nibwp'))) {
        return $denied;
    }

    if ($off = nibwp_buddypress_component('activity')) {
        return $off;
    }

    $activity = nibwp_buddypress_fetch_activity($input['activity_id'] ?? null);

    if ($activity instanceof WP_Error) {
        return $activity;
    }

    // Somebody wrote this. Deleting it takes their words off their own feed and
    // out of every stream that carried them, with no undo and no record, so it
    // takes an explicit confirm and the per-member gate on its author.
    $author = isset($activity->user_id) ? (int) $activity->user_id : 0;

    if ($author > 0 && ($denied = nibwp_buddypress_require_member($author, __('Deleting another member\'s post', 'nibwp')))) {
        return $denied;
    }

    if (($input['confirm'] ?? null) !== true) {
        return nibwp_buddypress_err(
            'confirm_required',
            sprintf(
                /* translators: %d: activity ID */
                __('Activity item %d is a post somebody made. Deleting it cannot be undone. Pass confirm true to go ahead, or use hide_activity, which is reversible.', 'nibwp'),
                (int) $activity->id
            ),
            428
        );
    }

    if (!function_exists('bp_activity_delete')) {
        return nibwp_buddypress_err('no_activity_api', __('This version of BuddyPress does not expose a way to delete activity from here.', 'nibwp'), 500);
    }

    $ok = (bool) bp_activity_delete(['id' => (int) $activity->id]);

    if (!$ok) {
        return nibwp_buddypress_err('delete_failed', __('BuddyPress did not delete the activity item and did not say why.', 'nibwp'), 500);
    }

    return [
        'deleted' => true,
        'activity_id' => (int) $activity->id,
        'author_id' => $author,
        'note' => __('That post is gone from every stream that carried it. There is no undo.', 'nibwp'),
    ];
}

/**
 * Take an item out of the site-wide stream, or put it back.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_set_activity_hidden(array $input, bool $hide): array|WP_Error
{
    $what = $hide
        ? __('Hiding a BuddyPress activity item', 'nibwp')
        : __('Unhiding a BuddyPress activity item', 'nibwp');

    if ($denied = nibwp_buddypress_require($what)) {
        return $denied;
    }

    if ($off = nibwp_buddypress_component('activity')) {
        return $off;
    }

    $activity = nibwp_buddypress_fetch_activity($input['activity_id'] ?? null);

    if ($activity instanceof WP_Error) {
        return $activity;
    }

    if (!class_exists('BP_Activity_Activity')) {
        return nibwp_buddypress_err('no_activity_api', __('This version of BuddyPress does not expose its activity model where this integration can change one.', 'nibwp'), 500);
    }

    // hide_sitewide is a column on the activity row and the only thing changed
    // here; the item, its author and its content are left exactly as they were
    // (bp-activity/classes/class-bp-activity-activity.php:111, 311).
    $item = new BP_Activity_Activity((int) $activity->id);

    if (empty($item->id)) {
        return nibwp_buddypress_err(
            'activity_not_found',
            sprintf(
                /* translators: %d: activity ID */
                __('There is no activity item %d.', 'nibwp'),
                (int) $activity->id
            ),
            404
        );
    }

    $was = (bool) ($item->hide_sitewide ?? false);
    $item->hide_sitewide = $hide ? 1 : 0;

    if (!$item->save()) {
        return nibwp_buddypress_err('save_failed', __('BuddyPress did not save the activity item and did not say why.', 'nibwp'), 500);
    }

    return [
        'activity_id' => (int) $activity->id,
        'was_hidden' => $was,
        'hidden' => $hide,
        'note' => $hide
            ? __('The item is out of the site-wide stream. It still exists, its author can still see it on their own profile, and unhide_activity puts it back.', 'nibwp')
            : __('The item is back in the site-wide stream, where everybody who follows the feed can see it again.', 'nibwp'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_hide_activity(array $input): array|WP_Error
{
    return nibwp_buddypress_set_activity_hidden($input, true);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_unhide_activity(array $input): array|WP_Error
{
    return nibwp_buddypress_set_activity_hidden($input, false);
}

/* ------------------------------------------------- counts, not conversations */

/**
 * How connected the community is.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_friendship_counts(array $input): array|WP_Error
{
    if ($denied = nibwp_buddypress_require(__('Counting BuddyPress friendships', 'nibwp'))) {
        return $denied;
    }

    if ($off = nibwp_buddypress_component('friends')) {
        return $off;
    }

    global $wpdb;

    $user_id = nibwp_buddypress_id($input['user_id'] ?? null);

    // One member's friend count is about that member, so it takes the per-member
    // gate; the community-wide totals do not.
    if ($user_id > 0 && ($denied = nibwp_buddypress_require_member($user_id, __('Counting one member\'s friendships', 'nibwp')))) {
        return $denied;
    }

    $for_member = null;

    if ($user_id > 0 && function_exists('friends_get_total_friend_count')) {
        $for_member = [
            'user_id' => $user_id,
            'friends' => (int) friends_get_total_friend_count($user_id),
        ];

        if (function_exists('friends_get_friendship_request_user_ids')) {
            $for_member['incoming_requests'] = count((array) friends_get_friendship_request_user_ids($user_id));
        }
    }

    $table = nibwp_buddypress_table('friends');
    $confirmed = null;
    $pending = null;

    if ($table !== '' && is_object($wpdb) && method_exists($wpdb, 'get_var')) {
        $confirmed = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(id) FROM ' . $table . ' WHERE is_confirmed = %d', 1)
        );
        $pending = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(id) FROM ' . $table . ' WHERE is_confirmed = %d', 0)
        );
    }

    return [
        'confirmed_friendships' => $confirmed,
        'pending_requests' => $pending,
        'member' => $for_member,
        'note' => __('Counts only. Who is friends with whom is a map of a real social group, and this integration does not hand one out.', 'nibwp'),
    ];
}

/**
 * How much private messaging happens — and nothing about what it says.
 *
 * The messages table has a `subject` and a `message` column
 * (bp-core/admin/bp-core-admin-schema.php:257-266). Neither is selected here,
 * by any action, at any nesting depth. A private message between two members is
 * not something an agent reads, and there is no capability that changes that.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_buddypress_message_counts(array $input): array|WP_Error
{
    if ($denied = nibwp_buddypress_require(__('Counting BuddyPress private messages', 'nibwp'))) {
        return $denied;
    }

    if ($off = nibwp_buddypress_component('messages')) {
        return $off;
    }

    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
        return nibwp_buddypress_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    $messages = nibwp_buddypress_table('messages');
    $recipients = nibwp_buddypress_table('recipients');

    if ($messages === '' || $recipients === '') {
        return nibwp_buddypress_err('no_tables', __('BuddyPress\'s own message table names could not be resolved, so nothing was counted.', 'nibwp'), 500);
    }

    $limit = nibwp_buddypress_limit($input['per_page'] ?? null);

    // Three aggregates and a bounded list of threads. Every column named here is
    // metadata: an id, a count, a date. Not subject, not message.
    $totals = (array) $wpdb->get_results(
        $wpdb->prepare(
            'SELECT COUNT(id) AS messages, COUNT(DISTINCT thread_id) AS threads, MAX(date_sent) AS last_sent'
            . ' FROM ' . $messages . ' WHERE id > %d',
            0
        ),
        ARRAY_A
    );

    $summary = is_array($totals[0] ?? null) ? (array) $totals[0] : [];

    $unread = (int) $wpdb->get_var(
        $wpdb->prepare('SELECT COALESCE(SUM(unread_count), 0) FROM ' . $recipients . ' WHERE is_deleted = %d', 0)
    );

    $threads = (array) $wpdb->get_results(
        $wpdb->prepare(
            'SELECT m.thread_id, COUNT(m.id) AS messages, COUNT(DISTINCT m.sender_id) AS senders,'
            . ' MIN(m.date_sent) AS started, MAX(m.date_sent) AS last_sent'
            . ' FROM ' . $messages . ' m'
            . ' WHERE m.thread_id > %d'
            . ' GROUP BY m.thread_id ORDER BY last_sent DESC LIMIT %d',
            0,
            $limit
        ),
        ARRAY_A
    );

    $rows = [];

    foreach ($threads as $thread) {
        $thread_id = (int) ($thread['thread_id'] ?? 0);

        $participants = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(DISTINCT user_id) FROM ' . $recipients . ' WHERE thread_id = %d AND is_deleted = %d',
                $thread_id,
                0
            )
        );

        $rows[] = [
            'thread_id' => $thread_id,
            'messages' => (int) ($thread['messages'] ?? 0),
            'senders' => (int) ($thread['senders'] ?? 0),
            'participants' => $participants,
            'started' => (string) ($thread['started'] ?? ''),
            'last_sent' => (string) ($thread['last_sent'] ?? ''),
        ];
    }

    return [
        'threads' => (int) ($summary['threads'] ?? 0),
        'messages' => (int) ($summary['messages'] ?? 0),
        'unread' => $unread,
        'last_sent' => isset($summary['last_sent']) && is_scalar($summary['last_sent'])
            ? (string) $summary['last_sent']
            : null,
        'recent_threads' => $rows,
        'returned' => count($rows),
        'message_bodies_returned' => false,
        'message_subjects_returned' => false,
        'note' => __('Counts and thread metadata only. The text of a private message, and its subject, are never returned by anything here, and no capability unlocks them. If somebody needs to read a thread, they are the wrong tool for it — the members involved are.', 'nibwp'),
    ];
}
