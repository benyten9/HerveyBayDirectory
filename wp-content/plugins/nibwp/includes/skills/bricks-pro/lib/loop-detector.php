<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Bricks Pro — loop detector.
 *
 * Walks the FLAT Bricks element tree and finds sibling structural patterns
 * (3+ children of the same parent sharing the same element name + first
 * global-class atom + child-count). These become Query Loop + CPT + ACF
 * candidates, surfaced via the orchestrator-recommender.
 *
 * @param array<int,array{name:string,settings:array,parent?:int|string,children?:array<int,int|string>}> $elements
 * @param int $min_count
 * @return array<int,array{signature:string,count:int,parent_id:int|string,sample_classes:array<int,string>,sample_element_name:string,paths:array<int,string>}>
 */
function nibwp_bricks_pro_detect_loops(array $elements, int $min_count = 3): array
{
    // Build parent → children map.
    $by_parent = [];
    foreach ($elements as $idx => $el) {
        if (!is_array($el)) {
            continue;
        }
        $parent = $el['parent'] ?? 0;
        $by_parent[(string) $parent][$idx] = $el;
    }

    $groups = [];
    foreach ($by_parent as $parent_id => $children_map) {
        $by_signature = [];
        foreach ($children_map as $idx => $child) {
            $sig = nibwp_bricks_pro_signature_of_element($child, $elements);
            if ($sig === '') {
                continue;
            }
            if (!isset($by_signature[$sig])) {
                $by_signature[$sig] = [
                    'count' => 0,
                    'paths' => [],
                    'sample_classes'     => nibwp_bricks_pro_sample_classes_of_element($child),
                    'sample_element_name'=> (string) ($child['name'] ?? ''),
                    'parent_id'          => $parent_id,
                ];
            }
            $by_signature[$sig]['count']++;
            $by_signature[$sig]['paths'][] = "elements[$idx]";
        }
        foreach ($by_signature as $sig => $info) {
            if ($info['count'] >= $min_count) {
                $groups[] = ['signature' => $sig] + $info;
            }
        }
    }

    usort($groups, static fn ($a, $b) => $b['count'] <=> $a['count']);
    return $groups;
}

/**
 * Structural signature of an element. Lossy on purpose.
 *
 * Encoding: `{name}|{first_class_family}|c={child_count}|s={child_names_csv}`
 * — child_count is the number of CHILDREN ids in $child['children'], not deep.
 */
function nibwp_bricks_pro_signature_of_element(array $el, array $all_elements): string
{
    $name = (string) ($el['name'] ?? '');
    if ($name === '') {
        return '';
    }
    $first_class = '';
    $settings = (array) ($el['settings'] ?? []);
    foreach ((array) ($settings['_cssGlobalClasses'] ?? $settings['globalClasses'] ?? []) as $cls) {
        $first_class = (string) preg_replace('/(__[a-z0-9-]+|--[a-z0-9-]+)$/i', '', (string) $cls);
        if ($first_class !== '') {
            break;
        }
    }
    $children_ids = (array) ($el['children'] ?? []);
    $child_names = [];
    foreach ($children_ids as $child_idx) {
        if (isset($all_elements[$child_idx]) && is_array($all_elements[$child_idx])) {
            $child_names[] = (string) ($all_elements[$child_idx]['name'] ?? '');
        }
    }
    return sprintf('%s|%s|c=%d|s=%s', $name, $first_class, count($children_ids), implode(',', $child_names));
}

/**
 * Pull a few sample class atoms from an element for the report payload.
 *
 * @return array<int,string>
 */
function nibwp_bricks_pro_sample_classes_of_element(array $el): array
{
    $out = [];
    $settings = (array) ($el['settings'] ?? []);
    foreach ((array) ($settings['_cssGlobalClasses'] ?? $settings['globalClasses'] ?? []) as $cls) {
        if (is_string($cls) && $cls !== '' && !in_array($cls, $out, true)) {
            $out[] = $cls;
        }
    }
    return array_slice($out, 0, 6);
}
