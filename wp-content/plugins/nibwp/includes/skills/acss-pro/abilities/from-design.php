<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * ACSS Pro — generate config from design source.
 *
 * The agent extracts palette + scales from the source (screenshot via
 * vision, HTML+CSS via parsing, URL via fetch); the server VALIDATES
 * and returns the structured config back to the agent so the user can
 * approve before the persister runs.
 *
 * No persistence happens here. The persist call is nibwp/acss-pro-update-variables.
 */

wp_register_ability('nibwp/acss-pro-from-design', [
    'label'       => __('ACSS Pro — Config from design', 'nibwp'),
    'description' => __('Validate an agent-built ACSS config extracted from a screenshot / HTML+CSS / URL. Server runs the contrast + scale + luminance rules and returns either {validated: true, config} or {failed:[...], fix_hints}. The agent surfaces failures to the user with copy-paste replacements and resubmits. Persistence is a separate call.', 'nibwp'),
    'category'    => 'acss-pro',
    'input_schema' => [
        'type'       => 'object',
        'required'   => ['config'],
        'properties' => [
            'config' => [
                'type'        => 'object',
                'description' => 'Agent-built ACSS config: { colors: {primary, secondary, background, heading, body, neutral_light, neutral_dark, primary_dark, …}, type: {family_heading, family_body, scale_ratio, size_min, size_max}, space: {scale_ratio, scale[]}, radius: {…}, shadows: {…}, breakpoints: {…} }.',
            ],
            'source' => [
                'type'        => 'object',
                'description' => 'Provenance of the extraction: { kind: "screenshot"|"html"|"url"|"figma", url?, notes? }.',
            ],
            '_preflight_token' => [
                'type'        => 'string',
                'description' => 'Preflight token from nibwp/skill-preflight. REQUIRED.',
            ],
        ],
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success'         => ['type' => 'boolean'],
            'validation'      => ['type' => 'object'],
            'unchecked_items' => ['type' => 'array'],
            'config'          => ['type' => 'object'],
            'next_action'     => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'nibwp_acss_pro_from_design',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => [
            'instructions' => "Extract palette + type/space ramps from the source AGENT-SIDE, build the config, submit here for validation. On pass, ask the user to approve, then call nibwp/acss-pro-update-variables with the same config + token.",
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function nibwp_acss_pro_from_design(array $input): array|WP_Error
{
    if (function_exists('nibwp_skill_gate')) {
        $gate = nibwp_skill_gate('acss-pro');
        if (is_wp_error($gate)) {
            return $gate;
        }
    }

    $raw_token = (string) ($input['_preflight_token'] ?? '');
    if (!function_exists('nibwp_skill_preflight_consume_token')) {
        require_once __DIR__ . '/../../../abilities/skill-preflight.php';
    }
    $token_payload = nibwp_skill_preflight_consume_token($raw_token, 'acss-pro');
    if (is_wp_error($token_payload)) {
        return [
            'success'             => false,
            'requires_user_input' => true,
            'question'            => 'Run nibwp/skill-preflight first for skill_id=acss-pro.',
            'next_action'         => 'call_preflight',
            'error_code'          => $token_payload->get_error_code(),
            'summary'             => $token_payload->get_error_message(),
        ];
    }

    require_once __DIR__ . '/../lib/validator.php';

    $config = (array) ($input['config'] ?? []);
    $validation = nibwp_acss_validate_config($config, [
        'mode' => (string) ($token_payload['answers']['palette_decision'] ?? 'merge_only_new_keys'),
    ]);

    if (!$validation['passed']) {
        nibwp_skill_preflight_bump_attempts($raw_token);
        return [
            'success'         => false,
            'validation'      => $validation,
            'unchecked_items' => $validation['failed'],
            'summary'         => sprintf('Validation failed: %d unchecked item(s). Patch and resubmit.', count($validation['failed'])),
            'next_action'     => 'Fix each failed[] entry (each carries fix_hint) and resubmit.',
        ];
    }

    return [
        'success'    => true,
        'validation' => $validation,
        'config'     => $config,
        'next_action'=> 'Ask the user to confirm. If yes, call nibwp/acss-pro-update-variables with this config + the same _preflight_token.',
    ];
}
