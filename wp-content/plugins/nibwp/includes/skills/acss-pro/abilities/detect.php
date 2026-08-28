<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * ACSS Pro — detect ability.
 *
 * Probes the current ACSS install + version + settings dump. Returns null
 * fields when ACSS is not active so the agent / preflight can branch.
 */

wp_register_ability('nibwp/acss-pro-detect', [
    'label'       => __('ACSS Pro — Detect installation', 'nibwp'),
    'description' => __('Probe Automatic.css installation. Returns active state, version, and the current settings option contents. Use BEFORE proposing a new config so the agent knows what to merge with.', 'nibwp'),
    'category'    => 'acss-pro',
    'input_schema'  => ['type' => 'object', 'properties' => new \stdClass()],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'active'         => ['type' => 'boolean'],
            'configured'     => ['type' => 'boolean'],
            'version'        => ['type' => 'string'],
            'settings'       => ['type' => 'object'],
            'settings_keys'  => ['type' => 'array'],
        ],
    ],
    'execute_callback'    => 'nibwp_acss_pro_detect',
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

function nibwp_acss_pro_detect(array $input): array
{
    if (function_exists('nibwp_skill_gate')) {
        $gate = nibwp_skill_gate('acss-pro');
        if (is_wp_error($gate)) {
            return ['active' => false, 'configured' => false, 'version' => '', 'settings' => [], 'settings_keys' => [], 'error' => $gate->get_error_message()];
        }
    }

    $active   = nibwp_acss_is_active();
    $settings = $active ? nibwp_acss_read_settings() : [];

    return [
        'active'        => $active,
        // Present and configured are different answers, and conflating them is
        // what let an empty read look like a blank site worth generating over.
        'configured'    => $settings !== [],
        'version'       => nibwp_acss_version(),
        'settings'      => $settings,
        'settings_keys' => array_keys($settings),
        'option_name'   => nibwp_acss_settings_option_name(),
        'read_via'      => nibwp_acss_db_settings() !== null ? 'Automatic_CSS\\Model\\Database_Settings::get_vars' : 'wp_options',
    ];
}
