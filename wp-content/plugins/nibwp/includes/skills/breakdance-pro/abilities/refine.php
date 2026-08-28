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
 * Breakdance Pro — refine and feedback.
 */

wp_register_ability('nibwp/breakdance-pro-refine', [
    'label'       => __('Breakdance Pro — Refine a section', 'nibwp'),
    'description' => __('Change specific nodes on an existing Breakdance page — properties, element type, or removal — validated against the site\'s registry, leaving everything else exactly as it was.', 'nibwp'),
    'category'    => 'breakdance-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'The page to change.'],
            'edits'   => [
                'type' => 'array',
                'description' => 'One entry per node to change: {node_id, properties?, type?, remove?}. Properties are merged, not replaced.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'node_id'    => ['type' => 'integer'],
                        'properties' => ['type' => 'object'],
                        'type'       => ['type' => 'string'],
                        'remove'     => ['type' => 'boolean', 'default' => false],
                    ],
                    'required' => ['node_id'],
                ],
            ],
            'dry_run' => ['type' => 'boolean', 'default' => true],
        ],
        'required' => ['post_id', 'edits'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_bdpro_refine_ability',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Use this instead of re-converting a page. Rebuilding to change a heading discards every hand edit made since.',
                'Get node IDs from nibwp/breakdance-tree action=outline or nibwp/breakdance-pro-audit.',
                'Properties merge, so send only what changes.',
                'Check the page has revisions before a large set of edits — that is the undo.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_bdpro_refine_ability(array $input)
{
    if (!function_exists('nibwp_breakdance_available') || !nibwp_breakdance_available()) {
        return new WP_Error('nibwp_bdpro_missing', __('Breakdance is not active on this site.', 'nibwp'));
    }

    $post_id = (int) ($input['post_id'] ?? 0);
    $edits = array_values((array) ($input['edits'] ?? []));
    $dry_run = !array_key_exists('dry_run', $input) || (bool) $input['dry_run'];

    if ($edits === []) {
        return new WP_Error('nibwp_bdpro_empty', __('No edits were supplied.', 'nibwp'));
    }

    $tree = nibwp_breakdance_get_tree($post_id);
    if ($tree instanceof WP_Error) {
        return $tree;
    }

    $existing = nibwp_bdpro_tree_nodes($tree);
    $errors = [];
    $planned = [];

    foreach ($edits as $edit) {
        $node_id = (int) ($edit['node_id'] ?? 0);

        if (!isset($existing[$node_id])) {
            $errors[] = ['rule' => 'unknown_node', 'message' => sprintf('No node %d on this page.', $node_id)];
            continue;
        }

        $new_type = (string) ($edit['type'] ?? '');
        if ($new_type !== '' && !nibwp_bdpro_element_exists($new_type)) {
            $errors[] = [
                'rule'         => 'unknown_element',
                'message'      => sprintf('"%s" is not registered on this site.', $new_type),
                'did_you_mean' => nibwp_bdpro_suggest_slugs($new_type),
            ];
            continue;
        }

        $type = $new_type !== '' ? $new_type : $existing[$node_id]['type'];
        $properties = (array) ($edit['properties'] ?? []);

        if ($properties !== []) {
            $report = nibwp_bdpro_validate([[
                'ref'        => 'n' . $node_id,
                'type'       => $type,
                'parent'     => null,
                'properties' => $properties,
            ]]);

            // The bare-root warning is meaningless for a single node lifted out
            // of a page for checking, so it is dropped rather than shown.
            foreach ($report['errors'] as $error) {
                if (($error['rule'] ?? '') !== 'bare_root_element') {
                    $errors[] = $error;
                }
            }
        }

        $planned[] = [
            'node_id' => $node_id,
            'from'    => $existing[$node_id]['type'],
            'to'      => $type,
            'remove'  => (bool) ($edit['remove'] ?? false),
            'properties' => $properties,
        ];
    }

    if ($errors !== []) {
        return ['ok' => false, 'written' => false, 'errors' => $errors];
    }

    if ($dry_run) {
        return ['ok' => true, 'written' => false, 'dry_run' => true, 'planned' => $planned];
    }

    foreach ($planned as $plan) {
        $args = ['post_id' => $post_id, 'node_id' => $plan['node_id']];

        if ($plan['remove']) {
            $result = nibwp_breakdance_nodes($args + ['action' => 'remove']);
        } else {
            $args['action'] = 'update';
            if ($plan['properties'] !== []) {
                $args['properties'] = $plan['properties'];
            }
            if ($plan['to'] !== $plan['from']) {
                $args['type'] = $plan['to'];
            }
            $result = nibwp_breakdance_nodes($args);
        }

        if ($result instanceof WP_Error) {
            return [
                'ok'      => false,
                'written' => true,
                'error'   => $result->get_error_message(),
                'note'    => __('Some edits were applied before this one failed. Re-read the page before retrying.', 'nibwp'),
            ];
        }
    }

    return [
        'ok'       => true,
        'written'  => true,
        'post_id'  => $post_id,
        'applied'  => count($planned),
        'edit_url' => admin_url('post.php?post=' . $post_id . '&action=edit'),
    ];
}

/* ── Feedback ─────────────────────────────────────────────────────────── */

wp_register_ability('nibwp/breakdance-pro-feedback', [
    'label'       => __('Breakdance Pro — Feedback', 'nibwp'),
    'description' => __('Record whether a Breakdance Pro build was good or bad, with a note, so the skill improves.', 'nibwp'),
    'category'    => 'breakdance-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'rating'  => ['type' => 'string', 'enum' => ['up', 'down']],
            'note'    => ['type' => 'string'],
            'post_id' => ['type' => 'integer'],
        ],
        'required' => ['rating'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_bdpro_feedback_ability',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Ask once, after the user has seen the result. A "down" with a note about what was wrong is worth more than ten silent successes.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_bdpro_feedback_ability(array $input)
{
    $entries = get_option('nibwp_bdpro_feedback', []);
    $entries = is_array($entries) ? $entries : [];

    $entries[] = [
        'rating'  => (string) ($input['rating'] ?? ''),
        'note'    => (string) ($input['note'] ?? ''),
        'post_id' => (int) ($input['post_id'] ?? 0),
        'at'      => gmdate('c'),
    ];

    // Bounded on purpose: this is a signal, not a log, and an unbounded option
    // is a row that grows until someone notices it in the database.
    if (count($entries) > 200) {
        $entries = array_slice($entries, -200);
    }

    update_option('nibwp_bdpro_feedback', $entries, false);

    return ['recorded' => true, 'total' => count($entries)];
}
