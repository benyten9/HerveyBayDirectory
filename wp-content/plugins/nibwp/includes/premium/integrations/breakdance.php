<?php

declare(strict_types=1);

/**
 * Breakdance integration — NIBWP MCP abilities (Layer 1 adapter).
 *
 * Fourteen domain-grouped abilities expose a Breakdance site to an AI agent:
 * the element vocabulary, page node trees and surgical edits to them, the
 * themeless post types (headers, footers, popups, templates, global blocks)
 * and their display conditions, global settings, selectors, presets,
 * variables, form submissions, revisions and deletion.
 *
 * Mechanism is IN-PROCESS — these run inside the same WP request as the MCP
 * call, using Breakdance's own namespaced functions rather than its AJAX layer:
 *   1. Breakdance\Data\{get_tree, save_document, get_tree_as_html, get_meta, set_meta}
 *   2. Breakdance\Data\{get_global_option, set_global_option} for global state
 *   3. Breakdance\Elements\get_elements_for_builder for the element registry
 *   4. Breakdance\Conditions\get_conditions_for_builder for display conditions
 *
 * DUAL BRAND. The same codebase ships as Breakdance and as Oxygen 6, chosen by
 * the BREAKDANCE_MODE constant, and that choice changes both the post type
 * slugs (breakdance_template vs oxygen_template) and the post meta prefix.
 * Nothing here hardcodes either: post types come from the BREAKDANCE_*_POST_TYPE
 * constants and the meta prefix from Breakdance's own __bdox() helper, so the
 * integration behaves correctly on an Oxygen 6 install.
 *
 * THE TREE IS THE PAGE. A Breakdance page is a JSON node tree in post meta, not
 * post_content. Editing the post through ordinary WordPress tools changes
 * nothing the visitor sees. Every structural ability here goes through the tree.
 *
 * Detection: BREAKDANCE_MODE + Breakdance\Data\get_tree().
 *
 * Verified against Breakdance 2.8.1 source.
 */

if (!defined('ABSPATH')) {
    exit();
}

/* ----------------------------------------------------------------------------
 * Shared helpers (file-local)
 * ------------------------------------------------------------------------- */

/** Is Breakdance (or Oxygen 6) active on this site? */
function nibwp_breakdance_available(): bool
{
    return defined('BREAKDANCE_MODE') && function_exists('Breakdance\\Data\\get_tree');
}

/** House WP_Error wrapper. */
function nibwp_breakdance_err(string $code, string $message): WP_Error
{
    return new WP_Error($code, $message);
}

/** The guard every callback opens with. */
function nibwp_breakdance_guard(): ?WP_Error
{
    if (!nibwp_breakdance_available()) {
        return nibwp_breakdance_err(
            'nibwp_breakdance_missing',
            __('Breakdance is not active on this site.', domain: 'nibwp')
        );
    }

    return null;
}

/** Run a Breakdance call, converting throwables into WP_Error. */
function nibwp_breakdance_try(callable $fn, string $code = 'nibwp_breakdance_failed')
{
    try {
        return $fn();
    } catch (\Throwable $e) {
        return nibwp_breakdance_err($code, $e->getMessage());
    }
}

/**
 * Resolve one of Breakdance's post types by role.
 *
 * Read from the constants rather than written out, because the slugs differ
 * between Breakdance mode and Oxygen mode and a literal would be wrong on half
 * the installs this runs on.
 */
function nibwp_breakdance_post_type(string $role): string
{
    return match ($role) {
        'template' => defined('BREAKDANCE_TEMPLATE_POST_TYPE') ? BREAKDANCE_TEMPLATE_POST_TYPE : '',
        'header'   => defined('BREAKDANCE_HEADER_POST_TYPE') ? BREAKDANCE_HEADER_POST_TYPE : '',
        'footer'   => defined('BREAKDANCE_FOOTER_POST_TYPE') ? BREAKDANCE_FOOTER_POST_TYPE : '',
        'popup'    => defined('BREAKDANCE_POPUP_POST_TYPE') ? BREAKDANCE_POPUP_POST_TYPE : '',
        'block'    => defined('BREAKDANCE_BLOCK_POST_TYPE') ? BREAKDANCE_BLOCK_POST_TYPE : '',
        'part'     => defined('BREAKDANCE_PART_POST_TYPE') ? BREAKDANCE_PART_POST_TYPE : '',
        default    => '',
    };
}

/** Every themeless post type this install registers, keyed by role. */
function nibwp_breakdance_post_types(): array
{
    $out = [];
    foreach (['template', 'header', 'footer', 'popup', 'block', 'part'] as $role) {
        $type = nibwp_breakdance_post_type($role);
        if ($type !== '') {
            $out[$role] = $type;
        }
    }

    return $out;
}

/**
 * The post meta key the node tree lives under.
 *
 * Breakdance composes it from a brand-dependent prefix, so it is asked for
 * rather than assumed. Falls back to the Breakdance-mode literal only if the
 * helper is unavailable, which would mean a version older than this adapter
 * was written against.
 */
function nibwp_breakdance_meta_prefix(): string
{
    if (function_exists('Breakdance\\BreakdanceOxygen\\Strings\\__bdox')) {
        $prefix = \Breakdance\BreakdanceOxygen\Strings\__bdox('_meta_prefix');
        if (is_string($prefix) && $prefix !== '') {
            return $prefix;
        }
    }

    return defined('BREAKDANCE_MODE') && BREAKDANCE_MODE === 'oxygen' ? '_oxygen_' : '_breakdance_';
}

/** The post meta key the node tree lives under. */
function nibwp_breakdance_tree_meta_key(): string
{
    return nibwp_breakdance_meta_prefix() . 'data';
}

/**
 * The post meta key template settings live under.
 *
 * Display conditions are stored here, NOT alongside the tree — save_document()
 * writes them to `<prefix>template_settings` as a separate call. Writing them
 * onto the tree's key instead would have replaced the page with its own
 * settings object.
 */
function nibwp_breakdance_settings_meta_key(): string
{
    return nibwp_breakdance_meta_prefix() . 'template_settings';
}

/**
 * Read a post's node tree.
 *
 * @return array|WP_Error
 */
function nibwp_breakdance_get_tree(int $post_id)
{
    if ($post_id <= 0 || !get_post($post_id)) {
        return nibwp_breakdance_err('nibwp_breakdance_bad_id', __('A valid post ID is required.', domain: 'nibwp'));
    }

    $tree = \Breakdance\Data\get_tree($post_id);

    if ($tree === false || !is_array($tree)) {
        return nibwp_breakdance_err(
            'nibwp_breakdance_no_tree',
            __('That post has no Breakdance tree. Enable the builder on it first with nibwp/breakdance-pages action=enable.', domain: 'nibwp')
        );
    }

    return $tree;
}

/**
 * Write a node tree back to a post.
 *
 * Written through set_meta() rather than update_post_meta(), because
 * Breakdance encodes the value on the way in and would not recognize a plain
 * JSON string written past it. The rendered cache is regenerated afterwards so
 * the change is visible without opening the builder.
 *
 * @return true|WP_Error
 */
function nibwp_breakdance_put_tree(int $post_id, array $tree)
{
    if (!\Breakdance\Data\is_valid_tree($tree)) {
        return nibwp_breakdance_err(
            'nibwp_breakdance_invalid_tree',
            __('That is not a valid Breakdance tree — it needs a root node with id, data and children.', domain: 'nibwp')
        );
    }

    $encoded = wp_json_encode($tree);
    if (!is_string($encoded)) {
        return nibwp_breakdance_err('nibwp_breakdance_encode_failed', __('The tree could not be encoded as JSON.', domain: 'nibwp'));
    }

    \Breakdance\Data\set_meta($post_id, nibwp_breakdance_tree_meta_key(), ['tree_json_string' => $encoded]);

    if (function_exists('Breakdance\\Data\\regenerate_post_cache')) {
        // Without this the stored CSS keeps describing the previous tree, so
        // the page renders with styles for elements that are no longer on it.
        // Takes no arguments — it works out which posts to rebuild itself.
        nibwp_breakdance_try(static fn() => \Breakdance\Data\regenerate_post_cache());
    }

    return true;
}

/** Walk every node in a tree, depth first, passing each to $visit. */
function nibwp_breakdance_walk(array $node, callable $visit, ?array $parent = null): void
{
    $visit($node, $parent);

    foreach (($node['children'] ?? []) as $child) {
        if (is_array($child)) {
            nibwp_breakdance_walk($child, $visit, $node);
        }
    }
}

/** Flatten a tree into id/type/parent rows — the shape an agent can reason about. */
function nibwp_breakdance_flatten(array $tree): array
{
    $rows = [];
    $root = $tree['root'] ?? null;
    if (!is_array($root)) {
        return $rows;
    }

    nibwp_breakdance_walk($root, static function (array $node, ?array $parent) use (&$rows): void {
        $rows[] = [
            'id'        => $node['id'] ?? null,
            'type'      => $node['data']['type'] ?? ($node['data']['slug'] ?? ''),
            'parent_id' => $parent['id'] ?? null,
            'children'  => count((array) ($node['children'] ?? [])),
        ];
    });

    return $rows;
}

/**
 * Find a node by ID and hand it to $mutate by reference.
 *
 * Returns whether the node was found, so a caller can tell "changed nothing"
 * apart from "no such node" — which are very different answers to an agent.
 */
function nibwp_breakdance_mutate_node(array &$node, $target_id, callable $mutate): bool
{
    if (($node['id'] ?? null) == $target_id) {
        $mutate($node);

        return true;
    }

    foreach (($node['children'] ?? []) as $i => $child) {
        if (is_array($child) && nibwp_breakdance_mutate_node($node['children'][$i], $target_id, $mutate)) {
            return true;
        }
    }

    return false;
}

/** Remove a node by ID from anywhere in the tree. */
function nibwp_breakdance_remove_node(array &$node, $target_id): bool
{
    foreach (($node['children'] ?? []) as $i => $child) {
        if (!is_array($child)) {
            continue;
        }
        if (($child['id'] ?? null) == $target_id) {
            array_splice($node['children'], $i, 1);

            return true;
        }
        if (nibwp_breakdance_remove_node($node['children'][$i], $target_id)) {
            return true;
        }
    }

    return false;
}

/** The highest node ID in a tree, so a new node can claim the next one. */
function nibwp_breakdance_max_id(array $tree): int
{
    $max = 0;
    $root = $tree['root'] ?? null;
    if (is_array($root)) {
        nibwp_breakdance_walk($root, static function (array $node) use (&$max): void {
            $max = max($max, (int) ($node['id'] ?? 0));
        });
    }

    return $max;
}

/** Clamp pagination to the house 1..100 window. */
function nibwp_breakdance_paginate(array $in): array
{
    $per_page = min(max((int) ($in['per_page'] ?? 20), 1), 100);

    return ['per_page' => $per_page, 'page' => max((int) ($in['page'] ?? 1), 1)];
}

/* ----------------------------------------------------------------------------
 * Ability 1 — nibwp/breakdance-info (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/breakdance-info', [
    'label'       => __('Breakdance — Info', domain: 'nibwp'),
    'description' => __('Detect Breakdance, which brand mode it runs in, its post types, element count and content counts (read-only).', domain: 'nibwp'),
    'category'    => 'breakdance',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_breakdance_info',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Call this first. mode tells you whether this install is Breakdance or Oxygen 6 — the post type slugs differ, and this reports the real ones.',
                'A Breakdance page is a JSON node tree in post meta, not post_content. Editing the post body changes nothing a visitor sees.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_breakdance_info(array $input): array|WP_Error
{
    if ($guard = nibwp_breakdance_guard()) {
        return $guard;
    }

    return nibwp_breakdance_try(static function (): array {
        $types = nibwp_breakdance_post_types();

        $counts = [];
        foreach ($types as $role => $type) {
            $counts[$role] = (int) (wp_count_posts($type)->publish ?? 0);
        }

        $element_count = 0;
        if (function_exists('Breakdance\\Elements\\get_elements_for_builder')) {
            $elements = nibwp_breakdance_try(static fn() => \Breakdance\Elements\get_elements_for_builder());
            $element_count = is_array($elements) ? count($elements) : 0;
        }

        return [
            'active'         => true,
            'version'        => defined('__BREAKDANCE_VERSION') ? __BREAKDANCE_VERSION : '',
            'mode'           => defined('BREAKDANCE_MODE') ? BREAKDANCE_MODE : '',
            'post_types'     => $types,
            'counts'         => $counts,
            'element_count'  => $element_count,
            'tree_meta_key'  => nibwp_breakdance_tree_meta_key(),
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 2 — nibwp/breakdance-elements (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/breakdance-elements', [
    'label'       => __('Breakdance — Elements', domain: 'nibwp'),
    'description' => __('List every element this install registers, with its slug, category and control schema — the vocabulary a tree has to be written in (read-only).', domain: 'nibwp'),
    'category'    => 'breakdance',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'get', 'categories'], 'default' => 'list', 'description' => 'The action to perform.'],
            'slug'    => ['type' => 'string', 'description' => 'Element slug. Required for get.'],
            'search'  => ['type' => 'string', 'description' => 'list: filter elements by name or slug.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_breakdance_elements',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Read this before writing any tree. An element type that is not registered renders as nothing, and the failure is silent.',
                'action=list is deliberately shallow — slug, name and category. Use action=get for one element\'s full control schema, which is large.',
                'What is registered depends on the license, the subplugins active and any third-party element packs.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_breakdance_elements(array $input): array|WP_Error
{
    if ($guard = nibwp_breakdance_guard()) {
        return $guard;
    }

    if (!function_exists('Breakdance\\Elements\\get_elements_for_builder')) {
        return nibwp_breakdance_err('nibwp_breakdance_no_elements', __('The Breakdance element registry is unavailable.', domain: 'nibwp'));
    }

    $action = (string) ($input['action'] ?? 'list');

    return nibwp_breakdance_try(static function () use ($action, $input) {
        $elements = \Breakdance\Elements\get_elements_for_builder();
        $elements = is_array($elements) ? $elements : [];

        if ($action === 'get') {
            $slug = (string) ($input['slug'] ?? '');
            if ($slug === '') {
                throw new \RuntimeException(__('An element slug is required.', domain: 'nibwp'));
            }
            foreach ($elements as $element) {
                $element = (array) $element;
                if ((string) ($element['slug'] ?? '') === $slug) {
                    return ['element' => $element];
                }
            }

            throw new \RuntimeException(__('No element with that slug is registered on this site.', domain: 'nibwp'));
        }

        if ($action === 'categories') {
            $categories = [];
            foreach ($elements as $element) {
                $element = (array) $element;
                $category = (string) ($element['category'] ?? 'uncategorised');
                $categories[$category] = ($categories[$category] ?? 0) + 1;
            }
            ksort($categories);

            return ['categories' => $categories, 'count' => count($categories)];
        }

        $search = strtolower((string) ($input['search'] ?? ''));
        $rows = [];
        foreach ($elements as $element) {
            $element = (array) $element;
            $slug = (string) ($element['slug'] ?? '');
            $name = (string) ($element['name'] ?? ($element['label'] ?? ''));
            if ($search !== '' && !str_contains(strtolower($slug . ' ' . $name), $search)) {
                continue;
            }
            $rows[] = [
                'slug'     => $slug,
                'name'     => $name,
                'category' => $element['category'] ?? '',
            ];
        }

        return ['elements' => $rows, 'count' => count($rows), 'total_registered' => count($elements)];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 3 — nibwp/breakdance-tree (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/breakdance-tree', [
    'label'       => __('Breakdance — Page tree', domain: 'nibwp'),
    'description' => __('Read a page\'s Breakdance node tree, its rendered HTML or a flat outline of it, and replace the whole tree in one call.', domain: 'nibwp'),
    'category'    => 'breakdance',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['get', 'outline', 'html', 'set', 'validate'], 'description' => 'The action to perform.'],
            'post_id' => ['type' => 'integer', 'description' => 'The post, page, template, header, footer, popup or block to work on.'],
            'tree'    => ['type' => 'string', 'description' => 'The complete tree as a JSON string. Required for set and validate.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_breakdance_tree',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'action=outline first. A full tree for a real page is very large, and the outline gives you the node IDs that every surgical edit needs.',
                'set REPLACES the entire tree. For anything smaller use nibwp/breakdance-nodes, which edits one node and leaves the rest alone.',
                'Validate before you set if the tree was assembled rather than read — an invalid tree is rejected, but a valid tree full of unregistered element types renders blank.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_breakdance_tree(array $input): array|WP_Error
{
    if ($guard = nibwp_breakdance_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $post_id = (int) ($input['post_id'] ?? 0);

    if ($action !== 'validate' && $post_id <= 0) {
        return nibwp_breakdance_err('nibwp_breakdance_bad_id', __('A valid post ID is required.', domain: 'nibwp'));
    }

    if (in_array($action, ['set', 'validate'], strict: true)) {
        $raw = (string) ($input['tree'] ?? '');
        if ($raw === '') {
            return nibwp_breakdance_err('nibwp_breakdance_bad_tree', __('A tree JSON string is required.', domain: 'nibwp'));
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return nibwp_breakdance_err(
                'nibwp_breakdance_bad_tree',
                sprintf(
                    /* translators: %s: JSON parser error */
                    __('The tree JSON could not be parsed: %s', domain: 'nibwp'),
                    json_last_error_msg()
                )
            );
        }
        $input['_decoded'] = $decoded;
    }

    return nibwp_breakdance_try(static function () use ($action, $post_id, $input) {
        switch ($action) {
            case 'validate':
                $valid = \Breakdance\Data\is_valid_tree($input['_decoded']);

                return [
                    'valid' => $valid,
                    'nodes' => $valid ? count(nibwp_breakdance_flatten($input['_decoded'])) : 0,
                ];

            case 'get':
                $tree = nibwp_breakdance_get_tree($post_id);

                return $tree instanceof WP_Error ? $tree : ['post_id' => $post_id, 'tree' => $tree];

            case 'outline':
                $tree = nibwp_breakdance_get_tree($post_id);
                if ($tree instanceof WP_Error) {
                    return $tree;
                }
                $rows = nibwp_breakdance_flatten($tree);

                return ['post_id' => $post_id, 'nodes' => $rows, 'count' => count($rows)];

            case 'html':
                if (!function_exists('Breakdance\\Data\\get_tree_as_html')) {
                    throw new \RuntimeException(__('Rendering to HTML is unavailable in this Breakdance version.', domain: 'nibwp'));
                }

                return ['post_id' => $post_id, 'html' => \Breakdance\Data\get_tree_as_html($post_id)];

            case 'set':
                $written = nibwp_breakdance_put_tree($post_id, $input['_decoded']);
                if ($written instanceof WP_Error) {
                    return $written;
                }

                return [
                    'post_id' => $post_id,
                    'updated' => true,
                    'nodes'   => count(nibwp_breakdance_flatten($input['_decoded'])),
                ];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ----------------------------------------------------------------------------
 * Ability 4 — nibwp/breakdance-nodes (read + write)
 *
 * Surgical edits. Replacing a whole tree to change one heading is how an agent
 * destroys work it never read.
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/breakdance-nodes', [
    'label'       => __('Breakdance — Nodes', domain: 'nibwp'),
    'description' => __('Read, add, update, duplicate, move and remove a single node inside a page\'s tree, leaving the rest of the page untouched.', domain: 'nibwp'),
    'category'    => 'breakdance',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'    => ['type' => 'string', 'enum' => ['get', 'add', 'update', 'duplicate', 'move', 'remove'], 'description' => 'The action to perform.'],
            'post_id'   => ['type' => 'integer', 'description' => 'The post whose tree is being edited.'],
            'node_id'   => ['type' => 'integer', 'description' => 'Target node. Required for everything except add.'],
            'parent_id' => ['type' => 'integer', 'description' => 'Parent to add into, or to move under. Defaults to the root for add.'],
            'type'      => ['type' => 'string', 'description' => 'Element slug for add — must be one nibwp/breakdance-elements lists.'],
            'properties' => ['type' => 'object', 'description' => 'Element properties to set, matching that element\'s control schema.'],
            'position'  => ['type' => 'integer', 'description' => 'Index among the parent\'s children. Appends when omitted.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_breakdance_nodes',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Prefer this over replacing a whole tree. Get the node IDs from nibwp/breakdance-tree action=outline.',
                'update merges the properties you pass into the node and leaves every other property alone.',
                'remove takes the node\'s children with it, and is not reversible except through a revision.',
                'Check the element\'s control schema before setting properties — Breakdance ignores unknown ones silently rather than erroring.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_breakdance_nodes(array $input): array|WP_Error
{
    if ($guard = nibwp_breakdance_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $post_id = (int) ($input['post_id'] ?? 0);
    $node_id = (int) ($input['node_id'] ?? 0);

    if ($post_id <= 0) {
        return nibwp_breakdance_err('nibwp_breakdance_bad_id', __('A valid post ID is required.', domain: 'nibwp'));
    }
    if ($action !== 'add' && $node_id <= 0) {
        return nibwp_breakdance_err('nibwp_breakdance_bad_id', __('A valid node ID is required. Get one from nibwp/breakdance-tree action=outline.', domain: 'nibwp'));
    }

    $tree = nibwp_breakdance_get_tree($post_id);
    if ($tree instanceof WP_Error) {
        return $tree;
    }

    return nibwp_breakdance_try(static function () use ($action, $post_id, $node_id, $input, $tree) {
        $tree = $tree;

        switch ($action) {
            case 'get':
                $found = null;
                nibwp_breakdance_walk($tree['root'], static function (array $node) use ($node_id, &$found): void {
                    if (($node['id'] ?? null) == $node_id) {
                        $found = $node;
                    }
                });
                if ($found === null) {
                    throw new \RuntimeException(__('No node with that ID exists on this page.', domain: 'nibwp'));
                }

                return ['post_id' => $post_id, 'node' => $found];

            case 'add':
                $type = (string) ($input['type'] ?? '');
                if ($type === '') {
                    throw new \RuntimeException(__('An element type is required. List them with nibwp/breakdance-elements.', domain: 'nibwp'));
                }

                $new_id = nibwp_breakdance_max_id($tree) + 1;
                $new_node = [
                    'id'       => $new_id,
                    'data'     => [
                        'type'       => $type,
                        'properties' => (object) ((array) ($input['properties'] ?? [])),
                    ],
                    'children' => [],
                ];

                $parent_id = (int) ($input['parent_id'] ?? 0);
                $position = array_key_exists('position', $input) ? (int) $input['position'] : null;

                $added = false;
                $insert = static function (array &$node) use ($new_node, $position, &$added): void {
                    if (!isset($node['children']) || !is_array($node['children'])) {
                        $node['children'] = [];
                    }
                    if ($position === null || $position >= count($node['children'])) {
                        $node['children'][] = $new_node;
                    } else {
                        array_splice($node['children'], max($position, 0), 0, [$new_node]);
                    }
                    $added = true;
                };

                if ($parent_id > 0) {
                    nibwp_breakdance_mutate_node($tree['root'], $parent_id, $insert);
                } else {
                    $insert($tree['root']);
                }

                if (!$added) {
                    throw new \RuntimeException(__('No node with that parent ID exists on this page.', domain: 'nibwp'));
                }

                $written = nibwp_breakdance_put_tree($post_id, $tree);
                if ($written instanceof WP_Error) {
                    return $written;
                }

                return ['post_id' => $post_id, 'node_id' => $new_id, 'type' => $type, 'created' => true];

            case 'update':
                $properties = (array) ($input['properties'] ?? []);
                $found = nibwp_breakdance_mutate_node($tree['root'], $node_id, static function (array &$node) use ($properties, $input): void {
                    if (isset($input['type'])) {
                        $node['data']['type'] = (string) $input['type'];
                    }
                    $current = (array) ($node['data']['properties'] ?? []);
                    // Merged, not replaced: an agent that sends one changed
                    // property should not silently clear every other one.
                    $node['data']['properties'] = (object) array_replace_recursive($current, $properties);
                });
                if (!$found) {
                    throw new \RuntimeException(__('No node with that ID exists on this page.', domain: 'nibwp'));
                }

                $written = nibwp_breakdance_put_tree($post_id, $tree);
                if ($written instanceof WP_Error) {
                    return $written;
                }

                return ['post_id' => $post_id, 'node_id' => $node_id, 'updated' => true];

            case 'duplicate':
                $source = null;
                nibwp_breakdance_walk($tree['root'], static function (array $node) use ($node_id, &$source): void {
                    if (($node['id'] ?? null) == $node_id) {
                        $source = $node;
                    }
                });
                if ($source === null) {
                    throw new \RuntimeException(__('No node with that ID exists on this page.', domain: 'nibwp'));
                }

                // Every node in the copy needs a fresh ID, or the tree carries
                // duplicates and the builder edits the wrong one.
                $next = nibwp_breakdance_max_id($tree);
                $reid = static function (array $node) use (&$reid, &$next): array {
                    $next++;
                    $node['id'] = $next;
                    foreach (($node['children'] ?? []) as $i => $child) {
                        if (is_array($child)) {
                            $node['children'][$i] = $reid($child);
                        }
                    }

                    return $node;
                };
                $copy = $reid($source);

                $placed = false;
                nibwp_breakdance_walk($tree['root'], static function () {});
                $place = static function (array &$node) use ($node_id, $copy, &$placed, &$place): void {
                    foreach (($node['children'] ?? []) as $i => $child) {
                        if (!is_array($child)) {
                            continue;
                        }
                        if (($child['id'] ?? null) == $node_id) {
                            array_splice($node['children'], $i + 1, 0, [$copy]);
                            $placed = true;

                            return;
                        }
                        $place($node['children'][$i]);
                        if ($placed) {
                            return;
                        }
                    }
                };
                $place($tree['root']);

                if (!$placed) {
                    throw new \RuntimeException(__('That node is the root and cannot be duplicated.', domain: 'nibwp'));
                }

                $written = nibwp_breakdance_put_tree($post_id, $tree);
                if ($written instanceof WP_Error) {
                    return $written;
                }

                return ['post_id' => $post_id, 'node_id' => $copy['id'], 'duplicated_from' => $node_id];

            case 'move':
                $parent_id = (int) ($input['parent_id'] ?? 0);
                if ($parent_id <= 0) {
                    throw new \RuntimeException(__('A parent_id is required to move a node.', domain: 'nibwp'));
                }

                $moving = null;
                nibwp_breakdance_walk($tree['root'], static function (array $node) use ($node_id, &$moving): void {
                    if (($node['id'] ?? null) == $node_id) {
                        $moving = $node;
                    }
                });
                if ($moving === null) {
                    throw new \RuntimeException(__('No node with that ID exists on this page.', domain: 'nibwp'));
                }

                // Refused rather than attempted: moving a node inside itself
                // detaches that whole branch from the page.
                $descendant = false;
                nibwp_breakdance_walk($moving, static function (array $node) use ($parent_id, &$descendant): void {
                    if (($node['id'] ?? null) == $parent_id) {
                        $descendant = true;
                    }
                });
                if ($descendant) {
                    throw new \RuntimeException(__('A node cannot be moved inside itself or its own children.', domain: 'nibwp'));
                }

                nibwp_breakdance_remove_node($tree['root'], $node_id);

                $position = array_key_exists('position', $input) ? (int) $input['position'] : null;
                $moved = false;
                nibwp_breakdance_mutate_node($tree['root'], $parent_id, static function (array &$node) use ($moving, $position, &$moved): void {
                    if (!isset($node['children']) || !is_array($node['children'])) {
                        $node['children'] = [];
                    }
                    if ($position === null || $position >= count($node['children'])) {
                        $node['children'][] = $moving;
                    } else {
                        array_splice($node['children'], max($position, 0), 0, [$moving]);
                    }
                    $moved = true;
                });

                if (!$moved) {
                    throw new \RuntimeException(__('No node with that parent ID exists on this page.', domain: 'nibwp'));
                }

                $written = nibwp_breakdance_put_tree($post_id, $tree);
                if ($written instanceof WP_Error) {
                    return $written;
                }

                return ['post_id' => $post_id, 'node_id' => $node_id, 'parent_id' => $parent_id, 'moved' => true];

            case 'remove':
                if (!nibwp_breakdance_remove_node($tree['root'], $node_id)) {
                    throw new \RuntimeException(__('No node with that ID exists on this page, or it is the root.', domain: 'nibwp'));
                }

                $written = nibwp_breakdance_put_tree($post_id, $tree);
                if ($written instanceof WP_Error) {
                    return $written;
                }

                return ['post_id' => $post_id, 'node_id' => $node_id, 'removed' => true];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ----------------------------------------------------------------------------
 * Ability 5 — nibwp/breakdance-pages (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/breakdance-pages', [
    'label'       => __('Breakdance — Pages', domain: 'nibwp'),
    'description' => __('Find posts and pages built with Breakdance, create new ones, enable the builder on an existing post, and get its edit and preview URLs.', domain: 'nibwp'),
    'category'    => 'breakdance',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'    => ['type' => 'string', 'enum' => ['list', 'create', 'enable', 'urls'], 'description' => 'The action to perform.'],
            'post_id'   => ['type' => 'integer', 'description' => 'Post ID. Required for enable and urls.'],
            'title'     => ['type' => 'string', 'description' => 'Title for create.'],
            'post_type' => ['type' => 'string', 'default' => 'page', 'description' => 'Post type for create, and the filter for list.'],
            'status'    => ['type' => 'string', 'enum' => ['publish', 'draft'], 'default' => 'draft'],
            'per_page'  => ['type' => 'integer', 'default' => 20],
            'page'      => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_breakdance_pages',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'create makes a post and seeds an empty Breakdance tree on it, so the builder opens on it straight away.',
                'enable does the same for a post that already exists and has no tree. It refuses if one is already there, rather than overwriting a built page.',
                'list finds posts that actually carry a tree, which is not the same as every post of that type.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_breakdance_pages(array $input): array|WP_Error
{
    if ($guard = nibwp_breakdance_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $post_id = (int) ($input['post_id'] ?? 0);

    if (in_array($action, ['enable', 'urls'], strict: true) && $post_id <= 0) {
        return nibwp_breakdance_err('nibwp_breakdance_bad_id', __('A valid post ID is required.', domain: 'nibwp'));
    }

    return nibwp_breakdance_try(static function () use ($action, $post_id, $input) {
        $meta_key = nibwp_breakdance_tree_meta_key();

        switch ($action) {
            case 'list':
                $page = nibwp_breakdance_paginate($input);
                $query = new WP_Query([
                    'post_type'      => (string) ($input['post_type'] ?? 'page'),
                    'post_status'    => 'any',
                    'posts_per_page' => $page['per_page'],
                    'paged'          => $page['page'],
                    'meta_query'     => [['key' => $meta_key, 'compare' => 'EXISTS']],
                    'fields'         => 'ids',
                ]);

                $rows = [];
                foreach ($query->posts as $id) {
                    $rows[] = [
                        'id'     => (int) $id,
                        'title'  => get_the_title((int) $id),
                        'type'   => get_post_type((int) $id),
                        'status' => get_post_status((int) $id),
                        'url'    => get_permalink((int) $id),
                    ];
                }

                return ['pages' => $rows, 'count' => count($rows), 'total' => (int) $query->found_posts];

            case 'create':
                $new_id = wp_insert_post([
                    'post_type'   => (string) ($input['post_type'] ?? 'page'),
                    'post_title'  => (string) ($input['title'] ?? __('New page', domain: 'nibwp')),
                    'post_status' => (string) ($input['status'] ?? 'draft'),
                ], true);

                if (is_wp_error($new_id)) {
                    return $new_id;
                }

                $seeded = nibwp_breakdance_put_tree((int) $new_id, ['root' => ['id' => 0, 'data' => ['type' => 'root'], 'children' => []]]);
                if ($seeded instanceof WP_Error) {
                    return $seeded;
                }

                return ['post_id' => (int) $new_id, 'created' => true, 'url' => get_permalink((int) $new_id)];

            case 'enable':
                if (\Breakdance\Data\get_tree($post_id) !== false) {
                    throw new \RuntimeException(__('That post already has a Breakdance tree — enabling again would discard it.', domain: 'nibwp'));
                }
                $seeded = nibwp_breakdance_put_tree($post_id, ['root' => ['id' => 0, 'data' => ['type' => 'root'], 'children' => []]]);
                if ($seeded instanceof WP_Error) {
                    return $seeded;
                }

                return ['post_id' => $post_id, 'enabled' => true];

            case 'urls':
                return [
                    'post_id' => $post_id,
                    'view'    => get_permalink($post_id),
                    'edit'    => admin_url('post.php?post=' . $post_id . '&action=edit'),
                    'builder' => add_query_arg(['breakdance' => 'builder', 'id' => $post_id], home_url('/')),
                ];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ----------------------------------------------------------------------------
 * Ability 6 — nibwp/breakdance-templates (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/breakdance-templates', [
    'label'       => __('Breakdance — Templates', domain: 'nibwp'),
    'description' => __('List, read, create and rename headers, footers, popups, templates and global blocks — the themeless post types Breakdance builds a site from.', domain: 'nibwp'),
    'category'    => 'breakdance',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'rename', 'types'], 'description' => 'The action to perform.'],
            'role'    => ['type' => 'string', 'enum' => ['template', 'header', 'footer', 'popup', 'block', 'part'], 'description' => 'Which themeless post type to work with.'],
            'post_id' => ['type' => 'integer', 'description' => 'Template ID. Required for get and rename.'],
            'title'   => ['type' => 'string', 'description' => 'Title for create and rename.'],
            'status'  => ['type' => 'string', 'enum' => ['publish', 'draft'], 'default' => 'publish'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_breakdance_templates',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'action=types first — the slugs differ between Breakdance and Oxygen mode and this reports the ones this install actually uses.',
                'A new template is created empty. Build it with nibwp/breakdance-nodes, then decide where it applies with nibwp/breakdance-conditions.',
                'A template with no conditions displays nowhere, so creating one is never the last step.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_breakdance_templates(array $input): array|WP_Error
{
    if ($guard = nibwp_breakdance_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $post_id = (int) ($input['post_id'] ?? 0);

    if (in_array($action, ['get', 'rename'], strict: true) && $post_id <= 0) {
        return nibwp_breakdance_err('nibwp_breakdance_bad_id', __('A valid template ID is required.', domain: 'nibwp'));
    }

    if (in_array($action, ['list', 'create'], strict: true)) {
        $role = (string) ($input['role'] ?? '');
        if (nibwp_breakdance_post_type($role) === '') {
            return nibwp_breakdance_err(
                'nibwp_breakdance_bad_role',
                __('A known role is required — template, header, footer, popup, block or part. List them with action=types.', domain: 'nibwp')
            );
        }
    }

    return nibwp_breakdance_try(static function () use ($action, $post_id, $input) {
        switch ($action) {
            case 'types':
                return ['post_types' => nibwp_breakdance_post_types(), 'mode' => defined('BREAKDANCE_MODE') ? BREAKDANCE_MODE : ''];

            case 'list':
                $type = nibwp_breakdance_post_type((string) $input['role']);
                $posts = get_posts([
                    'post_type'   => $type,
                    'post_status' => 'any',
                    'numberposts' => 100,
                    'orderby'     => 'title',
                    'order'       => 'ASC',
                ]);

                $rows = [];
                foreach ($posts as $post) {
                    $rows[] = [
                        'id'       => (int) $post->ID,
                        'title'    => $post->post_title,
                        'status'   => $post->post_status,
                        'has_tree' => \Breakdance\Data\get_tree((int) $post->ID) !== false,
                    ];
                }

                return ['role' => $input['role'], 'post_type' => $type, 'templates' => $rows, 'count' => count($rows)];

            case 'get':
                $post = get_post($post_id);
                if (!$post) {
                    throw new \RuntimeException(__('No such template.', domain: 'nibwp'));
                }
                $tree = \Breakdance\Data\get_tree($post_id);

                return [
                    'id'     => $post_id,
                    'title'  => $post->post_title,
                    'type'   => $post->post_type,
                    'status' => $post->post_status,
                    'nodes'  => is_array($tree) ? count(nibwp_breakdance_flatten($tree)) : 0,
                ];

            case 'create':
                $type = nibwp_breakdance_post_type((string) $input['role']);
                $new_id = wp_insert_post([
                    'post_type'   => $type,
                    'post_title'  => (string) ($input['title'] ?? __('New template', domain: 'nibwp')),
                    'post_status' => (string) ($input['status'] ?? 'publish'),
                ], true);

                if (is_wp_error($new_id)) {
                    return $new_id;
                }

                $seeded = nibwp_breakdance_put_tree((int) $new_id, ['root' => ['id' => 0, 'data' => ['type' => 'root'], 'children' => []]]);
                if ($seeded instanceof WP_Error) {
                    return $seeded;
                }

                return ['id' => (int) $new_id, 'role' => $input['role'], 'post_type' => $type, 'created' => true];

            case 'rename':
                $updated = wp_update_post(['ID' => $post_id, 'post_title' => (string) ($input['title'] ?? '')], true);
                if (is_wp_error($updated)) {
                    return $updated;
                }

                return ['id' => $post_id, 'renamed' => true];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ----------------------------------------------------------------------------
 * Ability 7 — nibwp/breakdance-conditions (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/breakdance-conditions', [
    'label'       => __('Breakdance — Display conditions', domain: 'nibwp'),
    'description' => __('List the display conditions this install offers, and read or set the conditions that decide where a template, header, footer or popup appears.', domain: 'nibwp'),
    'category'    => 'breakdance',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'     => ['type' => 'string', 'enum' => ['available', 'get', 'set'], 'description' => 'The action to perform.'],
            'post_id'    => ['type' => 'integer', 'description' => 'Template ID. Required for get and set.'],
            'conditions' => ['type' => 'string', 'description' => 'Conditions as a JSON string, in the shape action=get returns. Required for set.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_breakdance_conditions',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Read action=available first — the conditions on offer depend on which plugins are active, and an unknown condition is ignored rather than rejected.',
                'set REPLACES a template\'s conditions. Read them first if you mean to add one.',
                'Conditions are what make a template apply. Without them it is built but invisible.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_breakdance_conditions(array $input): array|WP_Error
{
    if ($guard = nibwp_breakdance_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $post_id = (int) ($input['post_id'] ?? 0);

    if (in_array($action, ['get', 'set'], strict: true) && $post_id <= 0) {
        return nibwp_breakdance_err('nibwp_breakdance_bad_id', __('A valid template ID is required.', domain: 'nibwp'));
    }

    if ($action === 'set') {
        $raw = (string) ($input['conditions'] ?? '');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return nibwp_breakdance_err('nibwp_breakdance_bad_conditions', __('Conditions must be a JSON array.', domain: 'nibwp'));
        }
        $input['_decoded'] = $decoded;
    }

    return nibwp_breakdance_try(static function () use ($action, $post_id, $input) {
        switch ($action) {
            case 'available':
                if (!function_exists('Breakdance\\Conditions\\get_conditions_for_builder')) {
                    throw new \RuntimeException(__('Display conditions are unavailable in this Breakdance version.', domain: 'nibwp'));
                }

                return ['conditions' => \Breakdance\Conditions\get_conditions_for_builder()];

            case 'get':
                $settings = \Breakdance\Data\get_meta($post_id, nibwp_breakdance_settings_meta_key());
                if (is_string($settings)) {
                    $settings = json_decode($settings, true);
                }

                return [
                    'post_id'    => $post_id,
                    'conditions' => is_array($settings) ? ($settings['conditions'] ?? []) : [],
                    'settings'   => $settings,
                ];

            case 'set':
                // Read, amend, write back. Template settings carry more than
                // conditions, and replacing the whole value would discard
                // whatever else the builder had stored there.
                $settings = \Breakdance\Data\get_meta($post_id, nibwp_breakdance_settings_meta_key());
                $was_string = is_string($settings);
                if ($was_string) {
                    $settings = json_decode($settings, true);
                }
                $settings = is_array($settings) ? $settings : [];
                $settings['conditions'] = $input['_decoded'];

                // Written back in the shape it was stored in: Breakdance saves
                // this value as a raw JSON string from the builder, and handing
                // set_meta an array where it expected a string changes the type
                // the builder reads back.
                \Breakdance\Data\set_meta(
                    $post_id,
                    nibwp_breakdance_settings_meta_key(),
                    $was_string ? wp_json_encode($settings) : $settings
                );

                return ['post_id' => $post_id, 'conditions' => $input['_decoded'], 'updated' => true];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ----------------------------------------------------------------------------
 * Ability 8 — nibwp/breakdance-global-settings (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/breakdance-global-settings', [
    'label'       => __('Breakdance — Global settings', domain: 'nibwp'),
    'description' => __('Read and write the site-wide Breakdance settings: typography, colors, breakpoints and the rest of the global style layer.', domain: 'nibwp'),
    'category'    => 'breakdance',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['get', 'set'], 'description' => 'The action to perform.'],
            'settings' => ['type' => 'string', 'description' => 'The complete settings object as a JSON string. Required for set.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_breakdance_global_settings',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'These settings apply to every page on the site. Read them, change the part you mean to change, and write the whole object back.',
                'A careless write here restyles the entire site at once, which is the largest blast radius of anything in this integration.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_breakdance_global_settings(array $input): array|WP_Error
{
    if ($guard = nibwp_breakdance_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');

    if ($action === 'set') {
        $decoded = json_decode((string) ($input['settings'] ?? ''), true);
        if (!is_array($decoded)) {
            return nibwp_breakdance_err('nibwp_breakdance_bad_settings', __('Settings must be a JSON object.', domain: 'nibwp'));
        }
        $input['_decoded'] = $decoded;
    }

    return nibwp_breakdance_try(static function () use ($action, $input) {
        switch ($action) {
            case 'get':
                return ['settings' => \Breakdance\Data\get_global_settings_array()];

            case 'set':
                if (!function_exists('Breakdance\\Data\\save_global_settings')) {
                    throw new \RuntimeException(__('Global settings cannot be written in this Breakdance version.', domain: 'nibwp'));
                }
                \Breakdance\Data\save_global_settings($input['_decoded']);

                if (function_exists('Breakdance\\Data\\regenerate_global_settings_cache')) {
                    \Breakdance\Data\regenerate_global_settings_cache();
                }

                return ['updated' => true];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ----------------------------------------------------------------------------
 * Ability 9 — nibwp/breakdance-selectors (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/breakdance-selectors', [
    'label'       => __('Breakdance — Selectors', domain: 'nibwp'),
    'description' => __('Read and write the global CSS classes and selectors Breakdance styles elements with.', domain: 'nibwp'),
    'category'    => 'breakdance',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'    => ['type' => 'string', 'enum' => ['get', 'set'], 'description' => 'The action to perform.'],
            'selectors' => ['type' => 'string', 'description' => 'The complete selectors object as a JSON string. Required for set.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_breakdance_selectors',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Selectors are shared across the site — a class used on forty pages is styled once here.',
                'set replaces the whole collection, so read it first and write back the full object with your change in it.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_breakdance_selectors(array $input): array|WP_Error
{
    if ($guard = nibwp_breakdance_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');

    if ($action === 'set') {
        $decoded = json_decode((string) ($input['selectors'] ?? ''), true);
        if (!is_array($decoded)) {
            return nibwp_breakdance_err('nibwp_breakdance_bad_selectors', __('Selectors must be a JSON object.', domain: 'nibwp'));
        }
        $input['_decoded'] = $decoded;
    }

    return nibwp_breakdance_try(static function () use ($action, $input) {
        switch ($action) {
            case 'get':
                if (!function_exists('Breakdance\\Data\\load_selectors')) {
                    throw new \RuntimeException(__('Selectors are unavailable in this Breakdance version.', domain: 'nibwp'));
                }

                return ['selectors' => \Breakdance\Data\load_selectors()];

            case 'set':
                if (!function_exists('Breakdance\\Data\\save_selectors')) {
                    throw new \RuntimeException(__('Selectors cannot be written in this Breakdance version.', domain: 'nibwp'));
                }
                \Breakdance\Data\save_selectors($input['_decoded']);

                return ['updated' => true];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ----------------------------------------------------------------------------
 * Ability 10 — nibwp/breakdance-presets (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/breakdance-presets', [
    'label'       => __('Breakdance — Design presets', domain: 'nibwp'),
    'description' => __('Read the design presets defined on this site, so new elements can be built to match what is already there (read-only).', domain: 'nibwp'),
    'category'    => 'breakdance',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_breakdance_presets',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Read these before styling anything by hand. Applying an existing preset keeps a page consistent with the rest of the site in a way ad-hoc properties do not.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_breakdance_presets(array $input): array|WP_Error
{
    if ($guard = nibwp_breakdance_guard()) {
        return $guard;
    }

    if (!function_exists('Breakdance\\Data\\load_presets')) {
        return nibwp_breakdance_err('nibwp_breakdance_no_presets', __('Design presets are unavailable in this Breakdance version.', domain: 'nibwp'));
    }

    return nibwp_breakdance_try(static fn(): array => ['presets' => \Breakdance\Data\load_presets()]);
}

/* ----------------------------------------------------------------------------
 * Ability 11 — nibwp/breakdance-variables (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/breakdance-variables', [
    'label'       => __('Breakdance — Variables', domain: 'nibwp'),
    'description' => __('Read the design variables and their collections — the colors, sizes and spacing tokens this site is built on (read-only).', domain: 'nibwp'),
    'category'    => 'breakdance',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['list', 'collections'], 'default' => 'list', 'description' => 'The action to perform.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_breakdance_variables',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use these tokens when setting element properties rather than literal colors and sizes, so the page follows the site\'s design system instead of drifting from it.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_breakdance_variables(array $input): array|WP_Error
{
    if ($guard = nibwp_breakdance_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? 'list');

    return nibwp_breakdance_try(static function () use ($action) {
        if ($action === 'collections') {
            if (!function_exists('Breakdance\\Variables\\getVariablesCollections')) {
                throw new \RuntimeException(__('Variable collections are unavailable in this Breakdance version.', domain: 'nibwp'));
            }

            return ['collections' => \Breakdance\Variables\getVariablesCollections()];
        }

        if (!function_exists('Breakdance\\Variables\\getVariables')) {
            throw new \RuntimeException(__('Variables are unavailable in this Breakdance version.', domain: 'nibwp'));
        }

        return ['variables' => \Breakdance\Variables\getVariables()];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 12 — nibwp/breakdance-forms (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/breakdance-forms', [
    'label'       => __('Breakdance — Form submissions', domain: 'nibwp'),
    'description' => __('List and read the submissions captured by Breakdance forms (read-only).', domain: 'nibwp'),
    'category'    => 'breakdance',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'        => ['type' => 'string', 'enum' => ['list', 'get'], 'default' => 'list', 'description' => 'The action to perform.'],
            'submission_id' => ['type' => 'integer', 'description' => 'Submission ID. Required for get.'],
            'per_page'      => ['type' => 'integer', 'default' => 20],
            'page'          => ['type' => 'integer', 'default' => 1],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_breakdance_forms',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Submissions are personal data. Read what the task needs and do not copy it somewhere it was not collected for.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_breakdance_forms(array $input): array|WP_Error
{
    if ($guard = nibwp_breakdance_guard()) {
        return $guard;
    }

    $post_type = 'breakdance_form_res';
    if (!post_type_exists($post_type)) {
        return nibwp_breakdance_err(
            'nibwp_breakdance_no_submissions',
            __('Breakdance form submission storage is not enabled on this site.', domain: 'nibwp')
        );
    }

    $action = (string) ($input['action'] ?? 'list');
    $submission_id = (int) ($input['submission_id'] ?? 0);

    if ($action === 'get' && $submission_id <= 0) {
        return nibwp_breakdance_err('nibwp_breakdance_bad_id', __('A valid submission ID is required.', domain: 'nibwp'));
    }

    return nibwp_breakdance_try(static function () use ($action, $submission_id, $input, $post_type) {
        if ($action === 'get') {
            $post = get_post($submission_id);
            if (!$post || $post->post_type !== $post_type) {
                throw new \RuntimeException(__('No such submission.', domain: 'nibwp'));
            }

            return [
                'id'      => $submission_id,
                'date'    => $post->post_date_gmt,
                'title'   => $post->post_title,
                'fields'  => get_post_meta($submission_id),
            ];
        }

        $page = nibwp_breakdance_paginate($input);
        $query = new WP_Query([
            'post_type'      => $post_type,
            'post_status'    => 'any',
            'posts_per_page' => $page['per_page'],
            'paged'          => $page['page'],
        ]);

        $rows = [];
        foreach ($query->posts as $post) {
            $rows[] = [
                'id'    => (int) $post->ID,
                'title' => $post->post_title,
                'date'  => $post->post_date_gmt,
            ];
        }

        return ['submissions' => $rows, 'count' => count($rows), 'total' => (int) $query->found_posts];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 13 — nibwp/breakdance-revisions (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/breakdance-revisions', [
    'label'       => __('Breakdance — Revisions', domain: 'nibwp'),
    'description' => __('List a page\'s Breakdance revisions and restore one, so a bad edit can be undone.', domain: 'nibwp'),
    'category'    => 'breakdance',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'      => ['type' => 'string', 'enum' => ['list', 'restore'], 'description' => 'The action to perform.'],
            'post_id'     => ['type' => 'integer', 'description' => 'The post whose revisions are wanted.'],
            'revision_id' => ['type' => 'integer', 'description' => 'Revision to restore. Required for restore.'],
        ],
        'required' => ['action', 'post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_breakdance_revisions',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'This is the undo for everything else here. Check that a page has revisions before making a large structural change to it.',
                'restore overwrites the current tree with the revision\'s.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_breakdance_revisions(array $input): array|WP_Error
{
    if ($guard = nibwp_breakdance_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $post_id = (int) ($input['post_id'] ?? 0);
    $revision_id = (int) ($input['revision_id'] ?? 0);

    if ($post_id <= 0) {
        return nibwp_breakdance_err('nibwp_breakdance_bad_id', __('A valid post ID is required.', domain: 'nibwp'));
    }
    if ($action === 'restore' && $revision_id <= 0) {
        return nibwp_breakdance_err('nibwp_breakdance_bad_id', __('A valid revision ID is required.', domain: 'nibwp'));
    }

    return nibwp_breakdance_try(static function () use ($action, $post_id, $revision_id) {
        $revisions = wp_get_post_revisions($post_id, ['posts_per_page' => 50]);

        if ($action === 'list') {
            $rows = [];
            foreach ($revisions as $revision) {
                $rows[] = [
                    'id'        => (int) $revision->ID,
                    'date'      => $revision->post_date_gmt,
                    'author'    => (int) $revision->post_author,
                    'has_tree'  => \Breakdance\Data\get_tree((int) $revision->ID) !== false,
                ];
            }

            return ['post_id' => $post_id, 'revisions' => $rows, 'count' => count($rows)];
        }

        if ($action === 'restore') {
            if (!isset($revisions[$revision_id])) {
                throw new \RuntimeException(__('That revision does not belong to this post.', domain: 'nibwp'));
            }

            $tree = \Breakdance\Data\get_tree($revision_id);
            if ($tree === false || !is_array($tree)) {
                throw new \RuntimeException(__('That revision carries no Breakdance tree.', domain: 'nibwp'));
            }

            $written = nibwp_breakdance_put_tree($post_id, $tree);
            if ($written instanceof WP_Error) {
                return $written;
            }

            return ['post_id' => $post_id, 'revision_id' => $revision_id, 'restored' => true];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ----------------------------------------------------------------------------
 * Ability 14 — nibwp/breakdance-delete (destructive)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/breakdance-delete', [
    'label'       => __('Breakdance — Delete', domain: 'nibwp'),
    'description' => __('Trash or permanently delete Breakdance templates and pages, and clear a page\'s tree without deleting the post. Irreversible where stated.', domain: 'nibwp'),
    'category'    => 'breakdance',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['trash', 'delete', 'clear_tree'], 'description' => 'The action to perform.'],
            'post_id' => ['type' => 'integer', 'description' => 'The post or template to act on.'],
            'confirm' => ['type' => 'boolean', 'default' => false, 'description' => 'Must be true for delete and clear_tree. Trashing does not require it.'],
        ],
        'required' => ['action', 'post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_breakdance_delete',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Prefer trash — it is reversible from the WordPress admin.',
                'clear_tree empties a page of every element while keeping the post. It is not covered by trash and needs confirm=true.',
                'Deleting a header, footer or template changes what visitors see on every page it applied to, not just one.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_breakdance_delete(array $input): array|WP_Error
{
    if ($guard = nibwp_breakdance_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $post_id = (int) ($input['post_id'] ?? 0);
    $confirm = (bool) ($input['confirm'] ?? false);

    if ($post_id <= 0) {
        return nibwp_breakdance_err('nibwp_breakdance_bad_id', __('A valid post ID is required.', domain: 'nibwp'));
    }

    if (in_array($action, ['delete', 'clear_tree'], strict: true) && !$confirm) {
        return nibwp_breakdance_err(
            'nibwp_breakdance_unconfirmed',
            __('This permanently destroys work. Re-issue the call with confirm set to true if that is intended.', domain: 'nibwp')
        );
    }

    return nibwp_breakdance_try(static function () use ($action, $post_id) {
        switch ($action) {
            case 'trash':
                $result = wp_trash_post($post_id);
                if (!$result) {
                    throw new \RuntimeException(__('That post could not be trashed.', domain: 'nibwp'));
                }

                return ['post_id' => $post_id, 'trashed' => true, 'reversible' => true];

            case 'delete':
                $result = wp_delete_post($post_id, true);
                if (!$result) {
                    throw new \RuntimeException(__('That post could not be deleted.', domain: 'nibwp'));
                }

                return ['post_id' => $post_id, 'deleted' => true, 'reversible' => false];

            case 'clear_tree':
                $written = nibwp_breakdance_put_tree($post_id, ['root' => ['id' => 0, 'data' => ['type' => 'root'], 'children' => []]]);
                if ($written instanceof WP_Error) {
                    return $written;
                }

                return ['post_id' => $post_id, 'cleared' => true, 'reversible' => false];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}
