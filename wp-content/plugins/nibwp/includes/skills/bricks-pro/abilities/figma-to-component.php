<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Bricks Pro — Figma frame → Bricks template (stub).
 *
 * v1 stub. Agent fetches Figma frame data via its own Figma MCP integration,
 * builds the Bricks payload, submits via nibwp/bricks-pro-html-to-component
 * with source.notes = "figma:{frame_url}".
 */

wp_register_ability('nibwp/bricks-pro-figma-to-component', [
    'label'       => __('Bricks Pro — Figma frame to Bricks template', 'nibwp'),
    'description' => __('Discoverable entry for Figma → Bricks. Real Figma extraction runs agent-side (Figma MCP or screenshot). Agent then submits the built Bricks payload via html-to-component for validation + persistence.', 'nibwp'),
    'category'    => 'bricks-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'figma_url'        => ['type' => 'string', 'description' => 'Figma frame URL or node id.'],
            '_preflight_token' => ['type' => 'string'],
        ],
        'required' => ['_preflight_token'],
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'next_action' => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'nibwp_bricks_pro_figma_to_component',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => [
            'instructions' => 'Use your Figma MCP integration (or take a screenshot of the frame) to extract layout, palette, typography, spacing. Build the Bricks payload per the playbook. Submit via nibwp/bricks-pro-html-to-component with source.notes containing the Figma URL.',
            'readonly'     => false,
            'destructive'  => false,
            'idempotent'   => true,
        ],
    ],
]);

function nibwp_bricks_pro_figma_to_component(array $input): array|WP_Error
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
    return [
        'next_action' => 'Extract Figma frame agent-side, build payload per playbook, submit via nibwp/bricks-pro-html-to-component.',
    ];
}
