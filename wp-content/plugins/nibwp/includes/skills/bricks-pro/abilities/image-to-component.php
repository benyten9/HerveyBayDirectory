<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Bricks Pro — Image → Bricks template.
 *
 * Thin entry point. Agent runs vision on the image, builds the Bricks
 * element tree + global classes, then resubmits via the main
 * `nibwp/bricks-pro-html-to-component` ability with `source.url` set.
 *
 * This ability exists as a discoverable entry — the actual validation +
 * persistence happens in html-to-component.
 */

wp_register_ability('nibwp/bricks-pro-image-to-component', [
    'label'       => __('Bricks Pro — Image to Bricks template', 'nibwp'),
    'description' => __('Agent extracts the layout from an image / screenshot URL, builds the Bricks element tree, then submits via html-to-component for validation + persistence. This ability is the discoverable entry; routing happens server-side.', 'nibwp'),
    'category'    => 'bricks-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'image_url'        => ['type' => 'string', 'description' => 'URL to the source image / screenshot (publicly accessible OR a media-library attachment URL).'],
            'attachment_id'    => ['type' => 'integer', 'description' => 'Alternative to image_url: a media-library attachment ID.'],
            '_preflight_token' => ['type' => 'string'],
        ],
        'required' => ['_preflight_token'],
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'next_action' => ['type' => 'string'],
            'summary'     => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'nibwp_bricks_pro_image_to_component',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => [
            'instructions' => "Use vision on the image / screenshot URL. Identify layout sections, repeating cards, copy, image placement. Build a Bricks element tree following the playbook (catalog elements only, global classes, ACSS tokens). Submit via nibwp/bricks-pro-html-to-component with source.url=<image_url> and payload={ template_type, elements, global_classes }.",
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function nibwp_bricks_pro_image_to_component(array $input): array|WP_Error
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
        'next_action' => 'Run vision on the image, build the Bricks element tree per the playbook, then submit nibwp/bricks-pro-html-to-component with source.url + payload. The _preflight_token from the preflight call stays valid across the chain.',
        'summary'     => 'Image-to-Bricks entry — actual conversion runs agent-side, validation server-side via html-to-component.',
    ];
}
