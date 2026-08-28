<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * ACSS Pro — persist config to ACSS settings option.
 *
 * Validator + persister run again at this layer (defense in depth): a
 * caller who bypasses from-design can't smuggle an invalid config in.
 */

wp_register_ability('nibwp/acss-pro-update-variables', [
    'label'       => __('ACSS Pro — Update ACSS variables', 'nibwp'),
    'description' => __('Persist a validated ACSS config into the ACSS settings option (mode controlled by preflight: preserve_keep_existing | overwrite_with_extracted | merge_only_new_keys). Re-runs the validator at the persist gate. Requires _preflight_token.', 'nibwp'),
    'category'    => 'acss-pro',
    'input_schema' => [
        'type'     => 'object',
        'required' => ['config', '_preflight_token'],
        'properties' => [
            'config' => ['type' => 'object'],
            '_preflight_token' => ['type' => 'string'],
        ],
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'diff'    => ['type' => 'object'],
            'mode'    => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'nibwp_acss_pro_update_variables',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => [
            'instructions' => 'Destructive — writes the ACSS settings map through ACSS\'s own settings model and recompiles the CSS. Call ONLY after the user has approved the validated config returned by nibwp/acss-pro-from-design.',
            'readonly'    => false,
            'destructive' => true,
            'idempotent'  => false,
        ],
    ],
]);

function nibwp_acss_pro_update_variables(array $input): array|WP_Error
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
        return $token_payload;
    }

    require_once __DIR__ . '/../lib/persister.php';
    $config = (array) ($input['config'] ?? []);
    $mode   = (string) ($token_payload['answers']['palette_decision'] ?? 'merge_only_new_keys');
    $group  = (string) ($token_payload['answers']['target_settings_group'] ?? 'all');

    $diff = nibwp_acss_persist_config($config, [
        'mode'                  => $mode,
        'target_settings_group' => $group,
    ]);
    if (is_wp_error($diff)) {
        nibwp_skill_preflight_bump_attempts($raw_token);
        return $diff;
    }
    nibwp_skill_preflight_clear_token($raw_token);
    return [
        'success' => true,
        'mode'    => $mode,
        'diff'    => $diff,
    ];
}
