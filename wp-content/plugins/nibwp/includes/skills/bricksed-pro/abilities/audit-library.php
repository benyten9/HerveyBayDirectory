<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Check what is stored against the rules a fresh save would face.
 *
 * Read-only. The rules tighten over time, and a library that was clean when a
 * component went into it is not necessarily clean now; this is how someone
 * finds out before a client does. Every component is checked as the Bricks
 * elements Bricksed hands to the builder, whichever shape it is stored in. It
 * does one read per component, so it is bounded, and it says when it stopped
 * early.
 */

/*
 * The libs this ability calls. Loaded here, not left to the integration file:
 * the skill loader checks that Bricksed is present, not that its integration
 * card is switched on. Each guard names a function from its own file.
 */
$nibwp_bricksed_lib = dirname(__DIR__) . '/lib/';
if (!function_exists('nibwp_bricksed_available')) {
    require_once $nibwp_bricksed_lib . 'library-client.php';
}
if (!function_exists('nibwp_bricksed_write_body')) {
    require_once $nibwp_bricksed_lib . 'library.php';
}
if (!function_exists('nibwp_bricksed_validate_portability')) {
    require_once $nibwp_bricksed_lib . 'portability.php';
}
if (!function_exists('nibwp_bricksed_normalize_findings')) {
    require_once $nibwp_bricksed_lib . 'findings.php';
}
unset($nibwp_bricksed_lib);

const NIBWP_BRICKSED_AUDIT_DEFAULT_LIMIT = 25;
const NIBWP_BRICKSED_AUDIT_MAX_LIMIT = 100;

wp_register_ability('nibwp/bricksed-pro-audit-library', [
    'label' => __('Bricksed Pro — Audit Library', 'nibwp'),
    'description' => 'Check stored Bricksed components against the Bricks Pro build rules (ACSS tokens, BEM brand prefix, no hardcoded colours or font sizes, native elements) and the portability rules (nothing tied to this site). Read-only. By default checks the components saved on this site; scope "all" includes the official library. Bounded by limit, and reports when it stopped early.',
    'category' => 'bricksed',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'string', 'description' => 'Audit one component by id, regardless of scope.'],
            'scope' => ['type' => 'string', 'enum' => ['user', 'all'], 'description' => 'user (default): components saved on this site. all: including the official library.'],
            'type' => ['type' => 'string', 'enum' => ['layout', 'element', 'component', 'template']],
            'category' => ['type' => 'string', 'description' => 'Case-insensitive.'],
            'limit' => ['type' => 'integer', 'description' => 'How many components to read. Default 25, at most 100.'],
            'include_passing' => ['type' => 'boolean', 'description' => 'List components that passed too. Default false: only those with findings.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'nibwp_bricksed_pro_audit_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
            'instructions' => implode("\n", [
                'Start with a category or type filter on a large library; each component costs a read.',
                'If the result says truncated, run it again with a narrower filter rather than a higher limit.',
                'A failed rule is something to fix. Offer to fix it with bricksed-manage update, and ask before writing.',
                'skipped lists what could not be checked and why: locked by the Bricksed tier, or unreadable.',
            ]),
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_bricksed_pro_audit_execute(array $input)
{
    $library = nibwp_bricksed_request('/library', 'GET');
    if (is_wp_error($library)) {
        return $library;
    }

    $missing = nibwp_bricksed_require_bricks_pro();
    if (is_wp_error($missing)) {
        return $missing;
    }

    $limit = (int) ($input['limit'] ?? NIBWP_BRICKSED_AUDIT_DEFAULT_LIMIT);
    $limit = max(1, min(NIBWP_BRICKSED_AUDIT_MAX_LIMIT, $limit));

    $only_id = (string) ($input['id'] ?? '');
    $scope = (string) ($input['scope'] ?? 'user') === 'all' ? 'all' : 'user';
    $type = (string) ($input['type'] ?? '');
    $category = strtolower(trim((string) ($input['category'] ?? '')));
    $include_passing = !empty($input['include_passing']);

    $queue = [];
    $skipped = [];

    foreach ((array) ($library['components'] ?? []) as $id => $meta) {
        $id = (string) $id;
        $meta = (array) $meta;

        if ($only_id !== '') {
            if ($id !== $only_id) {
                continue;
            }
        } else {
            if ($scope === 'user' && empty($meta['user'])) {
                continue;
            }
            if ($type !== '' && (string) ($meta['type'] ?? '') !== $type) {
                continue;
            }
            if ($category !== '' && strtolower((string) ($meta['category'] ?? '')) !== $category) {
                continue;
            }
        }

        // A locked component cannot be read, and trying only produces the
        // licence refusal. Reported, not fetched.
        if (!empty($meta['locked'])) {
            $skipped[] = ['id' => $id, 'name' => (string) ($meta['name'] ?? $id), 'reason' => 'locked'];
            continue;
        }

        $queue[] = $id;
    }

    $remaining = max(0, count($queue) - $limit);
    $queue = array_slice($queue, 0, $limit);

    $results = [];
    $checked = 0;
    $passed = 0;
    $failed = 0;
    $warned = 0;

    foreach ($queue as $id) {
        $stored = nibwp_bricksed_request('/component/' . $id, 'GET', ['format' => 'bricks']);
        if (is_wp_error($stored)) {
            // One unreadable component does not end the sweep.
            $skipped[] = [
                'id' => $id,
                'name' => (string) ($library['components'][$id]['name'] ?? $id),
                'reason' => $stored->get_error_message(),
            ];
            continue;
        }

        $meta = (array) ($library['components'][$id] ?? []);
        $bricks = nibwp_bricksed_bricks_of(['bricks' => $stored]);
        if ($bricks === null) {
            $skipped[] = ['id' => $id, 'name' => (string) ($meta['name'] ?? $id), 'reason' => 'no Bricks elements'];
            continue;
        }

        $quality = nibwp_bricksed_quality($bricks, (string) ($meta['brand'] ?? ''));
        $portability = nibwp_bricksed_validate_portability(['bricks' => $bricks]);

        $findings_failed = array_merge(
            nibwp_bricksed_normalize_findings($quality['failed']),
            nibwp_bricksed_normalize_findings($portability['failed'])
        );
        $findings_warned = array_merge(
            nibwp_bricksed_normalize_findings($quality['warnings']),
            nibwp_bricksed_normalize_findings($portability['warnings'])
        );

        $checked++;
        if ($findings_failed !== []) {
            $status = 'failed';
            $failed++;
        } elseif ($findings_warned !== []) {
            $status = 'warned';
            $warned++;
        } else {
            $status = 'passed';
            $passed++;
        }

        if ($status === 'passed' && !$include_passing) {
            continue;
        }

        $results[] = [
            'id' => $id,
            'name' => (string) ($meta['name'] ?? $id),
            'type' => (string) ($meta['type'] ?? ''),
            'category' => (string) ($meta['category'] ?? ''),
            'status' => $status,
            'failed' => $findings_failed,
            'warnings' => $findings_warned,
        ];
    }

    return [
        'checked' => $checked,
        'passed' => $passed,
        'warned' => $warned,
        'failed' => $failed,
        'results' => $results,
        'skipped' => $skipped,
        'truncated' => $remaining > 0,
        'remaining' => $remaining,
    ];
}
