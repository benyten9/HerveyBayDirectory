<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Voxel Pro — a rough quality read on a document that already validates.
 *
 * The validator answers "will this work". This answers "is this a Voxel
 * template or an Elementor page that happens to live on a Voxel site" — a
 * card with no dynamic tags renders the same text for every listing, which
 * validates perfectly and is always wrong.
 */

function nibwp_voxel_pro_score(array $elements, array $validation, string $kind): array
{
    $score  = 100;
    $notes  = [];

    $score -= min(30, count($validation['warnings']) * 5);
    if ($validation['warnings'] !== []) {
        $notes[] = sprintf('%d warning(s).', count($validation['warnings']));
    }

    $dtags = count((array) $validation['dtags']);
    $data_kinds = ['card', 'single-post', 'single-term'];
    if (in_array($kind, $data_kinds, true)) {
        if ($dtags === 0) {
            $score -= 40;
            $notes[] = 'No dynamic tags at all. Every listing would render identical text — bind the title, image and fields with @tags().';
        } elseif ($dtags < 3) {
            $score -= 10;
            $notes[] = sprintf('Only %d dynamic tag(s) for a %s. Most of what a listing shows should come from its own data.', $dtags, $kind);
        }
    }

    if ($validation['widget_count'] > 0 && $validation['element_count'] / max(1, $validation['widget_count']) > 4) {
        $score -= 5;
        $notes[] = 'Deeply nested for the amount of content — containers cost layout time on every page view.';
    }

    $repeats = nibwp_voxel_pro_repeated_siblings($elements);
    if ($repeats >= 3 && !in_array($kind, ['card'], true)) {
        $score -= 10;
        $notes[] = sprintf('%d near-identical sibling blocks. If they are listings, a ts-post-feed with a card template stays correct as content changes.', $repeats);
    }

    $score = max(0, min(100, $score));
    $grade = $score >= 90 ? 'A' : ($score >= 75 ? 'B' : ($score >= 60 ? 'C' : 'D'));

    return [
        'score' => $score,
        'grade' => $grade,
        'notes' => $notes,
    ];
}

/** Largest run of siblings that share a shape — the static-repetition smell. */
function nibwp_voxel_pro_repeated_siblings(array $nodes): int
{
    $max = 0;
    $signatures = [];
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }
        $sig = nibwp_voxel_pro_signature($node);
        $signatures[$sig] = ($signatures[$sig] ?? 0) + 1;
        $max = max($max, $signatures[$sig]);
        if (!empty($node['elements'])) {
            $max = max($max, nibwp_voxel_pro_repeated_siblings((array) $node['elements']));
        }
    }
    return $max;
}

function nibwp_voxel_pro_signature(array $node): string
{
    $parts = [(string) ($node['widgetType'] ?? $node['elType'] ?? '')];
    foreach ((array) ($node['elements'] ?? []) as $child) {
        if (is_array($child)) {
            $parts[] = (string) ($child['widgetType'] ?? $child['elType'] ?? '');
        }
    }
    return implode('>', $parts);
}
