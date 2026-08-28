<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Voxel Pro — element ids and search wiring.
 *
 * A search form finds its feed through post meta, not through the element
 * tree, and it finds it by POSITION: "1.2" means the third child of the
 * second top-level element. Any edit that inserts or moves an element
 * invalidates every stored path, which is why nothing here ever trusts a path
 * that arrives from outside. Ids are matched; paths are recomputed.
 */

require_once __DIR__ . '/registry.php';

/** Elementor ids are 7 hex characters. Mint one that is not already taken. */
function nibwp_voxel_pro_mint_id(array &$taken): string
{
    do {
        $id = substr(md5(uniqid('', true) . count($taken)), 0, 7);
    } while (isset($taken[$id]));
    $taken[$id] = true;
    return $id;
}

/**
 * Give every element and every repeater row a unique id, and make sure each
 * node has the keys Elementor expects. Duplicate ids are re-minted rather
 * than rejected: two elements sharing an id is a mistake with one obvious fix.
 */
function nibwp_voxel_pro_normalize_tree(array $elements, ?array &$renamed = null): array
{
    $taken = [];
    $notes = [];
    $result = nibwp_voxel_pro_normalize_nodes($elements, $taken, $notes);
    $renamed = $notes;
    return $result;
}

function nibwp_voxel_pro_normalize_nodes(array $nodes, array &$taken, array &$notes): array
{
    $out = [];
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }
        $given = (string) ($node['id'] ?? '');
        $id = $given;
        if ($id === '' || !preg_match('/^[0-9a-f]{7,8}$/', $id) || isset($taken[$id])) {
            $id = nibwp_voxel_pro_mint_id($taken);
            // An id that was asked for and not kept has to be reported, or the
            // agent's next refine call will name an element that no longer
            // exists under that name.
            if ($given !== '') {
                $notes[$given] = $id;
            }
        } else {
            $taken[$id] = true;
        }
        $node['id'] = $id;

        $node['elType']   = (string) ($node['elType'] ?? (isset($node['widgetType']) ? 'widget' : 'container'));
        $node['settings'] = (array) ($node['settings'] ?? []);
        $node['settings'] = nibwp_voxel_pro_normalize_repeaters($node['settings'], $taken);

        $children = (array) ($node['elements'] ?? []);
        $node['elements'] = $children === [] ? [] : nibwp_voxel_pro_normalize_nodes($children, $taken, $notes);

        $out[] = $node;
    }
    return $out;
}

/** Repeater rows carry their own ids, in the same shape. */
function nibwp_voxel_pro_normalize_repeaters(array $settings, array &$taken): array
{
    foreach ($settings as $key => $value) {
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            continue;
        }
        $is_repeater = true;
        foreach ($value as $row) {
            if (!is_array($row) || array_is_list($row)) {
                $is_repeater = false;
                break;
            }
        }
        if (!$is_repeater) {
            continue;
        }
        foreach ($value as $i => $row) {
            $row_id = (string) ($row['_id'] ?? '');
            if ($row_id === '' || !preg_match('/^[0-9a-f]{7,8}$/', $row_id) || isset($taken[$row_id])) {
                $row_id = nibwp_voxel_pro_mint_id($taken);
            } else {
                $taken[$row_id] = true;
            }
            $row['_id'] = $row_id;
            $value[$i] = $row;
        }
        $settings[$key] = $value;
    }
    return $settings;
}

/**
 * Every widget in the tree, with its dot-index path — the same walk Voxel
 * itself does when it resolves a relation.
 *
 * @return array<string, array{path:string, type:string, role:string, depth:int}>
 */
function nibwp_voxel_pro_widget_index(array $elements, string $prefix = ''): array
{
    $roles = nibwp_voxel_pro_widgets();
    $index = [];
    foreach ($elements as $i => $node) {
        $path = $prefix === '' ? (string) $i : $prefix . '.' . $i;
        if (($node['elType'] ?? '') === 'widget') {
            $type = (string) ($node['widgetType'] ?? '');
            $index[(string) $node['id']] = [
                'path'  => $path,
                'type'  => $type,
                'role'  => (string) ($roles[$type]['role'] ?? 'other'),
                'depth' => substr_count($path, '.'),
            ];
        }
        if (!empty($node['elements'])) {
            $index += nibwp_voxel_pro_widget_index((array) $node['elements'], $path);
        }
    }
    return $index;
}

/**
 * Work out which search form drives which feed and map, and give each pairing
 * the positional paths Voxel reads.
 *
 * @param 'auto'|array $spec
 */
function nibwp_voxel_pro_resolve_relations(array $elements, $spec = 'auto'): array
{
    $index = nibwp_voxel_pro_widget_index($elements);
    $out   = ['feedToSearch' => [], 'mapToSearch' => [], 'unpaired' => []];

    if (is_array($spec) && $spec !== []) {
        foreach (['feedToSearch', 'mapToSearch'] as $group) {
            foreach ((array) ($spec[$group] ?? []) as $pair) {
                $left  = (string) ($pair['left'] ?? '');
                $right = (string) ($pair['right'] ?? '');
                if (!isset($index[$left], $index[$right])) {
                    // Recorded, then reported by the validator with both ids.
                    $out['unpaired'][] = ['group' => $group, 'left' => $left, 'right' => $right, 'reason' => 'One of these element ids is not in the document.'];
                    continue;
                }
                $out[$group][] = [
                    'left'      => $left,
                    'right'     => $right,
                    'leftPath'  => $index[$left]['path'],
                    'rightPath' => $index[$right]['path'],
                ];
            }
        }
        return $out;
    }

    // Automatic: pair every feed and map with the search form that shares the
    // most of its path — the one it sits nearest to in the layout.
    $searches = array_filter($index, static fn(array $w): bool => $w['type'] === 'ts-search-form');
    if ($searches === []) {
        return $out;
    }
    foreach ($index as $id => $widget) {
        // An element id of all digits becomes an integer array key, and Voxel
        // matches relation ids with ===. Written as a number it would never
        // match, and the feed would silently show nothing.
        $id = (string) $id;
        $group = $widget['type'] === 'ts-post-feed' ? 'feedToSearch' : ($widget['type'] === 'ts-map' ? 'mapToSearch' : '');
        if ($group === '') {
            continue;
        }
        if (($widget['type'] === 'ts-post-feed') && !nibwp_voxel_pro_feed_wants_search($elements, $id)) {
            continue;
        }
        $search_id = nibwp_voxel_pro_nearest($searches, $widget['path']);
        $out[$group][] = [
            'left'      => $search_id,
            'right'     => $id,
            'leftPath'  => $searches[$search_id]['path'],
            'rightPath' => $widget['path'],
        ];
    }
    return $out;
}

/** A feed only needs a search form when it is sourced from one. */
function nibwp_voxel_pro_feed_wants_search(array $elements, string $feed_id): bool
{
    $node = nibwp_voxel_pro_find_node($elements, $feed_id);
    if ($node === null) {
        return true;
    }
    $source = (string) ($node['settings']['ts_source'] ?? 'search-form');
    return $source === '' || $source === 'search-form';
}

/** Which of these widgets shares the longest path prefix with $path. */
function nibwp_voxel_pro_nearest(array $candidates, string $path): string
{
    $target = explode('.', $path);
    $best   = '';
    $best_score = -1;
    foreach ($candidates as $id => $widget) {
        $other = explode('.', $widget['path']);
        $shared = 0;
        $max = min(count($target), count($other));
        for ($i = 0; $i < $max; $i++) {
            if ($target[$i] !== $other[$i]) {
                break;
            }
            $shared++;
        }
        if ($shared > $best_score) {
            $best_score = $shared;
            $best = (string) $id;
        }
    }
    return $best;
}

/** Find one node by element id. */
function nibwp_voxel_pro_find_node(array $elements, string $id): ?array
{
    foreach ($elements as $node) {
        if ((string) ($node['id'] ?? '') === $id) {
            return $node;
        }
        if (!empty($node['elements'])) {
            $found = nibwp_voxel_pro_find_node((array) $node['elements'], $id);
            if ($found !== null) {
                return $found;
            }
        }
    }
    return null;
}

/** The relations payload as Voxel stores it, merged over what was there before. */
function nibwp_voxel_pro_relations_payload(array $relations, array $previous = []): array
{
    $groups = (array) ($previous['relations'] ?? []);
    $groups['feedToSearch'] = $relations['feedToSearch'];
    $groups['mapToSearch']  = $relations['mapToSearch'];
    foreach (['tabsToNavbar', 'searchToNavbar'] as $group) {
        if (!isset($groups[$group])) {
            $groups[$group] = [];
        }
    }
    $previous['relations'] = $groups;
    return $previous;
}
