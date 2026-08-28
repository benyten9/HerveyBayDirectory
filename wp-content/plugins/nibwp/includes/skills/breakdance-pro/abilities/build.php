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
 * Breakdance Pro — build abilities.
 *
 * Whole-artefact work rather than one section: a template that knows where it
 * displays, a loop plan that replaces repetition, the design library, and
 * WooCommerce pages.
 */

/* ── Template + conditions in one step ───────────────────────────────── */

wp_register_ability('nibwp/breakdance-pro-template', [
    'label'       => __('Breakdance Pro — Build a template', 'nibwp'),
    'description' => __('Create a header, footer, popup, template or global block, build its content, and assign the display conditions that decide where it appears — in one call.', 'nibwp'),
    'category'    => 'breakdance-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'role'       => ['type' => 'string', 'enum' => ['template', 'header', 'footer', 'popup', 'block'], 'description' => 'What kind of template to build.'],
            'title'      => ['type' => 'string'],
            'nodes'      => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'The content, as the conversion abilities take it.'],
            'conditions' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Display conditions, in the shape nibwp/breakdance-conditions action=available describes. Omit to create it unassigned.'],
            'post_id'    => ['type' => 'integer', 'description' => 'Update an existing template instead of creating one.'],
            'dry_run'    => ['type' => 'boolean', 'default' => true],
        ],
        'required' => ['role'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_bdpro_template_ability',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'A template with no conditions is built and invisible. Read nibwp/breakdance-conditions action=available and set them, or tell the user plainly that it will not display yet.',
                'Headers and footers replace what the theme provides everywhere they apply — check with the user before assigning one site-wide.',
                'dry_run true first.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_bdpro_template_ability(array $input)
{
    if (!function_exists('nibwp_breakdance_available') || !nibwp_breakdance_available()) {
        return new WP_Error('nibwp_bdpro_missing', __('Breakdance is not active on this site.', 'nibwp'));
    }

    $role = (string) ($input['role'] ?? '');
    $post_type = nibwp_breakdance_post_type($role);
    if ($post_type === '') {
        return new WP_Error('nibwp_bdpro_bad_role', __('That is not a Breakdance post type on this install.', 'nibwp'));
    }

    $nodes = array_values((array) ($input['nodes'] ?? []));
    $conditions = array_values((array) ($input['conditions'] ?? []));
    $dry_run = !array_key_exists('dry_run', $input) || (bool) $input['dry_run'];

    $report = ['errors' => [], 'warnings' => [], 'recommendations' => []];
    if ($nodes !== []) {
        $report = nibwp_bdpro_validate($nodes, ['template_role' => $role]);
        if ($report['errors'] !== []) {
            return ['ok' => false, 'written' => false, 'errors' => $report['errors'], 'warnings' => $report['warnings']];
        }
    }

    if ($conditions === []) {
        $report['warnings'][] = [
            'rule'    => 'no_conditions',
            'message' => __('No display conditions were given, so this template will exist but never appear. Assign some, or tell the user it is unassigned.', 'nibwp'),
        ];
    }

    if ($dry_run) {
        return [
            'ok'       => true,
            'written'  => false,
            'dry_run'  => true,
            'role'     => $role,
            'post_type' => $post_type,
            'nodes'    => count($nodes),
            'conditions' => $conditions,
            'warnings' => $report['warnings'],
            'recommendations' => $report['recommendations'],
        ];
    }

    $post_id = (int) ($input['post_id'] ?? 0);

    if ($post_id <= 0) {
        $post_id = wp_insert_post([
            'post_type'   => $post_type,
            'post_title'  => (string) ($input['title'] ?? __('Untitled template', 'nibwp')),
            'post_status' => 'publish',
        ], true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }
        $post_id = (int) $post_id;
    }

    if ($nodes !== []) {
        $built = nibwp_bdpro_build_tree($nodes);
        if ($built['errors'] !== []) {
            return ['ok' => false, 'written' => false, 'errors' => $built['errors']];
        }

        $written = nibwp_breakdance_put_tree($post_id, $built['tree']);
        if ($written instanceof WP_Error) {
            return $written;
        }
    }

    if ($conditions !== []) {
        $set = nibwp_breakdance_conditions([
            'action'     => 'set',
            'post_id'    => $post_id,
            'conditions' => (string) wp_json_encode($conditions),
        ]);

        if ($set instanceof WP_Error) {
            // The template exists and has content; only the assignment failed.
            // Reported rather than rolled back, because deleting the work would
            // be worse than leaving it unassigned.
            return [
                'ok'       => false,
                'written'  => true,
                'post_id'  => $post_id,
                'error'    => $set->get_error_message(),
                'next_step' => __('The template was built but its conditions were not set. Assign them with nibwp/breakdance-conditions.', 'nibwp'),
            ];
        }
    }

    return [
        'ok'         => true,
        'written'    => true,
        'post_id'    => $post_id,
        'role'       => $role,
        'conditions' => $conditions,
        'warnings'   => $report['warnings'],
        'edit_url'   => admin_url('post.php?post=' . $post_id . '&action=edit'),
    ];
}

/* ── Loop plan ────────────────────────────────────────────────────────── */

wp_register_ability('nibwp/breakdance-pro-loop-plan', [
    'label'       => __('Breakdance Pro — Plan a loop', 'nibwp'),
    'description' => __('Find repeated structure in a payload or an existing page and return a concrete plan for replacing it with a Breakdance loop element bound to a post type (read-only).', 'nibwp'),
    'category'    => 'breakdance-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'nodes'   => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'A payload to analyse.'],
            'post_id' => ['type' => 'integer', 'description' => 'Or an existing page to analyse instead.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_bdpro_loop_plan_ability',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Five hand-built cards is five things to edit whenever the content changes. A loop is one.',
                'The plan is a proposal — creating the post type and fields is a separate decision the user makes.',
                'Not every repetition should be a loop. Three feature blurbs that will never change are fine as they are; a team listing is not.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_bdpro_loop_plan_ability(array $input)
{
    $nodes = array_values((array) ($input['nodes'] ?? []));
    $post_id = (int) ($input['post_id'] ?? 0);

    if ($nodes === [] && $post_id > 0) {
        if (!function_exists('nibwp_breakdance_available') || !nibwp_breakdance_available()) {
            return new WP_Error('nibwp_bdpro_missing', __('Breakdance is not active on this site.', 'nibwp'));
        }
        $tree = nibwp_breakdance_get_tree($post_id);
        if ($tree instanceof WP_Error) {
            return $tree;
        }
        $rows = nibwp_bdpro_flatten_tree($tree);
        foreach ($rows as $row) {
            if ($row['depth'] === 0) {
                continue;
            }
            $nodes[] = [
                'ref'    => $row['ref'],
                'type'   => $row['type'],
                'parent' => $row['parent'] === null ? null : 'n' . $row['parent'],
            ];
        }
    }

    if ($nodes === []) {
        return new WP_Error('nibwp_bdpro_empty', __('Give this either a node payload or a post_id.', 'nibwp'));
    }

    $repeats = nibwp_bdpro_detect_repetition($nodes);
    $loop_elements = nibwp_bdpro_loop_elements();

    $plans = [];
    foreach ($repeats as $repeat) {
        $plans[] = [
            'count'      => $repeat['count'],
            'refs'       => $repeat['refs'],
            'signature'  => $repeat['signature'],
            'suggested_element' => $loop_elements[0] ?? null,
            'steps'      => [
                __('Create a post type for these items, or pick an existing one.', 'nibwp'),
                __('Add fields for the parts that differ between the copies — heading, text, image, link.', 'nibwp'),
                __('Replace the repeated nodes with one loop element bound to that post type.', 'nibwp'),
                __('Inside the loop, bind each field with dynamic data instead of typed text.', 'nibwp'),
                __('Move the existing content into items of that post type.', 'nibwp'),
            ],
        ];
    }

    return [
        'repetitions'   => count($plans),
        'plans'         => $plans,
        'loop_elements' => $loop_elements,
        'verdict'       => $plans === []
            ? __('No repetition worth converting.', 'nibwp')
            : __('Repetition found. Ask the user whether this content will change or grow — if it will, a loop is worth it.', 'nibwp'),
    ];
}

/* ── Design library ───────────────────────────────────────────────────── */

wp_register_ability('nibwp/breakdance-pro-library', [
    'label'       => __('Breakdance Pro — Design library', 'nibwp'),
    'description' => __('List the saved design-library parts on this site, so a new section can start from something already built and approved rather than from nothing (read-only).', 'nibwp'),
    'category'    => 'breakdance-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'search'  => ['type' => 'string'],
            'post_id' => ['type' => 'integer', 'description' => 'Read one part\'s tree, to adapt rather than copy.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_bdpro_library_ability',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Check here before building a section from scratch. Matching something the site already uses beats inventing a new treatment for the same job.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_bdpro_library_ability(array $input)
{
    if (!function_exists('nibwp_breakdance_available') || !nibwp_breakdance_available()) {
        return new WP_Error('nibwp_bdpro_missing', __('Breakdance is not active on this site.', 'nibwp'));
    }

    $post_type = nibwp_breakdance_post_type('part');
    if ($post_type === '' || !post_type_exists($post_type)) {
        return ['parts' => [], 'count' => 0, 'note' => __('This install registers no design-library post type.', 'nibwp')];
    }

    $post_id = (int) ($input['post_id'] ?? 0);

    if ($post_id > 0) {
        $tree = nibwp_breakdance_get_tree($post_id);
        if ($tree instanceof WP_Error) {
            return $tree;
        }

        return ['post_id' => $post_id, 'title' => get_the_title($post_id), 'nodes' => nibwp_bdpro_flatten_tree($tree)];
    }

    $parts = get_posts([
        'post_type'   => $post_type,
        'post_status' => 'any',
        'numberposts' => 100,
        's'           => (string) ($input['search'] ?? ''),
        'orderby'     => 'title',
        'order'       => 'ASC',
    ]);

    $rows = [];
    foreach ($parts as $part) {
        $rows[] = ['id' => (int) $part->ID, 'title' => $part->post_title, 'status' => $part->post_status];
    }

    return ['parts' => $rows, 'count' => count($rows)];
}

/* ── WooCommerce ──────────────────────────────────────────────────────── */

wp_register_ability('nibwp/breakdance-pro-woocommerce', [
    'label'       => __('Breakdance Pro — WooCommerce elements', 'nibwp'),
    'description' => __('The WooCommerce elements Breakdance registers on this site — product grids, cart, checkout, single-product parts — for building store templates with real elements rather than shortcodes (read-only).', 'nibwp'),
    'category'    => 'breakdance-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'search' => ['type' => 'string'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_bdpro_woocommerce_ability',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Store templates need the WooCommerce elements, not a [woocommerce] shortcode dropped into a text element.',
                'A shop or product template also needs display conditions, or Woo keeps rendering its own.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_bdpro_woocommerce_ability(array $input)
{
    if (!class_exists('WooCommerce')) {
        return ['available' => false, 'note' => __('WooCommerce is not active on this site.', 'nibwp'), 'elements' => []];
    }

    $search = strtolower((string) ($input['search'] ?? ''));
    $rows = [];

    foreach (nibwp_bdpro_element_catalogue() as $element) {
        $haystack = strtolower($element['slug'] . ' ' . $element['name'] . ' ' . $element['category']);
        if (!str_contains($haystack, 'woo') && !str_contains($haystack, 'product') && !str_contains($haystack, 'cart') && !str_contains($haystack, 'checkout')) {
            continue;
        }
        if ($search !== '' && !str_contains($haystack, $search)) {
            continue;
        }
        $rows[] = $element;
    }

    return ['available' => true, 'elements' => $rows, 'count' => count($rows)];
}
