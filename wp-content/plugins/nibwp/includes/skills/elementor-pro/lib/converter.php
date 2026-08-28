<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Elementor Pro skill — converter.
 *
 * Normalises the agent-built tree into the exact shape Elementor stores in
 * `_elementor_data`: a recursive array of elements, each
 *   { id, elType, settings:{}, elements:[] }  (+ widgetType when elType=widget).
 *
 * - Mints a unique 7-char id for every element (Elementor's id format).
 * - Accepts "type" as an alias for "elType" and "widget" for "widgetType".
 * - Infers elType: has widgetType → "widget"; otherwise "container".
 * - Guarantees settings is an object and elements is an array (recursed).
 *
 * No styling decisions happen here — the agent supplies settings keyed by the
 * real control ids (see registry). This only fixes structure + ids.
 */

/** Mint a unique Elementor-style element id (7 hex chars), unique within a run. */
function nibwp_elementor_pro_mint_id(array &$seen): string
{
    do {
        $id = substr(bin2hex(random_bytes(4)), 0, 7);
    } while (isset($seen[$id]));
    $seen[$id] = true;
    return $id;
}

/**
 * Build a normalised Elementor element tree from the agent tree.
 *
 * @param array $tree Agent-built nodes.
 * @return array Normalised top-level elements.
 */
function nibwp_elementor_pro_build(array $tree): array
{
    $seen = [];
    $out = [];
    foreach ($tree as $node) {
        if (is_array($node)) {
            $out[] = nibwp_elementor_pro_normalise($node, $seen);
        }
    }
    return $out;
}

/** Recursively normalise a single node. */
function nibwp_elementor_pro_normalise(array $node, array &$seen): array
{
    // elType / type alias.
    $elType = (string) ($node['elType'] ?? $node['type'] ?? '');
    // widgetType / widget alias.
    $widgetType = (string) ($node['widgetType'] ?? $node['widget'] ?? '');

    if ($elType === '') {
        $elType = $widgetType !== '' ? 'widget' : 'container';
    }
    // Legacy elements keep their type; new trees should be container/widget.
    if (!in_array($elType, ['container', 'widget', 'section', 'column'], true)) {
        $elType = $widgetType !== '' ? 'widget' : 'container';
    }

    // Preserve a caller-supplied id only if it's a plausible id and not already used.
    $id = (string) ($node['id'] ?? '');
    if ($id === '' || strlen($id) > 8 || isset($seen[$id])) {
        $id = nibwp_elementor_pro_mint_id($seen);
    } else {
        $seen[$id] = true;
    }

    $out = [
        'id'       => $id,
        'elType'   => $elType,
        'settings' => (isset($node['settings']) && is_array($node['settings'])) ? $node['settings'] : [],
        'elements' => [],
    ];
    if ($elType === 'widget') {
        $out['widgetType'] = $widgetType !== '' ? $widgetType : 'heading';
    }

    if (!empty($node['elements']) && is_array($node['elements'])) {
        foreach ($node['elements'] as $child) {
            if (is_array($child)) {
                $out['elements'][] = nibwp_elementor_pro_normalise($child, $seen);
            }
        }
    }
    return $out;
}

/** Count all elements in a normalised tree (for reporting + round-trip guard). */
function nibwp_elementor_pro_count(array $tree): int
{
    $n = 0;
    foreach ($tree as $el) {
        $n++;
        if (!empty($el['elements'])) {
            $n += nibwp_elementor_pro_count($el['elements']);
        }
    }
    return $n;
}

/** Flatten every element (depth-first) — used by validator + scorer. */
function nibwp_elementor_pro_flatten(array $tree): array
{
    $flat = [];
    foreach ($tree as $el) {
        $flat[] = $el;
        if (!empty($el['elements'])) {
            foreach (nibwp_elementor_pro_flatten($el['elements']) as $c) {
                $flat[] = $c;
            }
        }
    }
    return $flat;
}
