<?php

declare(strict_types=1);

/**
 * FluentCommunity integration — NIBWP MCP abilities (Layer 1 adapter).
 *
 * Seven domain-grouped abilities give an AI agent full read/write control of a
 * FluentCommunity portal: discovery, Spaces + Space Groups + Courses, members
 * and their roles, the feed (posts), comments, reactions (likes), and member
 * XProfiles + gamification points.
 *
 * Mechanism: in-process, through FluentCommunity's own Eloquent-style models
 * (FluentCommunity\App\Models\{Space, SpaceGroup, Feed, Comment, Reaction,
 * SpaceUserPivot, XProfile}) and the FeedsHelper renderer (mdToHtml) so feed
 * markdown is stored exactly as the UI stores it. Spaces live in fcom_spaces,
 * posts in fcom_posts, membership in fcom_space_user (role + status), comments
 * in fcom_post_comments, reactions in fcom_post_reactions, profiles in
 * fcom_xprofile. Verified against FluentCommunity 1.x table columns + fillables.
 *
 * Detection: FLUENT_COMMUNITY_PLUGIN_VERSION / fluentCommunityApp() / the Space
 * model class.
 */

if (!defined('ABSPATH')) {
    exit();
}

/* ----------------------------------------------------------------------------
 * Shared helpers (file-local)
 * ------------------------------------------------------------------------- */

/** Is FluentCommunity active? */
function nibwp_fcom_available(): bool
{
    return defined('FLUENT_COMMUNITY_PLUGIN_VERSION')
        || function_exists('fluentCommunityApp')
        || class_exists('FluentCommunity\\App\\Models\\Space');
}

/** Resolve a FluentCommunity model FQCN, or null if the class is absent. */
function nibwp_fcom_model(string $short): ?string
{
    $cls = 'FluentCommunity\\App\\Models\\' . $short;
    return class_exists($cls) ? $cls : null;
}

/** House WP_Error wrapper. */
function nibwp_fcom_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error($code, $message, ['status' => $status]);
}

/** Render feed/comment markdown to HTML the way FluentCommunity does. */
function nibwp_fcom_render(string $message): string
{
    if (class_exists('FluentCommunity\\App\\Services\\FeedsHelper') && method_exists('FluentCommunity\\App\\Services\\FeedsHelper', 'mdToHtml')) {
        try {
            return (string) \FluentCommunity\App\Services\FeedsHelper::mdToHtml($message);
        } catch (\Throwable $e) {
            // fall through
        }
    }
    return wpautop(wp_kses_post($message));
}

/** Clamp pagination to sane bounds. */
function nibwp_fcom_paginate(array $input): array
{
    $per  = min(max((int) ($input['per_page'] ?? 25), 1), 100);
    $page = max((int) ($input['page'] ?? 1), 1);
    return [$per, $page, ($page - 1) * $per];
}

/** Turn a model / collection into a plain array. */
function nibwp_fcom_arr($value): array
{
    if (is_object($value) && method_exists($value, 'toArray')) {
        return (array) $value->toArray();
    }
    return is_array($value) ? $value : [];
}

/* ----------------------------------------------------------------------------
 * 1) fluentcommunity-info — discovery
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentcommunity-info', [
    'label'       => __('FluentCommunity — Info', 'nibwp'),
    'description' => __('Detect FluentCommunity, its version, the portal slug, and counts of spaces, space groups, courses, members, feed posts, comments and reactions.', 'nibwp'),
    'category'    => 'community',
    'input_schema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_fcom_info_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function nibwp_fcom_info_execute(array $input): array|WP_Error
{
    if (!nibwp_fcom_available()) {
        return nibwp_fcom_err('fcom_inactive', 'FluentCommunity is not active on this site.', 404);
    }
    global $wpdb;
    $p = $wpdb->prefix . 'fcom_';
    $count = static function (string $table, string $where = '') use ($wpdb, $p) {
        $sql = "SELECT COUNT(*) FROM {$p}{$table}" . ($where ? " WHERE {$where}" : '');
        return (int) $wpdb->get_var($sql);
    };
    return [
        'fluentcommunity_active' => true,
        'version'      => defined('FLUENT_COMMUNITY_PLUGIN_VERSION') ? FLUENT_COMMUNITY_PLUGIN_VERSION : '',
        'spaces'       => $count('spaces', "type = 'community'"),
        'courses'      => $count('spaces', "type = 'course'"),
        'space_groups' => $count('spaces', "type = 'space_group'"),
        'members'      => $count('space_user'),
        'feed_posts'   => $count('posts'),
        'comments'     => $count('post_comments'),
        'reactions'    => $count('post_reactions'),
        'profiles'     => $count('xprofile'),
        'abilities'    => ['spaces', 'members', 'feed', 'comments', 'reactions', 'profiles'],
    ];
}

/* ----------------------------------------------------------------------------
 * 2) fluentcommunity-spaces — spaces, space groups, courses
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentcommunity-spaces', [
    'label'       => __('FluentCommunity — Spaces', 'nibwp'),
    'description' => __('Manage FluentCommunity Spaces, Space Groups and Courses. Actions: list, get, create, update, delete, list_groups, create_group. Set title, slug, description, privacy (public|private|secret), type (community|course), parent group and settings.', 'nibwp'),
    'category'    => 'community',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'      => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'delete', 'list_groups', 'create_group']],
            'id'          => ['type' => 'integer'],
            'title'       => ['type' => 'string'],
            'slug'        => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'privacy'     => ['type' => 'string', 'enum' => ['public', 'private', 'secret']],
            'type'        => ['type' => 'string', 'enum' => ['community', 'course'], 'description' => 'Space type. Use create_group for a space group.'],
            'parent_id'   => ['type' => 'integer', 'description' => 'Space group id to nest this space under.'],
            'settings'    => ['type' => 'object'],
            'per_page'    => ['type' => 'integer'],
            'page'        => ['type' => 'integer'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_fcom_spaces_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false]],
]);

function nibwp_fcom_spaces_execute(array $input): array|WP_Error
{
    if (!nibwp_fcom_available()) {
        return nibwp_fcom_err('fcom_inactive', 'FluentCommunity is not active on this site.', 404);
    }
    $Space = nibwp_fcom_model('Space');
    $Group = nibwp_fcom_model('SpaceGroup');
    if (!$Space) {
        return nibwp_fcom_err('no_model', 'FluentCommunity Space model unavailable.');
    }
    $action = (string) ($input['action'] ?? '');
    [$per, $page, $offset] = nibwp_fcom_paginate($input);

    $build = static function (array $in, string $defaultType): array {
        $title = sanitize_text_field((string) ($in['title'] ?? ''));
        $data = [
            'title'       => $title,
            'slug'        => sanitize_title((string) ($in['slug'] ?? $title)),
            'description' => wp_kses_post((string) ($in['description'] ?? '')),
            'type'        => $defaultType,
            'privacy'     => in_array($in['privacy'] ?? 'public', ['public', 'private', 'secret'], true) ? $in['privacy'] : 'public',
            'status'      => 'published',
            'created_by'  => get_current_user_id() ?: 1,
        ];
        if (!empty($in['parent_id'])) {
            $data['parent_id'] = (int) $in['parent_id'];
        }
        if (isset($in['settings']) && is_array($in['settings'])) {
            $data['settings'] = $in['settings'];
        }
        return $data;
    };

    try {
        switch ($action) {
            case 'list':
                $rows = $Space::query()->whereIn('type', ['community', 'course'])->orderBy('id', 'desc')->limit($per)->offset($offset)->get();
                $out = [];
                foreach ($rows as $s) {
                    $out[] = ['id' => $s->id, 'title' => $s->title, 'slug' => $s->slug, 'type' => $s->type, 'privacy' => $s->privacy, 'status' => $s->status];
                }
                return ['spaces' => $out, 'count' => count($out)];

            case 'list_groups':
                if (!$Group) {
                    return nibwp_fcom_err('no_group_model', 'SpaceGroup model unavailable.');
                }
                $rows = $Group::query()->orderBy('id', 'desc')->limit($per)->offset($offset)->get();
                $out = [];
                foreach ($rows as $g) {
                    $out[] = ['id' => $g->id, 'title' => $g->title, 'slug' => $g->slug, 'type' => $g->type];
                }
                return ['groups' => $out, 'count' => count($out)];

            case 'get':
                $s = $Space::query()->find((int) ($input['id'] ?? 0));
                return $s ? ['space' => nibwp_fcom_arr($s)] : nibwp_fcom_err('not_found', 'Space not found.', 404);

            case 'create':
                $type = ($input['type'] ?? 'community') === 'course' ? 'course' : 'community';
                $s = $Space::create($build($input, $type));
                return ['created' => true, 'space' => nibwp_fcom_arr($s)];

            case 'create_group':
                if (!$Group) {
                    return nibwp_fcom_err('no_group_model', 'SpaceGroup model unavailable.');
                }
                $g = $Group::create($build($input, 'space_group'));
                return ['created' => true, 'group' => nibwp_fcom_arr($g)];

            case 'update':
                $s = $Space::query()->find((int) ($input['id'] ?? 0));
                if (!$s) {
                    return nibwp_fcom_err('not_found', 'Space not found.', 404);
                }
                foreach (['title', 'slug', 'description', 'privacy', 'status'] as $f) {
                    if (isset($input[$f])) {
                        $s->{$f} = $f === 'description' ? wp_kses_post((string) $input[$f]) : sanitize_text_field((string) $input[$f]);
                    }
                }
                if (isset($input['parent_id'])) { $s->parent_id = (int) $input['parent_id']; }
                if (isset($input['settings']) && is_array($input['settings'])) { $s->settings = $input['settings']; }
                $s->save();
                return ['updated' => true, 'space' => nibwp_fcom_arr($s)];

            case 'delete':
                $s = $Space::query()->find((int) ($input['id'] ?? 0));
                if (!$s) {
                    return nibwp_fcom_err('not_found', 'Space not found.', 404);
                }
                $s->delete();
                return ['deleted' => true, 'id' => (int) $input['id']];
        }
    } catch (\Throwable $e) {
        return nibwp_fcom_err('fcom_error', $e->getMessage());
    }
    return nibwp_fcom_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 3) fluentcommunity-members — space membership + roles
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentcommunity-members', [
    'label'       => __('FluentCommunity — Members', 'nibwp'),
    'description' => __('Manage space membership and roles. Actions: list, add, remove, set_role, get. Roles: admin, moderator, member. Status: active, pending.', 'nibwp'),
    'category'    => 'community',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['list', 'add', 'remove', 'set_role', 'get']],
            'space_id' => ['type' => 'integer'],
            'user_id'  => ['type' => 'integer'],
            'role'     => ['type' => 'string', 'enum' => ['admin', 'moderator', 'member']],
            'status'   => ['type' => 'string', 'enum' => ['active', 'pending']],
            'per_page' => ['type' => 'integer'],
            'page'     => ['type' => 'integer'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_fcom_members_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false]],
]);

function nibwp_fcom_members_execute(array $input): array|WP_Error
{
    if (!nibwp_fcom_available()) {
        return nibwp_fcom_err('fcom_inactive', 'FluentCommunity is not active on this site.', 404);
    }
    $Pivot = nibwp_fcom_model('SpaceUserPivot');
    if (!$Pivot) {
        return nibwp_fcom_err('no_model', 'SpaceUserPivot model unavailable.');
    }
    $action  = (string) ($input['action'] ?? '');
    $spaceId = (int) ($input['space_id'] ?? 0);
    $userId  = (int) ($input['user_id'] ?? 0);
    [$per, $page, $offset] = nibwp_fcom_paginate($input);

    try {
        switch ($action) {
            case 'list':
                $rows = $Pivot::query()->where('space_id', $spaceId)->limit($per)->offset($offset)->get();
                $out = [];
                foreach ($rows as $m) {
                    $u = get_userdata((int) $m->user_id);
                    $out[] = ['user_id' => (int) $m->user_id, 'login' => $u ? $u->user_login : '', 'role' => $m->role, 'status' => $m->status];
                }
                return ['members' => $out, 'count' => count($out), 'space_id' => $spaceId];

            case 'get':
                $m = $Pivot::query()->where('space_id', $spaceId)->where('user_id', $userId)->first();
                return $m ? ['membership' => nibwp_fcom_arr($m)] : nibwp_fcom_err('not_found', 'Membership not found.', 404);

            case 'add':
                if (!$spaceId || !$userId) {
                    return nibwp_fcom_err('bad_input', 'add needs space_id + user_id.');
                }
                $existing = $Pivot::query()->where('space_id', $spaceId)->where('user_id', $userId)->first();
                if ($existing) {
                    return ['added' => false, 'reason' => 'already a member', 'membership' => nibwp_fcom_arr($existing)];
                }
                $m = $Pivot::create([
                    'space_id' => $spaceId,
                    'user_id'  => $userId,
                    'role'     => in_array($input['role'] ?? 'member', ['admin', 'moderator', 'member'], true) ? $input['role'] : 'member',
                    'status'   => in_array($input['status'] ?? 'active', ['active', 'pending'], true) ? $input['status'] : 'active',
                ]);
                return ['added' => true, 'membership' => nibwp_fcom_arr($m)];

            case 'set_role':
                $m = $Pivot::query()->where('space_id', $spaceId)->where('user_id', $userId)->first();
                if (!$m) {
                    return nibwp_fcom_err('not_found', 'Membership not found.', 404);
                }
                $m->role = in_array($input['role'] ?? 'member', ['admin', 'moderator', 'member'], true) ? $input['role'] : 'member';
                $m->save();
                return ['updated' => true, 'membership' => nibwp_fcom_arr($m)];

            case 'remove':
                $m = $Pivot::query()->where('space_id', $spaceId)->where('user_id', $userId)->first();
                if (!$m) {
                    return nibwp_fcom_err('not_found', 'Membership not found.', 404);
                }
                $m->delete();
                return ['removed' => true, 'space_id' => $spaceId, 'user_id' => $userId];
        }
    } catch (\Throwable $e) {
        return nibwp_fcom_err('fcom_error', $e->getMessage());
    }
    return nibwp_fcom_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 4) fluentcommunity-feed — posts
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentcommunity-feed', [
    'label'       => __('FluentCommunity — Feed', 'nibwp'),
    'description' => __('Create and manage feed posts inside a space. Actions: list, get, create, update, delete, pin, unpin. Markdown in "message" is rendered the way the portal renders it.', 'nibwp'),
    'category'    => 'community',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'delete', 'pin', 'unpin']],
            'id'       => ['type' => 'integer'],
            'space_id' => ['type' => 'integer'],
            'user_id'  => ['type' => 'integer', 'description' => 'Author. Defaults to the current user.'],
            'title'    => ['type' => 'string'],
            'message'  => ['type' => 'string', 'description' => 'Post body (markdown).'],
            'privacy'  => ['type' => 'string', 'enum' => ['public', 'private']],
            'type'     => ['type' => 'string', 'default' => 'text'],
            'per_page' => ['type' => 'integer'],
            'page'     => ['type' => 'integer'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_fcom_feed_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false]],
]);

function nibwp_fcom_feed_execute(array $input): array|WP_Error
{
    if (!nibwp_fcom_available()) {
        return nibwp_fcom_err('fcom_inactive', 'FluentCommunity is not active on this site.', 404);
    }
    $Feed = nibwp_fcom_model('Feed');
    if (!$Feed) {
        return nibwp_fcom_err('no_model', 'Feed model unavailable.');
    }
    $action = (string) ($input['action'] ?? '');
    [$per, $page, $offset] = nibwp_fcom_paginate($input);

    try {
        switch ($action) {
            case 'list':
                $q = $Feed::query()->orderBy('id', 'desc');
                if (!empty($input['space_id'])) {
                    $q->where('space_id', (int) $input['space_id']);
                }
                $rows = $q->limit($per)->offset($offset)->get();
                $out = [];
                foreach ($rows as $f) {
                    $out[] = ['id' => $f->id, 'space_id' => $f->space_id, 'user_id' => $f->user_id, 'title' => $f->title, 'type' => $f->type, 'is_sticky' => (int) $f->is_sticky, 'comments' => (int) $f->comments_count, 'reactions' => (int) $f->reactions_count];
                }
                return ['feed' => $out, 'count' => count($out)];

            case 'get':
                $f = $Feed::query()->find((int) ($input['id'] ?? 0));
                return $f ? ['post' => nibwp_fcom_arr($f)] : nibwp_fcom_err('not_found', 'Post not found.', 404);

            case 'create':
                $message = (string) ($input['message'] ?? '');
                if ($message === '' && ($input['title'] ?? '') === '') {
                    return nibwp_fcom_err('empty', 'Provide a message or title.');
                }
                $f = $Feed::create([
                    'user_id'          => (int) ($input['user_id'] ?? get_current_user_id() ?: 1),
                    'space_id'         => !empty($input['space_id']) ? (int) $input['space_id'] : null,
                    'title'            => sanitize_text_field((string) ($input['title'] ?? '')),
                    'message'          => $message,
                    'message_rendered' => nibwp_fcom_render($message),
                    'type'             => sanitize_key((string) ($input['type'] ?? 'text')),
                    'content_type'     => 'text',
                    'privacy'          => in_array($input['privacy'] ?? 'public', ['public', 'private'], true) ? $input['privacy'] : 'public',
                    'status'           => 'published',
                ]);
                return ['created' => true, 'post' => nibwp_fcom_arr($f)];

            case 'update':
                $f = $Feed::query()->find((int) ($input['id'] ?? 0));
                if (!$f) {
                    return nibwp_fcom_err('not_found', 'Post not found.', 404);
                }
                if (isset($input['title']))   { $f->title = sanitize_text_field((string) $input['title']); }
                if (isset($input['message'])) { $f->message = (string) $input['message']; $f->message_rendered = nibwp_fcom_render((string) $input['message']); }
                if (isset($input['privacy'])) { $f->privacy = in_array($input['privacy'], ['public', 'private'], true) ? $input['privacy'] : $f->privacy; }
                $f->save();
                return ['updated' => true, 'post' => nibwp_fcom_arr($f)];

            case 'pin':
            case 'unpin':
                $f = $Feed::query()->find((int) ($input['id'] ?? 0));
                if (!$f) {
                    return nibwp_fcom_err('not_found', 'Post not found.', 404);
                }
                $f->is_sticky = $action === 'pin' ? 1 : 0;
                $f->save();
                return ['updated' => true, 'is_sticky' => (int) $f->is_sticky, 'id' => $f->id];

            case 'delete':
                $f = $Feed::query()->find((int) ($input['id'] ?? 0));
                if (!$f) {
                    return nibwp_fcom_err('not_found', 'Post not found.', 404);
                }
                $f->delete();
                return ['deleted' => true, 'id' => (int) $input['id']];
        }
    } catch (\Throwable $e) {
        return nibwp_fcom_err('fcom_error', $e->getMessage());
    }
    return nibwp_fcom_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 5) fluentcommunity-comments — comments on feed posts
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentcommunity-comments', [
    'label'       => __('FluentCommunity — Comments', 'nibwp'),
    'description' => __('Manage comments on feed posts. Actions: list, add, update, delete. Supports threaded replies via parent_id.', 'nibwp'),
    'category'    => 'community',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'    => ['type' => 'string', 'enum' => ['list', 'add', 'update', 'delete']],
            'id'        => ['type' => 'integer', 'description' => 'Comment id (update/delete).'],
            'post_id'   => ['type' => 'integer', 'description' => 'Feed post id.'],
            'user_id'   => ['type' => 'integer'],
            'message'   => ['type' => 'string'],
            'parent_id' => ['type' => 'integer', 'description' => 'Parent comment id for a reply.'],
            'per_page'  => ['type' => 'integer'],
            'page'      => ['type' => 'integer'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_fcom_comments_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false]],
]);

function nibwp_fcom_comments_execute(array $input): array|WP_Error
{
    if (!nibwp_fcom_available()) {
        return nibwp_fcom_err('fcom_inactive', 'FluentCommunity is not active on this site.', 404);
    }
    $Comment = nibwp_fcom_model('Comment');
    if (!$Comment) {
        return nibwp_fcom_err('no_model', 'Comment model unavailable.');
    }
    $action = (string) ($input['action'] ?? '');
    [$per, $page, $offset] = nibwp_fcom_paginate($input);

    try {
        switch ($action) {
            case 'list':
                $rows = $Comment::query()->where('post_id', (int) ($input['post_id'] ?? 0))->orderBy('id', 'asc')->limit($per)->offset($offset)->get();
                $out = [];
                foreach ($rows as $c) {
                    $out[] = ['id' => $c->id, 'user_id' => $c->user_id, 'parent_id' => $c->parent_id, 'message' => $c->message, 'reactions' => (int) $c->reactions_count];
                }
                return ['comments' => $out, 'count' => count($out)];

            case 'add':
                $message = (string) ($input['message'] ?? '');
                if ($message === '' || empty($input['post_id'])) {
                    return nibwp_fcom_err('bad_input', 'add needs post_id + message.');
                }
                $c = $Comment::create([
                    'post_id'          => (int) $input['post_id'],
                    'user_id'          => (int) ($input['user_id'] ?? get_current_user_id() ?: 1),
                    'parent_id'        => !empty($input['parent_id']) ? (int) $input['parent_id'] : null,
                    'message'          => $message,
                    'message_rendered' => nibwp_fcom_render($message),
                    'type'             => 'comment',
                    'content_type'     => 'text',
                    'status'           => 'published',
                ]);
                return ['added' => true, 'comment' => nibwp_fcom_arr($c)];

            case 'update':
                $c = $Comment::query()->find((int) ($input['id'] ?? 0));
                if (!$c) {
                    return nibwp_fcom_err('not_found', 'Comment not found.', 404);
                }
                if (isset($input['message'])) {
                    $c->message = (string) $input['message'];
                    $c->message_rendered = nibwp_fcom_render((string) $input['message']);
                    $c->save();
                }
                return ['updated' => true, 'comment' => nibwp_fcom_arr($c)];

            case 'delete':
                $c = $Comment::query()->find((int) ($input['id'] ?? 0));
                if (!$c) {
                    return nibwp_fcom_err('not_found', 'Comment not found.', 404);
                }
                $c->delete();
                return ['deleted' => true, 'id' => (int) $input['id']];
        }
    } catch (\Throwable $e) {
        return nibwp_fcom_err('fcom_error', $e->getMessage());
    }
    return nibwp_fcom_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 6) fluentcommunity-reactions — likes on posts + comments
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentcommunity-reactions', [
    'label'       => __('FluentCommunity — Reactions', 'nibwp'),
    'description' => __('Add or remove reactions (likes) on feed posts and comments, and list a reaction set. Actions: react, unreact, list. object_type: feed | comment.', 'nibwp'),
    'category'    => 'community',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'      => ['type' => 'string', 'enum' => ['react', 'unreact', 'list']],
            'object_id'   => ['type' => 'integer', 'description' => 'The feed post or comment id.'],
            'object_type' => ['type' => 'string', 'enum' => ['feed', 'comment'], 'default' => 'feed'],
            'user_id'     => ['type' => 'integer'],
            'type'        => ['type' => 'string', 'default' => 'like'],
            'per_page'    => ['type' => 'integer'],
            'page'        => ['type' => 'integer'],
        ],
        'required' => ['action', 'object_id'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_fcom_reactions_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function nibwp_fcom_reactions_execute(array $input): array|WP_Error
{
    if (!nibwp_fcom_available()) {
        return nibwp_fcom_err('fcom_inactive', 'FluentCommunity is not active on this site.', 404);
    }
    $Reaction = nibwp_fcom_model('Reaction');
    if (!$Reaction) {
        return nibwp_fcom_err('no_model', 'Reaction model unavailable.');
    }
    $action = (string) ($input['action'] ?? '');
    $objId  = (int) ($input['object_id'] ?? 0);
    $objType = in_array($input['object_type'] ?? 'feed', ['feed', 'comment'], true) ? (string) $input['object_type'] : 'feed';
    $userId = (int) ($input['user_id'] ?? get_current_user_id() ?: 1);
    $type   = sanitize_key((string) ($input['type'] ?? 'like'));
    [$per, $page, $offset] = nibwp_fcom_paginate($input);

    try {
        switch ($action) {
            case 'list':
                $rows = $Reaction::query()->where('object_id', $objId)->where('object_type', $objType)->limit($per)->offset($offset)->get();
                $out = [];
                foreach ($rows as $r) {
                    $out[] = ['user_id' => (int) $r->user_id, 'type' => $r->type];
                }
                return ['reactions' => $out, 'count' => count($out)];

            case 'react':
                $existing = $Reaction::query()->where('object_id', $objId)->where('object_type', $objType)->where('user_id', $userId)->first();
                if ($existing) {
                    $existing->type = $type;
                    $existing->save();
                    return ['reacted' => true, 'updated' => true, 'id' => $existing->id];
                }
                $r = $Reaction::create([
                    'user_id'     => $userId,
                    'object_id'   => $objId,
                    'object_type' => $objType,
                    'type'        => $type,
                    'ip_address'  => '',
                ]);
                nibwp_fcom_recount_reactions($objId, $objType);
                return ['reacted' => true, 'id' => $r->id];

            case 'unreact':
                $existing = $Reaction::query()->where('object_id', $objId)->where('object_type', $objType)->where('user_id', $userId)->first();
                if (!$existing) {
                    return ['unreacted' => false, 'reason' => 'no reaction'];
                }
                $existing->delete();
                nibwp_fcom_recount_reactions($objId, $objType);
                return ['unreacted' => true];
        }
    } catch (\Throwable $e) {
        return nibwp_fcom_err('fcom_error', $e->getMessage());
    }
    return nibwp_fcom_err('bad_action', 'Unknown action: ' . $action);
}

/** Recompute the cached reactions_count on a feed post or comment. */
function nibwp_fcom_recount_reactions(int $objId, string $objType): void
{
    global $wpdb;
    $p = $wpdb->prefix . 'fcom_';
    $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}post_reactions WHERE object_id = %d AND object_type = %s", $objId, $objType));
    $table = $objType === 'comment' ? $p . 'post_comments' : $p . 'posts';
    $wpdb->update($table, ['reactions_count' => $count], ['id' => $objId]);
}

/* ----------------------------------------------------------------------------
 * 7) fluentcommunity-profiles — XProfile + gamification points
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentcommunity-profiles', [
    'label'       => __('FluentCommunity — Profiles', 'nibwp'),
    'description' => __('Read/write member community profiles (XProfile) and gamification points. Actions: get, update, list, add_points, set_points. Fields: display_name, short_description, avatar, username, is_verified.', 'nibwp'),
    'category'    => 'community',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'            => ['type' => 'string', 'enum' => ['get', 'update', 'list', 'add_points', 'set_points']],
            'user_id'           => ['type' => 'integer'],
            'display_name'      => ['type' => 'string'],
            'short_description' => ['type' => 'string'],
            'avatar'            => ['type' => 'string'],
            'username'          => ['type' => 'string'],
            'is_verified'       => ['type' => 'boolean'],
            'points'            => ['type' => 'integer'],
            'per_page'          => ['type' => 'integer'],
            'page'              => ['type' => 'integer'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_fcom_profiles_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function nibwp_fcom_profiles_execute(array $input): array|WP_Error
{
    if (!nibwp_fcom_available()) {
        return nibwp_fcom_err('fcom_inactive', 'FluentCommunity is not active on this site.', 404);
    }
    $XProfile = nibwp_fcom_model('XProfile');
    if (!$XProfile) {
        return nibwp_fcom_err('no_model', 'XProfile model unavailable.');
    }
    $action = (string) ($input['action'] ?? '');
    $userId = (int) ($input['user_id'] ?? 0);
    [$per, $page, $offset] = nibwp_fcom_paginate($input);

    $find = static function (int $uid) use ($XProfile) {
        return $XProfile::query()->where('user_id', $uid)->first();
    };

    try {
        switch ($action) {
            case 'list':
                $rows = $XProfile::query()->orderBy('total_points', 'desc')->limit($per)->offset($offset)->get();
                $out = [];
                foreach ($rows as $x) {
                    $out[] = ['user_id' => (int) $x->user_id, 'display_name' => $x->display_name, 'username' => $x->username, 'points' => (int) $x->total_points, 'is_verified' => (int) $x->is_verified];
                }
                return ['profiles' => $out, 'count' => count($out)];

            case 'get':
                $x = $find($userId);
                return $x ? ['profile' => nibwp_fcom_arr($x)] : nibwp_fcom_err('not_found', 'Profile not found for user ' . $userId, 404);

            case 'update':
                $x = $find($userId);
                if (!$x) {
                    return nibwp_fcom_err('not_found', 'Profile not found for user ' . $userId, 404);
                }
                foreach (['display_name', 'short_description', 'avatar', 'username'] as $f) {
                    if (isset($input[$f])) {
                        $x->{$f} = $f === 'short_description' ? wp_kses_post((string) $input[$f]) : sanitize_text_field((string) $input[$f]);
                    }
                }
                if (isset($input['is_verified'])) {
                    $x->is_verified = !empty($input['is_verified']) ? 1 : 0;
                }
                $x->save();
                return ['updated' => true, 'profile' => nibwp_fcom_arr($x)];

            case 'add_points':
            case 'set_points':
                $x = $find($userId);
                if (!$x) {
                    return nibwp_fcom_err('not_found', 'Profile not found for user ' . $userId, 404);
                }
                $points = (int) ($input['points'] ?? 0);
                $x->total_points = $action === 'add_points' ? ((int) $x->total_points + $points) : $points;
                $x->save();
                return ['updated' => true, 'user_id' => $userId, 'total_points' => (int) $x->total_points];
        }
    } catch (\Throwable $e) {
        return nibwp_fcom_err('fcom_error', $e->getMessage());
    }
    return nibwp_fcom_err('bad_action', 'Unknown action: ' . $action);
}
