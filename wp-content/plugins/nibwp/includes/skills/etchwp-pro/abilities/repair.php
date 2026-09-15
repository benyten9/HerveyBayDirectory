<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * EtchWP Pro — repair pages built by earlier NibWP versions.
 *
 * Two bugs have been fixed going forward, and neither fix reaches a page that
 * was already written:
 *
 *   - Post content used to be saved unslashed, which strips the backslash from
 *     the - escapes WordPress uses for "--" in block attributes. The class
 *     "bg--base" is stored as "bgu002du002dbase", so no ACSS modifier class on
 *     the page applies.
 *   - Components used to be kept in an option Etch never reads, with instances
 *     pointing at them by `componentId`. Etch resolves a component only by
 *     `ref` to a wp_block post, so every such instance renders nothing.
 *
 * This finds those pages and rewrites them in Etch's shape. Dry run by default;
 * every write leaves a revision behind.
 */

wp_register_ability('nibwp/etchwp-pro-repair', [
    'label'       => __('EtchWP Pro — Repair pages from earlier versions', 'nibwp'),
    'description' => __('Finds Etch pages NibWP built with an earlier version and repairs them: restores ACSS modifier classes whose "--" was lost on save, and turns old componentId instances into real Etch components. Dry run by default.', 'nibwp'),
    'category'    => 'etchwp-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => [
                'type'        => 'integer',
                'description' => 'Repair one post. Omit to check every post that carries NibWP Etch output.',
            ],
            'dry_run' => [
                'type'        => 'boolean',
                'default'     => true,
                'description' => 'Report what would change without writing anything.',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'dry_run' => ['type' => 'boolean'],
            'posts'   => ['type' => 'array', 'items' => ['type' => 'object']],
            'summary' => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'nibwp_etchwp_pro_repair',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => [
            'instructions' => "Run with dry_run:true first and show the user the per-post report.\nOnly after they agree, run again with dry_run:false.\nEach repaired post keeps a revision, so it can be restored from its revisions.",
            'readonly'    => false,
            'destructive' => true,
            'idempotent'  => true,
        ],
    ],
]);

function nibwp_etchwp_pro_repair(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('etchwp-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }

    require_once __DIR__ . '/../lib/validator.php';
    require_once __DIR__ . '/../lib/persister.php';

    $dry_run = !array_key_exists('dry_run', $input) || !empty($input['dry_run']);
    $post_id = (int) ($input['post_id'] ?? 0);

    if ($post_id > 0) {
        if (!get_post($post_id)) {
            return new WP_Error('repair_post_missing', sprintf('Post %d does not exist.', $post_id));
        }
        $ids = [$post_id];
    } else {
        global $wpdb;
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type <> 'revision' AND post_status NOT IN ('trash', 'auto-draft', 'inherit') AND post_content LIKE %s ORDER BY ID LIMIT 200",
            '%' . $wpdb->esc_like('nibwp:etchwp-component') . '%'
        )));
    }

    $legacy  = (array) get_option('nibwp_etchwp_components', []);
    $report  = [];
    $touched = 0;

    foreach ($ids as $id) {
        $post = get_post($id);
        if (!$post || !current_user_can('edit_post', $id)) {
            continue;
        }

        $result = nibwp_etchwp_repair_blocks(parse_blocks((string) $post->post_content), $legacy);
        $row = [
            'post_id'              => $id,
            'title'                => get_the_title($id),
            'escapes_restored'     => $result['escapes'],
            'component_instances'  => $result['instances'],
            'missing_definitions'  => $result['missing'],
        ];

        if ($result['escapes'] === 0 && $result['instances'] === 0) {
            $report[] = $row + ['status' => 'clean'];
            continue;
        }
        if ($dry_run) {
            $report[] = $row + ['status' => 'would_repair'];
            continue;
        }

        $blocks = $result['blocks'];
        if ($result['components'] !== []) {
            // Reuse the persister's own path, so a repaired component is minted
            // exactly like a new one: wp_block post, properties meta, refs.
            $wrapper = [
                'components'     => $result['components'],
                'gutenbergBlock' => ['blockName' => 'etch/element', 'attrs' => [], 'innerBlocks' => $blocks],
            ];
            $materialized = nibwp_etchwp_materialize_components($wrapper);
            if (isset($materialized['error'])) {
                $report[] = $row + ['status' => 'error', 'error' => $materialized['error']->get_error_message()];
                continue;
            }
            $blocks = $wrapper['gutenbergBlock']['innerBlocks'];
            $row['component_posts'] = $materialized['map'];
        }

        $updated = wp_update_post([
            'ID'           => $id,
            // Slashed, or this write would strip the escapes all over again.
            'post_content' => wp_slash(serialize_blocks($blocks)),
        ], true);

        if (is_wp_error($updated)) {
            $report[] = $row + ['status' => 'error', 'error' => $updated->get_error_message()];
            continue;
        }

        $touched++;
        $report[] = $row + ['status' => 'repaired'];
    }

    $needing = count(array_filter($report, static fn(array $r): bool => $r['status'] !== 'clean'));

    return [
        'success' => true,
        'dry_run' => $dry_run,
        'posts'   => $report,
        'summary' => $dry_run
            ? sprintf('%d post(s) checked, %d need repair. Nothing was changed.', count($report), $needing)
            : sprintf('%d post(s) checked, %d repaired.', count($report), $touched),
    ];
}

/**
 * Repair a parsed block list without touching the database.
 *
 * @param array<int,mixed>    $blocks from parse_blocks()
 * @param array<string,mixed> $legacy  the old nibwp_etchwp_components option
 * @return array{blocks:array<int,mixed>,escapes:int,instances:int,components:array<string,array>,missing:array<int,string>}
 */
function nibwp_etchwp_repair_blocks(array $blocks, array $legacy): array
{
    $escapes   = 0;
    $instances = 0;
    $used      = [];

    $walk = static function (array &$nodes) use (&$walk, &$escapes, &$instances, &$used): void {
        foreach ($nodes as &$node) {
            if (!is_array($node)) {
                continue;
            }
            $name = (string) ($node['blockName'] ?? '');
            if (str_starts_with($name, 'etch/') && isset($node['attrs']) && is_array($node['attrs'])) {
                $node['attrs'] = nibwp_etchwp_repair_escapes($node['attrs'], $escapes);
                if ($name === 'etch/component' && empty($node['attrs']['ref']) && (string) ($node['attrs']['componentId'] ?? '') !== '') {
                    $instances++;
                    $used[(string) $node['attrs']['componentId']] = true;
                }
            }
            if (!empty($node['innerBlocks']) && is_array($node['innerBlocks'])) {
                $walk($node['innerBlocks']);
            }
        }
        unset($node);
    };
    $walk($blocks);

    $components = [];
    $missing    = [];
    foreach (array_keys($used) as $cid) {
        $cid = (string) $cid;
        if (!isset($legacy[$cid]) || !is_array($legacy[$cid])) {
            $missing[] = $cid;
            continue;
        }
        $definition = $legacy[$cid];
        unset($definition['updated_at']);
        $ignored = 0;
        $components[$cid] = nibwp_etchwp_repair_escapes($definition, $ignored);
    }

    return [
        'blocks'     => $blocks,
        'escapes'    => $escapes,
        'instances'  => $instances,
        'components' => $components,
        'missing'    => $missing,
    ];
}

/**
 * Restore the characters WordPress escapes in block attributes once their
 * backslashes were stripped: "--", "<", ">", "&" and '"'.
 *
 * Only the forms serialize_block_attributes() produces are touched, and "-" is
 * only restored in pairs, because that is the only way it is ever escaped.
 *
 * @param mixed $value
 * @return mixed
 */
function nibwp_etchwp_repair_escapes($value, int &$count)
{
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = nibwp_etchwp_repair_escapes($item, $count);
        }
        return $value;
    }

    if (!is_string($value) || !str_contains($value, 'u00')) {
        return $value;
    }

    $map = ['u002du002d' => '--', 'u003c' => '<', 'u003e' => '>', 'u0026' => '&', 'u0022' => '"'];

    return (string) preg_replace_callback(
        '/(?<!\\\\)(u002du002d|u003c|u003e|u0026|u0022)/i',
        static function (array $m) use (&$count, $map): string {
            $count++;
            return $map[strtolower($m[1])];
        },
        $value
    );
}
