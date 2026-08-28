<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * EtchWP Pro — record conversion feedback.
 *
 * After every successful html-to-component commit, the agent asks the user
 * 👍 / 👎. This ability records the rating; aggregated thumb-down reasons are
 * surfaced on the next conversion of the same (brand, element_type) pair via
 * nibwp/load-skill-playbook. See references/feedback-loop.md.
 *
 * Storage: wp_options['nibwp_etchwp_feedback'] (autoload: false), shape:
 *   "<brand>::<element_type>" => {
 *       up: int,
 *       down: int,
 *       recent_down_reasons: [{ ts, reason, component_id }]   // capped to 10
 *   }
 */

wp_register_ability('nibwp/etchwp-pro-feedback', [
    'label'       => __('EtchWP Pro — Record conversion feedback', 'nibwp'),
    'description' => __('Record a 👍 / 👎 rating for a just-converted EtchWP component. Thumb-down reasons are aggregated per (brand, element_type) and injected into the next conversion of the same kind so future runs avoid past mistakes.', 'nibwp'),
    'category'    => 'etchwp-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'component_id' => [
                'type' => 'string',
                'description' => 'The component_id returned by nibwp/etchwp-pro-html-to-component on a successful commit.',
            ],
            'brand' => [
                'type' => 'string',
                'description' => 'Brand slug used for the conversion (e.g. "etched", "alpha", "luxe").',
            ],
            'element_type' => [
                'type' => 'string',
                'description' => 'Element type the conversion produced (button, hero, card-grid, form, navbar, footer, …).',
            ],
            'rating' => [
                'type' => 'string',
                'enum' => ['up', 'down'],
                'description' => 'Thumb-up or thumb-down.',
            ],
            'reason' => [
                'type' => 'string',
                'description' => 'Short one-line reason for a thumb-down. Stored as plain text (no PII).',
            ],
        ],
        'required' => ['component_id', 'brand', 'element_type', 'rating'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'recorded'   => ['type' => 'boolean'],
            'aggregated' => ['type' => 'object'],
        ],
    ],
    'execute_callback'    => 'nibwp_etchwp_record_feedback',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => [
            'instructions' => "Call once after a successful html-to-component commit, with the user's thumb-up/down. The aggregated thumb-down reasons are injected into the next conversion of the same (brand, element_type) via nibwp/load-skill-playbook.",
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

function nibwp_etchwp_record_feedback(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('etchwp-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }

    $brand        = sanitize_key((string) ($input['brand'] ?? ''));
    $element_type = sanitize_key((string) ($input['element_type'] ?? ''));
    $rating       = (string) ($input['rating'] ?? '');
    $component_id = sanitize_text_field((string) ($input['component_id'] ?? ''));
    $reason       = sanitize_text_field((string) ($input['reason'] ?? ''));

    if (!in_array($rating, ['up', 'down'], true)) {
        return new WP_Error('invalid_rating', 'rating must be "up" or "down".');
    }
    if ($brand === '' || $element_type === '' || $component_id === '') {
        return new WP_Error('missing_field', 'brand, element_type and component_id are required.');
    }

    $key = $brand . '::' . $element_type;
    $all = (array) get_option('nibwp_etchwp_feedback', []);
    $row = (array) ($all[$key] ?? ['up' => 0, 'down' => 0, 'recent_down_reasons' => []]);

    if ($rating === 'up') {
        $row['up'] = (int) ($row['up'] ?? 0) + 1;
    } else {
        $row['down'] = (int) ($row['down'] ?? 0) + 1;
        $reasons = (array) ($row['recent_down_reasons'] ?? []);
        array_unshift($reasons, [
            'ts'           => time(),
            'reason'       => $reason,
            'component_id' => $component_id,
        ]);
        $row['recent_down_reasons'] = array_slice($reasons, 0, 10);
    }

    $all[$key] = $row;
    update_option('nibwp_etchwp_feedback', $all, autoload: false);

    return [
        'recorded'   => true,
        'aggregated' => $row,
    ];
}
