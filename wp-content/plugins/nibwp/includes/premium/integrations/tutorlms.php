<?php

declare(strict_types=1);

/**
 * Tutor LMS integration — NIBWP MCP abilities (Layer 1 adapter).
 *
 * Seven domain-grouped abilities expose full read/write control of a Tutor LMS
 * site to an AI agent: courses, content (topics/lessons/announcements),
 * students (enrollment/progress), quizzes (+assignments), instructors,
 * monetization, and reports.
 *
 * Mechanism is HYBRID and IN-PROCESS — these run inside the same WP request as
 * the MCP call, so we prefer Tutor's own PHP paths in this order:
 *   1. Tutor\Models\* model methods (the canonical write paths)
 *   2. tutor_utils() helper methods
 *   3. raw CPT + post_meta (wp_insert_post / update_post_meta)
 *   4. raw $wpdb on Tutor's custom tables (reads only)
 * No loopback REST: Tutor's tutor/v1 is read-mostly + API-key-auth'd, and every
 * endpoint has an in-process equivalent above.
 *
 * Detection: TUTOR_VERSION. Pro features gate per-addon via
 * tutor_utils()->is_addon_enabled(), not merely TUTOR_PRO_VERSION.
 *
 * Verified against Tutor LMS 3.9.12 + Tutor Pro 3.9.11 source.
 */

if (!defined('ABSPATH')) {
    exit();
}

/* ----------------------------------------------------------------------------
 * Shared helpers (file-local)
 * ------------------------------------------------------------------------- */

/** Is Tutor LMS active on this site? */
function nibwp_tutorlms_available(): bool
{
    return defined('TUTOR_VERSION') && function_exists('tutor_utils');
}

/** House WP_Error wrapper. */
function nibwp_tutorlms_err(string $code, string $message): WP_Error
{
    return new WP_Error($code, $message);
}

/**
 * Resolve a Tutor post-type slug, preferring the live tutor() instance and
 * falling back to the verified literal.
 *
 * @param string $which course|lesson|topic|quiz|assignment|enrolled|announcement
 */
function nibwp_tutorlms_cpt(string $which): string
{
    $t = function_exists('tutor') ? tutor() : null;
    return match ($which) {
        'course'       => $t && isset($t->course_post_type) ? (string) $t->course_post_type : 'courses',
        'lesson'       => $t && isset($t->lesson_post_type) ? (string) $t->lesson_post_type : 'lesson',
        'announcement' => $t && isset($t->announcement_post_type) ? (string) $t->announcement_post_type : 'tutor_announcements',
        'topic'        => 'topics',
        'quiz'         => 'tutor_quiz',
        'assignment'   => 'tutor_assignments',
        'enrolled'     => 'tutor_enrolled',
        default        => '',
    };
}

/** Clamp pagination to the house 1..100 window. */
function nibwp_tutorlms_paginate(array $in): array
{
    return [
        'per_page' => min(max((int) ($in['per_page'] ?? 20), 1), 100),
        'page'     => max((int) ($in['page'] ?? 1), 1),
    ];
}

/**
 * Capability + engine probe. Single source of truth for Pro/addon/engine gating.
 *
 * @return array{pro:bool, monetize_by:string, has_wc:bool, has_edd:bool, assignments:bool, multi_instructor:bool, prerequisites:bool, course_bundle:bool, reports:bool, certificate:bool, subscription:bool}
 */
function nibwp_tutorlms_caps(): array
{
    $pro = defined('TUTOR_PRO_VERSION');
    $u   = function_exists('tutor_utils') ? tutor_utils() : null;
    $addon = static function (string $slug) use ($pro, $u): bool {
        return $pro && $u && method_exists($u, 'is_addon_enabled') && $u->is_addon_enabled($slug);
    };

    return [
        'pro'              => $pro,
        'monetize_by'      => function_exists('get_tutor_option') ? (string) get_tutor_option('monetize_by', 'tutor') : 'tutor',
        'has_wc'           => $u && method_exists($u, 'has_wc') ? (bool) $u->has_wc() : false,
        'has_edd'          => $u && method_exists($u, 'has_edd') ? (bool) $u->has_edd() : false,
        'assignments'      => $addon('tutor-assignments'),
        'multi_instructor' => $addon('tutor-multi-instructors'),
        'prerequisites'    => $addon('tutor-prerequisites'),
        'course_bundle'    => $addon('course-bundle'),
        'reports'          => $addon('tutor-report'),
        'certificate'      => $addon('tutor-certificate'),
        'subscription'     => $addon('subscription'),
    ];
}

/** Return a WP_Error when a Pro-addon-gated action runs without the addon enabled. */
function nibwp_tutorlms_require_pro(string $cap_key, array $caps): ?WP_Error
{
    if (empty($caps[$cap_key])) {
        return nibwp_tutorlms_err('tutor_pro_required', sprintf(
            'This action requires Tutor Pro with the "%s" addon enabled.',
            $cap_key,
        ));
    }
    return null;
}

/**
 * STABLE COURSE DTO — the frozen, additive-only shape L2 (mini-sites) and
 * L4 (marketplace) consume so they never read Tutor internals directly.
 *
 * @return array<string,mixed>|null
 */
function nibwp_tutorlms_course_shape(int $course_id): ?array
{
    $post = get_post($course_id);
    if (!$post || $post->post_type !== nibwp_tutorlms_cpt('course')) {
        return null;
    }
    $u = tutor_utils();
    $caps = nibwp_tutorlms_caps();
    $price_type = function_exists('get_post_meta') ? (string) get_post_meta($course_id, '_tutor_course_price_type', true) : '';

    return [
        'id'               => $course_id,
        'title'            => $post->post_title,
        'status'           => $post->post_status,
        'slug'             => $post->post_name,
        'price'            => $u && method_exists($u, 'get_course_price') ? $u->get_course_price($course_id) : null,
        'raw_price'        => $u && method_exists($u, 'get_raw_course_price') ? $u->get_raw_course_price($course_id) : null,
        'price_type'       => $price_type !== '' ? $price_type : 'free',
        'product_id'       => (int) get_post_meta($course_id, '_tutor_course_product_id', true),
        'engine'           => $caps['monetize_by'],
        'topics_count'     => is_array($u?->get_topics($course_id)) ? count((array) $u->get_topics($course_id)) : 0,
        'enrolled_count'   => $u && method_exists($u, 'count_enrolled_users_by_course') ? (int) $u->count_enrolled_users_by_course($course_id) : null,
        'instructor_ids'   => class_exists('Tutor\\Models\\CourseModel') && method_exists('Tutor\\Models\\CourseModel', 'get_course_instructor_ids')
            ? array_map('intval', (array) \Tutor\Models\CourseModel::get_course_instructor_ids($course_id))
            : [],
        'prerequisite_ids' => array_map('intval', (array) maybe_unserialize(get_post_meta($course_id, '_tutor_course_prerequisites_ids', true) ?: [])),
        'category_ids'     => wp_get_post_terms($course_id, 'course-category', ['fields' => 'ids']) ?: [],
    ];
}

/* ----------------------------------------------------------------------------
 * Ability 1 — nibwp/tutorlms-courses
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/tutorlms-courses', [
    'label'       => __('Tutor LMS — Courses', domain: 'nibwp'),
    'description' => __('Create, read, update, delete and price Tutor LMS courses, plus prerequisites and bundles.', domain: 'nibwp'),
    'category'    => 'lms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list', 'get', 'create', 'update', 'delete', 'pricing_summary', 'get_prerequisites', 'set_prerequisites', 'list_bundles', 'get_bundle'],
                'description' => 'The action to perform.',
            ],
            'course_id'        => ['type' => 'integer', 'description' => 'Course post ID.'],
            'title'            => ['type' => 'string'],
            'content'          => ['type' => 'string'],
            'excerpt'          => ['type' => 'string'],
            'status'           => ['type' => 'string', 'enum' => ['publish', 'draft', 'pending', 'private']],
            'price'            => ['type' => 'number'],
            'price_type'       => ['type' => 'string', 'enum' => ['free', 'paid']],
            'category_ids'     => ['type' => 'array', 'items' => ['type' => 'integer']],
            'prerequisite_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'bundle_id'        => ['type' => 'integer'],
            'per_page'         => ['type' => 'integer', 'default' => 20],
            'page'             => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_tutorlms_courses_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage Tutor LMS courses.',
                'ACTIONS: list, get, create, update, delete (DESTRUCTIVE — cascades to topics/lessons/quizzes),',
                'pricing_summary (price + engine), get_prerequisites, set_prerequisites [Pro addon],',
                'list_bundles / get_bundle [Pro addon].',
                'Call nibwp/tutorlms-monetization detect_engine before touching price. Requires Tutor LMS active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_tutorlms_courses_manage(array $input): array|WP_Error
{
    if (!nibwp_tutorlms_available()) {
        return nibwp_tutorlms_err('tutorlms_not_active', 'Tutor LMS is not active.');
    }
    $caps = nibwp_tutorlms_caps();
    $cpt  = nibwp_tutorlms_cpt('course');
    ['per_page' => $per_page, 'page' => $page] = nibwp_tutorlms_paginate($input);
    $action = (string) ($input['action'] ?? '');

    try {
        switch ($action) {
            case 'list':
                $q = new WP_Query([
                    'post_type'      => $cpt,
                    'post_status'    => ['publish', 'draft', 'pending', 'private'],
                    'posts_per_page' => $per_page,
                    'paged'          => $page,
                    's'              => isset($input['search']) ? sanitize_text_field((string) $input['search']) : '',
                ]);
                $courses = array_map(static fn(WP_Post $p) => [
                    'id' => $p->ID, 'title' => $p->post_title, 'status' => $p->post_status, 'slug' => $p->post_name, 'date' => $p->post_date,
                ], $q->posts);
                return ['courses' => $courses, 'total' => (int) $q->found_posts, 'page' => $page];

            case 'get':
                $cid = (int) ($input['course_id'] ?? 0);
                $dto = $cid > 0 ? nibwp_tutorlms_course_shape($cid) : null;
                if ($dto === null) {
                    return nibwp_tutorlms_err('course_not_found', sprintf('Course %d not found.', $cid));
                }
                $post = get_post($cid);
                $dto['content'] = $post->post_content;
                $dto['excerpt'] = $post->post_excerpt;
                return ['course' => $dto];

            case 'create':
                if (empty($input['title'])) {
                    return nibwp_tutorlms_err('missing_params', 'title is required to create a course.');
                }
                $cid = wp_insert_post([
                    'post_type'    => $cpt,
                    'post_title'   => sanitize_text_field((string) $input['title']),
                    'post_content' => wp_kses_post((string) ($input['content'] ?? '')),
                    'post_excerpt' => sanitize_text_field((string) ($input['excerpt'] ?? '')),
                    'post_status'  => in_array($input['status'] ?? '', ['publish', 'draft', 'pending', 'private'], true) ? $input['status'] : 'draft',
                ], true);
                if (is_wp_error($cid)) {
                    return $cid;
                }
                nibwp_tutorlms_apply_course_meta((int) $cid, $input);
                return ['course' => nibwp_tutorlms_course_shape((int) $cid), 'created' => true];

            case 'update':
                $cid = (int) ($input['course_id'] ?? 0);
                if ($cid <= 0 || get_post_type($cid) !== $cpt) {
                    return nibwp_tutorlms_err('course_not_found', sprintf('Course %d not found.', $cid));
                }
                $patch = ['ID' => $cid];
                if (isset($input['title'])) {
                    $patch['post_title'] = sanitize_text_field((string) $input['title']);
                }
                if (isset($input['content'])) {
                    $patch['post_content'] = wp_kses_post((string) $input['content']);
                }
                if (isset($input['excerpt'])) {
                    $patch['post_excerpt'] = sanitize_text_field((string) $input['excerpt']);
                }
                if (isset($input['status']) && in_array($input['status'], ['publish', 'draft', 'pending', 'private'], true)) {
                    $patch['post_status'] = $input['status'];
                }
                $r = wp_update_post($patch, true);
                if (is_wp_error($r)) {
                    return $r;
                }
                nibwp_tutorlms_apply_course_meta($cid, $input);
                return ['course' => nibwp_tutorlms_course_shape($cid), 'updated' => true];

            case 'delete':
                $cid = (int) ($input['course_id'] ?? 0);
                if ($cid <= 0 || get_post_type($cid) !== $cpt) {
                    return nibwp_tutorlms_err('course_not_found', sprintf('Course %d not found.', $cid));
                }
                if (class_exists('Tutor\\Models\\CourseModel') && method_exists('Tutor\\Models\\CourseModel', 'delete_course')) {
                    \Tutor\Models\CourseModel::delete_course($cid);
                } else {
                    wp_delete_post($cid, true);
                }
                return ['deleted' => true, 'course_id' => $cid];

            case 'pricing_summary':
                $cid = (int) ($input['course_id'] ?? 0);
                if ($cid <= 0 || get_post_type($cid) !== $cpt) {
                    return nibwp_tutorlms_err('course_not_found', sprintf('Course %d not found.', $cid));
                }
                $u = tutor_utils();
                return ['pricing' => [
                    'course_id'  => $cid,
                    'price'      => method_exists($u, 'get_course_price') ? $u->get_course_price($cid) : null,
                    'raw_price'  => method_exists($u, 'get_raw_course_price') ? $u->get_raw_course_price($cid) : null,
                    'price_type' => (string) (get_post_meta($cid, '_tutor_course_price_type', true) ?: 'free'),
                    'product_id' => (int) get_post_meta($cid, '_tutor_course_product_id', true),
                    'engine'     => $caps['monetize_by'],
                ], 'engine' => $caps['monetize_by']];

            case 'get_prerequisites':
                $cid = (int) ($input['course_id'] ?? 0);
                $ids = array_map('intval', (array) maybe_unserialize(get_post_meta($cid, '_tutor_course_prerequisites_ids', true) ?: []));
                return ['prerequisite_ids' => $ids];

            case 'set_prerequisites':
                if ($err = nibwp_tutorlms_require_pro('prerequisites', $caps)) {
                    return $err;
                }
                $cid = (int) ($input['course_id'] ?? 0);
                if ($cid <= 0 || get_post_type($cid) !== $cpt) {
                    return nibwp_tutorlms_err('course_not_found', sprintf('Course %d not found.', $cid));
                }
                $ids = array_values(array_unique(array_map('intval', (array) ($input['prerequisite_ids'] ?? []))));
                update_post_meta($cid, '_tutor_course_prerequisites_ids', $ids);
                return ['prerequisite_ids' => $ids, 'updated' => true];

            case 'list_bundles':
                if ($err = nibwp_tutorlms_require_pro('course_bundle', $caps)) {
                    return $err;
                }
                $q = new WP_Query(['post_type' => 'course-bundle', 'post_status' => 'publish', 'posts_per_page' => $per_page, 'paged' => $page]);
                return ['bundles' => array_map(static fn(WP_Post $p) => ['id' => $p->ID, 'title' => $p->post_title, 'status' => $p->post_status], $q->posts), 'total' => (int) $q->found_posts];

            case 'get_bundle':
                if ($err = nibwp_tutorlms_require_pro('course_bundle', $caps)) {
                    return $err;
                }
                $bid = (int) ($input['bundle_id'] ?? 0);
                $p = get_post($bid);
                if (!$p || $p->post_type !== 'course-bundle') {
                    return nibwp_tutorlms_err('bundle_not_found', sprintf('Bundle %d not found.', $bid));
                }
                return ['bundle' => ['id' => $p->ID, 'title' => $p->post_title, 'content' => $p->post_content, 'status' => $p->post_status]];

            default:
                return nibwp_tutorlms_err('invalid_action', sprintf('Unknown action: %s', $action));
        }
    } catch (\Throwable $e) {
        return nibwp_tutorlms_err('tutorlms_error', $e->getMessage());
    }
}

/** Apply price/price_type/category meta on create+update. */
function nibwp_tutorlms_apply_course_meta(int $course_id, array $input): void
{
    if (isset($input['price_type']) && in_array($input['price_type'], ['free', 'paid'], true)) {
        update_post_meta($course_id, '_tutor_course_price_type', $input['price_type']);
    }
    if (isset($input['price'])) {
        update_post_meta($course_id, 'tutor_course_price', (float) $input['price']);
    }
    if (isset($input['category_ids']) && is_array($input['category_ids'])) {
        wp_set_post_terms($course_id, array_map('intval', $input['category_ids']), 'course-category');
    }
}

/* ----------------------------------------------------------------------------
 * Ability 2 — nibwp/tutorlms-content (topics, lessons, announcements)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/tutorlms-content', [
    'label'       => __('Tutor LMS — Content', domain: 'nibwp'),
    'description' => __('Manage Tutor LMS topics, lessons and course announcements (CRUD + reordering).', domain: 'nibwp'),
    'category'    => 'lms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list_topics', 'create_topic', 'update_topic', 'delete_topic', 'reorder_topics', 'list_lessons', 'get_lesson', 'create_lesson', 'update_lesson', 'delete_lesson', 'reorder_lessons', 'list_announcements', 'create_announcement', 'delete_announcement'],
                'description' => 'The action to perform.',
            ],
            'course_id'       => ['type' => 'integer'],
            'topic_id'        => ['type' => 'integer'],
            'lesson_id'       => ['type' => 'integer'],
            'announcement_id' => ['type' => 'integer'],
            'title'           => ['type' => 'string'],
            'content'         => ['type' => 'string'],
            'summary'         => ['type' => 'string'],
            'order'           => ['type' => 'array', 'items' => ['type' => 'integer']],
            'per_page'        => ['type' => 'integer', 'default' => 20],
            'page'            => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_tutorlms_content_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage Tutor LMS course content.',
                'Topics belong to a course (course_id); lessons belong to a topic (topic_id).',
                'ACTIONS: list_topics, create_topic, update_topic, delete_topic, reorder_topics(order=[topic_id,...]),',
                'list_lessons, get_lesson, create_lesson, update_lesson, delete_lesson, reorder_lessons(order=[lesson_id,...]),',
                'list_announcements, create_announcement, delete_announcement. delete_* is destructive.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_tutorlms_content_manage(array $input): array|WP_Error
{
    if (!nibwp_tutorlms_available()) {
        return nibwp_tutorlms_err('tutorlms_not_active', 'Tutor LMS is not active.');
    }
    ['per_page' => $per_page, 'page' => $page] = nibwp_tutorlms_paginate($input);
    $action = (string) ($input['action'] ?? '');
    $topic_cpt        = nibwp_tutorlms_cpt('topic');
    $lesson_cpt       = nibwp_tutorlms_cpt('lesson');
    $announcement_cpt = nibwp_tutorlms_cpt('announcement');

    try {
        switch ($action) {
            case 'list_topics':
                $cid = (int) ($input['course_id'] ?? 0);
                $topics = tutor_utils()->get_topics($cid);
                $rows = [];
                foreach (($topics->posts ?? (is_array($topics) ? $topics : [])) as $p) {
                    $rows[] = ['id' => $p->ID, 'title' => $p->post_title, 'summary' => $p->post_content, 'order' => $p->menu_order];
                }
                return ['topics' => $rows, 'total' => count($rows)];

            case 'create_topic':
                $cid = (int) ($input['course_id'] ?? 0);
                if ($cid <= 0 || get_post_type($cid) !== nibwp_tutorlms_cpt('course')) {
                    return nibwp_tutorlms_err('course_not_found', sprintf('Course %d not found.', $cid));
                }
                $tid = wp_insert_post([
                    'post_type'    => $topic_cpt,
                    'post_parent'  => $cid,
                    'post_title'   => sanitize_text_field((string) ($input['title'] ?? 'Untitled topic')),
                    'post_content' => sanitize_textarea_field((string) ($input['summary'] ?? $input['content'] ?? '')),
                    'post_status'  => 'publish',
                ], true);
                return is_wp_error($tid) ? $tid : ['topic_id' => (int) $tid, 'created' => true];

            case 'update_topic':
                $tid = (int) ($input['topic_id'] ?? 0);
                if (get_post_type($tid) !== $topic_cpt) {
                    return nibwp_tutorlms_err('topic_not_found', sprintf('Topic %d not found.', $tid));
                }
                $patch = ['ID' => $tid];
                if (isset($input['title'])) {
                    $patch['post_title'] = sanitize_text_field((string) $input['title']);
                }
                if (isset($input['summary']) || isset($input['content'])) {
                    $patch['post_content'] = sanitize_textarea_field((string) ($input['summary'] ?? $input['content']));
                }
                $r = wp_update_post($patch, true);
                return is_wp_error($r) ? $r : ['topic_id' => $tid, 'updated' => true];

            case 'delete_topic':
                $tid = (int) ($input['topic_id'] ?? 0);
                if (get_post_type($tid) !== $topic_cpt) {
                    return nibwp_tutorlms_err('topic_not_found', sprintf('Topic %d not found.', $tid));
                }
                wp_delete_post($tid, true);
                return ['deleted' => true, 'topic_id' => $tid];

            case 'reorder_topics':
            case 'reorder_lessons':
                $ids = array_map('intval', (array) ($input['order'] ?? []));
                $i = 0;
                foreach ($ids as $pid) {
                    wp_update_post(['ID' => $pid, 'menu_order' => $i++]);
                }
                return ['reordered' => count($ids)];

            case 'list_lessons':
                $tid = (int) ($input['topic_id'] ?? 0);
                $q = new WP_Query([
                    'post_type'      => $lesson_cpt,
                    'post_parent'    => $tid,
                    'posts_per_page' => $per_page,
                    'paged'          => $page,
                    'orderby'        => 'menu_order',
                    'order'          => 'ASC',
                ]);
                return ['lessons' => array_map(static fn(WP_Post $p) => ['id' => $p->ID, 'title' => $p->post_title, 'order' => $p->menu_order], $q->posts), 'total' => (int) $q->found_posts];

            case 'get_lesson':
                $lid = (int) ($input['lesson_id'] ?? 0);
                $p = get_post($lid);
                if (!$p || $p->post_type !== $lesson_cpt) {
                    return nibwp_tutorlms_err('lesson_not_found', sprintf('Lesson %d not found.', $lid));
                }
                return ['lesson' => [
                    'id' => $p->ID, 'title' => $p->post_title, 'content' => $p->post_content, 'topic_id' => $p->post_parent,
                    'video' => maybe_unserialize(get_post_meta($lid, '_video', true) ?: []),
                    'attachments' => maybe_unserialize(get_post_meta($lid, '_tutor_attachments', true) ?: []),
                ]];

            case 'create_lesson':
                $tid = (int) ($input['topic_id'] ?? 0);
                if (get_post_type($tid) !== $topic_cpt) {
                    return nibwp_tutorlms_err('topic_not_found', sprintf('Topic %d not found.', $tid));
                }
                $lid = wp_insert_post([
                    'post_type'    => $lesson_cpt,
                    'post_parent'  => $tid,
                    'post_title'   => sanitize_text_field((string) ($input['title'] ?? 'Untitled lesson')),
                    'post_content' => wp_kses_post((string) ($input['content'] ?? '')),
                    'post_status'  => 'publish',
                ], true);
                return is_wp_error($lid) ? $lid : ['lesson_id' => (int) $lid, 'created' => true];

            case 'update_lesson':
                $lid = (int) ($input['lesson_id'] ?? 0);
                if (get_post_type($lid) !== $lesson_cpt) {
                    return nibwp_tutorlms_err('lesson_not_found', sprintf('Lesson %d not found.', $lid));
                }
                $patch = ['ID' => $lid];
                if (isset($input['title'])) {
                    $patch['post_title'] = sanitize_text_field((string) $input['title']);
                }
                if (isset($input['content'])) {
                    $patch['post_content'] = wp_kses_post((string) $input['content']);
                }
                $r = wp_update_post($patch, true);
                return is_wp_error($r) ? $r : ['lesson_id' => $lid, 'updated' => true];

            case 'delete_lesson':
                $lid = (int) ($input['lesson_id'] ?? 0);
                if (get_post_type($lid) !== $lesson_cpt) {
                    return nibwp_tutorlms_err('lesson_not_found', sprintf('Lesson %d not found.', $lid));
                }
                wp_delete_post($lid, true);
                return ['deleted' => true, 'lesson_id' => $lid];

            case 'list_announcements':
                $cid = (int) ($input['course_id'] ?? 0);
                $q = new WP_Query(['post_type' => $announcement_cpt, 'post_parent' => $cid, 'posts_per_page' => $per_page, 'paged' => $page]);
                return ['announcements' => array_map(static fn(WP_Post $p) => ['id' => $p->ID, 'title' => $p->post_title, 'content' => $p->post_content, 'date' => $p->post_date], $q->posts), 'total' => (int) $q->found_posts];

            case 'create_announcement':
                $cid = (int) ($input['course_id'] ?? 0);
                if ($cid <= 0 || get_post_type($cid) !== nibwp_tutorlms_cpt('course')) {
                    return nibwp_tutorlms_err('course_not_found', sprintf('Course %d not found.', $cid));
                }
                $aid = wp_insert_post([
                    'post_type'    => $announcement_cpt,
                    'post_parent'  => $cid,
                    'post_title'   => sanitize_text_field((string) ($input['title'] ?? '')),
                    'post_content' => wp_kses_post((string) ($input['content'] ?? '')),
                    'post_status'  => 'publish',
                ], true);
                return is_wp_error($aid) ? $aid : ['announcement_id' => (int) $aid, 'created' => true];

            case 'delete_announcement':
                $aid = (int) ($input['announcement_id'] ?? 0);
                if (get_post_type($aid) !== $announcement_cpt) {
                    return nibwp_tutorlms_err('announcement_not_found', sprintf('Announcement %d not found.', $aid));
                }
                wp_delete_post($aid, true);
                return ['deleted' => true, 'announcement_id' => $aid];

            default:
                return nibwp_tutorlms_err('invalid_action', sprintf('Unknown action: %s', $action));
        }
    } catch (\Throwable $e) {
        return nibwp_tutorlms_err('tutorlms_error', $e->getMessage());
    }
}

/* ----------------------------------------------------------------------------
 * Ability 3 — nibwp/tutorlms-students (enrollment + progress)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/tutorlms-students', [
    'label'       => __('Tutor LMS — Students', domain: 'nibwp'),
    'description' => __('Enroll/unenroll students, list enrollments and read/update course progress.', domain: 'nibwp'),
    'category'    => 'lms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['enroll', 'unenroll', 'list_enrollments', 'get_progress', 'mark_lesson_complete', 'mark_course_complete', 'reset_progress'],
                'description' => 'The action to perform.',
            ],
            'course_id'  => ['type' => 'integer'],
            'user_id'    => ['type' => 'integer'],
            'lesson_id'  => ['type' => 'integer'],
            'hard'       => ['type' => 'boolean', 'description' => 'unenroll: permanently delete the enrollment record.'],
            'send_email' => ['type' => 'boolean', 'default' => false, 'description' => 'enroll: fire Tutor hooks (emails + earnings). Default false.'],
            'per_page'   => ['type' => 'integer', 'default' => 20],
            'page'       => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_tutorlms_students_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage Tutor LMS enrollments + progress.',
                'enroll(course_id,user_id): send_email defaults FALSE to suppress enrollment emails/earnings (do_enroll fire_hook=false). Re-enroll is idempotent.',
                'unenroll(course_id,user_id, hard?): hard=true permanently deletes the enrollment record.',
                'list_enrollments(course_id), get_progress(course_id,user_id), mark_lesson_complete(lesson_id,user_id),',
                'mark_course_complete(course_id,user_id), reset_progress(course_id,user_id) [DESTRUCTIVE].',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_tutorlms_students_manage(array $input): array|WP_Error
{
    if (!nibwp_tutorlms_available()) {
        return nibwp_tutorlms_err('tutorlms_not_active', 'Tutor LMS is not active.');
    }
    ['per_page' => $per_page, 'page' => $page] = nibwp_tutorlms_paginate($input);
    $action = (string) ($input['action'] ?? '');
    $u = tutor_utils();

    try {
        switch ($action) {
            case 'enroll':
                $cid = (int) ($input['course_id'] ?? 0);
                $uid = (int) ($input['user_id'] ?? 0);
                if ($cid <= 0 || $uid <= 0) {
                    return nibwp_tutorlms_err('missing_params', 'course_id and user_id are required.');
                }
                if (!get_userdata($uid)) {
                    return nibwp_tutorlms_err('user_not_found', sprintf('User %d not found.', $uid));
                }
                $fire = (bool) ($input['send_email'] ?? false);
                $enrol_id = $u->do_enroll($cid, 0, $uid, $fire);
                return ['enrolled' => (bool) $enrol_id, 'enrollment_id' => (int) $enrol_id, 'fired_hooks' => $fire];

            case 'unenroll':
                $cid = (int) ($input['course_id'] ?? 0);
                $uid = (int) ($input['user_id'] ?? 0);
                if (!empty($input['hard']) && method_exists($u, 'delete_enrollment_record')) {
                    $ok = $u->delete_enrollment_record($uid, $cid);
                    return ['unenrolled' => (bool) $ok, 'hard' => true];
                }
                if (method_exists($u, 'cancel_course_enrol')) {
                    $u->cancel_course_enrol($cid, $uid, 'canceled');
                    return ['unenrolled' => true, 'hard' => false];
                }
                return nibwp_tutorlms_err('tutorlms_error', 'No unenroll path available in this Tutor version.');

            case 'list_enrollments':
                $cid = (int) ($input['course_id'] ?? 0);
                $q = new WP_Query([
                    'post_type'      => nibwp_tutorlms_cpt('enrolled'),
                    'post_parent'    => $cid,
                    'post_status'    => 'completed',
                    'posts_per_page' => $per_page,
                    'paged'          => $page,
                ]);
                $rows = array_map(static fn(WP_Post $p) => [
                    'enrollment_id' => $p->ID, 'user_id' => (int) $p->post_author, 'date' => $p->post_date, 'status' => $p->post_status,
                ], $q->posts);
                return ['enrollments' => $rows, 'total' => (int) $q->found_posts];

            case 'get_progress':
                $cid = (int) ($input['course_id'] ?? 0);
                $uid = (int) ($input['user_id'] ?? 0);
                return ['progress' => [
                    'course_id' => $cid,
                    'user_id'   => $uid,
                    'enrolled'  => method_exists($u, 'is_enrolled') ? (bool) $u->is_enrolled($cid, $uid) : null,
                    'percent'   => method_exists($u, 'get_course_completed_percent') ? $u->get_course_completed_percent($cid, $uid) : null,
                ]];

            case 'mark_lesson_complete':
                $lid = (int) ($input['lesson_id'] ?? 0);
                $uid = (int) ($input['user_id'] ?? 0) ?: get_current_user_id();
                if (class_exists('Tutor\\Models\\LessonModel') && method_exists('Tutor\\Models\\LessonModel', 'mark_lesson_complete')) {
                    \Tutor\Models\LessonModel::mark_lesson_complete($lid, $uid);
                } else {
                    update_user_meta($uid, '_tutor_completed_lesson_id_' . $lid, $lid);
                }
                return ['completed' => true, 'lesson_id' => $lid, 'user_id' => $uid];

            case 'mark_course_complete':
                $cid = (int) ($input['course_id'] ?? 0);
                $uid = (int) ($input['user_id'] ?? 0) ?: get_current_user_id();
                if (class_exists('Tutor\\Models\\CourseModel') && method_exists('Tutor\\Models\\CourseModel', 'mark_course_as_completed')) {
                    \Tutor\Models\CourseModel::mark_course_as_completed($cid, $uid);
                    return ['completed' => true, 'course_id' => $cid, 'user_id' => $uid];
                }
                return nibwp_tutorlms_err('tutorlms_error', 'CourseModel::mark_course_as_completed unavailable.');

            case 'reset_progress':
                $cid = (int) ($input['course_id'] ?? 0);
                $uid = (int) ($input['user_id'] ?? 0);
                if ($cid <= 0 || $uid <= 0) {
                    return nibwp_tutorlms_err('missing_params', 'course_id and user_id are required.');
                }
                $topics = $u->get_topics($cid);
                $removed = 0;
                foreach (($topics->posts ?? []) as $topic) {
                    $lessons = get_posts(['post_type' => nibwp_tutorlms_cpt('lesson'), 'post_parent' => $topic->ID, 'numberposts' => -1, 'fields' => 'ids']);
                    foreach ($lessons as $lid) {
                        if (delete_user_meta($uid, '_tutor_completed_lesson_id_' . $lid)) {
                            $removed++;
                        }
                    }
                }
                return ['reset' => true, 'lessons_cleared' => $removed, 'warnings' => ['Course-completed marker (if any) is not removed by this action.']];

            default:
                return nibwp_tutorlms_err('invalid_action', sprintf('Unknown action: %s', $action));
        }
    } catch (\Throwable $e) {
        return nibwp_tutorlms_err('tutorlms_error', $e->getMessage());
    }
}

/* ----------------------------------------------------------------------------
 * Ability 4 — nibwp/tutorlms-quizzes (quizzes, questions, attempts, assignments)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/tutorlms-quizzes', [
    'label'       => __('Tutor LMS — Quizzes', domain: 'nibwp'),
    'description' => __('Manage Tutor LMS quizzes, questions, attempts and (Pro) assignments.', domain: 'nibwp'),
    'category'    => 'lms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list_quizzes', 'get_quiz', 'create_quiz', 'update_quiz', 'delete_quiz', 'list_questions', 'list_attempts', 'get_attempt', 'delete_attempt', 'grade_attempt', 'list_assignments', 'get_assignment'],
                'description' => 'The action to perform.',
            ],
            'course_id'    => ['type' => 'integer'],
            'topic_id'     => ['type' => 'integer'],
            'quiz_id'      => ['type' => 'integer'],
            'question_id'  => ['type' => 'integer'],
            'attempt_id'   => ['type' => 'integer'],
            'assignment_id'=> ['type' => 'integer'],
            'user_id'      => ['type' => 'integer'],
            'title'        => ['type' => 'string'],
            'earned_marks' => ['type' => 'number'],
            'per_page'     => ['type' => 'integer', 'default' => 20],
            'page'         => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_tutorlms_quizzes_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage Tutor LMS quizzes + assessments.',
                'Quizzes are children of a topic (topic_id). ACTIONS: list_quizzes(topic_id|course_id), get_quiz, create_quiz(topic_id),',
                'update_quiz, delete_quiz, list_questions(quiz_id), list_attempts(quiz_id|course_id), get_attempt(attempt_id),',
                'delete_attempt [DESTRUCTIVE], grade_attempt(attempt_id, earned_marks) for manual review.',
                'list_assignments / get_assignment require the Tutor Pro Assignments addon.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_tutorlms_quizzes_manage(array $input): array|WP_Error
{
    if (!nibwp_tutorlms_available()) {
        return nibwp_tutorlms_err('tutorlms_not_active', 'Tutor LMS is not active.');
    }
    global $wpdb;
    $caps = nibwp_tutorlms_caps();
    ['per_page' => $per_page, 'page' => $page] = nibwp_tutorlms_paginate($input);
    $action = (string) ($input['action'] ?? '');
    $quiz_cpt = nibwp_tutorlms_cpt('quiz');

    try {
        switch ($action) {
            case 'list_quizzes':
                $parent = (int) ($input['topic_id'] ?? 0);
                $args = ['post_type' => $quiz_cpt, 'posts_per_page' => $per_page, 'paged' => $page];
                if ($parent > 0) {
                    $args['post_parent'] = $parent;
                }
                $q = new WP_Query($args);
                return ['quizzes' => array_map(static fn(WP_Post $p) => ['id' => $p->ID, 'title' => $p->post_title, 'topic_id' => $p->post_parent], $q->posts), 'total' => (int) $q->found_posts];

            case 'get_quiz':
                $qid = (int) ($input['quiz_id'] ?? 0);
                $p = get_post($qid);
                if (!$p || $p->post_type !== $quiz_cpt) {
                    return nibwp_tutorlms_err('quiz_not_found', sprintf('Quiz %d not found.', $qid));
                }
                return ['quiz' => ['id' => $p->ID, 'title' => $p->post_title, 'topic_id' => $p->post_parent, 'settings' => maybe_unserialize(get_post_meta($qid, 'tutor_quiz_option', true) ?: [])]];

            case 'create_quiz':
                $tid = (int) ($input['topic_id'] ?? 0);
                if (get_post_type($tid) !== nibwp_tutorlms_cpt('topic')) {
                    return nibwp_tutorlms_err('topic_not_found', sprintf('Topic %d not found.', $tid));
                }
                $qid = wp_insert_post(['post_type' => $quiz_cpt, 'post_parent' => $tid, 'post_title' => sanitize_text_field((string) ($input['title'] ?? 'Untitled quiz')), 'post_status' => 'publish'], true);
                return is_wp_error($qid) ? $qid : ['quiz_id' => (int) $qid, 'created' => true];

            case 'update_quiz':
                $qid = (int) ($input['quiz_id'] ?? 0);
                if (get_post_type($qid) !== $quiz_cpt) {
                    return nibwp_tutorlms_err('quiz_not_found', sprintf('Quiz %d not found.', $qid));
                }
                if (isset($input['title'])) {
                    wp_update_post(['ID' => $qid, 'post_title' => sanitize_text_field((string) $input['title'])]);
                }
                return ['quiz_id' => $qid, 'updated' => true];

            case 'delete_quiz':
                $qid = (int) ($input['quiz_id'] ?? 0);
                if (get_post_type($qid) !== $quiz_cpt) {
                    return nibwp_tutorlms_err('quiz_not_found', sprintf('Quiz %d not found.', $qid));
                }
                wp_delete_post($qid, true);
                return ['deleted' => true, 'quiz_id' => $qid];

            case 'list_questions':
                $qid = (int) ($input['quiz_id'] ?? 0);
                $table = $wpdb->prefix . 'tutor_quiz_questions';
                $rows = $wpdb->get_results($wpdb->prepare("SELECT question_id, question_title, question_type, question_mark FROM {$table} WHERE quiz_id = %d ORDER BY question_order ASC", $qid), ARRAY_A);
                return ['questions' => $rows ?: [], 'total' => count($rows ?: [])];

            case 'list_attempts':
                $table = $wpdb->prefix . 'tutor_quiz_attempts';
                $where = '1=1';
                $params = [];
                if (!empty($input['quiz_id'])) {
                    $where .= ' AND quiz_id = %d';
                    $params[] = (int) $input['quiz_id'];
                }
                if (!empty($input['course_id'])) {
                    $where .= ' AND course_id = %d';
                    $params[] = (int) $input['course_id'];
                }
                if (!empty($input['user_id'])) {
                    $where .= ' AND user_id = %d';
                    $params[] = (int) $input['user_id'];
                }
                $offset = ($page - 1) * $per_page;
                $sql = "SELECT attempt_id, quiz_id, user_id, course_id, total_marks, earned_marks, attempt_status, result FROM {$table} WHERE {$where} ORDER BY attempt_id DESC LIMIT %d OFFSET %d";
                $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, [$per_page, $offset])), ARRAY_A);
                return ['attempts' => $rows ?: [], 'page' => $page];

            case 'get_attempt':
                $aid = (int) ($input['attempt_id'] ?? 0);
                $table = $wpdb->prefix . 'tutor_quiz_attempts';
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE attempt_id = %d", $aid), ARRAY_A);
                if (!$row) {
                    return nibwp_tutorlms_err('attempt_not_found', sprintf('Attempt %d not found.', $aid));
                }
                return ['attempt' => $row];

            case 'delete_attempt':
                $aid = (int) ($input['attempt_id'] ?? 0);
                if (class_exists('Tutor\\Models\\QuizModel') && method_exists('Tutor\\Models\\QuizModel', 'delete_quiz_attempt')) {
                    \Tutor\Models\QuizModel::delete_quiz_attempt([$aid]);
                    return ['deleted' => true, 'attempt_id' => $aid];
                }
                $table = $wpdb->prefix . 'tutor_quiz_attempts';
                $wpdb->delete($table, ['attempt_id' => $aid], ['%d']);
                return ['deleted' => true, 'attempt_id' => $aid];

            case 'grade_attempt':
                $aid = (int) ($input['attempt_id'] ?? 0);
                if ($aid <= 0 || !isset($input['earned_marks'])) {
                    return nibwp_tutorlms_err('missing_params', 'attempt_id and earned_marks are required.');
                }
                $table = $wpdb->prefix . 'tutor_quiz_attempts';
                $wpdb->update(
                    $table,
                    ['earned_marks' => (float) $input['earned_marks'], 'attempt_status' => 'attempt_ended'],
                    ['attempt_id' => $aid],
                    ['%f', '%s'],
                    ['%d'],
                );
                return ['graded' => true, 'attempt_id' => $aid, 'earned_marks' => (float) $input['earned_marks']];

            case 'list_assignments':
            case 'get_assignment':
                if ($err = nibwp_tutorlms_require_pro('assignments', $caps)) {
                    return $err;
                }
                $acpt = nibwp_tutorlms_cpt('assignment');
                if ($action === 'get_assignment') {
                    $aid = (int) ($input['assignment_id'] ?? 0);
                    $p = get_post($aid);
                    if (!$p || $p->post_type !== $acpt) {
                        return nibwp_tutorlms_err('assignment_not_found', sprintf('Assignment %d not found.', $aid));
                    }
                    return ['assignment' => ['id' => $p->ID, 'title' => $p->post_title, 'content' => $p->post_content, 'topic_id' => $p->post_parent]];
                }
                $args = ['post_type' => $acpt, 'posts_per_page' => $per_page, 'paged' => $page];
                if (!empty($input['topic_id'])) {
                    $args['post_parent'] = (int) $input['topic_id'];
                }
                $q = new WP_Query($args);
                return ['assignments' => array_map(static fn(WP_Post $p) => ['id' => $p->ID, 'title' => $p->post_title, 'topic_id' => $p->post_parent], $q->posts), 'total' => (int) $q->found_posts];

            default:
                return nibwp_tutorlms_err('invalid_action', sprintf('Unknown action: %s', $action));
        }
    } catch (\Throwable $e) {
        return nibwp_tutorlms_err('tutorlms_error', $e->getMessage());
    }
}

/* ----------------------------------------------------------------------------
 * Ability 5 — nibwp/tutorlms-instructors
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/tutorlms-instructors', [
    'label'       => __('Tutor LMS — Instructors', domain: 'nibwp'),
    'description' => __('List instructors, promote users to instructor, manage co-instructors (Pro) and approval status.', domain: 'nibwp'),
    'category'    => 'lms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list', 'get', 'make_instructor', 'list_course_instructors', 'add_co_instructor', 'remove_co_instructor', 'approve', 'block'],
                'description' => 'The action to perform.',
            ],
            'user_id'   => ['type' => 'integer'],
            'course_id' => ['type' => 'integer'],
            'per_page'  => ['type' => 'integer', 'default' => 20],
            'page'      => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_tutorlms_instructors_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage Tutor LMS instructors.',
                'list, get(user_id), make_instructor(user_id) [grants the tutor_instructor role site-wide],',
                'list_course_instructors(course_id), add_co_instructor / remove_co_instructor(user_id,course_id) [Pro Multi-Instructor addon],',
                'approve / block(user_id) [sets instructor status].',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_tutorlms_instructors_manage(array $input): array|WP_Error
{
    if (!nibwp_tutorlms_available()) {
        return nibwp_tutorlms_err('tutorlms_not_active', 'Tutor LMS is not active.');
    }
    $caps = nibwp_tutorlms_caps();
    ['per_page' => $per_page, 'page' => $page] = nibwp_tutorlms_paginate($input);
    $action = (string) ($input['action'] ?? '');
    $u = tutor_utils();

    try {
        switch ($action) {
            case 'list':
                $offset = ($page - 1) * $per_page;
                $list = method_exists($u, 'get_instructors') ? $u->get_instructors($offset, $per_page) : [];
                $rows = [];
                foreach ((array) $list as $row) {
                    $rows[] = ['id' => (int) ($row->ID ?? 0), 'name' => $row->display_name ?? '', 'email' => $row->user_email ?? ''];
                }
                return ['instructors' => $rows, 'total' => count($rows)];

            case 'get':
                $uid = (int) ($input['user_id'] ?? 0);
                $user = get_userdata($uid);
                if (!$user) {
                    return nibwp_tutorlms_err('user_not_found', sprintf('User %d not found.', $uid));
                }
                return ['instructor' => [
                    'id' => $uid, 'name' => $user->display_name, 'email' => $user->user_email,
                    'is_instructor' => in_array('tutor_instructor', (array) $user->roles, true),
                    'total_students' => method_exists($u, 'get_total_students_by_instructor') ? (int) $u->get_total_students_by_instructor($uid) : null,
                ]];

            case 'make_instructor':
                $uid = (int) ($input['user_id'] ?? 0);
                if (!get_userdata($uid)) {
                    return nibwp_tutorlms_err('user_not_found', sprintf('User %d not found.', $uid));
                }
                if (method_exists($u, 'add_instructor_role')) {
                    $u->add_instructor_role($uid);
                }
                // Tutor identifies instructors by the _is_tutor_instructor timestamp
                // (its get_instructors() queries filter on it). Stamp it + status.
                $now = function_exists('tutor_time') ? tutor_time() : time();
                update_user_meta($uid, '_is_tutor_instructor', $now);
                update_user_meta($uid, '_tutor_instructor_status', 'approved');
                update_user_meta($uid, '_tutor_instructor_approved', $now);
                return ['made_instructor' => true, 'user_id' => $uid];

            case 'list_course_instructors':
                $cid = (int) ($input['course_id'] ?? 0);
                $list = method_exists($u, 'get_instructors_by_course') ? $u->get_instructors_by_course($cid) : [];
                $rows = array_map(static fn($r) => ['id' => (int) ($r->ID ?? 0), 'name' => $r->display_name ?? ''], (array) $list);
                return ['instructors' => $rows, 'total' => count($rows)];

            case 'add_co_instructor':
                if ($err = nibwp_tutorlms_require_pro('multi_instructor', $caps)) {
                    return $err;
                }
                $uid = (int) ($input['user_id'] ?? 0);
                $cid = (int) ($input['course_id'] ?? 0);
                add_user_meta($uid, '_tutor_instructor_course_id', $cid);
                return ['added' => true, 'user_id' => $uid, 'course_id' => $cid];

            case 'remove_co_instructor':
                if ($err = nibwp_tutorlms_require_pro('multi_instructor', $caps)) {
                    return $err;
                }
                $uid = (int) ($input['user_id'] ?? 0);
                $cid = (int) ($input['course_id'] ?? 0);
                delete_user_meta($uid, '_tutor_instructor_course_id', $cid);
                return ['removed' => true, 'user_id' => $uid, 'course_id' => $cid];

            case 'approve':
            case 'block':
                $uid = (int) ($input['user_id'] ?? 0);
                if (!get_userdata($uid)) {
                    return nibwp_tutorlms_err('user_not_found', sprintf('User %d not found.', $uid));
                }
                $status = $action === 'approve' ? 'approved' : 'blocked';
                update_user_meta($uid, '_tutor_instructor_status', $status);
                if ($status === 'approved') {
                    update_user_meta($uid, '_tutor_instructor_approved', function_exists('tutor_time') ? tutor_time() : time());
                }
                return ['user_id' => $uid, 'status' => $status];

            default:
                return nibwp_tutorlms_err('invalid_action', sprintf('Unknown action: %s', $action));
        }
    } catch (\Throwable $e) {
        return nibwp_tutorlms_err('tutorlms_error', $e->getMessage());
    }
}

/* ----------------------------------------------------------------------------
 * Ability 6 — nibwp/tutorlms-monetization
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/tutorlms-monetization', [
    'label'       => __('Tutor LMS — Monetization', domain: 'nibwp'),
    'description' => __('Detect the monetization engine, set course pricing, manage native coupons, and read earnings/withdrawals.', domain: 'nibwp'),
    'category'    => 'lms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['detect_engine', 'get_price', 'set_price', 'list_coupons', 'get_coupon', 'create_coupon', 'update_coupon', 'delete_coupon', 'list_earnings', 'get_instructor_earnings', 'list_withdrawals', 'get_commission'],
                'description' => 'The action to perform.',
            ],
            'course_id'     => ['type' => 'integer'],
            'price'         => ['type' => 'number'],
            'price_type'    => ['type' => 'string', 'enum' => ['free', 'paid']],
            'coupon_id'     => ['type' => 'integer'],
            'coupon_data'   => ['type' => 'object'],
            'instructor_id' => ['type' => 'integer'],
            'per_page'      => ['type' => 'integer', 'default' => 20],
            'page'          => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_tutorlms_monetization_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage Tutor LMS monetization. ALWAYS call detect_engine first.',
                'Native coupons + earnings + withdrawals are authoritative only when engine=tutor; with engine=wc/edd, pricing/coupons live in WooCommerce/EDD on the linked product (_tutor_course_product_id).',
                'set_price writes live pricing. Every response includes the active engine.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_tutorlms_monetization_manage(array $input): array|WP_Error
{
    if (!nibwp_tutorlms_available()) {
        return nibwp_tutorlms_err('tutorlms_not_active', 'Tutor LMS is not active.');
    }
    global $wpdb;
    $caps = nibwp_tutorlms_caps();
    $engine = $caps['monetize_by'];
    ['per_page' => $per_page, 'page' => $page] = nibwp_tutorlms_paginate($input);
    $action = (string) ($input['action'] ?? '');
    $u = tutor_utils();
    $coupon_model = 'Tutor\\Models\\CouponModel';

    try {
        switch ($action) {
            case 'detect_engine':
                return ['engine' => $engine, 'has_wc' => $caps['has_wc'], 'has_edd' => $caps['has_edd'], 'native_ecommerce' => $engine === 'tutor'];

            case 'get_price':
                $cid = (int) ($input['course_id'] ?? 0);
                return ['engine' => $engine, 'price' => method_exists($u, 'get_course_price') ? $u->get_course_price($cid) : null, 'raw_price' => method_exists($u, 'get_raw_course_price') ? $u->get_raw_course_price($cid) : null, 'product_id' => (int) get_post_meta($cid, '_tutor_course_product_id', true)];

            case 'set_price':
                $cid = (int) ($input['course_id'] ?? 0);
                if ($cid <= 0 || get_post_type($cid) !== nibwp_tutorlms_cpt('course')) {
                    return nibwp_tutorlms_err('course_not_found', sprintf('Course %d not found.', $cid));
                }
                $warnings = [];
                if (isset($input['price_type'])) {
                    update_post_meta($cid, '_tutor_course_price_type', $input['price_type']);
                }
                if (isset($input['price'])) {
                    if ($engine === 'tutor') {
                        update_post_meta($cid, 'tutor_course_price', (float) $input['price']);
                    } else {
                        $pid = (int) get_post_meta($cid, '_tutor_course_product_id', true);
                        if ($pid && function_exists('update_post_meta')) {
                            update_post_meta($pid, '_price', (float) $input['price']);
                            update_post_meta($pid, '_regular_price', (float) $input['price']);
                            $warnings[] = sprintf('Price written to linked %s product %d.', $engine, $pid);
                        } else {
                            $warnings[] = 'No linked product found; native price meta set as a fallback.';
                            update_post_meta($cid, 'tutor_course_price', (float) $input['price']);
                        }
                    }
                }
                return ['engine' => $engine, 'updated' => true, 'course_id' => $cid, 'warnings' => $warnings];

            case 'list_coupons':
            case 'get_coupon':
            case 'create_coupon':
            case 'update_coupon':
            case 'delete_coupon':
                if ($engine !== 'tutor' || !class_exists($coupon_model)) {
                    return nibwp_tutorlms_err('engine_mismatch', sprintf('Native coupons require monetize_by=tutor (current engine: %s).', $engine));
                }
                return nibwp_tutorlms_coupon_dispatch($action, $input, $coupon_model, $per_page, $page, $engine);

            case 'list_earnings':
            case 'get_instructor_earnings':
                $table = $wpdb->prefix . 'tutor_earnings';
                $where = '1=1';
                $params = [];
                if (!empty($input['instructor_id'])) {
                    $where .= ' AND user_id = %d';
                    $params[] = (int) $input['instructor_id'];
                }
                if (!empty($input['course_id'])) {
                    $where .= ' AND course_id = %d';
                    $params[] = (int) $input['course_id'];
                }
                $offset = ($page - 1) * $per_page;
                $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY earning_id DESC LIMIT %d OFFSET %d";
                $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, [$per_page, $offset])), ARRAY_A);
                return ['engine' => $engine, 'earnings' => $rows ?: [], 'page' => $page, 'warnings' => $engine !== 'tutor' ? ['Earnings rows are only authoritative when engine=tutor.'] : []];

            case 'list_withdrawals':
                $table = $wpdb->prefix . 'tutor_withdraws';
                $offset = ($page - 1) * $per_page;
                $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY withdraw_id DESC LIMIT %d OFFSET %d", $per_page, $offset), ARRAY_A);
                return ['engine' => $engine, 'withdrawals' => $rows ?: [], 'page' => $page];

            case 'get_commission':
                return ['engine' => $engine, 'sharing_enabled' => function_exists('get_tutor_option') ? (bool) get_tutor_option('enable_revenue_sharing') : null, 'instructor_percent' => function_exists('get_tutor_option') ? get_tutor_option('earning_instructor_commission') : null, 'admin_percent' => function_exists('get_tutor_option') ? get_tutor_option('earning_admin_commission') : null];

            default:
                return nibwp_tutorlms_err('invalid_action', sprintf('Unknown action: %s', $action));
        }
    } catch (\Throwable $e) {
        return nibwp_tutorlms_err('tutorlms_error', $e->getMessage());
    }
}

/** Native coupon CRUD via Tutor\Models\CouponModel (engine=tutor only). */
function nibwp_tutorlms_coupon_dispatch(string $action, array $input, string $model, int $per_page, int $page, string $engine): array|WP_Error
{
    switch ($action) {
        case 'list_coupons':
            $rows = method_exists($model, 'get_coupons') ? $model::get_coupons(['per_page' => $per_page, 'page' => $page]) : [];
            return ['engine' => $engine, 'coupons' => $rows];
        case 'get_coupon':
            $cid = (int) ($input['coupon_id'] ?? 0);
            $row = method_exists($model, 'get_coupon_by_id') ? $model::get_coupon_by_id($cid) : null;
            return $row ? ['engine' => $engine, 'coupon' => $row] : nibwp_tutorlms_err('coupon_not_found', sprintf('Coupon %d not found.', $cid));
        case 'create_coupon':
            if (!method_exists($model, 'create_coupon')) {
                return nibwp_tutorlms_err('tutorlms_error', 'CouponModel::create_coupon unavailable.');
            }
            $id = $model::create_coupon((array) ($input['coupon_data'] ?? []));
            return ['engine' => $engine, 'coupon_id' => $id, 'created' => true];
        case 'update_coupon':
            if (!method_exists($model, 'update_coupon')) {
                return nibwp_tutorlms_err('tutorlms_error', 'CouponModel::update_coupon unavailable.');
            }
            $model::update_coupon((int) ($input['coupon_id'] ?? 0), (array) ($input['coupon_data'] ?? []));
            return ['engine' => $engine, 'updated' => true];
        case 'delete_coupon':
            if (!method_exists($model, 'delete_coupon')) {
                return nibwp_tutorlms_err('tutorlms_error', 'CouponModel::delete_coupon unavailable.');
            }
            $model::delete_coupon((int) ($input['coupon_id'] ?? 0));
            return ['engine' => $engine, 'deleted' => true];
    }
    return nibwp_tutorlms_err('invalid_action', $action);
}

/* ----------------------------------------------------------------------------
 * Ability 7 — nibwp/tutorlms-reports (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/tutorlms-reports', [
    'label'       => __('Tutor LMS — Reports', domain: 'nibwp'),
    'description' => __('Read course analytics, sales, the student roster and per-student progress (read-only).', domain: 'nibwp'),
    'category'    => 'lms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['course_analytics', 'sales', 'roster', 'progress_export'],
                'description' => 'The action to perform.',
            ],
            'course_id'     => ['type' => 'integer'],
            'instructor_id' => ['type' => 'integer'],
            'date_from'     => ['type' => 'string'],
            'date_to'       => ['type' => 'string'],
            'per_page'      => ['type' => 'integer', 'default' => 50],
            'page'          => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_tutorlms_reports_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Read Tutor LMS analytics (works on Tutor Free-core data).',
                'course_analytics(course_id), sales(date_from,date_to), roster(course_id), progress_export(course_id).',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_tutorlms_reports_manage(array $input): array|WP_Error
{
    if (!nibwp_tutorlms_available()) {
        return nibwp_tutorlms_err('tutorlms_not_active', 'Tutor LMS is not active.');
    }
    global $wpdb;
    ['per_page' => $per_page, 'page' => $page] = nibwp_tutorlms_paginate($input);
    $action = (string) ($input['action'] ?? '');
    $u = tutor_utils();

    try {
        switch ($action) {
            case 'course_analytics':
                $cid = (int) ($input['course_id'] ?? 0);
                $completion = class_exists('Tutor\\Models\\CourseModel') && method_exists('Tutor\\Models\\CourseModel', 'get_course_completion_data_by_course_id')
                    ? (new \Tutor\Models\CourseModel())->get_course_completion_data_by_course_id($cid)
                    : [];
                return ['analytics' => [
                    'course_id'      => $cid,
                    'total_students' => method_exists($u, 'get_total_students') ? (int) $u->get_total_students('', $cid) : null,
                    'completion'     => $completion,
                ]];

            case 'sales':
                $table = $wpdb->prefix . 'tutor_earnings';
                $where = '1=1';
                $params = [];
                if (!empty($input['date_from'])) {
                    $where .= ' AND created_at >= %s';
                    $params[] = sanitize_text_field((string) $input['date_from']);
                }
                if (!empty($input['date_to'])) {
                    $where .= ' AND created_at <= %s';
                    $params[] = sanitize_text_field((string) $input['date_to']);
                }
                $sql = "SELECT COUNT(*) AS orders, SUM(course_price_total) AS gross, SUM(instructor_amount) AS instructor_total, SUM(admin_amount) AS admin_total FROM {$table} WHERE {$where}";
                $row = $params ? $wpdb->get_row($wpdb->prepare($sql, ...$params), ARRAY_A) : $wpdb->get_row($sql, ARRAY_A);
                return ['sales' => $row ?: [], 'warnings' => ['Sales aggregate is authoritative only when monetize_by=tutor.']];

            case 'roster':
            case 'progress_export':
                $cid = (int) ($input['course_id'] ?? 0);
                $offset = ($page - 1) * $per_page;
                $enrolled = get_posts([
                    'post_type'   => nibwp_tutorlms_cpt('enrolled'),
                    'post_parent' => $cid,
                    'post_status' => 'completed',
                    'numberposts' => $per_page,
                    'offset'      => $offset,
                ]);
                $rows = [];
                foreach ($enrolled as $e) {
                    $uid = (int) $e->post_author;
                    $user = get_userdata($uid);
                    $rows[] = [
                        'user_id'   => $uid,
                        'name'      => $user ? $user->display_name : '',
                        'email'     => $user ? $user->user_email : '',
                        'enrolled'  => $e->post_date,
                        'percent'   => method_exists($u, 'get_course_completed_percent') ? $u->get_course_completed_percent($cid, $uid) : null,
                    ];
                }
                return ['roster' => $rows, 'count' => count($rows), 'page' => $page];

            default:
                return nibwp_tutorlms_err('invalid_action', sprintf('Unknown action: %s', $action));
        }
    } catch (\Throwable $e) {
        return nibwp_tutorlms_err('tutorlms_error', $e->getMessage());
    }
}
