<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/** Tutor LMS Mini-site — record feedback. Storage: wp_options['nibwp_tutorlms_minisite_feedback']. */

wp_register_ability('nibwp/tutorlms-minisite-feedback', [
    'label'       => __('Tutor LMS Mini-site — Record feedback', 'nibwp'),
    'description' => __('Record a 👍 / 👎 for a built landing page. Thumb-down reasons surface on the next build.', 'nibwp'),
    'category'    => 'tutorlms-minisite',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'page_id' => ['type' => 'integer'],
            'rating'  => ['type' => 'string', 'enum' => ['up', 'down']],
            'reason'  => ['type' => 'string'],
        ],
        'required' => ['rating'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_tutorlms_minisite_record_feedback',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function nibwp_tutorlms_minisite_record_feedback(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('tutorlms-minisite');
    if (is_wp_error($gate)) {
        return $gate;
    }
    $rating = (string) ($input['rating'] ?? '');
    if (!in_array($rating, ['up', 'down'], true)) {
        return new WP_Error('invalid_rating', 'rating must be "up" or "down".');
    }
    $page_id = (int) ($input['page_id'] ?? 0);
    $reason  = sanitize_text_field((string) ($input['reason'] ?? ''));

    $all = (array) get_option('nibwp_tutorlms_minisite_feedback', []);
    $row = (array) ($all['minisite'] ?? ['up' => 0, 'down' => 0, 'recent_down_reasons' => []]);
    if ($rating === 'up') {
        $row['up'] = (int) ($row['up'] ?? 0) + 1;
    } else {
        $row['down'] = (int) ($row['down'] ?? 0) + 1;
        $reasons = (array) ($row['recent_down_reasons'] ?? []);
        array_unshift($reasons, ['ts' => time(), 'reason' => $reason, 'page_id' => $page_id]);
        $row['recent_down_reasons'] = array_slice($reasons, 0, 10);
    }
    $all['minisite'] = $row;
    update_option('nibwp_tutorlms_minisite_feedback', $all, autoload: false);

    return ['recorded' => true, 'aggregated' => $row];
}
