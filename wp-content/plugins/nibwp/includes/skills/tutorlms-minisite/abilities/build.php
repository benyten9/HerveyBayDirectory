<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Tutor LMS Mini-site — build a course landing page from a section tree.
 * Pipeline ability: validate (dry_run) then persist. Requires a _preflight_token.
 */

wp_register_ability('nibwp/tutorlms-minisite-build', [
    'label'       => __('Tutor LMS Mini-site — Build landing page', 'nibwp'),
    'description' => __('Validate an agent-built section tree and persist it as a styled WordPress landing page linked to a Tutor LMS course. dry_run:true validates; dry_run:false commits.', 'nibwp'),
    'category'    => 'tutorlms-minisite',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'payload' => ['type' => 'object', 'description' => '{ page:{ title, slug? }, sections:[ { type, ... } ] }. Build sections from the course DTO (read it via nibwp/tutorlms-courses action=get).'],
            'dry_run' => ['type' => 'boolean', 'default' => true],
            '_preflight_token' => ['type' => 'string', 'description' => 'Token from nibwp/skill-preflight { skill_id:"tutorlms-minisite" }. REQUIRED.'],
        ],
        'required' => ['payload', '_preflight_token', 'dry_run'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_tutorlms_minisite_build',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Read the course DTO first (nibwp/tutorlms-courses action=get), then synthesize the section tree.',
                'Submit dry_run:true; patch unchecked_items via fix_hint; surface recommendations. Then dry_run:false to persist.',
                'Always include a hero and an enroll CTA pointing at the course URL.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_tutorlms_minisite_build(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('tutorlms-minisite');
    if (is_wp_error($gate)) {
        return $gate;
    }
    $raw_token = (string) ($input['_preflight_token'] ?? '');
    $token = nibwp_skill_preflight_consume_token($raw_token, 'tutorlms-minisite');
    if (is_wp_error($token)) {
        return [
            'success' => false,
            'requires_user_input' => true,
            'next_action' => 'call_preflight',
            'question' => 'Preflight token invalid or expired. Re-run nibwp/skill-preflight { skill_id:"tutorlms-minisite" }.',
        ];
    }

    $answers = (array) ($token['answers'] ?? []);
    $payload = (array) ($input['payload'] ?? []);
    $dry_run = (bool) ($input['dry_run'] ?? true);

    require_once __DIR__ . '/../lib/validator.php';
    $validation = nibwp_tutorlms_minisite_validate($payload, $answers);
    if (!$validation['passed']) {
        nibwp_skill_preflight_bump_attempts($raw_token);
        return [
            'success' => false,
            'validation' => $validation,
            'unchecked_items' => $validation['unchecked_items'],
            'recommendations' => $validation['recommendations'],
            'attempts_used' => (int) ($token['attempts'] ?? 0) + 1,
            'attempts_max'  => defined('NIBWP_PREFLIGHT_MAX_ATTEMPTS') ? NIBWP_PREFLIGHT_MAX_ATTEMPTS : 3,
        ];
    }
    if ($dry_run) {
        return ['success' => true, 'dry_run' => true, 'validation' => $validation, 'recommendations' => $validation['recommendations'], 'summary' => 'Validated. Resubmit dry_run:false to persist.'];
    }

    require_once __DIR__ . '/../lib/persister.php';
    $diff = nibwp_tutorlms_minisite_persist($payload, $answers);
    if (is_wp_error($diff)) {
        return $diff;
    }
    nibwp_skill_preflight_clear_token($raw_token);

    return [
        'success' => true,
        'diff'    => $diff,
        'page_id' => (int) ($diff['page_id'] ?? 0),
        'url'     => (string) ($diff['url'] ?? ''),
        'summary' => sprintf('Built landing page %d (%d sections) for course %d — %s', (int) $diff['page_id'], (int) $diff['sections'], (int) $diff['course_id'], (string) $diff['edit_url']),
        'next_steps' => ['QA on the frontend URL, then call nibwp/tutorlms-minisite-feedback.'],
    ];
}
