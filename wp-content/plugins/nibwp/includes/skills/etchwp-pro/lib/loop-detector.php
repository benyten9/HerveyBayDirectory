<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * EtchWP Pro — loop detector.
 *
 * Scans the agent-built block tree for repeated STRUCTURAL patterns — three
 * or more siblings with the same shape (tag + child-count + class skeleton).
 * The intent: turn static repetition into a dynamic Etch loop block backed
 * by a CPT + ACF field group, rather than persist 6 identical hero cards.
 *
 * The detector ignores text content, ids, and attribute values; only the
 * structural skeleton (tag, depth, child count, BEM class atoms) matters
 * for similarity scoring. A signature is intentionally lossy so cards that
 * differ in copy but share a layout still collapse into one group.
 */

/**
 * Detect repeated structural patterns inside the payload's gutenbergBlock tree.
 *
 * @param array<string,mixed> $payload
 * @param int                 $min_count Minimum siblings to be considered a loop. Default 3.
 * @return array<int,array{signature:string,count:int,sample_classes:array<int,string>,sample_block_name:string,paths:array<int,string>,depth:int}>
 */
function nibwp_etchwp_detect_loops(array $payload, int $min_count = 3): array
{
    $root = $payload['gutenbergBlock'] ?? null;
    if (!is_array($root)) {
        return [];
    }
    $groups = [];
    nibwp_etchwp_walk_for_loops($root, 'gutenbergBlock', 0, $groups);

    $loops = [];
    foreach ($groups as $signature => $info) {
        if ($info['count'] < $min_count) {
            continue;
        }
        $loops[] = [
            'signature'         => $signature,
            'count'             => $info['count'],
            'sample_classes'    => $info['sample_classes'],
            'sample_block_name' => $info['sample_block_name'],
            'paths'             => $info['paths'],
            'depth'             => $info['depth'],
        ];
    }
    // Largest groups first — they're usually the most valuable to dynamise.
    usort($loops, static fn ($a, $b) => $b['count'] <=> $a['count']);
    return $loops;
}

/**
 * Walk the block tree. At every parent, look at the IMMEDIATE children list,
 * compute each child's signature, and tally identical signatures sharing
 * the same parent + same depth. Repeated cross-parent patterns are out of
 * scope — a loop is a single parent with N similar children.
 *
 * @param array<string,mixed>                                       $node
 * @param string                                                    $path
 * @param int                                                       $depth
 * @param array<string,array{count:int,sample_classes:array<int,string>,sample_block_name:string,paths:array<int,string>,depth:int}> $groups
 */
function nibwp_etchwp_walk_for_loops(array $node, string $path, int $depth, array &$groups): void
{
    $inner_blocks = (array) ($node['innerBlocks'] ?? []);
    if ($inner_blocks !== []) {
        $by_signature = [];
        foreach ($inner_blocks as $i => $child) {
            if (!is_array($child)) {
                continue;
            }
            $sig = nibwp_etchwp_signature_of_block($child);
            if ($sig === '') {
                continue;
            }
            $child_path = $path . '.innerBlocks[' . $i . ']';
            if (!isset($by_signature[$sig])) {
                $by_signature[$sig] = [
                    'count'             => 0,
                    'paths'             => [],
                    'sample_classes'    => nibwp_etchwp_sample_classes_of_block($child),
                    'sample_block_name' => (string) ($child['blockName'] ?? ''),
                    'depth'             => $depth + 1,
                ];
            }
            $by_signature[$sig]['count']++;
            $by_signature[$sig]['paths'][] = $child_path;
        }
        // Merge child-level groups into the global groups map keyed by signature,
        // BUT scoped by parent path so two independent loops don't smear into one.
        foreach ($by_signature as $sig => $info) {
            if ($info['count'] < 2) {
                continue;
            }
            $key = $sig . '|@' . $path;
            $groups[$key] = $info;
        }
        // Recurse.
        foreach ($inner_blocks as $i => $child) {
            if (is_array($child)) {
                nibwp_etchwp_walk_for_loops($child, $path . '.innerBlocks[' . $i . ']', $depth + 1, $groups);
            }
        }
    }
    // Also scan classes inside core/html / etch/raw-html for repeated DOM siblings.
    $name = (string) ($node['blockName'] ?? '');
    if ($name === 'core/html' || $name === 'etch/raw-html') {
        $inner = (string) ($node['innerHTML'] ?? '');
        if ($inner === '') {
            foreach ((array) ($node['innerContent'] ?? []) as $piece) {
                if (is_string($piece)) {
                    $inner .= $piece;
                }
            }
        }
        if ($inner !== '') {
            nibwp_etchwp_detect_html_loops($inner, $path, $depth, $groups);
        }
    }
}

/**
 * Compute a structural signature for a block. Lossy on purpose.
 *
 * Encoding: `{blockName}|{first_class_atom}|c={child_count}|s={shape}`
 * where shape is the comma-joined sequence of immediate child blockNames.
 */
function nibwp_etchwp_signature_of_block(array $block): string
{
    $name = (string) ($block['blockName'] ?? '');
    if ($name === '') {
        return '';
    }
    $attrs = (array) ($block['attrs'] ?? []);
    // First class atom from attrs.styles (Etch convention) — drop suffix '-style'.
    $first_class = '';
    foreach ((array) ($attrs['styles'] ?? []) as $sid) {
        if (is_string($sid) && $sid !== '') {
            $first_class = preg_replace('/-style$/', '', (string) $sid) ?? '';
            break;
        }
    }
    if ($first_class === '') {
        // Try BEM atom extracted from raw class= attribute in innerHTML, taking
        // ONLY the BEM block (drop element/modifier suffixes) so card vs card__title
        // collapse to the same "card" family.
        $inner = (string) ($block['innerHTML'] ?? '');
        if ($inner !== '' && preg_match('/class\s*=\s*"([^"]+)"/i', $inner, $m)) {
            $first = preg_split('/\s+/', trim($m[1]))[0] ?? '';
            if ($first !== '') {
                $first_class = (string) preg_replace('/(__[a-z0-9-]+|--[a-z0-9-]+)$/i', '', $first);
            }
        }
    }
    $children = (array) ($block['innerBlocks'] ?? []);
    $child_names = array_map(static fn ($c) => is_array($c) ? (string) ($c['blockName'] ?? '') : '', $children);
    $shape = implode(',', $child_names);
    return sprintf('%s|%s|c=%d|s=%s', $name, $first_class, count($children), $shape);
}

/**
 * Pull a few class atoms from a block for the report payload.
 *
 * @return array<int,string>
 */
function nibwp_etchwp_sample_classes_of_block(array $block): array
{
    $out = [];
    foreach ((array) (($block['attrs']['styles']) ?? []) as $sid) {
        if (is_string($sid) && $sid !== '') {
            $out[] = preg_replace('/-style$/', '', $sid) ?? $sid;
        }
    }
    $inner = (string) ($block['innerHTML'] ?? '');
    if ($inner !== '' && preg_match('/class\s*=\s*"([^"]+)"/i', $inner, $m)) {
        foreach (preg_split('/\s+/', trim($m[1])) as $cls) {
            if ($cls !== '' && !in_array($cls, $out, true)) {
                $out[] = $cls;
            }
        }
    }
    return array_slice(array_values(array_unique($out)), 0, 6);
}

/**
 * Inside a raw HTML blob, detect immediate-sibling repetition under the same
 * parent. Cheap regex pass — we look for runs of the same opening tag with
 * a matching first class atom.
 *
 * @param array<string,array{count:int,sample_classes:array<int,string>,sample_block_name:string,paths:array<int,string>,depth:int}> $groups (in/out)
 */
function nibwp_etchwp_detect_html_loops(string $html, string $path, int $depth, array &$groups): void
{
    // Match every opening tag with a class attribute. Collapse to (tag, first_class).
    if (!preg_match_all('/<(article|section|div|li|figure|a)\s[^>]*class\s*=\s*"([^"]+)"/i', $html, $matches, PREG_SET_ORDER)) {
        return;
    }
    $by_signature = [];
    foreach ($matches as $idx => $m) {
        $tag = strtolower($m[1]);
        $first = preg_split('/\s+/', trim($m[2]))[0] ?? '';
        if ($first === '') {
            continue;
        }
        // Collapse BEM element/modifier so card and card__title share family "card".
        $family = (string) preg_replace('/(__[a-z0-9-]+|--[a-z0-9-]+)$/i', '', $first);
        $sig = sprintf('html:%s|%s', $tag, $family);
        if (!isset($by_signature[$sig])) {
            $by_signature[$sig] = [
                'count'             => 0,
                'paths'             => [],
                'sample_classes'    => [$first],
                'sample_block_name' => 'html:' . $tag,
                'depth'             => $depth + 1,
            ];
        }
        $by_signature[$sig]['count']++;
        $by_signature[$sig]['paths'][] = $path . '.innerHTML#' . $idx;
    }
    foreach ($by_signature as $sig => $info) {
        if ($info['count'] < 2) {
            continue;
        }
        $groups[$sig . '|@' . $path] = $info;
    }
}
