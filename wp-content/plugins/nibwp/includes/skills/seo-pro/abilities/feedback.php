<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('nibwp/seo-pro-feedback', [
    'label'       => __('SEO Pro — Feedback', 'nibwp'),
    'description' => __('Record a thumb up/down on a SEO Pro action so future runs improve. Call after a pipeline completes.', 'nibwp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'rating'  => ['type' => 'string', 'enum' => ['up', 'down']],
            'ability' => ['type' => 'string', 'description' => 'Which ability the feedback is about.'],
            'note'    => ['type' => 'string'],
        ],
        'required' => ['rating'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_seo_pro_feedback_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => nibwp_seo_pro_ability_meta(false, false),
]);

function nibwp_seo_pro_feedback_execute(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('seo-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }
    $log = get_option('nibwp_seo_pro_feedback', []);
    $log = is_array($log) ? $log : [];
    $log[] = [
        'rating'  => ($input['rating'] ?? 'up') === 'down' ? 'down' : 'up',
        'ability' => sanitize_text_field((string) ($input['ability'] ?? '')),
        'note'    => sanitize_textarea_field((string) ($input['note'] ?? '')),
        'user'    => get_current_user_id(),
        'time'    => current_time('mysql'),
    ];
    if (count($log) > 200) {
        $log = array_slice($log, -200);
    }
    update_option('nibwp_seo_pro_feedback', $log, false);
    return ['recorded' => true];
}
