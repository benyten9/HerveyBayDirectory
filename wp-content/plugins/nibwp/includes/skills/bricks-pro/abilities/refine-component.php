<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Bricks Pro — refine an existing Bricks template (stub).
 *
 * v1: agent reads the existing template via wp-get-post (post_type=
 * bricks_template, meta _bricks_page_content_2), applies a natural-
 * language refinement to the element tree, re-submits via html-to-component
 * with target.mode=replace_template and target.template_id=N.
 */

wp_register_ability('nibwp/bricks-pro-refine-component', [
    'label'       => __('Bricks Pro — Refine existing template', 'nibwp'),
    'description' => __('Discoverable entry for natural-language refinements to an existing Bricks template. Agent loads the template, applies the instruction, re-submits via html-to-component with mode=replace_template.', 'nibwp'),
    'category'    => 'bricks-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'template_id'      => ['type' => 'integer'],
            'instruction'      => ['type' => 'string'],
            '_preflight_token' => ['type' => 'string'],
        ],
        'required' => ['template_id', 'instruction', '_preflight_token'],
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'current_settings' => ['type' => 'object'],
            'next_action'      => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'nibwp_bricks_pro_refine_component',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => [
            'instructions' => 'Reads the existing template element tree from post meta. Apply the refinement agent-side. Submit the modified payload via nibwp/bricks-pro-html-to-component with target.mode=replace_template + target.template_id=N.',
            'readonly'     => true,
            'destructive'  => false,
            'idempotent'   => true,
        ],
    ],
]);

function nibwp_bricks_pro_refine_component(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('bricks-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }
    $raw_token = (string) ($input['_preflight_token'] ?? '');
    if (!function_exists('nibwp_skill_preflight_consume_token')) {
        require_once __DIR__ . '/../../../abilities/skill-preflight.php';
    }
    $token_payload = nibwp_skill_preflight_consume_token($raw_token, 'bricks-pro', $input);
    if (is_wp_error($token_payload)) {
        return $token_payload;
    }
    $tid = (int) ($input['template_id'] ?? 0);
    if ($tid <= 0) {
        return new WP_Error('refine_no_template', 'template_id is required.');
    }
    $post = get_post($tid);
    if (!$post || $post->post_type !== 'bricks_template') {
        return new WP_Error('refine_not_a_template', sprintf('Post %d is not a bricks_template.', $tid));
    }
    return [
        'current_settings' => [
            'template_type' => (string) get_post_meta($tid, '_bricks_template_settings', true)['templateType'] ?? '',
            'elements'      => (array) get_post_meta($tid, '_bricks_page_content_2', true),
            'title'         => $post->post_title,
        ],
        'next_action' => 'Apply the instruction "' . esc_html((string) ($input['instruction'] ?? '')) . '" agent-side. Resubmit via nibwp/bricks-pro-html-to-component with target.mode=replace_template, target.template_id=' . $tid . ', payload.elements = modified tree.',
    ];
}
