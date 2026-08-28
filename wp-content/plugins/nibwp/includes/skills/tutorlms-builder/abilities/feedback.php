<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Tutor LMS Builder — record conversion feedback.
 *
 * Storage: wp_options['nibwp_tutorlms_builder_feedback'] (autoload:false), shape:
 *   "<element_type>" => { up:int, down:int, recent_down_reasons:[{ts,reason,course_id}] }  // capped 10
 */

wp_register_ability('nibwp/tutorlms-builder-feedback', [
    'label'       => __('Tutor LMS Builder — Record feedback', 'nibwp'),
    'description' => __('Record a 👍 / 👎 for a just-built course. Thumb-down reasons are aggregated and surfaced on the next build via nibwp/load-skill-playbook.', 'nibwp'),
    'category'    => 'tutorlms-builder',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'course_id'    => ['type' => 'integer'],
            'element_type' => ['type' => 'string', 'description' => 'course | lesson | quiz', 'default' => 'course'],
            'rating'       => ['type' => 'string', 'enum' => ['up', 'down']],
            'reason'       => ['type' => 'string'],
        ],
        'required' => ['rating'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => ['recorded' => ['type' => 'boolean'], 'aggregated' => ['type' => 'object']],
    ],
    'execute_callback'    => 'nibwp_tutorlms_builder_record_feedback',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['instructions' => 'Call once after a build, with the user thumb-up/down. Down reasons improve the next build.', 'readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function nibwp_tutorlms_builder_record_feedback(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('tutorlms-builder');
    if (is_wp_error($gate)) {
        return $gate;
    }
    $rating = (string) ($input['rating'] ?? '');
    if (!in_array($rating, ['up', 'down'], true)) {
        return new WP_Error('invalid_rating', 'rating must be "up" or "down".');
    }
    $element_type = sanitize_key((string) ($input['element_type'] ?? 'course')) ?: 'course';
    $course_id    = (int) ($input['course_id'] ?? 0);
    $reason       = sanitize_text_field((string) ($input['reason'] ?? ''));

    $all = (array) get_option('nibwp_tutorlms_builder_feedback', []);
    $row = (array) ($all[$element_type] ?? ['up' => 0, 'down' => 0, 'recent_down_reasons' => []]);
    if ($rating === 'up') {
        $row['up'] = (int) ($row['up'] ?? 0) + 1;
    } else {
        $row['down'] = (int) ($row['down'] ?? 0) + 1;
        $reasons = (array) ($row['recent_down_reasons'] ?? []);
        array_unshift($reasons, ['ts' => time(), 'reason' => $reason, 'course_id' => $course_id]);
        $row['recent_down_reasons'] = array_slice($reasons, 0, 10);
    }
    $all[$element_type] = $row;
    update_option('nibwp_tutorlms_builder_feedback', $all, autoload: false);

    return ['recorded' => true, 'aggregated' => $row];
}
