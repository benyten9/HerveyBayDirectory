<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . "/../lib/registry.php";
require_once __DIR__ . "/../lib/tokens.php";
require_once __DIR__ . "/../lib/tree.php";
require_once __DIR__ . "/../lib/validator.php";

/**
 * Breakdance Pro — inspection abilities.
 *
 * Everything an agent should read before it writes, plus the two ways of
 * checking work: validating a payload it is about to send, and auditing a page
 * that already exists.
 */

/* ── Elements ─────────────────────────────────────────────────────────── */

wp_register_ability('nibwp/breakdance-pro-elements', [
    'label'       => __('Breakdance Pro — Element catalogue', 'nibwp'),
    'description' => __('The elements registered on THIS site, with their categories, and the full control schema and property paths for any one of them. Read this before converting anything (read-only).', 'nibwp'),
    'category'    => 'breakdance-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'    => ['type' => 'string', 'enum' => ['list', 'get', 'containers', 'loops'], 'default' => 'list'],
            'search'    => ['type' => 'string', 'description' => 'list: filter by slug, name or category.'],
            'slug'      => ['type' => 'string', 'description' => 'get: the element to describe, e.g. EssentialElements\\\\Heading.'],
            'paths_only' => ['type' => 'boolean', 'default' => false, 'description' => 'get: return just the property paths, not the whole schema. Much smaller.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_bdpro_elements_ability',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'The element set is per-site: it depends on the license, the active subplugins and any third-party packs. Never assume a slug exists.',
                'action=list is shallow by design. Use action=get with paths_only for the property paths of the one element you are about to configure.',
                'Slugs are namespaced — EssentialElements\\Heading — and in JSON the backslash must be doubled.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_bdpro_elements_ability(array $input)
{
    if (nibwp_bdpro_elements() === []) {
        return new WP_Error(
            'nibwp_bdpro_no_registry',
            __('The Breakdance element registry is not readable — is Breakdance active?', 'nibwp')
        );
    }

    $action = (string) ($input['action'] ?? 'list');

    if ($action === 'get') {
        $slug = (string) ($input['slug'] ?? '');
        if ($slug === '') {
            return new WP_Error('nibwp_bdpro_bad_slug', __('An element slug is required.', 'nibwp'));
        }

        if (!nibwp_bdpro_element_exists($slug)) {
            return [
                'found'        => false,
                'slug'         => $slug,
                'did_you_mean' => nibwp_bdpro_suggest_slugs($slug),
            ];
        }

        $paths = nibwp_bdpro_element_property_paths($slug);

        if (!empty($input['paths_only'])) {
            return ['slug' => $slug, 'property_paths' => $paths, 'count' => count($paths), 'is_container' => nibwp_bdpro_is_container($slug)];
        }

        return [
            'slug'           => $slug,
            'element'        => nibwp_bdpro_elements()[$slug],
            'property_paths' => $paths,
            'is_container'   => nibwp_bdpro_is_container($slug),
        ];
    }

    if ($action === 'containers') {
        $containers = array_values(array_filter(
            array_keys(nibwp_bdpro_elements()),
            'nibwp_bdpro_is_container'
        ));

        return ['containers' => $containers, 'count' => count($containers)];
    }

    if ($action === 'loops') {
        return ['loop_elements' => nibwp_bdpro_loop_elements()];
    }

    $rows = nibwp_bdpro_element_catalogue((string) ($input['search'] ?? ''));

    return ['elements' => $rows, 'count' => count($rows), 'total_registered' => count(nibwp_bdpro_elements())];
}

/* ── Tokens ───────────────────────────────────────────────────────────── */

wp_register_ability('nibwp/breakdance-pro-tokens', [
    'label'       => __('Breakdance Pro — Design tokens', 'nibwp'),
    'description' => __('The variables, global selectors and design presets this site already uses, so generated sections match it instead of inventing a second design system (read-only).', 'nibwp'),
    'category'    => 'breakdance-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['all', 'variables', 'selectors', 'presets'], 'default' => 'all'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_bdpro_tokens_ability',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Read this before choosing any color, size or spacing value. Using a variable the site already has is what makes a new section look like it belongs.',
                'If has_token_layer is false the site defines none, and literal values are the honest choice — say so rather than inventing a palette.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_bdpro_tokens_ability(array $input)
{
    $action = (string) ($input['action'] ?? 'all');

    $out = ['has_token_layer' => nibwp_bdpro_has_token_layer()];

    if ($action === 'all' || $action === 'variables') {
        $out['variables'] = nibwp_bdpro_variables();
    }
    if ($action === 'all' || $action === 'selectors') {
        $out['selectors'] = nibwp_bdpro_selectors();
    }
    if ($action === 'all' || $action === 'presets') {
        $out['presets'] = nibwp_bdpro_presets();
    }

    return $out;
}

/* ── Validate ─────────────────────────────────────────────────────────── */

wp_register_ability('nibwp/breakdance-pro-validate', [
    'label'       => __('Breakdance Pro — Validate payload', 'nibwp'),
    'description' => __('Check a node payload against this site\'s element registry and the Breakdance Pro rules without writing anything — element slugs, property paths, inline styling, tokens, accessibility and repetition (read-only).', 'nibwp'),
    'category'    => 'breakdance-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'nodes' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'The same flat node list the conversion abilities take.'],
            'role'  => ['type' => 'string', 'enum' => ['page', 'template', 'header', 'footer', 'popup', 'block'], 'default' => 'page'],
        ],
        'required' => ['nodes'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_bdpro_validate_ability',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Cheaper than a dry-run conversion when you only want to know whether a payload is well formed. The conversion abilities run these same rules anyway.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_bdpro_validate_ability(array $input)
{
    $nodes = array_values((array) ($input['nodes'] ?? []));
    if ($nodes === []) {
        return new WP_Error('nibwp_bdpro_empty', __('No nodes were supplied.', 'nibwp'));
    }

    $report = nibwp_bdpro_validate($nodes, ['template_role' => (string) ($input['role'] ?? 'page')]);
    $built = nibwp_bdpro_build_tree($nodes);

    foreach ($built['errors'] as $message) {
        $report['errors'][] = ['rule' => 'structure', 'message' => $message];
    }

    return [
        'ok'              => $report['errors'] === [],
        'errors'          => $report['errors'],
        'warnings'        => $report['warnings'],
        'recommendations' => $report['recommendations'],
        'nodes'           => count($nodes),
    ];
}

/* ── Audit ────────────────────────────────────────────────────────────── */

wp_register_ability('nibwp/breakdance-pro-audit', [
    'label'       => __('Breakdance Pro — Audit a page', 'nibwp'),
    'description' => __('Read an existing Breakdance page and report what is wrong with it: unregistered elements left by a migration, hardcoded values that have variables, missing alt text, repeated blocks that should be a loop, and headings out of order (read-only).', 'nibwp'),
    'category'    => 'breakdance-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'The page, template, header, footer or block to audit.'],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_bdpro_audit_ability',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Works on pages nobody built with this skill, which is most of them.',
                'Findings are advice, not a to-do list to apply unasked. Show them and let the user choose.',
                'Pair with nibwp/breakdance-pro-refine to fix what the user accepts.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_bdpro_audit_ability(array $input)
{
    if (!function_exists('nibwp_breakdance_available') || !nibwp_breakdance_available()) {
        return new WP_Error('nibwp_bdpro_missing', __('Breakdance is not active on this site.', 'nibwp'));
    }

    $post_id = (int) ($input['post_id'] ?? 0);
    $tree = nibwp_breakdance_get_tree($post_id);
    if ($tree instanceof WP_Error) {
        return $tree;
    }

    // The audit reuses the validator by turning the live tree back into the
    // payload shape, so a page is judged by exactly the rules a new one is.
    $rows = nibwp_bdpro_flatten_tree($tree);
    $properties = nibwp_bdpro_tree_nodes($tree);

    $nodes = [];
    foreach ($rows as $row) {
        if ($row['depth'] === 0) {
            continue;
        }
        $nodes[] = [
            'ref'        => $row['ref'],
            'type'       => $row['type'],
            'parent'     => $row['parent'] === null ? null : 'n' . $row['parent'],
            'properties' => $properties[$row['id']]['properties'] ?? [],
        ];
    }

    $report = nibwp_bdpro_validate($nodes, ['template_role' => get_post_type($post_id) === 'page' ? 'page' : 'template']);

    $unregistered = [];
    foreach ($nodes as $node) {
        if ($node['type'] !== '' && !nibwp_bdpro_element_exists($node['type'])) {
            $unregistered[] = $node['type'];
        }
    }

    return [
        'post_id'         => $post_id,
        'title'           => get_the_title($post_id),
        'node_count'      => count($nodes),
        'depth'           => $rows === [] ? 0 : max(array_column($rows, 'depth')),
        'unregistered_elements' => array_values(array_unique($unregistered)),
        'errors'          => $report['errors'],
        'warnings'        => $report['warnings'],
        'recommendations' => $report['recommendations'],
    ];
}
