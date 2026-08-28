<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Tutor LMS Builder — refine an existing course.
 *
 * Apply a written change to an existing course: update course-level fields
 * and/or append new topics (with lessons, quizzes and questions). Same
 * validate → persist contract as build-course; requires a _preflight_token.
 */

wp_register_ability('nibwp/tutorlms-builder-refine', [
    'label'       => __('Tutor LMS Builder — Refine course', 'nibwp'),
    'description' => __('Apply a change to an existing Tutor LMS course: update course fields and/or append new topics/lessons/quizzes. dry_run:true validates; dry_run:false commits.', 'nibwp'),
    'category'    => 'tutorlms-builder',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'course_id'   => ['type' => 'integer', 'description' => 'The course to refine.'],
            'instruction' => ['type' => 'string', 'description' => 'The natural-language change (for audit).'],
            'patch'       => ['type' => 'object', 'description' => '{ course?:{ title?, description?, status?, price_type?, price? }, add_topics?:[ topic tree ] }.'],
            'dry_run'     => ['type' => 'boolean', 'default' => true],
            '_preflight_token' => ['type' => 'string', 'description' => 'Token from nibwp/skill-preflight { skill_id:"tutorlms-builder" }. REQUIRED.'],
        ],
        'required' => ['course_id', 'patch', '_preflight_token', 'dry_run'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_tutorlms_builder_refine',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => [
            'instructions' => 'Refine an existing course. patch.course updates course fields; patch.add_topics appends new topics (same tree shape as build-course). Validate with dry_run:true, then commit with dry_run:false.',
            'readonly'    => false,
            'destructive' => true,
            'idempotent'  => false,
        ],
    ],
]);

function nibwp_tutorlms_builder_refine(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('tutorlms-builder');
    if (is_wp_error($gate)) {
        return $gate;
    }

    $raw_token = (string) ($input['_preflight_token'] ?? '');
    $token = nibwp_skill_preflight_consume_token($raw_token, 'tutorlms-builder');
    if (is_wp_error($token)) {
        return [
            'success' => false,
            'requires_user_input' => true,
            'next_action' => 'call_preflight',
            'question' => 'Preflight token invalid or expired. Re-run nibwp/skill-preflight { skill_id:"tutorlms-builder" }.',
        ];
    }

    $course_id = (int) ($input['course_id'] ?? 0);
    if ($course_id <= 0 || get_post_type($course_id) !== (function_exists('nibwp_tutorlms_cpt') ? nibwp_tutorlms_cpt('course') : 'courses')) {
        return new WP_Error('course_not_found', sprintf('Course %d not found.', $course_id));
    }
    $patch = (array) ($input['patch'] ?? []);
    $add_topics = array_values((array) ($patch['add_topics'] ?? []));
    $dry_run = (bool) ($input['dry_run'] ?? true);

    // Validate appended topics (wrap into a temp tree; course-level price checks skipped).
    require_once __DIR__ . '/../lib/validator.php';
    $validation = ['passed' => true, 'unchecked_items' => [], 'recommendations' => [], 'warnings' => []];
    if ($add_topics !== []) {
        $validation = nibwp_tutorlms_builder_validate(
            ['course' => ['title' => '(existing course)', 'price_type' => 'free'], 'topics' => $add_topics],
            (array) ($token['answers'] ?? []),
        );
    }
    if (!$validation['passed']) {
        nibwp_skill_preflight_bump_attempts($raw_token);
        return [
            'success' => false,
            'validation' => $validation,
            'unchecked_items' => $validation['unchecked_items'],
            'attempts_used' => (int) ($token['attempts'] ?? 0) + 1,
            'attempts_max'  => defined('NIBWP_PREFLIGHT_MAX_ATTEMPTS') ? NIBWP_PREFLIGHT_MAX_ATTEMPTS : 3,
        ];
    }
    if ($dry_run) {
        return ['success' => true, 'dry_run' => true, 'validation' => $validation, 'summary' => 'Refine validated. Resubmit dry_run:false to apply.'];
    }

    require_once __DIR__ . '/../lib/persister.php';
    if (!function_exists('nibwp_tutorlms_courses_manage')) {
        $int = WP_PLUGIN_DIR . '/nibwp/includes/premium/integrations/tutorlms.php';
        if (file_exists($int)) {
            require_once $int;
        }
    }
    $diff = ['course_id' => $course_id, 'updated_fields' => [], 'topics' => 0, 'lessons' => 0, 'quizzes' => 0, 'questions' => 0, 'warnings' => []];

    // Course field updates.
    $cpatch = (array) ($patch['course'] ?? []);
    if ($cpatch !== [] && function_exists('nibwp_tutorlms_courses_manage')) {
        $args = ['action' => 'update', 'course_id' => $course_id];
        foreach (['title', 'content', 'excerpt', 'status', 'price', 'price_type'] as $f) {
            if (array_key_exists($f, $cpatch)) {
                $args[$f] = $cpatch[$f];
            }
        }
        if (isset($cpatch['description'])) {
            $args['content'] = $cpatch['description'];
        }
        $r = nibwp_tutorlms_courses_manage($args);
        $diff['updated_fields'] = is_wp_error($r) ? ['error' => $r->get_error_message()] : array_keys($args);
    }

    // Append topics.
    foreach ($add_topics as $topic) {
        $topic = (array) $topic;
        $tres = nibwp_tutorlms_content_manage(['action' => 'create_topic', 'course_id' => $course_id, 'title' => (string) ($topic['title'] ?? 'Untitled topic'), 'summary' => (string) ($topic['summary'] ?? '')]);
        if (is_wp_error($tres)) {
            $diff['warnings'][] = $tres->get_error_message();
            continue;
        }
        $topic_id = (int) ($tres['topic_id'] ?? 0);
        $diff['topics']++;
        foreach (array_values((array) ($topic['lessons'] ?? [])) as $lesson) {
            $lesson = (array) $lesson;
            $lres = nibwp_tutorlms_content_manage(['action' => 'create_lesson', 'topic_id' => $topic_id, 'title' => (string) ($lesson['title'] ?? 'Untitled lesson'), 'content' => (string) ($lesson['content'] ?? '')]);
            if (!is_wp_error($lres)) {
                $diff['lessons']++;
            }
        }
        foreach (array_values((array) ($topic['quizzes'] ?? [])) as $quiz) {
            $quiz = (array) $quiz;
            $qres = nibwp_tutorlms_quizzes_manage(['action' => 'create_quiz', 'topic_id' => $topic_id, 'title' => (string) ($quiz['title'] ?? 'Untitled quiz')]);
            if (is_wp_error($qres)) {
                continue;
            }
            $diff['quizzes']++;
            $diff['questions'] += nibwp_tutorlms_builder_persist_questions((int) ($qres['quiz_id'] ?? 0), array_values((array) ($quiz['questions'] ?? [])));
        }
    }

    nibwp_skill_preflight_clear_token($raw_token);
    return ['success' => true, 'diff' => $diff, 'course_id' => $course_id, 'summary' => sprintf('Refined course %d: +%d topics, +%d lessons, +%d quizzes.', $course_id, $diff['topics'], $diff['lessons'], $diff['quizzes'])];
}
