<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * ACSS Pro — standalone config validator ability.
 *
 * Same rules as nibwp/acss-pro-from-design but with no token required
 * (read-only). Use it to lint an existing config before persisting OR to
 * lint a hand-authored config that didn't come through from-design.
 */

wp_register_ability('nibwp/acss-pro-validate-config', [
    'label'       => __('ACSS Pro — Validate config', 'nibwp'),
    'description' => __('Run the ACSS validator rules (contrast / modular scale / luminance / palette delta-E) against a config and return the failed[] list with fix_hints. Read-only — does not require a preflight token.', 'nibwp'),
    'category'    => 'acss-pro',
    'input_schema' => [
        'type'     => 'object',
        'required' => ['config'],
        'properties' => [
            'config' => ['type' => 'object'],
        ],
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'validation' => ['type' => 'object'],
        ],
    ],
    'execute_callback'    => 'nibwp_acss_pro_validate_config',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => [
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function nibwp_acss_pro_validate_config(array $input): array
{
    require_once __DIR__ . '/../lib/validator.php';
    $config = (array) ($input['config'] ?? []);
    $validation = nibwp_acss_validate_config($config);
    return ['validation' => $validation];
}
