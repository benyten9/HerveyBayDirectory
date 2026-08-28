<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Bricks Pro — record conversion feedback.
 *
 * Same shape as etchwp-pro-feedback. Aggregates thumb-down reasons per
 * (brand, element_type) so the next load-skill-playbook call returns
 * lessons-learned the agent reads BEFORE synthesizing.
 *
 * Storage: option `nibwp_bricks_pro_feedback`.
 */

wp_register_ability('nibwp/bricks-pro-feedback', [
    'label'       => __('Bricks Pro — Record conversion feedback', 'nibwp'),
    'description' => __('Record thumb-up / thumb-down on the most recent Bricks conversion. Thumb-down reasons are aggregated per (brand, element_type) and surfaced to future runs via load-skill-playbook so the same mistakes are not repeated.', 'nibwp'),
    'category'    => 'bricks-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'template_id'  => ['type' => 'integer'],
            'brand'        => ['type' => 'string'],
            'element_type' => ['type' => 'string'],
            'rating'       => ['enum' => ['up', 'down']],
            'reason'       => ['type' => 'string'],
        ],
        'required' => ['template_id', 'rating'],
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'recorded'   => ['type' => 'boolean'],
            'aggregated' => ['type' => 'object'],
        ],
    ],
    'execute_callback'    => 'nibwp_bricks_pro_record_feedback',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => [
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

function nibwp_bricks_pro_record_feedback(array $in): array|WP_Error
{
    $gate = nibwp_skill_gate('bricks-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }
    $key = sanitize_key((string) ($in['brand'] ?? 'unknown')) . '::' . sanitize_key((string) ($in['element_type'] ?? 'unknown'));
    $all = (array) get_option('nibwp_bricks_pro_feedback', []);
    $row = $all[$key] ?? ['up' => 0, 'down' => 0, 'recent_down_reasons' => []];
    $rating = (string) ($in['rating'] ?? 'up');
    if ($rating === 'down') {
        $row['down']++;
        array_unshift($row['recent_down_reasons'], [
            'ts'          => time(),
            'reason'      => sanitize_text_field((string) ($in['reason'] ?? '')),
            'template_id' => (int) ($in['template_id'] ?? 0),
        ]);
        $row['recent_down_reasons'] = array_slice($row['recent_down_reasons'], 0, 10);
    } else {
        $row['up']++;
    }
    $all[$key] = $row;
    update_option('nibwp_bricks_pro_feedback', $all, false);
    return ['recorded' => true, 'aggregated' => $row];
}
