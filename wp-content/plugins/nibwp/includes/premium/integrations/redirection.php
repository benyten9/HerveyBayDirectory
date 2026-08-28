<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Redirection integration for NIBWP (Pro tier).
 *
 * Replaces the five actions this carried in plugin-integrations.php, one of
 * which never worked: list_redirects called Red_Item::get_last_access(), a
 * method Redirection does not have — it is get_last_hit(). Every listing
 * raised an undefined-method error that the surrounding try/catch turned into
 * a generic failure, so the integration looked flaky rather than broken.
 *
 * Built against Redirection's own model classes (Red_Item, Red_Group,
 * Red_404_Log, Red_Redirect_Log), not its tables, so schema changes stay
 * Redirection's problem.
 *
 * REQUIRES: Redirection plugin active.
 */

/** @return WP_Error|null */
function nibwp_rdr_guard(): ?WP_Error
{
    if (!class_exists('Red_Item')) {
        return new WP_Error(
            'nibwp_rdr_missing',
            __('Redirection is not active on this site.', domain: 'nibwp')
        );
    }

    return null;
}

/** One redirect, flattened. */
function nibwp_rdr_shape($item): array
{
    return [
        'id'          => (int) $item->get_id(),
        'title'       => (string) $item->get_title(),
        'source_url'  => (string) $item->get_url(),
        'target_url'  => (string) $item->get_action_data(),
        'match_type'  => (string) $item->get_match_type(),
        'action_type' => (string) $item->get_action_type(),
        'http_code'   => (int) $item->get_action_code(),
        'group_id'    => (int) $item->get_group_id(),
        'hits'        => (int) $item->get_hits(),
        'enabled'     => (bool) $item->is_enabled(),
        'is_regex'    => (bool) $item->is_regex(),
        // get_last_hit, NOT get_last_access — the latter does not exist and
        // was what broke every listing before this rewrite.
        'last_hit'    => $item->get_last_hit(),
    ];
}

wp_register_ability('nibwp/redirection-manage', [
    'label' => __('Redirection – Redirects & 404s', domain: 'nibwp'),
    'description' => __(
        'Manage Redirection: create, edit, enable, disable and delete redirects, organize them into groups, read the redirect log and the 404 log, and check what a given URL would do.',
        domain: 'nibwp',
    ),
    'category' => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => [
                    // Redirects
                    'list_redirects', 'get_redirect', 'create_redirect', 'update_redirect', 'delete_redirect',
                    'enable_redirect', 'disable_redirect', 'reset_hits',
                    // Groups
                    'list_groups', 'create_group', 'update_group', 'delete_group',
                    // Logs
                    'list_logs', 'clear_logs', 'list_404s', 'clear_404s', 'top_404s',
                    // Diagnostics
                    'check_url',
                ],
                'description' => 'The operation to perform.',
            ],

            'redirect_id' => ['type' => 'integer', 'description' => 'Redirect ID.'],
            'group_id'    => ['type' => 'integer', 'description' => 'Group ID.'],

            'source_url' => ['type' => 'string', 'description' => 'The path to match, e.g. /old-page.'],
            'target_url' => ['type' => 'string', 'description' => 'Where it should go.'],
            'title'      => ['type' => 'string', 'description' => 'Human label for the redirect or group.'],
            'match_type' => ['type' => 'string', 'enum' => ['url', 'regex'], 'default' => 'url'],
            'http_code'  => ['type' => 'integer', 'enum' => [301, 302, 303, 307, 308, 404, 410], 'default' => 301],
            'enabled'    => ['type' => 'boolean', 'description' => 'Whether the redirect is live.'],

            'url'      => ['type' => 'string', 'description' => 'URL to test with check_url.'],
            'search'   => ['type' => 'string', 'description' => 'Filter listings by URL fragment.'],
            'per_page' => ['type' => 'integer', 'default' => 25],
            'page'     => ['type' => 'integer', 'default' => 1],

            'confirm' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Required for delete_redirect, delete_group, clear_logs and clear_404s.',
            ],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'nibwp_redirection_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage Redirection.',
                '',
                'A redirect is a live routing rule: getting source_url wrong sends real',
                'visitors somewhere unintended, and a regex that matches too much can take',
                'a whole section of the site with it. Prefer match_type=url unless a',
                'pattern is genuinely needed, and use check_url afterwards to confirm the',
                'rule does what you expect.',
                '',
                'IRREVERSIBLE — needs confirm=true:',
                '- delete_redirect, delete_group, clear_logs, clear_404s',
                'delete_group takes every redirect inside it.',
                '',
                'Use disable_redirect rather than delete when you only want to stop a rule;',
                'it can be switched back on.',
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
function nibwp_redirection_manage(array $input): array|WP_Error
{
    if ($guard = nibwp_rdr_guard()) {
        return $guard;
    }

    $action   = (string) ($input['action'] ?? '');
    $per_page = max(1, min(200, (int) ($input['per_page'] ?? 25)));
    $page     = max(1, (int) ($input['page'] ?? 1));

    $irreversible = ['delete_redirect', 'delete_group', 'clear_logs', 'clear_404s'];
    if (in_array($action, $irreversible, strict: true) && empty($input['confirm'])) {
        return new WP_Error(
            'nibwp_rdr_unconfirmed',
            __('This cannot be undone. Re-issue the call with confirm set to true if that is intended.', domain: 'nibwp')
        );
    }

    try {
        return nibwp_rdr_dispatch($action, $input, $per_page, $page);
    } catch (\Throwable $e) {
        return new WP_Error('nibwp_rdr_failed', sprintf(
            /* translators: 1: action name, 2: error message. */
            __('Redirection could not complete %1$s: %2$s', domain: 'nibwp'),
            $action,
            $e->getMessage()
        ));
    }
}

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_rdr_dispatch(string $action, array $in, int $per_page, int $page): array|WP_Error
{
    $offset = ($page - 1) * $per_page;

    switch ($action) {
        case 'list_redirects':
            $all = Red_Item::get_all();
            if (!empty($in['search'])) {
                $needle = strtolower((string) $in['search']);
                $all = array_values(array_filter($all, static function ($i) use ($needle) {
                    return str_contains(strtolower((string) $i->get_url()), $needle)
                        || str_contains(strtolower((string) $i->get_action_data()), $needle);
                }));
            }

            return [
                'redirects' => array_map('nibwp_rdr_shape', array_slice($all, $offset, $per_page)),
                'total'     => count($all),
            ];

        case 'get_redirect':
        case 'update_redirect':
        case 'delete_redirect':
        case 'enable_redirect':
        case 'disable_redirect':
        case 'reset_hits':
            return nibwp_rdr_one($action, $in);

        case 'create_redirect':
            $source = trim((string) ($in['source_url'] ?? ''));
            $target = trim((string) ($in['target_url'] ?? ''));
            $code   = (int) ($in['http_code'] ?? 301);

            if ($source === '') {
                return new WP_Error('nibwp_rdr_no_source', __('create_redirect needs a source_url.', domain: 'nibwp'));
            }
            // 404 and 410 deliberately answer with nothing, so they are the one
            // pair that legitimately has no target.
            if ($target === '' && !in_array($code, [404, 410], true)) {
                return new WP_Error('nibwp_rdr_no_target', __('create_redirect needs a target_url, unless http_code is 404 or 410.', domain: 'nibwp'));
            }

            $created = Red_Item::create([
                'url'         => $source,
                'action_data' => ['url' => $target],
                'action_type' => in_array($code, [404, 410], true) ? 'error' : 'url',
                'action_code' => $code,
                'match_type'  => ($in['match_type'] ?? 'url') === 'regex' ? 'regex' : 'url',
                'group_id'    => (int) ($in['group_id'] ?? 1),
                'title'       => (string) ($in['title'] ?? ''),
                'status'      => array_key_exists('enabled', $in) && !$in['enabled'] ? 'disabled' : 'enabled',
            ]);

            if (is_wp_error($created)) {
                return $created;
            }

            return ['created' => true, 'redirect' => nibwp_rdr_shape($created)];

        case 'list_groups':
            $groups = Red_Group::get_all();

            return [
                'groups' => array_map(static fn($g) => [
                    'id'        => (int) $g->get_id(),
                    'name'      => (string) $g->get_name(),
                    'module_id' => (int) $g->get_module_id(),
                    'redirects' => (int) $g->get_total_redirects(),
                    'enabled'   => (bool) $g->is_enabled(),
                ], $groups),
            ];

        case 'create_group':
            $name = trim((string) ($in['title'] ?? ''));
            if ($name === '') {
                return new WP_Error('nibwp_rdr_no_name', __('create_group needs a title.', domain: 'nibwp'));
            }
            $group = Red_Group::create($name, (int) ($in['group_id'] ?? 1));
            if (!$group) {
                return new WP_Error('nibwp_rdr_group_failed', __('Redirection refused to create that group.', domain: 'nibwp'));
            }

            return ['created' => true, 'group' => ['id' => (int) $group->get_id(), 'name' => (string) $group->get_name()]];

        case 'update_group':
        case 'delete_group':
            $id = (int) ($in['group_id'] ?? 0);
            $group = $id > 0 ? Red_Group::get($id) : false;
            if (!$group) {
                return new WP_Error('nibwp_rdr_no_group', __('No group with that ID.', domain: 'nibwp'));
            }

            if ($action === 'delete_group') {
                $count = (int) $group->get_total_redirects();
                $group->delete();

                return ['deleted' => true, 'group_id' => $id, 'redirects_removed' => $count];
            }

            $group->update(['name' => (string) ($in['title'] ?? $group->get_name())]);

            return ['updated' => true, 'group_id' => $id];

        case 'list_logs':
        case 'clear_logs':
            if (!class_exists('Red_Redirect_Log')) {
                return new WP_Error('nibwp_rdr_no_log', __('Redirection log is unavailable on this version.', domain: 'nibwp'));
            }
            if ($action === 'clear_logs') {
                Red_Redirect_Log::delete_all();

                return ['cleared' => true];
            }

            return ['logs' => nibwp_rdr_log_rows('Red_Redirect_Log', $per_page, $offset)];

        case 'list_404s':
        case 'clear_404s':
        case 'top_404s':
            if (!class_exists('Red_404_Log')) {
                return new WP_Error('nibwp_rdr_no_log', __('Redirection 404 log is unavailable on this version.', domain: 'nibwp'));
            }
            if ($action === 'clear_404s') {
                Red_404_Log::delete_all();

                return ['cleared' => true];
            }
            if ($action === 'top_404s') {
                // The point of the 404 log is finding what to fix first.
                $rows = nibwp_rdr_log_rows('Red_404_Log', 500, 0);
                $tally = [];
                foreach ($rows as $row) {
                    $url = (string) ($row['url'] ?? '');
                    if ($url === '') {
                        continue;
                    }
                    $tally[$url] = ($tally[$url] ?? 0) + 1;
                }
                arsort($tally);

                return ['top_404s' => array_map(
                    static fn($u, $n) => ['url' => $u, 'hits' => $n],
                    array_keys(array_slice($tally, 0, 20, true)),
                    array_values(array_slice($tally, 0, 20, true))
                )];
            }

            return ['not_found' => nibwp_rdr_log_rows('Red_404_Log', $per_page, $offset)];

        case 'check_url':
            $url = trim((string) ($in['url'] ?? ''));
            if ($url === '') {
                return new WP_Error('nibwp_rdr_no_url', __('check_url needs a url to test.', domain: 'nibwp'));
            }

            $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?: $url);
            $hits = [];
            foreach (Red_Item::get_all() as $item) {
                if (!$item->is_enabled()) {
                    continue;
                }
                $source = (string) $item->get_url();
                $matched = $item->is_regex()
                    ? @preg_match('@' . str_replace('@', '\@', $source) . '@', $path) === 1
                    : rtrim($source, '/') === rtrim($path, '/');

                if ($matched) {
                    $hits[] = nibwp_rdr_shape($item);
                }
            }

            return [
                'url'     => $url,
                'path'    => $path,
                'matches' => $hits,
                'note'    => $hits === []
                    ? __('No enabled redirect matches this path.', domain: 'nibwp')
                    : __('Redirection applies the first match in position order.', domain: 'nibwp'),
            ];
    }

    return new WP_Error('nibwp_rdr_unknown_action', sprintf(
        /* translators: %s: the requested action. */
        __('Unknown Redirection action: %s', domain: 'nibwp'),
        $action
    ));
}

/** Single-redirect operations, all of which need an existing ID. */
function nibwp_rdr_one(string $action, array $in): array|WP_Error
{
    $id = (int) ($in['redirect_id'] ?? 0);
    $item = $id > 0 ? Red_Item::get_by_id($id) : false;

    if (!$item) {
        return new WP_Error('nibwp_rdr_no_redirect', __('No redirect with that ID.', domain: 'nibwp'));
    }

    switch ($action) {
        case 'get_redirect':
            return ['redirect' => nibwp_rdr_shape($item)];

        case 'delete_redirect':
            $item->delete();

            return ['deleted' => true, 'redirect_id' => $id];

        case 'enable_redirect':
            $item->enable();

            return ['enabled' => true, 'redirect' => nibwp_rdr_shape(Red_Item::get_by_id($id))];

        case 'disable_redirect':
            $item->disable();

            return ['disabled' => true, 'redirect' => nibwp_rdr_shape(Red_Item::get_by_id($id))];

        case 'reset_hits':
            $item->reset();

            return ['reset' => true, 'redirect' => nibwp_rdr_shape(Red_Item::get_by_id($id))];
    }

    // update_redirect
    $patch = [
        'url'         => (string) ($in['source_url'] ?? $item->get_url()),
        'action_data' => ['url' => (string) ($in['target_url'] ?? $item->get_action_data())],
        'action_type' => (string) $item->get_action_type(),
        'action_code' => (int) ($in['http_code'] ?? $item->get_action_code()),
        'match_type'  => (string) ($in['match_type'] ?? $item->get_match_type()),
        'group_id'    => (int) ($in['group_id'] ?? $item->get_group_id()),
        'title'       => (string) ($in['title'] ?? $item->get_title()),
    ];

    $result = $item->update($patch);
    if (is_wp_error($result)) {
        return $result;
    }

    return ['updated' => true, 'redirect' => nibwp_rdr_shape(Red_Item::get_by_id($id))];
}

/**
 * Read rows from either log class without caring which.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_rdr_log_rows(string $class, int $per_page, int $offset): array
{
    if (!method_exists($class, 'get_filtered')) {
        return [];
    }

    $result = $class::get_filtered(['per_page' => $per_page, 'offset' => $offset]);
    $items  = is_array($result) ? ($result['items'] ?? $result) : [];

    $rows = [];
    foreach ((array) $items as $row) {
        $row = is_object($row) && method_exists($row, 'to_json') ? $row->to_json() : (array) $row;
        $rows[] = [
            'id'         => (int) ($row['id'] ?? 0),
            'url'        => (string) ($row['url'] ?? ''),
            'sent_to'    => (string) ($row['sent_to'] ?? ''),
            'referrer'   => (string) ($row['referrer'] ?? ''),
            'user_agent' => (string) ($row['agent'] ?? $row['user_agent'] ?? ''),
            'created_at' => (string) ($row['created'] ?? $row['created_at'] ?? ''),
        ];
    }

    return $rows;
}
