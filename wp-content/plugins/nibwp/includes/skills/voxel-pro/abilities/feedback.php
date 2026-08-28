<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Voxel Pro — record build feedback. Thumb-down reasons aggregate per
 * (brand, kind) so future load-skill-playbook calls surface the lessons in
 * that kind's checklist. Storage: option `nibwp_voxel_pro_feedback`.
 */

wp_register_ability('nibwp/voxel-pro-feedback', [
    'label'       => __('Voxel Pro — record build feedback', 'nibwp'),
    'description' => __('Record thumb-up / thumb-down on the most recent Voxel template build. Down reasons are aggregated per (brand, kind) and surfaced to future runs through the matching checklist.', 'nibwp'),
    'category'    => 'voxel-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'template_id' => ['type' => 'integer'],
            'brand'       => ['type' => 'string'],
            'kind'        => ['type' => 'string', 'description' => 'Template kind: card, single-post, archive, search-page, header, footer, style-kit-popup, …'],
            'rating'      => ['enum' => ['up', 'down']],
            'reason'      => ['type' => 'string'],
        ],
        'required' => ['rating'],
        'additionalProperties' => false,
    ],
    'execute_callback'    => 'nibwp_voxel_pro_record_feedback',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function nibwp_voxel_pro_record_feedback(array $in): array|WP_Error
{
    $gate = nibwp_skill_gate('voxel-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }
    $key = sanitize_key((string) ($in['brand'] ?? 'unknown')) . '::' . sanitize_key((string) ($in['kind'] ?? 'unknown'));
    $all = (array) get_option('nibwp_voxel_pro_feedback', []);
    $row = $all[$key] ?? ['up' => 0, 'down' => 0, 'recent_down_reasons' => []];
    if ((string) ($in['rating'] ?? 'up') === 'down') {
        $row['down']++;
        array_unshift($row['recent_down_reasons'], [
            'ts'      => time(),
            'reason'  => sanitize_text_field((string) ($in['reason'] ?? '')),
            'post_id' => (int) ($in['template_id'] ?? 0),
        ]);
        $row['recent_down_reasons'] = array_slice($row['recent_down_reasons'], 0, 10);
    } else {
        $row['up']++;
    }
    $all[$key] = $row;
    update_option('nibwp_voxel_pro_feedback', $all, false);
    return ['recorded' => true, 'aggregated' => $row];
}
