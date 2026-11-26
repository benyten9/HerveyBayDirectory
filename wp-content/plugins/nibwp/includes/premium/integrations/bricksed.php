<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Bricksed — the Bricks component library, as tools.
 *
 * Bricksed owns the library: the folder tree, the manifest, the licence tiers,
 * the converter that turns a library item into native Bricks elements. NibWP
 * owns none of that and adds none of it. What this file adds is the ability
 * for an agent to read that library and write back into it, through
 * Bricksed's own REST routes, under Bricksed's own permission callbacks.
 */

/*
 * The libs live under the skill, because that is where build/build-skill.sh
 * picks them up for the standalone skill zip, and the skill's abilities load
 * them too. Each guard names a function from its OWN file: require_once keys on
 * the resolved path, a standalone skill install may have loaded these from a
 * different path, and a second declaration is a fatal compile error.
 */
$nibwp_bricksed_lib_dir = dirname(__DIR__, 2) . '/skills/bricksed-pro/lib/';

if (!function_exists('nibwp_bricksed_available') && is_readable($nibwp_bricksed_lib_dir . 'library-client.php')) {
    require_once $nibwp_bricksed_lib_dir . 'library-client.php';
}

if (!function_exists('nibwp_bricksed_write_body') && is_readable($nibwp_bricksed_lib_dir . 'library.php')) {
    require_once $nibwp_bricksed_lib_dir . 'library.php';
}

if (!function_exists('nibwp_bricksed_validate_portability') && is_readable($nibwp_bricksed_lib_dir . 'portability.php')) {
    require_once $nibwp_bricksed_lib_dir . 'portability.php';
}

if (!function_exists('nibwp_bricksed_normalize_findings') && is_readable($nibwp_bricksed_lib_dir . 'findings.php')) {
    require_once $nibwp_bricksed_lib_dir . 'findings.php';
}

unset($nibwp_bricksed_lib_dir);

wp_register_ability('nibwp/bricksed-manage', [
    'label' => __('Bricksed — Manage Library', 'nibwp'),
    'description' => 'Read and write the Bricksed component library for Bricks: list and search the tree, read a component (as stored, or as native Bricks elements), save a new one into a folder, update its details or content, rename it, move it between folders, duplicate it, delete it, manage per-user folders and pins, and rebuild the manifest. There is one Bricksed library on the site, whichever edition of the Bricksed plugin is installed (free or Pro): nibwp/bricksed-manage and the nibwp/bricksed-pro-* abilities all read and write that same library. Call it Bricksed and never ask the person to choose between Bricksed and Bricksed Pro.',
    'category' => 'bricksed',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list', 'get', 'search', 'save', 'update', 'rename', 'move', 'duplicate', 'delete', 'folders', 'set_folders', 'pins', 'sync'],
                'description' => 'The operation to perform.',
            ],
            'id' => ['type' => 'string', 'description' => 'Component id, e.g. "components-hero-split-hero". Required for get, update, rename, move, duplicate, delete.'],
            'format' => ['type' => 'string', 'enum' => ['stored', 'bricks'], 'description' => 'For get: "stored" (default) is the item as the library keeps it; "bricks" is the native Bricks elements, global classes and Bricks components Bricksed inserts into the builder.'],
            'query' => ['type' => 'string', 'description' => 'For search: matched against name, type, category, brand and tags.'],
            'name' => ['type' => 'string', 'description' => 'Display name. For rename: the new name. For duplicate: the name of the copy. For update: optional.'],
            'type' => ['type' => 'string', 'enum' => ['layout', 'element', 'component', 'template'], 'description' => 'Top-level folder. For move and duplicate: the destination. update cannot change it.'],
            'category' => ['type' => 'string', 'description' => 'Sub-folder, e.g. "Hero". A "/" becomes " - ". For move and duplicate: the destination. update cannot change it.'],
            'brand' => ['type' => 'string', 'description' => 'Optional brand scope.'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Search tags.'],
            'description' => ['type' => 'string', 'description' => 'What this component is for.'],
            'demo_url' => ['type' => 'string', 'description' => 'Optional live demo URL.'],
            'preview_image' => ['type' => 'string', 'description' => 'Preview image URL or data URI. Counts against Bricksed\'s size cap.'],
            'block' => ['type' => 'object', 'description' => 'The component content: Bricks data { bricks: { content, globalClasses, components } } (the Bricks copy shape { content, globalClasses, components } is accepted too). Required for save. On update it REPLACES all stored content; leave it out to change only the details.'],
            'folders' => ['type' => 'array', 'description' => 'For set_folders: the complete per-user folder structure. It replaces the stored one.'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'nibwp_bricksed_manage_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
            'instructions' => implode("\n", [
                'Call "list" or "search" first — ids come from the manifest tree and cannot be guessed.',
                'get with format "bricks" shows the item as native Bricks elements: what a placement puts on a page.',
                'save creates. update changes details, and changes content only when you send a block — omit block to leave the content alone. A block with no Bricks elements (and no tree or definitions) is refused.',
                'save, and an update that sends a block, are held to the portability rules: no attachment, post or template ids, no wp-content URLs, every global class and Bricks component the elements use included.',
                'rename changes only the display name; the id and the file stay. update cannot change type or category — use move.',
                'move copies the component to the new type or category and then deletes the original, so it gets a NEW id. The original is only removed after the copy succeeded.',
                'duplicate copies to a new name, and optionally a new type or category, without touching the original.',
                'set_folders replaces the whole folder structure; read it with folders first.',
            ]),
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_bricksed_manage_execute(array $input)
{
    if (!nibwp_bricksed_available()) {
        return new WP_Error(
            'bricksed_inactive',
            __('Bricksed is not active on this site.', 'nibwp'),
            ['status' => 404]
        );
    }

    $action = (string) ($input['action'] ?? '');
    $id = (string) ($input['id'] ?? '');

    $need_id = static function () use ($id) {
        return $id === ''
            ? new WP_Error('bricksed_missing_id', __('An "id" is required. Call "list" to see the available ids.', 'nibwp'), ['status' => 400])
            : null;
    };

    switch ($action) {
        case 'list':
            return nibwp_bricksed_request('/library', 'GET');

        case 'search':
            $library = nibwp_bricksed_request('/library', 'GET');
            if (is_wp_error($library)) {
                return $library;
            }

            return ['query' => (string) ($input['query'] ?? ''), 'results' => nibwp_bricksed_search_library($library, (string) ($input['query'] ?? ''))];

        case 'get':
            if ($err = $need_id()) {
                return $err;
            }

            // Read-only either way; "bricks" asks Bricksed for what its builder inserts.
            return nibwp_bricksed_request('/component/' . $id, 'GET', ($input['format'] ?? '') === 'bricks' ? ['format' => 'bricks'] : []);

        case 'save':
            $body = nibwp_bricksed_write_body($input);
            if (is_wp_error($body)) {
                return $body;
            }
            $refusal = nibwp_bricksed_content_refusal($body);
            if ($refusal !== null) {
                return $refusal;
            }
            $result = nibwp_bricksed_request('/save-component', 'POST', $body);
            if (is_wp_error($result)) {
                return $result;
            }
            $result['warning'] = nibwp_bricksed_update_warning();

            return $result;

        case 'update':
        case 'rename':
            if ($err = $need_id()) {
                return $err;
            }

            return nibwp_bricksed_update($id, $input, $action);

        case 'move':
            if ($err = $need_id()) {
                return $err;
            }

            return nibwp_bricksed_move($id, $input);

        case 'duplicate':
            if ($err = $need_id()) {
                return $err;
            }
            if (trim((string) ($input['name'] ?? '')) === '') {
                return new WP_Error('bricksed_missing_name', __('A "name" is required for the copy.', 'nibwp'), ['status' => 400]);
            }

            return nibwp_bricksed_duplicate($id, $input);

        case 'delete':
            if ($err = $need_id()) {
                return $err;
            }

            return nibwp_bricksed_request('/delete-component', 'POST', ['id' => $id]);

        case 'folders':
            return nibwp_bricksed_request('/user-folders', 'GET');

        case 'set_folders':
            // The stored structure is replaced by what is sent. Sending nothing
            // would erase it, so "nothing" is refused rather than defaulted.
            if (!isset($input['folders']) || !is_array($input['folders'])) {
                return new WP_Error(
                    'bricksed_missing_folders',
                    __('"folders" is required and replaces the whole folder structure. Read it with "folders" first, change it, and send all of it back.', 'nibwp'),
                    ['status' => 400]
                );
            }

            return nibwp_bricksed_request('/user-folders', 'POST', ['folders' => $input['folders']]);

        case 'pins':
            return nibwp_bricksed_request('/pins', 'GET');

        case 'sync':
            $result = nibwp_bricksed_request('/sync-library', 'POST');
            if (is_wp_error($result)) {
                return $result;
            }
            $result['warning'] = nibwp_bricksed_update_warning();

            return $result;
    }

    return new WP_Error(
        'bricksed_unknown_action',
        sprintf(__('Unknown action "%s".', 'nibwp'), $action),
        ['status' => 400]
    );
}

/**
 * What stops a block of content being written: no real content, a size
 * Bricksed would refuse, or anything tied to this site alone.
 *
 * Applied to the hub's save and to an update that sends a block. The hub is
 * exposed over MCP; without this it would be a way into the shared library
 * around the rules the save ability enforces. move and duplicate are exempt
 * from the portability rules on purpose — they copy what is already stored,
 * and must not fail because the rules were tightened after it went in.
 *
 * @param array<string, mixed> $body A body from nibwp_bricksed_write_body().
 */
function nibwp_bricksed_content_refusal(array $body): ?WP_Error
{
    if (!nibwp_bricksed_has_content($body['block'] ?? null)) {
        return nibwp_bricksed_empty_block_error();
    }

    $too_large = nibwp_bricksed_payload_too_large($body);
    if ($too_large !== null) {
        return $too_large;
    }

    $portability = nibwp_bricksed_validate_portability((array) $body['block']);
    if ($portability['failed'] !== []) {
        return new WP_Error(
            'bricksed_validation_failed',
            __('Not written: something in this component is true of this site alone and would break on any other. Fix the rules below and try again.', 'nibwp'),
            [
                'status' => 422,
                'failed' => nibwp_bricksed_normalize_findings($portability['failed']),
                'warnings' => nibwp_bricksed_normalize_findings($portability['warnings']),
            ]
        );
    }

    return null;
}

/**
 * Update a component's details, and its content only if a block is sent.
 *
 * Bricksed's update-component rewrites the same file in place. It changes the
 * details, the manifest entry and the tree leaf's name; it does not move the
 * file, change the id or re-slug anything. So rename keeps its id, and a change
 * of type or category is refused here — the details would say one folder while
 * the file and the tree said another. That is move's job.
 *
 * Anything the caller leaves out is filled from the stored component, because
 * Bricksed requires a name and a type on every update.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_bricksed_update(string $id, array $input, string $action)
{
    if ($action === 'rename' && trim((string) ($input['name'] ?? '')) === '') {
        return new WP_Error('bricksed_missing_name', __('A "name" is required to rename.', 'nibwp'), ['status' => 400]);
    }

    $has_block = array_key_exists('block', $input);
    if ($has_block && !nibwp_bricksed_has_content($input['block'])) {
        return nibwp_bricksed_empty_block_error();
    }

    $stored = nibwp_bricksed_request('/component/' . $id, 'GET');
    if (is_wp_error($stored)) {
        return $stored;
    }

    $meta = (array) ($stored['__libraryMeta'] ?? []);
    $stored_type = (string) ($meta['type'] ?? '');
    $stored_category = (string) ($meta['category'] ?? '');

    $wants_type = trim((string) ($input['type'] ?? ''));
    $wants_category = trim((string) ($input['category'] ?? ''));

    // Bricksed needs a type on every update. A stored component that lost its own
    // would otherwise fail with "type must be one of", which reads as the
    // caller's mistake. If the caller names one, that repairs it.
    if ($stored_type === '') {
        if ($wants_type === '') {
            return new WP_Error(
                'bricksed_stored_type_missing',
                __('This stored component has no type recorded, so it cannot be updated as it is. Pass the "type" it should have (layout, element, component or template).', 'nibwp'),
                ['status' => 400]
            );
        }
        $stored_type = $wants_type;
    }

    if (
        ($wants_type !== '' && $wants_type !== $stored_type)
        || ($wants_category !== '' && !nibwp_bricksed_same_category($wants_category, $stored_category))
    ) {
        return new WP_Error(
            'bricksed_use_move',
            __('update and rename cannot change where a component lives: the file stays where it is, so the details and the folder would disagree. Use "move" to put it in another type or category.', 'nibwp'),
            ['status' => 400]
        );
    }

    // What the caller sent wins, empty or not, so brand, tags and description
    // can be cleared. The name is the exception: it is required, so a blank
    // one means "keep the name", not "erase it".
    $pick = static function (string $key) use ($input, $meta) {
        $stored_value = $meta[$key] ?? ($key === 'tags' ? [] : '');
        if (!array_key_exists($key, $input) || $input[$key] === null) {
            return $stored_value;
        }
        if ($key === 'name' && trim((string) $input[$key]) === '') {
            return $stored_value;
        }

        return $input[$key];
    };

    $merged = [
        'name' => (string) $pick('name'),
        'type' => $stored_type,
        'category' => $stored_category,
        'brand' => (string) $pick('brand'),
        'tags' => (array) $pick('tags'),
        'description' => (string) $pick('description'),
    ];
    foreach (['demo_url', 'preview_image'] as $optional) {
        if (isset($input[$optional])) {
            $merged[$optional] = $input[$optional];
        }
    }
    if ($has_block) {
        $merged['block'] = $input['block'];
    }

    $body = nibwp_bricksed_write_body($merged);
    if (is_wp_error($body)) {
        return $body;
    }
    if ($has_block) {
        $refusal = nibwp_bricksed_content_refusal($body);
        if ($refusal !== null) {
            return $refusal;
        }
    }
    $body['id'] = $id;

    $result = nibwp_bricksed_request('/update-component', 'POST', $body);
    if (is_wp_error($result)) {
        return $result;
    }

    $result['warning'] = nibwp_bricksed_update_warning();

    return $result;
}

/**
 * The fields a copy carries over from a stored component.
 *
 * The preview image and the demo URL are passed through write_body, which puts
 * them inside the block's own __libraryMeta — the only place Bricksed's
 * save-component reads them from. Sent any other way they were dropped, and a
 * move then deleted the original that still had them.
 *
 * @param array<string, mixed> $stored The component as get returned it.
 * @return array<string, mixed>
 */
function nibwp_bricksed_copy_fields(array $stored): array
{
    $meta = (array) ($stored['__libraryMeta'] ?? []);
    $block = $stored;
    unset($block['__libraryMeta']);

    $copy = [
        'brand' => (string) ($meta['brand'] ?? ''),
        'tags' => (array) ($meta['tags'] ?? []),
        'description' => (string) ($meta['description'] ?? ''),
        'block' => $block,
    ];
    if (!empty($meta['previewImage'])) {
        $copy['preview_image'] = (string) $meta['previewImage'];
    }
    if (!empty($meta['demoUrl'])) {
        $copy['demo_url'] = (string) $meta['demoUrl'];
    }

    return $copy;
}

/**
 * Move a component to another type or category.
 *
 * A copy, then a delete, in that order. Bricksed cannot relocate a file, and the
 * other order — delete, then save — loses the component the first time the save
 * fails. Done this way the worst outcome is a duplicate, which is recoverable,
 * and it is reported as one.
 *
 * The component gets a new id, because the id is derived from its folder.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_bricksed_move(string $id, array $input)
{
    $stored = nibwp_bricksed_request('/component/' . $id, 'GET');
    if (is_wp_error($stored)) {
        return $stored;
    }

    $meta = (array) ($stored['__libraryMeta'] ?? []);
    $stored_type = (string) ($meta['type'] ?? '');
    $stored_category = (string) ($meta['category'] ?? '');

    $type = trim((string) ($input['type'] ?? '')) !== '' ? trim((string) $input['type']) : $stored_type;
    $category = trim((string) ($input['category'] ?? '')) !== '' ? trim((string) $input['category']) : $stored_category;

    if ($type === $stored_type && nibwp_bricksed_same_category($category, $stored_category)) {
        return new WP_Error(
            'bricksed_nothing_to_move',
            __('That is where the component already is. Give a different "type" or "category" to move it.', 'nibwp'),
            ['status' => 400]
        );
    }

    $body = nibwp_bricksed_write_body(array_merge(nibwp_bricksed_copy_fields($stored), [
        'name' => (string) (($meta['name'] ?? '') !== '' ? $meta['name'] : $id),
        'type' => $type,
        'category' => $category,
    ]));
    if (is_wp_error($body)) {
        return $body;
    }
    $too_large = nibwp_bricksed_payload_too_large($body);
    if ($too_large !== null) {
        return $too_large;
    }

    $saved = nibwp_bricksed_request('/save-component', 'POST', $body);
    if (is_wp_error($saved)) {
        // Nothing has been deleted, and nothing will be.
        return $saved;
    }

    $deleted = nibwp_bricksed_request('/delete-component', 'POST', ['id' => $id]);

    $result = [
        'id' => (string) ($saved['id'] ?? ''),
        'moved_from' => $id,
        'moved' => !is_wp_error($deleted),
        'saved' => $saved,
    ];

    if (is_wp_error($deleted)) {
        $result['cleanup_error'] = $deleted->get_error_message();
        $result['warning'] = __('The copy was saved in the new location, but the original could not be removed and is still in its old folder. Delete it once you have checked the copy.', 'nibwp')
            . ' ' . nibwp_bricksed_update_warning();

        return $result;
    }

    $result['warning'] = nibwp_bricksed_update_warning();

    return $result;
}

/**
 * Copy a stored component under a new name.
 *
 * A plain read followed by a plain save: the copy inherits type, category,
 * brand, tags, description, preview image and demo URL from the original
 * unless the caller overrides them, and the original is never written to. It
 * is not run through the portability rules a fresh build would be —
 * duplicating something that is already in the library must not fail because
 * the rules have since been tightened.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_bricksed_duplicate(string $id, array $input)
{
    $stored = nibwp_bricksed_request('/component/' . $id, 'GET');
    if (is_wp_error($stored)) {
        return $stored;
    }

    $meta = (array) ($stored['__libraryMeta'] ?? []);
    $copy = nibwp_bricksed_copy_fields($stored);

    $copy['name'] = (string) $input['name'];
    $copy['type'] = (string) (($input['type'] ?? '') !== '' ? $input['type'] : ($meta['type'] ?? ''));
    $copy['category'] = (string) (($input['category'] ?? '') !== '' ? $input['category'] : ($meta['category'] ?? ''));
    foreach (['brand', 'description'] as $field) {
        if (($input[$field] ?? '') !== '') {
            $copy[$field] = (string) $input[$field];
        }
    }
    if (($input['tags'] ?? null) !== null) {
        $copy['tags'] = (array) $input['tags'];
    }

    $body = nibwp_bricksed_write_body($copy);
    if (is_wp_error($body)) {
        return $body;
    }
    $too_large = nibwp_bricksed_payload_too_large($body);
    if ($too_large !== null) {
        return $too_large;
    }

    $result = nibwp_bricksed_request('/save-component', 'POST', $body);
    if (is_wp_error($result)) {
        return $result;
    }

    $result['duplicated_from'] = $id;
    $result['warning'] = nibwp_bricksed_update_warning();

    return $result;
}

/**
 * Search the library.
 *
 * Reads the `components` map, not the tree. /library returns both, but a tree
 * leaf carries only a name and an id; the tags, type, category, brand and the
 * pro / locked flags live in the map. Searching the tree would match names and
 * silently never match a tag.
 *
 * `locked` is returned so an agent does not propose a component the customer's
 * Bricksed tier will refuse to hand over.
 *
 * @param array<mixed> $library The decoded /library response.
 * @return array<int, array<string, mixed>>
 */
function nibwp_bricksed_search_library(array $library, string $query): array
{
    $needle = strtolower(trim($query));
    $out = [];

    foreach ((array) ($library['components'] ?? []) as $id => $meta) {
        if (!is_array($meta)) {
            continue;
        }

        $haystack = strtolower(implode(' ', [
            (string) ($meta['name'] ?? ''),
            (string) ($meta['type'] ?? ''),
            (string) ($meta['category'] ?? ''),
            (string) ($meta['brand'] ?? ''),
            implode(' ', array_map('strval', (array) ($meta['tags'] ?? []))),
        ]));

        if ($needle !== '' && !str_contains($haystack, $needle)) {
            continue;
        }

        $out[] = [
            'id' => (string) $id,
            'name' => (string) ($meta['name'] ?? $id),
            'type' => (string) ($meta['type'] ?? ''),
            'category' => (string) ($meta['category'] ?? ''),
            'brand' => (string) ($meta['brand'] ?? ''),
            'pro' => !empty($meta['pro']),
            'locked' => !empty($meta['locked']),
            // Its picture, as the plugin serves it (a web link only), for choices the person picks from.
            'preview' => preg_match('#^https?://#i', (string) ($meta['previewImage'] ?? '')) ? (string) $meta['previewImage'] : '',
        ];
    }

    return $out;
}
