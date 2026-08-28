<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Kadence Pro — record conversion feedback. Thumb-down reasons aggregate per
 * (brand, section_type) so future load-skill-playbook calls surface lessons.
 * Storage: option `nibwp_kadence_pro_feedback`.
 */

wp_register_ability('nibwp/kadence-pro-feedback', [
    'label'       => __('Kadence Pro — record conversion feedback', 'nibwp'),
    'description' => __('Record thumb-up / thumb-down on the most recent Kadence conversion. Down reasons are aggregated per (brand, section_type) and surfaced to future runs.', 'nibwp'),
    'category'    => 'kadence-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id'      => ['type' => 'integer'],
            'brand'        => ['type' => 'string'],
            'section_type' => ['type' => 'string'],
            'rating'       => ['enum' => ['up', 'down']],
            'reason'       => ['type' => 'string'],
        ],
        'required' => ['rating'],
    ],
    'execute_callback'    => 'nibwp_kadence_pro_record_feedback',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function nibwp_kadence_pro_record_feedback(array $in): array|WP_Error
{
    $gate = nibwp_skill_gate('kadence-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }
    $key = sanitize_key((string) ($in['brand'] ?? 'unknown')) . '::' . sanitize_key((string) ($in['section_type'] ?? 'unknown'));
    $all = (array) get_option('nibwp_kadence_pro_feedback', []);
    $row = $all[$key] ?? ['up' => 0, 'down' => 0, 'recent_down_reasons' => []];
    if ((string) ($in['rating'] ?? 'up') === 'down') {
        $row['down']++;
        array_unshift($row['recent_down_reasons'], [
            'ts' => time(),
            'reason' => sanitize_text_field((string) ($in['reason'] ?? '')),
            'post_id' => (int) ($in['post_id'] ?? 0),
        ]);
        $row['recent_down_reasons'] = array_slice($row['recent_down_reasons'], 0, 10);
    } else {
        $row['up']++;
    }
    $all[$key] = $row;
    update_option('nibwp_kadence_pro_feedback', $all, false);
    return ['recorded' => true, 'aggregated' => $row];
}
