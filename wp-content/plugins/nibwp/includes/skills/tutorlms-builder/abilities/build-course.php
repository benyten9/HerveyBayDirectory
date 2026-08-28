<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Tutor LMS Builder — build a full course from a synthesized course tree.
 *
 * Pipeline ability. The agent synthesizes the course tree JSON (see
 * authoring/references/course-schema.md), submits it with dry_run:true to
 * validate, patches any unchecked_items via their fix_hint, then submits
 * dry_run:false to persist. Requires a _preflight_token from nibwp/skill-preflight.
 */

wp_register_ability('nibwp/tutorlms-builder-build-course', [
    'label'       => __('Tutor LMS Builder — Build course', 'nibwp'),
    'description' => __('Validate an agent-built Tutor LMS course tree (course + topics + lessons + quizzes with questions) and persist it through the Tutor LMS integration. dry_run:true validates; dry_run:false commits.', 'nibwp'),
    'category'    => 'tutorlms-builder',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'payload' => ['type' => 'object', 'description' => 'The course tree: { course:{...}, topics:[{ title, summary, lessons:[...], quizzes:[{ title, questions:[...] }] }] }.'],
            'source'  => ['type' => 'string', 'description' => 'What the course was built from (brief, outline, transcript, url, pdf). For audit only.'],
            'dry_run' => ['type' => 'boolean', 'default' => true, 'description' => 'true = validate only; false = persist.'],
            '_preflight_token' => ['type' => 'string', 'description' => 'Token minted by nibwp/skill-preflight { skill_id: "tutorlms-builder" }. REQUIRED.'],
        ],
        'required' => ['payload', '_preflight_token', 'dry_run'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success'         => ['type' => 'boolean'],
            'requires_user_input' => ['type' => 'boolean'],
            'validation'      => ['type' => 'object'],
            'recommendations' => ['type' => 'array'],
            'unchecked_items' => ['type' => 'array'],
            'attempts_used'   => ['type' => 'integer'],
            'attempts_max'    => ['type' => 'integer'],
            'diff'            => ['type' => 'object'],
            'course_id'       => ['type' => 'integer'],
        ],
    ],
    'execute_callback'    => 'nibwp_tutorlms_builder_build_course',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Build a Tutor LMS course AGENT-SIDE as a course tree, then submit here.',
                '1) Call with dry_run:true. If validation.passed is false, fix each unchecked_items[] using its fix_hint and resubmit (up to 3 attempts per token).',
                '2) Surface recommendations[] to the user (group topics, add assessment, prerequisites).',
                '3) Resubmit with dry_run:false to persist. Then call nibwp/tutorlms-builder-feedback.',
                'Pricing + instructor + status come from the preflight answers. enroll/email side-effects are not triggered by building.',
            ]),
            'readonly'    => false,
            'destructive' => true,
            'idempotent'  => false,
        ],
    ],
]);

function nibwp_tutorlms_builder_build_course(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('tutorlms-builder');
    if (is_wp_error($gate)) {
        return $gate;
    }

    $raw_token = (string) ($input['_preflight_token'] ?? '');
    if ($raw_token === '') {
        return [
            'success' => false,
            'requires_user_input' => true,
            'next_action' => 'call_preflight',
            'question' => 'Run nibwp/skill-preflight { skill_id: "tutorlms-builder" } first to obtain a _preflight_token.',
        ];
    }
    $token = nibwp_skill_preflight_consume_token($raw_token, 'tutorlms-builder');
    if (is_wp_error($token)) {
        return [
            'success' => false,
            'requires_user_input' => true,
            'next_action' => 'call_preflight',
            'error_code'  => $token->get_error_code(),
            'question' => 'Preflight token invalid or expired. Re-run nibwp/skill-preflight { skill_id: "tutorlms-builder" }.',
        ];
    }

    $answers = (array) ($token['answers'] ?? []);
    $payload = (array) ($input['payload'] ?? []);
    $dry_run = (bool) ($input['dry_run'] ?? true);

    require_once __DIR__ . '/../lib/validator.php';
    $validation = nibwp_tutorlms_builder_validate($payload, $answers);

    $max = defined('NIBWP_PREFLIGHT_MAX_ATTEMPTS') ? NIBWP_PREFLIGHT_MAX_ATTEMPTS : 3;

    if (!$validation['passed']) {
        nibwp_skill_preflight_bump_attempts($raw_token);
        return [
            'success'         => false,
            'validation'      => $validation,
            'unchecked_items' => $validation['unchecked_items'],
            'recommendations' => $validation['recommendations'],
            'attempts_used'   => (int) ($token['attempts'] ?? 0) + 1,
            'attempts_max'    => $max,
            'summary'         => 'Validation failed. Patch each unchecked_items[] via its fix_hint and resubmit dry_run:true.',
        ];
    }

    if ($dry_run) {
        return [
            'success'         => true,
            'dry_run'         => true,
            'validation'      => $validation,
            'recommendations' => $validation['recommendations'],
            'summary'         => 'Validation passed. Surface recommendations, then resubmit with dry_run:false to persist.',
        ];
    }

    require_once __DIR__ . '/../lib/persister.php';
    $diff = nibwp_tutorlms_builder_persist($payload, $answers);
    if (is_wp_error($diff)) {
        return $diff;
    }

    nibwp_skill_preflight_clear_token($raw_token);

    return [
        'success'    => true,
        'validation' => $validation,
        'diff'       => $diff,
        'course_id'  => (int) ($diff['course_id'] ?? 0),
        'edit_url'   => (string) ($diff['edit_url'] ?? ''),
        'summary'    => sprintf('Persisted course %d: %d topics, %d lessons, %d quizzes, %d questions.', (int) $diff['course_id'], (int) $diff['topics'], (int) $diff['lessons'], (int) $diff['quizzes'], (int) $diff['questions']),
        'next_steps' => ['Call nibwp/tutorlms-builder-feedback with the user thumb-up/down.'],
    ];
}
