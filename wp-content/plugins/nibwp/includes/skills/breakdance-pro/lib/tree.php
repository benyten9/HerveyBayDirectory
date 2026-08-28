<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Breakdance Pro — payload to tree.
 *
 * The agent hands over a flat, readable node list. This turns it into the
 * nested structure Breakdance stores, allocating IDs and preserving order.
 *
 * Payload node shape:
 *   ['ref' => 'hero', 'type' => 'EssentialElements\Section',
 *    'parent' => null, 'properties' => [...]]
 *
 * `ref` is the agent's own name for a node, used only to express parentage.
 * Real numeric IDs are allocated here, because the agent cannot know what IDs
 * an existing page already uses.
 */

/**
 * Build a Breakdance tree from a flat node list.
 *
 * @param list<array<string, mixed>> $nodes
 * @param int $start_id First ID to allocate. Pass max-existing-id when
 *                      appending to a page rather than replacing it.
 * @return array{tree: array, map: array<string, int>, errors: list<string>}
 */
function nibwp_bdpro_build_tree(array $nodes, int $start_id = 0): array
{
    $errors = [];
    $map = [];
    $built = [];
    $next = $start_id + 1;

    foreach ($nodes as $i => $node) {
        $ref = (string) ($node['ref'] ?? ('node_' . $i));

        if (isset($map[$ref])) {
            $errors[] = sprintf('Duplicate ref "%s" — every node needs its own.', $ref);
            continue;
        }

        $map[$ref] = $next;

        $built[$ref] = [
            'id'       => $next,
            'data'     => [
                'type'       => (string) ($node['type'] ?? ''),
                'properties' => (object) ((array) ($node['properties'] ?? [])),
            ],
            'children' => [],
            '_parent'  => $node['parent'] ?? null,
        ];

        $next++;
    }

    // Parents resolved in a second pass, so a node may name a parent that
    // appears later in the list — an agent describing a page top-down should
    // not have to think about ordering.
    $root_children = [];

    foreach ($built as $ref => $node) {
        $parent = $node['_parent'];

        if ($parent === null || $parent === '') {
            $root_children[] = $ref;
            continue;
        }

        $parent = (string) $parent;

        if (!isset($built[$parent])) {
            $errors[] = sprintf('Node "%s" names parent "%s", which is not in the payload.', $ref, $parent);
            $root_children[] = $ref;
            continue;
        }

        if (!nibwp_bdpro_is_container((string) $built[$parent]['data']['type'])) {
            $errors[] = sprintf(
                'Node "%s" is inside "%s" (%s), which cannot hold children.',
                $ref,
                $parent,
                $built[$parent]['data']['type']
            );
        }
    }

    if (nibwp_bdpro_has_cycle($built)) {
        $errors[] = 'The node list contains a parent cycle.';

        return ['tree' => [], 'map' => $map, 'errors' => $errors];
    }

    $assemble = static function (string $ref) use (&$assemble, $built): array {
        $node = $built[$ref];
        unset($node['_parent']);

        foreach ($built as $child_ref => $child) {
            if ((string) ($child['_parent'] ?? '') === $ref) {
                $node['children'][] = $assemble($child_ref);
            }
        }

        return $node;
    };

    $tree = [
        'root' => [
            'id'       => $start_id,
            'data'     => ['type' => 'root'],
            'children' => array_map($assemble, $root_children),
        ],
    ];

    return ['tree' => $tree, 'map' => $map, 'errors' => $errors];
}

/**
 * Would this parentage produce a cycle?
 *
 * A cycle makes the assembler recurse until PHP runs out of stack, which
 * surfaces as a blank 500 rather than an error anyone can act on.
 *
 * @param array<string, array<string, mixed>> $built
 */
function nibwp_bdpro_has_cycle(array $built): bool
{
    foreach (array_keys($built) as $ref) {
        $seen = [];
        $cursor = $ref;

        while ($cursor !== null && $cursor !== '' && isset($built[$cursor])) {
            if (isset($seen[$cursor])) {
                return true;
            }
            $seen[$cursor] = true;
            $cursor = $built[$cursor]['_parent'] ?? null;
            $cursor = $cursor === null ? null : (string) $cursor;
        }
    }

    return false;
}

/**
 * Flatten a Breakdance tree back into a readable node list.
 *
 * The inverse of the builder, used by the audit and refine abilities so an
 * agent can reason about an existing page in the same vocabulary it writes in.
 *
 * @return list<array{id:int, ref:string, type:string, parent:?int, depth:int}>
 */
function nibwp_bdpro_flatten_tree(array $tree): array
{
    $rows = [];

    $walk = static function (array $node, ?int $parent, int $depth) use (&$walk, &$rows): void {
        $rows[] = [
            'id'     => (int) ($node['id'] ?? 0),
            'ref'    => 'n' . (int) ($node['id'] ?? 0),
            'type'   => (string) ($node['data']['type'] ?? ''),
            'parent' => $parent,
            'depth'  => $depth,
        ];

        foreach ((array) ($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $walk($child, (int) ($node['id'] ?? 0), $depth + 1);
            }
        }
    };

    if (isset($tree['root']) && is_array($tree['root'])) {
        $walk($tree['root'], null, 0);
    }

    return $rows;
}

/** Every node in a tree, with its properties, keyed by id. */
function nibwp_bdpro_tree_nodes(array $tree): array
{
    $out = [];

    $walk = static function (array $node) use (&$walk, &$out): void {
        $out[(int) ($node['id'] ?? 0)] = [
            'type'       => (string) ($node['data']['type'] ?? ''),
            'properties' => (array) ($node['data']['properties'] ?? []),
        ];

        foreach ((array) ($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $walk($child);
            }
        }
    };

    if (isset($tree['root']) && is_array($tree['root'])) {
        $walk($tree['root']);
    }

    return $out;
}
