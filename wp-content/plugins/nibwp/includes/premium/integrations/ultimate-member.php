<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Ultimate Member integration for NIBWP (Pro tier).
 *
 * Verified against Ultimate Member 2.14.0 (ultimate-member.php:6).
 *
 * This plugin's entire job is deciding who may see whose profile, so the
 * integration is built around the same question rather than around convenience:
 * may THIS account read THAT member? Two things make that answerable here
 * without re-implementing the plugin's rules.
 *
 * First, Ultimate Member hooks `user_has_cap` and rewrites edit_users,
 * delete_users and list_users according to its own role settings — can_view_all,
 * can_edit_everyone, can_edit_roles and can_access_private_profile
 * (includes/common/class-users.php:21 and :67-96). So current_user_can('edit_user',
 * $id) is not merely the WordPress answer, it is already the Ultimate Member
 * answer. Asking WordPress is therefore more correct than asking the plugin, and
 * it stays correct when the plugin's rules change.
 *
 * Second, the plugin's own status helpers do NOT check permissions: every
 * can_be_approved() / can_be_rejected() / can_be_deactivated() has its
 * can_current_user_edit_user() call commented out
 * (includes/common/class-users.php:464-467, 550-553, 645-648, 744-747). wp-admin
 * gates those actions separately with current_user_can('edit_users')
 * (includes/admin/class-actions-listener.php:58). Calling approve() with no check
 * of our own would hand an unprivileged account the power to let someone into the
 * site, so every write here asks first.
 *
 * WHAT NEVER LEAVES. Member data goes out through one allow-list
 * (nibwp_um_member_fields()) and one shaper (nibwp_um_member_record()). A
 * denylist would be wrong by construction: WordPress user meta holds
 * session_tokens, {prefix}capabilities, user_activation_key and
 * _application_passwords, and Ultimate Member adds account_secret_hash and
 * account_secret_hash_expiry (includes/common/class-users.php:259-267, 285-295),
 * _um_card_anchor_token (includes/core/class-member-directory.php:352) and
 * um_user_blocked__metadata, which stores a whole capability map
 * (includes/common/class-secure.php:217-229). The plugin's own banned-key list
 * covers four of those and misses the rest (includes/core/class-user.php:97-115)
 * — which is exactly why this is an allow-list.
 *
 * REQUIRES: Ultimate Member active. Every action refuses with
 * `nibwp_um_missing` when it is not.
 */

/** One guard for every action: is Ultimate Member even here? */
function nibwp_um_guard(): ?WP_Error
{
    if (!defined('UM_VERSION') && !function_exists('UM')) {
        return new WP_Error(
            'nibwp_um_missing',
            __('Ultimate Member is not active on this site.', 'nibwp')
        );
    }

    return null;
}

/** The single source of truth for the action enum and the switch that serves it. */
function nibwp_um_actions(): array
{
    return [
        // Plugin state
        'plugin_info',
        // Members and their approval status
        'list_members', 'get_member', 'member_status_counts',
        'approve_member', 'reject_member', 'set_member_pending',
        'deactivate_member', 'reactivate_member',
        // Roles and what each role may do
        'list_roles', 'get_role', 'update_role_permissions',
        // Registration / login / profile forms and their fields
        'list_forms', 'get_form', 'list_form_fields', 'update_form_field',
        // Member directories
        'list_directories', 'get_directory', 'update_directory_settings',
        // Content restriction
        'get_restriction_settings', 'get_post_restriction', 'set_post_restriction',
        // Email notifications
        'list_emails', 'get_email', 'update_email',
    ];
}

/** Actions that change what somebody can reach, and so cannot run unconfirmed. */
function nibwp_um_confirm_actions(): array
{
    return ['reject_member', 'deactivate_member', 'update_role_permissions', 'set_post_restriction'];
}

/**
 * One of Ultimate Member's service objects, or null.
 *
 * UM() answers unknown method names through __call() (includes/class-init.php:113),
 * which returns null rather than erroring, so method_exists() is the only honest
 * test that a version still carries the service.
 */
function nibwp_um_service(string $name): ?object
{
    if (!function_exists('UM')) {
        return null;
    }

    try {
        $um = UM();
        if (!is_object($um) || !method_exists($um, $name)) {
            return null;
        }
        $service = $um->{$name}();

        return is_object($service) ? $service : null;
    } catch (\Throwable $e) {
        return null;
    }
}

/** The um\common\Users service, which owns statuses and the status transitions. */
function nibwp_um_users_service(): ?object
{
    $common = nibwp_um_service('common');
    if ($common === null || !method_exists($common, 'users')) {
        return null;
    }

    try {
        $users = $common->users();

        return is_object($users) ? $users : null;
    } catch (\Throwable $e) {
        return null;
    }
}

/** A plugin setting, read through UM's own option layer (includes/core/class-options.php:184). */
function nibwp_um_option(string $key): mixed
{
    $options = nibwp_um_service('options');
    if ($options === null || !method_exists($options, 'get')) {
        return '';
    }

    try {
        return $options->get($key);
    } catch (\Throwable $e) {
        return '';
    }
}

/** Write one plugin setting back through UM's own option layer. */
function nibwp_um_option_update(string $key, mixed $value): bool
{
    $options = nibwp_um_service('options');
    if ($options === null || !method_exists($options, 'update')) {
        return false;
    }

    try {
        $options->update($key, $value);

        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * The account statuses this install knows about.
 *
 * Read from the plugin so a filtered status list is honoured
 * (includes/common/class-users.php:130-150); the fallback is the five it ships,
 * so a listing can still validate a status filter on a version that dropped the
 * method.
 *
 * @return array<string, string>
 */
function nibwp_um_statuses(): array
{
    $users = nibwp_um_users_service();
    if ($users !== null && method_exists($users, 'statuses_list')) {
        try {
            $list = $users->statuses_list();
            if (is_array($list)) {
                $clean = [];
                foreach ($list as $key => $label) {
                    if (is_string($key) && $key !== '' && is_scalar($label)) {
                        $clean[$key] = (string) $label;
                    }
                }
                if ($clean !== []) {
                    return $clean;
                }
            }
        } catch (\Throwable $e) {
            // Fall through to the shipped list.
        }
    }

    return [
        'approved'                    => __('Approved', 'nibwp'),
        'awaiting_admin_review'       => __('Pending administrator review', 'nibwp'),
        'awaiting_email_confirmation' => __('Waiting email confirmation', 'nibwp'),
        'inactive'                    => __('Membership inactive', 'nibwp'),
        'rejected'                    => __('Membership rejected', 'nibwp'),
    ];
}

/** A refusal that says "you may not", never "that does not exist". */
function nibwp_um_forbidden(string $message): WP_Error
{
    return new WP_Error('nibwp_um_forbidden', $message);
}

/** Plugin-wide surface: the capability that opens the plugin's own settings. */
function nibwp_um_require_cap(string $cap, string $what): ?WP_Error
{
    if (current_user_can($cap)) {
        return null;
    }

    return nibwp_um_forbidden(sprintf(
        /* translators: 1: what was attempted, 2: WordPress capability name. */
        __('%1$s needs the %2$s capability, which this account does not have.', 'nibwp'),
        $what,
        $cap
    ));
}

/**
 * Object-level authorisation: this member, this post, not "users in general".
 *
 * The id is deliberately absent from the message. A refusal that named what was
 * asked for would let a caller who may read nothing enumerate what exists.
 */
function nibwp_um_require_object_cap(string $cap, int $object_id, string $what): ?WP_Error
{
    if ($object_id > 0 && current_user_can($cap, $object_id)) {
        return null;
    }

    return nibwp_um_forbidden(sprintf(
        /* translators: 1: what was attempted, 2: WordPress capability name. */
        __('%1$s is not allowed for this account (%2$s was refused for that record).', 'nibwp'),
        $what,
        $cap
    ));
}

/** Anything that moves the walls says so before it runs, not after. */
function nibwp_um_confirm_gate(string $action, array $in): ?WP_Error
{
    if (!in_array($action, nibwp_um_confirm_actions(), true)) {
        return null;
    }
    if (!empty($in['confirm'])) {
        return null;
    }

    return new WP_Error(
        'nibwp_um_unconfirmed',
        __('This changes what people can reach on the site. Re-issue the call with confirm set to true if that is intended.', 'nibwp')
    );
}

/**
 * Clamp a caller-supplied count.
 *
 * Bounded on the way in, so no listing can be talked into returning the whole
 * member base by asking for a big enough page.
 */
function nibwp_um_bound(mixed $value, int $default, int $max): int
{
    $number = is_numeric($value) ? (int) $value : $default;
    if ($number < 1) {
        $number = $default;
    }

    return min($max, $number);
}

/**
 * The fields a member record may carry out of here, and the type each leaves as.
 *
 * An allow-list. `string_list` is the one non-scalar shape allowed, and only
 * because a role set genuinely is a list; its members are still filtered to
 * scalars one by one.
 *
 * @return array<string, string> field => int|string|string_list
 */
function nibwp_um_member_fields(): array
{
    return [
        'id'              => 'int',
        'username'        => 'string',
        'email'           => 'string',
        'display_name'    => 'string',
        'first_name'      => 'string',
        'last_name'       => 'string',
        'nickname'        => 'string',
        'description'     => 'string',
        'website'         => 'string',
        'registered'      => 'string',
        'roles'           => 'string_list',
        'um_role'         => 'string',
        'account_status'  => 'string',
        'status_label'    => 'string',
        'profile_privacy' => 'string',
        'hide_in_members' => 'string',
        'last_login'      => 'string',
        'blocked'         => 'string',
        'profile_url'     => 'string',
    ];
}

/**
 * The only shape a member leaves this integration in.
 *
 * A value that is not scalar is dropped rather than cast, because an array is
 * how a capability map or a token set travels out under an innocent key — and
 * (string) on an array emits "Array" while telling nobody anything.
 *
 * @param array<string, mixed> $raw
 * @return array<string, mixed>
 */
function nibwp_um_member_record(array $raw): array
{
    $record = [];

    foreach (nibwp_um_member_fields() as $field => $type) {
        if (!array_key_exists($field, $raw)) {
            continue;
        }
        $value = $raw[$field];

        if ($type === 'string_list') {
            if (!is_array($value)) {
                continue;
            }
            $list = [];
            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $list[] = (string) $item;
                }
            }
            $record[$field] = $list;
            continue;
        }

        if (!is_scalar($value)) {
            continue;
        }

        $record[$field] = $type === 'int' ? (int) $value : (string) $value;
    }

    return $record;
}

/**
 * Whether a user meta key that a FORM declared may be read back as a value.
 *
 * Form fields are author-defined, so their metakeys are input rather than a fixed
 * list, and the rule is deliberately over-strict: lower-case word characters only
 * (which rules out _application_passwords and every _um_* internal), no substring
 * that belongs to a credential, and the plugin's own banned-key test on top when
 * this version carries it (includes/core/class-user.php:187-201). A legitimate
 * field called "monkey" is refused by the "key" fragment; that is the failure
 * mode to prefer.
 */
function nibwp_um_meta_key_safe(string $key): bool
{
    if ($key === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
        return false;
    }

    $fragments = [
        'pass', 'secret', 'secur', 'token', 'hash', 'session', 'nonce', 'salt',
        'auth', 'cap', 'level', 'activation', 'key', 'blocked', 'credential',
    ];
    foreach ($fragments as $fragment) {
        if (str_contains($key, $fragment)) {
            return false;
        }
    }

    // Never shadow an account field that already travels under its own name.
    if (array_key_exists($key, nibwp_um_member_fields())) {
        return false;
    }

    $user = nibwp_um_service('user');
    if ($user !== null && method_exists($user, 'is_metakey_banned')) {
        try {
            if ($user->is_metakey_banned($key)) {
                return false;
            }
        } catch (\Throwable $e) {
            // An unreadable answer is treated as "banned".
            return false;
        }
    }

    return true;
}

/**
 * The profile metakeys the site's own forms declare, capped.
 *
 * `um_fields` is the global field store (includes/core/class-fields.php:259); its
 * array key is the field's metakey (includes/admin/core/class-admin-builder.php:653).
 * Password-type fields are dropped whatever they are called.
 *
 * @return list<string>
 */
function nibwp_um_declared_profile_metakeys(): array
{
    $fields = get_option('um_fields', []);
    if (!is_array($fields)) {
        return [];
    }

    $keys = [];
    foreach ($fields as $key => $args) {
        if (count($keys) >= 200) {
            break;
        }
        $metakey = is_string($key) ? $key : '';
        if (is_array($args) && isset($args['metakey']) && is_string($args['metakey'])) {
            $metakey = $args['metakey'];
        }
        $type = is_array($args) && isset($args['type']) && is_scalar($args['type']) ? (string) $args['type'] : '';
        if ($type === 'password') {
            continue;
        }
        if (!nibwp_um_meta_key_safe($metakey)) {
            continue;
        }
        $keys[$metakey] = true;
    }

    return array_keys($keys);
}

/**
 * The values a member submitted for the fields the site's forms declare.
 *
 * Scalars only, bounded, and only for keys that passed nibwp_um_meta_key_safe().
 *
 * @return array<string, string>
 */
function nibwp_um_member_profile_values(int $user_id): array
{
    $values = [];

    foreach (nibwp_um_declared_profile_metakeys() as $metakey) {
        $value = get_user_meta($user_id, $metakey, true);
        if (!is_scalar($value) || (string) $value === '') {
            continue;
        }
        $values[$metakey] = (string) $value;
    }

    return $values;
}

/**
 * Everything known about one member before the allow-list sees it.
 *
 * $deep is off for listings: the profile URL is another meta read plus a
 * permalink build per member, and a page of it is a page of those.
 *
 * @return array<string, mixed>
 */
function nibwp_um_member_raw(int $id, object $user, bool $deep = false): array
{
    $statuses = nibwp_um_statuses();

    // An empty account_status means approved to the plugin
    // (includes/core/class-user.php:1742-1745), and reporting "" reads like a
    // broken account rather than a working one.
    $status = get_user_meta($id, 'account_status', true);
    $status = is_scalar($status) && (string) $status !== '' ? (string) $status : 'approved';

    $raw = [
        'id'              => $id,
        'username'        => $user->user_login ?? '',
        'email'           => $user->user_email ?? '',
        'display_name'    => $user->display_name ?? '',
        'first_name'      => get_user_meta($id, 'first_name', true),
        'last_name'       => get_user_meta($id, 'last_name', true),
        'nickname'        => get_user_meta($id, 'nickname', true),
        'description'     => get_user_meta($id, 'description', true),
        'website'         => $user->user_url ?? '',
        'registered'      => $user->user_registered ?? '',
        'roles'           => isset($user->roles) && is_array($user->roles) ? array_values($user->roles) : [],
        'um_role'         => '',
        'account_status'  => $status,
        'status_label'    => $statuses[$status] ?? '',
        'profile_privacy' => get_user_meta($id, 'profile_privacy', true),
        'hide_in_members' => get_user_meta($id, 'hide_in_members', true),
        'last_login'      => get_user_meta($id, '_um_last_login', true),
        'blocked'         => get_user_meta($id, 'um_user_blocked', true),
    ];

    $roles = nibwp_um_service('roles');
    if ($roles !== null && method_exists($roles, 'get_um_user_role')) {
        try {
            $um_role = $roles->get_um_user_role($id);
            $raw['um_role'] = is_scalar($um_role) ? (string) $um_role : '';
        } catch (\Throwable $e) {
            $raw['um_role'] = '';
        }
    }

    if ($deep) {
        $user_service = nibwp_um_service('user');
        if ($user_service !== null && method_exists($user_service, 'get_profile_link')) {
            try {
                // get_profile_link() only reads. um_user_profile_url() would
                // generate a missing slug, and a read must not write.
                $link = $user_service->get_profile_link($id);
                $raw['profile_url'] = is_scalar($link) ? (string) $link : '';
            } catch (\Throwable $e) {
                $raw['profile_url'] = '';
            }
        }
    }

    return $raw;
}

wp_register_ability('nibwp/ultimate-member-manage', [
    'label' => __('Ultimate Member – Community Manager', 'nibwp'),
    'description' => 'Run an Ultimate Member community: member accounts and their approval status (approve, reject, put back to pending, deactivate, reactivate), the plugin roles and what each role may do, registration/login/profile forms and their fields, member directories, content restriction, and the notification emails the plugin sends. Reading a member needs list_users plus edit_user for that person; records this account may not read are counted and skipped rather than included.',
    'category' => 'membership',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_um_actions(),
                'description' => 'The community operation to perform.',
            ],

            'user_id'      => ['type' => 'integer', 'description' => 'WordPress user ID of the member.'],
            'role_id'      => ['type' => 'string', 'description' => 'Role slug, e.g. subscriber or um_member.'],
            'form_id'      => ['type' => 'integer', 'description' => 'um_form post ID.'],
            'directory_id' => ['type' => 'integer', 'description' => 'um_directory post ID.'],
            'post_id'      => ['type' => 'integer', 'description' => 'Post, page or CPT ID for content restriction.'],
            'field_key'    => ['type' => 'string', 'description' => 'A form field metakey, which is also its key inside the form.'],
            'email_key'    => ['type' => 'string', 'description' => 'Email notification key, e.g. approved_email.'],

            'status' => ['type' => 'string', 'description' => 'Account status to filter a listing by: approved, awaiting_admin_review, awaiting_email_confirmation, inactive, rejected.'],
            'role'   => ['type' => 'string', 'description' => 'Role slug to filter a member listing by.'],
            'search' => ['type' => 'string', 'description' => 'Free-text filter where the action supports it.'],

            'with_profile_fields' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'get_member only: also return the values this member submitted for the fields the site forms declare.',
            ],

            'settings' => [
                'type' => 'object',
                'description' => 'The changes to apply, for update_role_permissions, update_form_field, update_directory_settings and set_post_restriction. Keys outside each action allow-list are reported back as ignored rather than written.',
            ],

            'subject' => ['type' => 'string', 'description' => 'update_email: the subject line.'],
            'body'    => ['type' => 'string', 'description' => 'update_email: the message body.'],
            'enabled' => ['type' => 'boolean', 'description' => 'update_email: whether the plugin sends this notification at all.'],

            'per_page' => ['type' => 'integer', 'default' => 20, 'description' => 'Records per page. Capped at 100, whatever is asked for.'],
            'page'     => ['type' => 'integer', 'default' => 1],

            'confirm' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Required for reject_member, deactivate_member, update_role_permissions and set_post_restriction.',
            ],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'nibwp_um_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Run an Ultimate Member community.',
                '',
                'THIS HUB READS PERSONAL DATA. A listing needs list_users, and every single',
                'member is then checked with edit_user for that person. A response can',
                'therefore hold fewer members than the site has: `skipped` is in every',
                'listing and means "this account may not read those". Report it rather than',
                'presenting a partial list as the whole membership.',
                '',
                'APPROVAL CHANGES ACCESS. approve_member lets somebody into the site and',
                'sends them the welcome or approval email. reject_member and',
                'deactivate_member end every session they have and lock them out. Both need',
                'confirm=true. The plugin allows only some transitions: rejected only from',
                'awaiting_admin_review, reactivated only from inactive, approved only from',
                'something that is neither approved nor inactive. Read get_member first and',
                'say what you are about to change.',
                '',
                'ROLES: update_role_permissions writes the plugin own role settings only. It',
                'will not touch wp_capabilities — changing a role WordPress capabilities is',
                'privilege escalation and does not belong in this hub.',
                '',
                'FORMS: a form fields are keyed by metakey. update_form_field edits the',
                'labelling and validation of an existing field; it will not change a field',
                'metakey or type, because both rewire where member data is stored.',
                '',
                'EMAILS: update_email writes the subject, body and on/off switch. A theme',
                'that overrides the template wins over the body stored here, and get_email',
                'reports when that is the case.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_um_manage(array $input): array|WP_Error
{
    if ($guard = nibwp_um_guard()) {
        return $guard;
    }

    $action   = (string) ($input['action'] ?? '');
    $per_page = nibwp_um_bound($input['per_page'] ?? null, 20, 100);
    $page     = max(1, (int) ($input['page'] ?? 1));

    try {
        return nibwp_um_dispatch($action, $input, $per_page, $page);
    } catch (\Throwable $e) {
        // An uncaught Error inside an ability takes down the whole REST request,
        // and Ultimate Member throws from deep inside its own service objects.
        return new WP_Error('nibwp_um_failed', sprintf(
            /* translators: 1: action name, 2: error message. */
            __('Ultimate Member could not complete %1$s: %2$s', 'nibwp'),
            $action,
            $e->getMessage()
        ));
    }
}

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_um_dispatch(string $action, array $in, int $per_page, int $page): array|WP_Error
{
    switch ($action) {
        case 'plugin_info':
            return nibwp_um_plugin_info();

        case 'list_members':
            return nibwp_um_list_members($in, $per_page, $page);

        case 'get_member':
            return nibwp_um_get_member($in);

        case 'member_status_counts':
            return nibwp_um_status_counts();

        case 'approve_member':
        case 'reject_member':
        case 'set_member_pending':
        case 'deactivate_member':
        case 'reactivate_member':
            return nibwp_um_change_status($action, $in);

        case 'list_roles':
            return nibwp_um_list_roles($per_page, $page);

        case 'get_role':
            return nibwp_um_get_role($in);

        case 'update_role_permissions':
            return nibwp_um_update_role_permissions($in);

        case 'list_forms':
        case 'list_directories':
            return nibwp_um_list_cpt($action, $in, $per_page, $page);

        case 'get_form':
            return nibwp_um_get_form($in);

        case 'list_form_fields':
            return nibwp_um_list_form_fields($in, $per_page, $page);

        case 'update_form_field':
            return nibwp_um_update_form_field($in);

        case 'get_directory':
            return nibwp_um_get_directory($in);

        case 'update_directory_settings':
            return nibwp_um_update_directory_settings($in);

        case 'get_restriction_settings':
            return nibwp_um_get_restriction_settings();

        case 'get_post_restriction':
            return nibwp_um_get_post_restriction($in);

        case 'set_post_restriction':
            return nibwp_um_set_post_restriction($in);

        case 'list_emails':
            return nibwp_um_list_emails($per_page, $page);

        case 'get_email':
            return nibwp_um_get_email($in);

        case 'update_email':
            return nibwp_um_update_email($in);
    }

    return new WP_Error('nibwp_um_unknown_action', sprintf(
        /* translators: %s: the action that was requested. */
        __('Unknown Ultimate Member action: %s', 'nibwp'),
        $action
    ));
}

/* ─── plugin state ───────────────────────────────────────────────────────── */

/** @return array<string, mixed>|WP_Error */
function nibwp_um_plugin_info(): array|WP_Error
{
    if ($denied = nibwp_um_require_cap('manage_options', __('Reading the Ultimate Member configuration', 'nibwp'))) {
        return $denied;
    }

    $role_keys = get_option('um_roles', []);

    return [
        'version'             => defined('UM_VERSION') ? UM_VERSION : null,
        'statuses'            => nibwp_um_statuses(),
        'custom_role_keys'    => is_array($role_keys) ? array_values(array_filter($role_keys, 'is_scalar')) : [],
        'members_page_on'     => (bool) nibwp_um_option('members_page'),
        'global_site_access'  => (int) nibwp_um_option('accessible'),
        'default_forms'       => nibwp_um_int_map(get_option('um_core_forms', [])),
        'default_directories' => nibwp_um_int_map(get_option('um_core_directories', [])),
    ];
}

/**
 * A map of mode => post id, with anything that is not one dropped.
 *
 * @return array<string, int>
 */
function nibwp_um_int_map(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $map = [];
    foreach ($value as $key => $id) {
        if (is_string($key) && is_scalar($id)) {
            $map[$key] = (int) $id;
        }
    }

    return $map;
}

/* ─── members ────────────────────────────────────────────────────────────── */

/**
 * A page of members, with the ones this account may not read counted out.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_um_list_members(array $in, int $per_page, int $page): array|WP_Error
{
    // The door: list_users is what opens Users → All Users in wp-admin, and
    // Ultimate Member already rewrites it per its can_view_all role setting
    // (includes/common/class-users.php:88-92).
    if ($denied = nibwp_um_require_cap('list_users', __('Listing members', 'nibwp'))) {
        return $denied;
    }
    if (!class_exists('WP_User_Query')) {
        return new WP_Error('nibwp_um_no_query', __('WP_User_Query is unavailable, so members cannot be listed.', 'nibwp'));
    }

    $args = [
        'number'  => $per_page,
        'paged'   => $page,
        'orderby' => 'registered',
        'order'   => 'DESC',
        'fields'  => 'ID',
    ];

    $status = isset($in['status']) && is_scalar($in['status']) ? (string) $in['status'] : '';
    if ($status !== '') {
        $statuses = nibwp_um_statuses();
        if (!array_key_exists($status, $statuses)) {
            return new WP_Error('nibwp_um_bad_status', sprintf(
                /* translators: %s: comma-separated list of status keys. */
                __('Unknown account status. Use one of: %s', 'nibwp'),
                implode(', ', array_keys($statuses))
            ));
        }
        $args['meta_query'] = [
            [
                'key'     => 'account_status',
                'value'   => $status,
                'compare' => '=',
            ],
        ];
    }

    $role = isset($in['role']) && is_scalar($in['role']) ? sanitize_key((string) $in['role']) : '';
    if ($role !== '') {
        $args['role'] = $role;
    }

    $search = isset($in['search']) && is_scalar($in['search']) ? trim((string) $in['search']) : '';
    if ($search !== '') {
        $args['search'] = '*' . $search . '*';
        $args['search_columns'] = ['user_login', 'user_email', 'user_nicename', 'display_name'];
    }

    $query   = new WP_User_Query($args);
    $results = $query->get_results();
    $ids     = is_array($results) ? $results : [];

    $members = [];
    $skipped = 0;

    foreach ($ids as $raw_id) {
        $id = (int) (is_object($raw_id) ? ($raw_id->ID ?? 0) : $raw_id);
        if ($id <= 0) {
            $skipped++;
            continue;
        }
        // Per member, not per role: the listing capability says nothing about
        // whether this account may read this particular person.
        if (!current_user_can('edit_user', $id)) {
            $skipped++;
            continue;
        }
        $user = get_userdata($id);
        if (!is_object($user)) {
            $skipped++;
            continue;
        }
        $members[] = nibwp_um_member_record(nibwp_um_member_raw($id, $user));
    }

    return [
        'members'         => $members,
        'returned'        => count($members),
        'skipped'         => $skipped,
        'skipped_reason'  => $skipped > 0
            ? __('Records this account may not read. They are excluded from every count above.', 'nibwp')
            : '',
        'page'            => $page,
        'per_page'        => $per_page,
        'total_matching'  => (int) $query->get_total(),
        'status_filter'   => $status,
    ];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_um_get_member(array $in): array|WP_Error
{
    if ($denied = nibwp_um_require_cap('list_users', __('Reading a member', 'nibwp'))) {
        return $denied;
    }

    $id = (int) ($in['user_id'] ?? 0);

    // Asked before the record is fetched, so a refusal cannot be used to learn
    // which ids exist.
    if ($denied = nibwp_um_require_object_cap('edit_user', $id, __('Reading a member', 'nibwp'))) {
        return $denied;
    }

    $user = get_userdata($id);
    if (!is_object($user)) {
        return new WP_Error('nibwp_um_no_member', __('No member with that ID.', 'nibwp'));
    }

    $out = ['member' => nibwp_um_member_record(nibwp_um_member_raw($id, $user, true))];

    if (!empty($in['with_profile_fields'])) {
        $out['profile_fields'] = nibwp_um_member_profile_values($id);
        $out['profile_fields_note'] = __('Only the fields the site forms declare, and only scalar values.', 'nibwp');
    }

    return $out;
}

/** @return array<string, mixed>|WP_Error */
function nibwp_um_status_counts(): array|WP_Error
{
    if ($denied = nibwp_um_require_cap('list_users', __('Counting members by status', 'nibwp'))) {
        return $denied;
    }

    $query  = nibwp_um_service('query');
    $counts = [];

    foreach (array_keys(nibwp_um_statuses()) as $status) {
        if ($query === null || !method_exists($query, 'count_users_by_status')) {
            $counts[$status] = null;
            continue;
        }
        try {
            $counts[$status] = (int) $query->count_users_by_status($status);
        } catch (\Throwable $e) {
            $counts[$status] = null;
        }
    }

    $total = null;
    if ($query !== null && method_exists($query, 'count_users')) {
        try {
            $total = (int) $query->count_users();
        } catch (\Throwable $e) {
            $total = null;
        }
    }

    return [
        'counts' => $counts,
        'total'  => $total,
        'note'   => __('Counts are the whole site, not filtered by what this account may read.', 'nibwp'),
    ];
}

/**
 * Approve, reject, re-pend, deactivate or reactivate one member.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_um_change_status(string $action, array $in): array|WP_Error
{
    // wp-admin gates every one of these on edit_users
    // (includes/admin/class-actions-listener.php:58); the object-level question
    // follows, because edit_users alone says nothing about this person.
    if ($denied = nibwp_um_require_cap('edit_users', __('Changing a member account status', 'nibwp'))) {
        return $denied;
    }

    $id = (int) ($in['user_id'] ?? 0);
    if ($denied = nibwp_um_require_object_cap('edit_user', $id, __('Changing a member account status', 'nibwp'))) {
        return $denied;
    }
    if ($denied = nibwp_um_confirm_gate($action, $in)) {
        return $denied;
    }

    $users = nibwp_um_users_service();
    if ($users === null) {
        return new WP_Error('nibwp_um_no_service', __('The Ultimate Member users service is unavailable on this version.', 'nibwp'));
    }

    if (!is_object(get_userdata($id))) {
        return new WP_Error('nibwp_um_no_member', __('No member with that ID.', 'nibwp'));
    }

    $map = [
        'approve_member'     => ['approve', 'can_be_approved', __('Approving lets this member into the site and sends the welcome or approval email. The plugin refuses it when the account is already approved, or when it is inactive — reactivate it instead.', 'nibwp')],
        'reject_member'      => ['reject', 'can_be_rejected', __('Rejecting ends every session this member has and locks them out. The plugin allows it only from the awaiting_admin_review status.', 'nibwp')],
        'set_member_pending' => ['set_as_pending', 'can_be_set_as_pending', __('Putting a member back to pending ends their sessions until an administrator reviews them again. The plugin refuses it when they are already pending.', 'nibwp')],
        'deactivate_member'  => ['deactivate', 'can_be_deactivated', __('Deactivating ends every session this member has and locks them out. The plugin refuses it when the account is already inactive.', 'nibwp')],
        'reactivate_member'  => ['reactivate', 'can_be_reactivated', __('Reactivating restores an inactive account to approved. The plugin allows it only from the inactive status.', 'nibwp')],
    ];

    if (!isset($map[$action])) {
        return new WP_Error('nibwp_um_unknown_action', sprintf(
            /* translators: %s: the action that was requested. */
            __('Unknown Ultimate Member action: %s', 'nibwp'),
            $action
        ));
    }

    [$method, $precondition, $explanation] = $map[$action];

    if (!method_exists($users, $method)) {
        return new WP_Error('nibwp_um_no_method', sprintf(
            /* translators: %s: method name. */
            __('This Ultimate Member version does not carry %s.', 'nibwp'),
            $method
        ));
    }

    $before = nibwp_um_read_status($users, $id);

    // The plugin's own precondition, asked first, so a refusal can say why
    // instead of returning a bare false.
    if (method_exists($users, $precondition)) {
        try {
            if (!$users->{$precondition}($id)) {
                return new WP_Error('nibwp_um_transition_refused', sprintf(
                    /* translators: 1: current status key, 2: why the plugin refuses. */
                    __('Ultimate Member will not make that change from the "%1$s" status. %2$s', 'nibwp'),
                    $before,
                    $explanation
                ));
            }
        } catch (\Throwable $e) {
            return new WP_Error('nibwp_um_failed', $e->getMessage());
        }
    }

    try {
        $changed = (bool) $users->{$method}($id);
    } catch (\Throwable $e) {
        return new WP_Error('nibwp_um_failed', $e->getMessage());
    }

    $after = nibwp_um_read_status($users, $id);

    return [
        'changed'     => $changed,
        'user_id'     => $id,
        'was'         => $before,
        'now'         => $after,
        'explanation' => $explanation,
    ];
}

/** The member's current status key, via the plugin so a filtered status still reads back. */
function nibwp_um_read_status(object $users, int $id): string
{
    if (method_exists($users, 'get_status')) {
        try {
            $status = $users->get_status($id);
            if (is_scalar($status) && (string) $status !== '') {
                return (string) $status;
            }
        } catch (\Throwable $e) {
            // Fall through to the raw meta.
        }
    }

    $raw = get_user_meta($id, 'account_status', true);

    return is_scalar($raw) && (string) $raw !== '' ? (string) $raw : 'approved';
}

/* ─── roles ──────────────────────────────────────────────────────────────── */

/**
 * The UM role settings this hub may read back, and the type each leaves as.
 *
 * Taken from the plugin's own role meta map (includes/admin/class-admin.php:90-209),
 * minus wp_capabilities: a role's WordPress capabilities are handled separately
 * below, as a list of names rather than the raw grant map.
 *
 * @return array<string, string>
 */
function nibwp_um_role_setting_fields(): array
{
    return [
        '_um_priority'                   => 'int',
        '_um_can_access_wpadmin'         => 'bool',
        '_um_can_not_see_adminbar'       => 'bool',
        '_um_can_edit_everyone'          => 'bool',
        '_um_can_edit_roles'             => 'string_list',
        '_um_can_delete_everyone'        => 'bool',
        '_um_can_delete_roles'           => 'string_list',
        '_um_can_edit_profile'           => 'bool',
        '_um_can_delete_profile'         => 'bool',
        '_um_can_view_all'               => 'bool',
        '_um_can_view_roles'             => 'string_list',
        '_um_can_make_private_profile'   => 'bool',
        '_um_can_access_private_profile' => 'bool',
        '_um_profile_noindex'            => 'string',
        '_um_default_homepage'           => 'bool',
        '_um_redirect_homepage'          => 'string',
        '_um_status'                     => 'string',
        '_um_auto_approve_act'           => 'string',
        '_um_auto_approve_url'           => 'string',
        '_um_login_email_activate'       => 'bool',
        '_um_after_login'                => 'string',
        '_um_login_redirect_url'         => 'string',
        '_um_after_logout'               => 'string',
        '_um_logout_redirect_url'        => 'string',
        '_um_after_delete'               => 'string',
        '_um_delete_redirect_url'        => 'string',
    ];
}

/**
 * The settings update_role_permissions will write, and how each is coerced.
 *
 * wp_capabilities is absent on purpose. The plugin keeps a banned-capability list
 * precisely because a UM role that can promote_users or manage_options is an
 * escalation path (includes/common/class-secure.php:141-200), and granting one
 * from an MCP call is the same hole by another road.
 *
 * @return array<string, string>
 */
function nibwp_um_role_writable_fields(): array
{
    return [
        '_um_priority'                   => 'int',
        '_um_can_access_wpadmin'         => 'bool',
        '_um_can_not_see_adminbar'       => 'bool',
        '_um_can_edit_everyone'          => 'bool',
        '_um_can_delete_everyone'        => 'bool',
        '_um_can_edit_profile'           => 'bool',
        '_um_can_delete_profile'         => 'bool',
        '_um_can_view_all'               => 'bool',
        '_um_can_make_private_profile'   => 'bool',
        '_um_can_access_private_profile' => 'bool',
        '_um_default_homepage'           => 'bool',
        '_um_status'                     => 'status_enum',
        '_um_after_login'                => 'login_enum',
        '_um_after_logout'               => 'logout_enum',
    ];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_um_list_roles(int $per_page, int $page): array|WP_Error
{
    if ($denied = nibwp_um_require_cap('manage_options', __('Listing the community roles', 'nibwp'))) {
        return $denied;
    }
    if (!function_exists('wp_roles')) {
        return new WP_Error('nibwp_um_no_roles', __('wp_roles() is unavailable, so roles cannot be listed.', 'nibwp'));
    }

    $custom = get_option('um_roles', []);
    $custom = is_array($custom) ? array_values(array_filter($custom, 'is_scalar')) : [];
    $custom_ids = [];
    foreach ($custom as $key) {
        $custom_ids[] = 'um_' . (string) $key;
    }

    $names = wp_roles()->role_names;
    $names = is_array($names) ? $names : [];

    $all = [];
    foreach ($names as $role_id => $label) {
        if (!is_string($role_id) || !is_scalar($label)) {
            continue;
        }
        $meta_key = in_array($role_id, $custom_ids, true) ? substr($role_id, 3) : $role_id;
        $meta     = get_option('um_role_' . $meta_key . '_meta', []);

        $all[] = [
            'role_id'       => $role_id,
            'name'          => (string) $label,
            'is_um_role'    => in_array($role_id, $custom_ids, true),
            'has_um_config' => is_array($meta) && $meta !== [],
            'priority'      => is_array($meta) && isset($meta['_um_priority']) && is_scalar($meta['_um_priority'])
                ? (int) $meta['_um_priority']
                : 0,
        ];
    }

    $total  = count($all);
    $offset = ($page - 1) * $per_page;

    return [
        'roles'    => array_slice($all, $offset, $per_page),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
    ];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_um_get_role(array $in): array|WP_Error
{
    if ($denied = nibwp_um_require_cap('manage_options', __('Reading a community role', 'nibwp'))) {
        return $denied;
    }

    $role_id = isset($in['role_id']) && is_scalar($in['role_id']) ? sanitize_key((string) $in['role_id']) : '';
    if ($role_id === '') {
        return new WP_Error('nibwp_um_no_role', __('get_role needs a role_id.', 'nibwp'));
    }

    $meta = nibwp_um_role_meta($role_id);
    if ($meta === null) {
        return new WP_Error('nibwp_um_no_role', __('No role with that slug, and no Ultimate Member settings stored for it.', 'nibwp'));
    }

    $settings = [];
    foreach (nibwp_um_role_setting_fields() as $key => $type) {
        if (!array_key_exists($key, $meta)) {
            continue;
        }
        $value = $meta[$key];
        if ($type === 'string_list') {
            if (!is_array($value)) {
                continue;
            }
            $list = [];
            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $list[] = (string) $item;
                }
            }
            $settings[$key] = $list;
            continue;
        }
        if (!is_scalar($value)) {
            continue;
        }
        $settings[$key] = match ($type) {
            'int'  => (int) $value,
            'bool' => (bool) $value,
            default => (string) $value,
        };
    }

    // The granted capability NAMES, not the raw grant map: an owner asking "what
    // can this role do" wants the list, and the map is the shape a cap set uses
    // to travel somewhere it should not.
    $capabilities = [];
    if (isset($meta['wp_capabilities']) && is_array($meta['wp_capabilities'])) {
        foreach ($meta['wp_capabilities'] as $cap => $granted) {
            if (is_string($cap) && $granted) {
                $capabilities[] = $cap;
            }
        }
        sort($capabilities);
    }

    $name = '';
    if (isset($meta['name']) && is_scalar($meta['name'])) {
        $name = (string) $meta['name'];
    }

    return [
        'role_id'       => $role_id,
        'name'          => $name,
        'settings'      => $settings,
        'capabilities'  => array_slice($capabilities, 0, 300),
        'capability_count' => count($capabilities),
    ];
}

/**
 * A role's stored Ultimate Member meta, or null when there is neither a role nor
 * a stored config under that slug.
 *
 * Custom UM roles live under the key without the `um_` prefix
 * (includes/core/class-roles-capabilities.php:654-666).
 *
 * @return array<string, mixed>|null
 */
function nibwp_um_role_meta(string $role_id): ?array
{
    $meta_key = str_starts_with($role_id, 'um_') ? substr($role_id, 3) : $role_id;
    $meta     = get_option('um_role_' . $meta_key . '_meta', []);

    if (is_array($meta) && $meta !== []) {
        return $meta;
    }

    if (function_exists('get_role') && get_role($role_id) !== null) {
        return [];
    }

    return null;
}

/** @return array<string, mixed>|WP_Error */
function nibwp_um_update_role_permissions(array $in): array|WP_Error
{
    if ($denied = nibwp_um_require_cap('manage_options', __('Changing what a community role may do', 'nibwp'))) {
        return $denied;
    }
    if ($denied = nibwp_um_confirm_gate('update_role_permissions', $in)) {
        return $denied;
    }

    $role_id = isset($in['role_id']) && is_scalar($in['role_id']) ? sanitize_key((string) $in['role_id']) : '';
    if ($role_id === '') {
        return new WP_Error('nibwp_um_no_role', __('update_role_permissions needs a role_id.', 'nibwp'));
    }

    $meta = nibwp_um_role_meta($role_id);
    if ($meta === null) {
        return new WP_Error('nibwp_um_no_role', __('No role with that slug.', 'nibwp'));
    }

    $settings = isset($in['settings']) && is_array($in['settings']) ? $in['settings'] : [];
    if ($settings === []) {
        return new WP_Error('nibwp_um_nothing', __('Send a settings object naming the role permissions to change.', 'nibwp'));
    }

    $writable = nibwp_um_role_writable_fields();
    $applied  = [];
    $ignored  = [];

    foreach ($settings as $key => $value) {
        $full = is_string($key) && str_starts_with($key, '_um_') ? $key : '_um_' . (string) $key;
        if (!isset($writable[$full])) {
            $ignored[] = (string) $key;
            continue;
        }
        if (!is_scalar($value)) {
            $ignored[] = (string) $key;
            continue;
        }

        $coerced = match ($writable[$full]) {
            'int'  => (int) $value,
            'bool' => empty($value) ? 0 : 1,
            // Enum values come straight from the plugin's own sanitize maps
            // (includes/admin/class-admin.php:142-146 and :176-186).
            'status_enum' => in_array((string) $value, ['approved', 'checkmail', 'pending'], true) ? (string) $value : null,
            'login_enum'  => in_array((string) $value, ['redirect_profile', 'redirect_url', 'refresh', 'redirect_admin'], true) ? (string) $value : null,
            'logout_enum' => in_array((string) $value, ['redirect_home', 'redirect_url'], true) ? (string) $value : null,
            default => null,
        };

        if ($coerced === null) {
            $ignored[] = (string) $key;
            continue;
        }

        $meta[$full]   = $coerced;
        $applied[$full] = $coerced;
    }

    if ($applied === []) {
        return new WP_Error('nibwp_um_nothing', sprintf(
            /* translators: %s: comma-separated list of allowed setting keys. */
            __('Nothing writable in that settings object. This action writes only: %s', 'nibwp'),
            implode(', ', array_keys($writable))
        ));
    }

    $meta_key = str_starts_with($role_id, 'um_') ? substr($role_id, 3) : $role_id;
    update_option('um_role_' . $meta_key . '_meta', $meta);

    return [
        'updated' => true,
        'role_id' => $role_id,
        'applied' => $applied,
        'ignored' => $ignored,
        'note'    => __('WordPress capabilities were not touched. This action writes the plugin role settings only.', 'nibwp'),
    ];
}

/* ─── forms, fields and directories ──────────────────────────────────────── */

/**
 * List um_form or um_directory posts.
 *
 * Both CPTs map every capability to manage_options
 * (includes/common/class-cpt.php:41-50 and :73-82), so that is the door; the
 * per-post question is asked again on the single-record actions.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_um_list_cpt(string $action, array $in, int $per_page, int $page): array|WP_Error
{
    $is_form   = $action === 'list_forms';
    $post_type = $is_form ? 'um_form' : 'um_directory';

    if ($denied = nibwp_um_require_cap(
        'manage_options',
        $is_form ? __('Listing the Ultimate Member forms', 'nibwp') : __('Listing the member directories', 'nibwp')
    )) {
        return $denied;
    }

    $defaults = nibwp_um_int_map(get_option($is_form ? 'um_core_forms' : 'um_core_directories', []));
    $search   = isset($in['search']) && is_scalar($in['search']) ? trim((string) $in['search']) : '';

    $posts = get_posts([
        'post_type'        => $post_type,
        'post_status'      => ['publish', 'draft', 'pending', 'private'],
        's'                => $search,
        'posts_per_page'   => $per_page,
        'offset'           => ($page - 1) * $per_page,
        'orderby'          => 'date',
        'order'            => 'DESC',
        'suppress_filters' => false,
    ]);
    $posts = is_array($posts) ? $posts : [];

    $rows = [];
    foreach ($posts as $post) {
        if (!is_object($post)) {
            continue;
        }
        $id   = (int) ($post->ID ?? 0);
        $mode = get_post_meta($id, '_um_mode', true);
        $core = get_post_meta($id, '_um_core', true);

        $rows[] = [
            'id'         => $id,
            'title'      => isset($post->post_title) && is_scalar($post->post_title) ? (string) $post->post_title : '',
            'status'     => isset($post->post_status) && is_scalar($post->post_status) ? (string) $post->post_status : '',
            'mode'       => is_scalar($mode) ? (string) $mode : '',
            'core'       => is_scalar($core) ? (string) $core : '',
            'is_default' => in_array($id, array_values($defaults), true),
        ];
    }

    return [
        $is_form ? 'forms' : 'directories' => $rows,
        'returned' => count($rows),
        'page'     => $page,
        'per_page' => $per_page,
        'defaults' => $defaults,
    ];
}

/**
 * The form settings this hub reads back.
 *
 * Only keys that exist in the plugin's own form meta map
 * (includes/admin/class-admin.php:408+) and in its shipped defaults
 * (includes/class-config.php:219-390), so a theme or add-on writing arbitrary
 * post meta on a form cannot smuggle it out here.
 *
 * @return list<string>
 */
function nibwp_um_form_setting_keys(): array
{
    return [
        '_um_mode', '_um_core',
        '_um_register_role', '_um_register_template', '_um_register_max_width',
        '_um_register_align', '_um_register_icons', '_um_register_primary_btn_word',
        '_um_register_secondary_btn', '_um_register_secondary_btn_word',
        '_um_register_secondary_btn_url', '_um_register_use_custom_settings',
        '_um_register_use_gdpr', '_um_register_use_gdpr_agreement',
        '_um_login_template', '_um_login_max_width', '_um_login_align',
        '_um_login_icons', '_um_login_primary_btn_word', '_um_login_forgot_pass_link',
        '_um_login_show_rememberme', '_um_login_secondary_btn',
        '_um_login_secondary_btn_word', '_um_login_secondary_btn_url',
        '_um_login_use_custom_settings', '_um_login_after_login', '_um_login_redirect_url',
        '_um_profile_template', '_um_profile_max_width', '_um_profile_area_max_width',
        '_um_profile_align', '_um_profile_icons', '_um_profile_show_name',
        '_um_profile_show_social_links', '_um_profile_show_bio', '_um_profile_bio_maxchars',
        '_um_profile_header_menu', '_um_profile_photosize', '_um_profile_cover_enabled',
        '_um_profile_coversize', '_um_profile_cover_ratio', '_um_profile_disable_photo_upload',
        '_um_profile_primary_btn_word', '_um_profile_secondary_btn',
        '_um_profile_secondary_btn_word', '_um_profile_use_custom_settings',
    ];
}

/**
 * Read an allow-listed set of `_um_*` post meta as scalars and role lists.
 *
 * @param list<string> $keys
 * @return array<string, mixed>
 */
function nibwp_um_read_meta_subset(int $post_id, array $keys): array
{
    $out = [];

    foreach ($keys as $key) {
        $value = get_post_meta($post_id, $key, true);

        if (is_array($value)) {
            $list = [];
            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $list[] = (string) $item;
                }
            }
            $out[$key] = $list;
            continue;
        }
        if (!is_scalar($value)) {
            continue;
        }
        $out[$key] = (string) $value;
    }

    return $out;
}

/**
 * Resolve a um_form or um_directory post and ask whether this account may edit it.
 *
 * @return array{id: int, post: object}|WP_Error
 */
function nibwp_um_cpt_target(array $in, string $key, string $post_type, string $what): array|WP_Error
{
    $id = (int) ($in[$key] ?? 0);

    if ($denied = nibwp_um_require_object_cap('edit_post', $id, $what)) {
        return $denied;
    }

    $post = get_post($id);
    if (!is_object($post) || ($post->post_type ?? '') !== $post_type) {
        return new WP_Error('nibwp_um_no_post', sprintf(
            /* translators: %s: post type name. */
            __('No %s with that ID.', 'nibwp'),
            $post_type
        ));
    }

    return ['id' => $id, 'post' => $post];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_um_get_form(array $in): array|WP_Error
{
    $target = nibwp_um_cpt_target($in, 'form_id', 'um_form', __('Reading an Ultimate Member form', 'nibwp'));
    if (is_wp_error($target)) {
        return $target;
    }

    $fields = get_post_meta($target['id'], '_um_custom_fields', true);

    return [
        'form' => [
            'id'     => $target['id'],
            'title'  => isset($target['post']->post_title) && is_scalar($target['post']->post_title)
                ? (string) $target['post']->post_title
                : '',
            'status' => isset($target['post']->post_status) && is_scalar($target['post']->post_status)
                ? (string) $target['post']->post_status
                : '',
        ],
        'settings'    => nibwp_um_read_meta_subset($target['id'], nibwp_um_form_setting_keys()),
        'field_count' => is_array($fields) ? count($fields) : 0,
    ];
}

/**
 * The field attributes this hub reports, and the type each leaves as.
 *
 * Stored field keys carry no leading underscore — the builder strips it before
 * saving (includes/admin/core/class-admin-builder.php:653-659).
 *
 * @return array<string, string>
 */
function nibwp_um_field_report_fields(): array
{
    return [
        'metakey'    => 'string',
        'type'       => 'string',
        'title'      => 'string',
        'label'      => 'string',
        'placeholder' => 'string',
        'help'       => 'string',
        'default'    => 'string',
        'required'   => 'string',
        'public'     => 'string',
        'editable'   => 'string',
        'visibility' => 'string',
        'min_chars'  => 'string',
        'max_chars'  => 'string',
        'validate'   => 'string',
        'position'   => 'string',
        'in_row'     => 'string',
        'in_column'  => 'string',
    ];
}

/**
 * The field attributes update_form_field will write, and how each is coerced.
 *
 * metakey and type are absent on purpose: both decide where a member's answer is
 * stored, and changing either orphans every value already collected.
 *
 * @return array<string, string>
 */
function nibwp_um_field_writable_fields(): array
{
    return [
        'title'       => 'text',
        'label'       => 'text',
        'placeholder' => 'text',
        'help'        => 'text',
        'default'     => 'text',
        'required'    => 'bool',
        'editable'    => 'bool',
        'visibility'  => 'visibility_enum',
        'public'      => 'public_enum',
        'min_chars'   => 'int',
        'max_chars'   => 'int',
    ];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_um_list_form_fields(array $in, int $per_page, int $page): array|WP_Error
{
    $target = nibwp_um_cpt_target($in, 'form_id', 'um_form', __('Reading an Ultimate Member form', 'nibwp'));
    if (is_wp_error($target)) {
        return $target;
    }

    $fields = get_post_meta($target['id'], '_um_custom_fields', true);
    if (!is_array($fields)) {
        $fields = [];
    }

    $report = nibwp_um_field_report_fields();
    $rows   = [];

    foreach ($fields as $key => $args) {
        if (!is_array($args)) {
            continue;
        }
        $row = ['key' => is_scalar($key) ? (string) $key : ''];
        foreach ($report as $attribute => $type) {
            if (!array_key_exists($attribute, $args) || !is_scalar($args[$attribute])) {
                continue;
            }
            $row[$attribute] = (string) $args[$attribute];
        }
        $row['option_count'] = isset($args['options']) && is_array($args['options']) ? count($args['options']) : 0;
        $rows[] = $row;
    }

    $total  = count($rows);
    $offset = ($page - 1) * $per_page;

    return [
        'form_id'  => $target['id'],
        'fields'   => array_slice($rows, $offset, $per_page),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
    ];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_um_update_form_field(array $in): array|WP_Error
{
    $target = nibwp_um_cpt_target($in, 'form_id', 'um_form', __('Editing an Ultimate Member form field', 'nibwp'));
    if (is_wp_error($target)) {
        return $target;
    }

    $field_key = isset($in['field_key']) && is_scalar($in['field_key']) ? (string) $in['field_key'] : '';
    if ($field_key === '') {
        return new WP_Error('nibwp_um_no_field', __('update_form_field needs a field_key.', 'nibwp'));
    }

    $fields = get_post_meta($target['id'], '_um_custom_fields', true);
    if (!is_array($fields) || !isset($fields[$field_key]) || !is_array($fields[$field_key])) {
        return new WP_Error('nibwp_um_no_field', __('That form has no field with that key.', 'nibwp'));
    }

    $settings = isset($in['settings']) && is_array($in['settings']) ? $in['settings'] : [];
    if ($settings === []) {
        return new WP_Error('nibwp_um_nothing', __('Send a settings object naming the field attributes to change.', 'nibwp'));
    }

    $writable = nibwp_um_field_writable_fields();
    $applied  = [];
    $ignored  = [];

    foreach ($settings as $key => $value) {
        $attribute = is_string($key) ? ltrim($key, '_') : '';
        if ($attribute === '' || !isset($writable[$attribute]) || !is_scalar($value)) {
            $ignored[] = (string) $key;
            continue;
        }

        $coerced = match ($writable[$attribute]) {
            'text' => sanitize_text_field((string) $value),
            'bool' => empty($value) ? 0 : 1,
            'int'  => (string) max(0, (int) $value),
            // The three the builder offers (includes/admin/core/class-admin-metabox.php:1423-1426).
            'visibility_enum' => in_array((string) $value, ['all', 'edit', 'view'], true) ? (string) $value : null,
            // Field privacy (includes/admin/core/class-admin-metabox.php:2405-2411).
            'public_enum' => in_array((string) $value, ['1', '2', '-1', '-2', '-3'], true) ? (string) $value : null,
            default => null,
        };

        if ($coerced === null) {
            $ignored[] = (string) $key;
            continue;
        }

        $fields[$field_key][$attribute] = $coerced;
        $applied[$attribute] = $coerced;
    }

    if ($applied === []) {
        return new WP_Error('nibwp_um_nothing', sprintf(
            /* translators: %s: comma-separated list of editable attribute names. */
            __('Nothing writable in that settings object. This action writes only: %s', 'nibwp'),
            implode(', ', array_keys($writable))
        ));
    }

    update_post_meta($target['id'], '_um_custom_fields', $fields);

    return [
        'updated'   => true,
        'form_id'   => $target['id'],
        'field_key' => $field_key,
        'applied'   => $applied,
        'ignored'   => $ignored,
        'note'      => __('The field metakey and type were not touched; changing either would orphan every value already collected under it.', 'nibwp'),
    ];
}

/**
 * The directory settings this hub reads and writes.
 *
 * Keys and their coercions follow the plugin's own member directory meta map
 * (includes/admin/class-admin.php:278-408).
 *
 * @return array<string, string>
 */
function nibwp_um_directory_writable_fields(): array
{
    return [
        '_um_directory_template'       => 'text',
        '_um_default_view'             => 'key',
        '_um_privacy'                  => 'absint',
        '_um_roles'                    => 'role_list',
        '_um_has_profile_photo'        => 'bool',
        '_um_must_search'              => 'bool',
        '_um_max_users'                => 'absint',
        '_um_profiles_per_page'        => 'absint',
        '_um_profiles_per_page_mobile' => 'absint',
        '_um_directory_header'         => 'text',
        '_um_directory_header_single'  => 'text',
        '_um_directory_no_users'       => 'text',
        '_um_profile_photo'            => 'bool',
        '_um_cover_photos'             => 'bool',
        '_um_show_name'                => 'bool',
        '_um_show_tagline'             => 'bool',
        '_um_show_userinfo'            => 'bool',
        '_um_show_social'              => 'bool',
        '_um_search'                   => 'bool',
        '_um_filters'                  => 'bool',
        '_um_filters_expanded'         => 'bool',
        '_um_filters_is_collapsible'   => 'bool',
        '_um_sortby'                   => 'text',
        '_um_enable_sorting'           => 'bool',
    ];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_um_get_directory(array $in): array|WP_Error
{
    $target = nibwp_um_cpt_target($in, 'directory_id', 'um_directory', __('Reading a member directory', 'nibwp'));
    if (is_wp_error($target)) {
        return $target;
    }

    $keys = array_keys(nibwp_um_directory_writable_fields());
    foreach (['_um_mode', '_um_core', '_um_privacy_roles', '_um_view_types', '_um_search_fields', '_um_sorting_fields'] as $extra) {
        $keys[] = $extra;
    }

    return [
        'directory' => [
            'id'     => $target['id'],
            'title'  => isset($target['post']->post_title) && is_scalar($target['post']->post_title)
                ? (string) $target['post']->post_title
                : '',
            'status' => isset($target['post']->post_status) && is_scalar($target['post']->post_status)
                ? (string) $target['post']->post_status
                : '',
        ],
        'settings' => nibwp_um_read_meta_subset($target['id'], $keys),
    ];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_um_update_directory_settings(array $in): array|WP_Error
{
    $target = nibwp_um_cpt_target($in, 'directory_id', 'um_directory', __('Changing a member directory', 'nibwp'));
    if (is_wp_error($target)) {
        return $target;
    }

    $settings = isset($in['settings']) && is_array($in['settings']) ? $in['settings'] : [];
    if ($settings === []) {
        return new WP_Error('nibwp_um_nothing', __('Send a settings object naming the directory settings to change.', 'nibwp'));
    }

    $writable = nibwp_um_directory_writable_fields();
    $applied  = [];
    $ignored  = [];

    foreach ($settings as $key => $value) {
        $full = is_string($key) && str_starts_with($key, '_um_') ? $key : '_um_' . (string) $key;
        if (!isset($writable[$full])) {
            $ignored[] = (string) $key;
            continue;
        }

        if ($writable[$full] === 'role_list') {
            $roles = nibwp_um_role_slug_list($value);
            if ($roles === null) {
                $ignored[] = (string) $key;
                continue;
            }
            update_post_meta($target['id'], $full, $roles);
            $applied[$full] = $roles;
            continue;
        }

        if (!is_scalar($value)) {
            $ignored[] = (string) $key;
            continue;
        }

        $coerced = match ($writable[$full]) {
            'text'   => sanitize_text_field((string) $value),
            'key'    => sanitize_key((string) $value),
            'absint' => absint($value),
            'bool'   => empty($value) ? 0 : 1,
            default  => null,
        };

        if ($coerced === null) {
            $ignored[] = (string) $key;
            continue;
        }

        update_post_meta($target['id'], $full, $coerced);
        $applied[$full] = $coerced;
    }

    if ($applied === []) {
        return new WP_Error('nibwp_um_nothing', sprintf(
            /* translators: %s: comma-separated list of allowed setting keys. */
            __('Nothing writable in that settings object. This action writes only: %s', 'nibwp'),
            implode(', ', array_keys($writable))
        ));
    }

    return [
        'updated'      => true,
        'directory_id' => $target['id'],
        'applied'      => $applied,
        'ignored'      => $ignored,
    ];
}

/**
 * A list of role slugs that exist on this site, or null when nothing survives.
 *
 * @return list<string>|null
 */
function nibwp_um_role_slug_list(mixed $value): ?array
{
    if (is_scalar($value)) {
        $value = [$value];
    }
    if (!is_array($value)) {
        return null;
    }

    $known = [];
    if (function_exists('wp_roles')) {
        $names = wp_roles()->role_names;
        $known = is_array($names) ? array_keys($names) : [];
    }

    $out = [];
    foreach ($value as $slug) {
        if (!is_scalar($slug)) {
            continue;
        }
        $slug = sanitize_key((string) $slug);
        if ($slug === '' || ($known !== [] && !in_array($slug, $known, true))) {
            continue;
        }
        $out[$slug] = true;
    }

    return $out === [] ? null : array_keys($out);
}

/* ─── content restriction ────────────────────────────────────────────────── */

/**
 * The global content-restriction settings this hub reads.
 *
 * An allow-list even though these are "just settings": `um_options` is one option
 * row that also holds api_key fields, which fall back to the database when no
 * wp-config constant is defined (includes/core/class-options.php:184-192), so
 * dumping the row would hand out a Google Maps key with the access rules.
 *
 * @return array<string, string>
 */
function nibwp_um_restriction_setting_fields(): array
{
    return [
        'accessible'                    => 'int',
        'access_redirect'               => 'string',
        'home_page_accessible'          => 'bool',
        'category_page_accessible'      => 'bool',
        'restricted_post_title_replace' => 'bool',
        'restricted_access_post_title'  => 'string',
        'restricted_access_message'     => 'string',
        'restricted_blocks'             => 'bool',
        'restricted_block_message'      => 'string',
        'disable_restriction_pre_queries' => 'bool',
    ];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_um_get_restriction_settings(): array|WP_Error
{
    if ($denied = nibwp_um_require_cap('manage_options', __('Reading the content restriction settings', 'nibwp'))) {
        return $denied;
    }

    $settings = [];
    foreach (nibwp_um_restriction_setting_fields() as $key => $type) {
        $value = nibwp_um_option($key);
        if (is_array($value)) {
            $list = [];
            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $list[] = (string) $item;
                }
            }
            $settings[$key] = $list;
            continue;
        }
        if (!is_scalar($value)) {
            continue;
        }
        $settings[$key] = match ($type) {
            'int'  => (int) $value,
            'bool' => (bool) $value,
            default => (string) $value,
        };
    }

    $excluded = nibwp_um_option('access_exclude_uris');
    $settings['access_exclude_uris'] = is_array($excluded)
        ? array_slice(array_values(array_filter($excluded, 'is_scalar')), 0, 100)
        : [];

    return [
        'settings' => $settings,
        'note'     => __('Global site access: 0 means everyone, 2 means logged-in users only. Per-post rules override this.', 'nibwp'),
    ];
}

/**
 * The per-post restriction keys, and how each is coerced.
 *
 * Matches the plugin's own restriction post meta map and sanitisers
 * (includes/admin/class-admin.php:212-243, :1191-1241). The sanitisers are
 * reproduced rather than called: UM()->admin() constructs um\admin\Admin, which
 * registers wp-admin hooks, and an ability has no business doing that mid-REST.
 *
 * @return array<string, string>
 */
function nibwp_um_restriction_post_fields(): array
{
    return [
        '_um_custom_access_settings'     => 'bool',
        '_um_accessible'                 => 'int',
        '_um_access_roles'               => 'role_map',
        '_um_noaccess_action'            => 'int',
        '_um_restrict_by_custom_message' => 'int',
        '_um_restrict_custom_message'    => 'kses',
        '_um_access_redirect'            => 'int',
        '_um_access_redirect_url'        => 'url',
        '_um_access_hide_from_queries'   => 'bool',
    ];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_um_get_post_restriction(array $in): array|WP_Error
{
    $id = (int) ($in['post_id'] ?? 0);
    if ($denied = nibwp_um_require_object_cap('edit_post', $id, __('Reading the restriction settings of a post', 'nibwp'))) {
        return $denied;
    }

    $post = get_post($id);
    if (!is_object($post)) {
        return new WP_Error('nibwp_um_no_post', __('No post with that ID.', 'nibwp'));
    }

    // Post meta, not prefixed with an underscore, holding the whole map
    // (includes/admin/core/class-admin-metabox.php:368).
    $stored = get_post_meta($id, 'um_content_restriction', true);
    $stored = is_array($stored) ? $stored : [];

    $settings = [];
    foreach (nibwp_um_restriction_post_fields() as $key => $type) {
        if (!array_key_exists($key, $stored)) {
            continue;
        }
        $value = $stored[$key];

        if ($type === 'role_map') {
            $roles = [];
            if (is_array($value)) {
                foreach ($value as $slug => $granted) {
                    if (is_string($slug) && $granted) {
                        $roles[] = $slug;
                    }
                }
            }
            $settings[$key] = $roles;
            continue;
        }
        if (!is_scalar($value)) {
            continue;
        }
        $settings[$key] = match ($type) {
            'int'  => (int) $value,
            'bool' => (bool) $value,
            default => (string) $value,
        };
    }

    return [
        'post_id'    => $id,
        'post_type'  => isset($post->post_type) && is_scalar($post->post_type) ? (string) $post->post_type : '',
        'restricted' => !empty($stored['_um_custom_access_settings']),
        'settings'   => $settings,
    ];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_um_set_post_restriction(array $in): array|WP_Error
{
    $id = (int) ($in['post_id'] ?? 0);
    if ($denied = nibwp_um_require_object_cap('edit_post', $id, __('Changing who can read a post', 'nibwp'))) {
        return $denied;
    }
    if ($denied = nibwp_um_confirm_gate('set_post_restriction', $in)) {
        return $denied;
    }

    if (!is_object(get_post($id))) {
        return new WP_Error('nibwp_um_no_post', __('No post with that ID.', 'nibwp'));
    }

    $settings = isset($in['settings']) && is_array($in['settings']) ? $in['settings'] : [];
    if ($settings === []) {
        return new WP_Error('nibwp_um_nothing', __('Send a settings object naming the restriction settings to change.', 'nibwp'));
    }

    $stored = get_post_meta($id, 'um_content_restriction', true);
    $stored = is_array($stored) ? $stored : [];

    $writable = nibwp_um_restriction_post_fields();
    $applied  = [];
    $ignored  = [];

    foreach ($settings as $key => $value) {
        $full = is_string($key) && str_starts_with($key, '_um_') ? $key : '_um_' . (string) $key;
        if (!isset($writable[$full])) {
            $ignored[] = (string) $key;
            continue;
        }

        if ($writable[$full] === 'role_map') {
            $roles = nibwp_um_role_slug_list($value);
            if ($roles === null) {
                $ignored[] = (string) $key;
                continue;
            }
            // Stored as slug => 1 (includes/admin/core/class-admin-metabox.php:551-556).
            $map = [];
            foreach ($roles as $slug) {
                $map[$slug] = 1;
            }
            $stored[$full]  = $map;
            $applied[$full] = $roles;
            continue;
        }

        if (!is_scalar($value)) {
            $ignored[] = (string) $key;
            continue;
        }

        $coerced = match ($writable[$full]) {
            'bool' => (bool) $value,
            'int'  => (int) $value,
            'url'  => esc_url_raw((string) $value),
            'kses' => wp_kses_post((string) $value),
            default => null,
        };

        if ($coerced === null) {
            $ignored[] = (string) $key;
            continue;
        }

        $stored[$full]  = $coerced;
        $applied[$full] = $coerced;
    }

    if ($applied === []) {
        return new WP_Error('nibwp_um_nothing', sprintf(
            /* translators: %s: comma-separated list of allowed setting keys. */
            __('Nothing writable in that settings object. This action writes only: %s', 'nibwp'),
            implode(', ', array_keys($writable))
        ));
    }

    update_post_meta($id, 'um_content_restriction', $stored);

    return [
        'updated' => true,
        'post_id' => $id,
        'applied' => $applied,
        'ignored' => $ignored,
        'note'    => __('Restriction only bites when _um_custom_access_settings is on; set it in the same call if the post was unrestricted.', 'nibwp'),
    ];
}

/* ─── email notifications ────────────────────────────────────────────────── */

/**
 * The notifications the plugin ships, key => title.
 *
 * Read from the plugin's own definitions when they are reachable so an add-on's
 * notification is included (includes/class-config.php:445-627); the fallback is
 * the fourteen that 2.14.0 ships.
 *
 * @return array<string, string>
 */
function nibwp_um_emails(): array
{
    $config = nibwp_um_service('config');
    if ($config !== null && isset($config->email_notifications) && is_array($config->email_notifications)) {
        $list = [];
        foreach ($config->email_notifications as $key => $notification) {
            if (!is_string($key) || $key === '' || !is_array($notification)) {
                continue;
            }
            $title = isset($notification['title']) && is_scalar($notification['title']) ? (string) $notification['title'] : $key;
            $list[$key] = $title;
        }
        if ($list !== []) {
            return $list;
        }
    }

    return [
        'welcome_email'          => __('Account Welcome Email', 'nibwp'),
        'checkmail_email'        => __('Account Activation Email', 'nibwp'),
        'pending_email'          => __('Account pending review', 'nibwp'),
        'approved_email'         => __('Account Approved Email', 'nibwp'),
        'rejected_email'         => __('Account Rejected Email', 'nibwp'),
        'inactive_email'         => __('Account Deactivated Email', 'nibwp'),
        'deletion_email'         => __('Account Deleted Email', 'nibwp'),
        'resetpw_email'          => __('Password Reset Email', 'nibwp'),
        'changedpw_email'        => __('Password Changed Email', 'nibwp'),
        'changedaccount_email'   => __('Account Updated Email', 'nibwp'),
        'notification_new_user'  => __('New User Notification', 'nibwp'),
        'notification_review'    => __('Account Needs Review Notification', 'nibwp'),
        'notification_deletion'  => __('Account Deletion Notification', 'nibwp'),
        'suspicious-activity'    => __('Security: Suspicious Account Activity', 'nibwp'),
    ];
}

/** Whether the active theme overrides a notification template. */
function nibwp_um_email_theme_override(string $key): bool
{
    $mail = nibwp_um_service('mail');
    if ($mail === null || !method_exists($mail, 'template_in_theme')) {
        return false;
    }

    try {
        return (bool) $mail->template_in_theme($key);
    } catch (\Throwable $e) {
        return false;
    }
}

/** @return array<string, mixed>|WP_Error */
function nibwp_um_list_emails(int $per_page, int $page): array|WP_Error
{
    if ($denied = nibwp_um_require_cap('manage_options', __('Listing the notification emails', 'nibwp'))) {
        return $denied;
    }

    $all  = nibwp_um_emails();
    $rows = [];

    foreach ($all as $key => $title) {
        $subject = nibwp_um_option($key . '_sub');
        $rows[] = [
            'key'            => $key,
            'title'          => $title,
            'enabled'        => (bool) nibwp_um_option($key . '_on'),
            'subject'        => is_scalar($subject) ? (string) $subject : '',
            'theme_override' => nibwp_um_email_theme_override($key),
        ];
    }

    $total  = count($rows);
    $offset = ($page - 1) * $per_page;

    return [
        'emails'   => array_slice($rows, $offset, $per_page),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
    ];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_um_get_email(array $in): array|WP_Error
{
    if ($denied = nibwp_um_require_cap('manage_options', __('Reading a notification email', 'nibwp'))) {
        return $denied;
    }

    $all = nibwp_um_emails();
    $key = isset($in['email_key']) && is_scalar($in['email_key']) ? (string) $in['email_key'] : '';
    if ($key === '' || !isset($all[$key])) {
        return new WP_Error('nibwp_um_no_email', sprintf(
            /* translators: %s: comma-separated list of notification keys. */
            __('Unknown notification. This site has: %s', 'nibwp'),
            implode(', ', array_keys($all))
        ));
    }

    $subject = nibwp_um_option($key . '_sub');
    $body    = nibwp_um_option($key);

    return [
        'email' => [
            'key'            => $key,
            'title'          => $all[$key],
            'enabled'        => (bool) nibwp_um_option($key . '_on'),
            'subject'        => is_scalar($subject) ? (string) $subject : '',
            'body'           => is_scalar($body) ? (string) $body : '',
            'theme_override' => nibwp_um_email_theme_override($key),
        ],
        'note' => nibwp_um_email_theme_override($key)
            ? __('The active theme overrides this template, so the body stored here is not what goes out.', 'nibwp')
            : '',
    ];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_um_update_email(array $in): array|WP_Error
{
    if ($denied = nibwp_um_require_cap('manage_options', __('Editing a notification email', 'nibwp'))) {
        return $denied;
    }

    $all = nibwp_um_emails();
    $key = isset($in['email_key']) && is_scalar($in['email_key']) ? (string) $in['email_key'] : '';
    if ($key === '' || !isset($all[$key])) {
        return new WP_Error('nibwp_um_no_email', sprintf(
            /* translators: %s: comma-separated list of notification keys. */
            __('Unknown notification. This site has: %s', 'nibwp'),
            implode(', ', array_keys($all))
        ));
    }

    $applied = [];

    if (isset($in['subject']) && is_scalar($in['subject'])) {
        $subject = sanitize_text_field((string) $in['subject']);
        if (nibwp_um_option_update($key . '_sub', $subject)) {
            $applied['subject'] = $subject;
        }
    }
    if (isset($in['body']) && is_scalar($in['body'])) {
        // The stored body is rendered as HTML when email_html is on, so it is
        // kses-filtered rather than stripped.
        $body = wp_kses_post((string) $in['body']);
        if (nibwp_um_option_update($key, $body)) {
            $applied['body'] = $body;
        }
    }
    if (array_key_exists('enabled', $in)) {
        $enabled = empty($in['enabled']) ? 0 : 1;
        if (nibwp_um_option_update($key . '_on', $enabled)) {
            $applied['enabled'] = (bool) $enabled;
        }
    }

    if ($applied === []) {
        return new WP_Error('nibwp_um_nothing', __('Nothing to update — send subject, body or enabled.', 'nibwp'));
    }

    return [
        'updated'   => true,
        'email_key' => $key,
        'applied'   => $applied,
        'note'      => nibwp_um_email_theme_override($key)
            ? __('Saved, but the active theme overrides this template so the stored body is not what goes out.', 'nibwp')
            : '',
    ];
}
