<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: LifterLMS.
 *
 * One hub over a learning-management plugin, which means two different kinds of
 * data sit side by side in the same tables: course structure, which is editorial
 * content, and student records, which are personal data about paying people. The
 * file treats them as two different things throughout — a course listing takes the
 * post-type capability a course editor already has, and anything that names a
 * student takes the capability LifterLMS itself puts on its reporting screen.
 *
 * Verified against LifterLMS 10.2.1 (lifterlms.php:14).
 *
 * THE CAPABILITY FOR STUDENT DATA is 'view_lifterlms_reports'. That is what
 * LifterLMS hangs its own Reporting submenu on
 * (includes/admin/class.llms.admin.menus.php:209) and what its students meta box
 * declares (includes/admin/post-types/meta-boxes/class.llms.meta.box.students.php:29).
 * It is declared as a core LifterLMS capability in
 * includes/class.llms.roles.php:76. edit_posts would have been wrong in both
 * directions: an author who may edit a post has no business reading a student's
 * grades, and an instructor who legitimately reads their own students' reports
 * does not necessarily have edit_posts on anything.
 *
 * READING ABOUT ONE NAMED STUDENT takes more than the role. LifterLMS's own rule
 * is llms_current_user_can('view_lifterlms_reports', $student_id)
 * (includes/functions/llms.functions.person.php:63): the wider
 * 'view_others_lifterlms_reports' grants it outright, and otherwise the account
 * must be an instructor on a course that student is actually enrolled in. This
 * file calls that function when it is available and falls back to requiring the
 * wider capability when it is not, which is the safe direction to fall.
 *
 * ENROLLING AND UNENROLLING change what a paying student can reach, so they take
 * LifterLMS's own 'enroll' and 'unenroll' capabilities
 * (includes/class.llms.roles.php:78-79) — the same ones its bulk-enroll screen
 * checks (includes/admin/class.llms.student.bulk.enroll.php:130). Those two are
 * deliberately withheld from the instructor role by LifterLMS itself
 * (includes/class.llms.roles.php:113-114), and this file does not second-guess
 * that.
 *
 * DOES UNENROLLING DESTROY PROGRESS? In 10.2.1, no. LLMS_Student::unenroll()
 * (includes/models/model.llms.student.php:1567) writes one new '_status' row —
 * 'expired' by default — through insert_status_postmeta() and touches nothing
 * else. The '_is_complete' rows that record which lessons the student finished
 * stay where they are, so re-enrolling restores the progress. The separate
 * delete_enrollment() (:1670) is the one that removes enrollment records, and
 * this hub does not offer it. Access stops immediately either way, which is the
 * part that matters to the student, and every description says so.
 *
 * WHAT NEVER LEAVES: a student record is assembled by an allow-list
 * (nibwp_lifterlms_student_record), not filtered by a list of names somebody
 * remembered. A WP user row carries user_pass; user meta carries session_tokens,
 * wp_capabilities and _application_passwords; and a LifterLMS order sitting next
 * to the same student carries order_key, which is a bearer token in a URL
 * (includes/models/model.llms.order.php:55) plus whatever the gateway stored. A
 * denylist would have shipped every one of those the first time a version added a
 * new key. A field whose value is not a scalar is DROPPED rather than cast,
 * because a capability map or a token set would otherwise travel as a serialised
 * string under an innocent name.
 *
 * AN ID FROM A CALLER IS A CLAIM. Every action that takes a course, lesson, quiz,
 * membership or plan id resolves it through nibwp_lifterlms_post() first, which
 * checks the post type ALONE and before any capability. That ordering is the
 * point: current_user_can('edit_post', $id) on the id of an unrelated page
 * answers true for anybody who may edit that page, and an update would then land
 * on it. The refusal carries checked => 'post_type' so the order is visible from
 * the outside, and a check pins it.
 */

/** The capability LifterLMS puts on its own reporting screen. */
const NIBWP_LLMS_CAP_REPORTS = 'view_lifterlms_reports';

/** The wider report capability: other people's students, not only your own. */
const NIBWP_LLMS_CAP_OTHERS = 'view_others_lifterlms_reports';

/** LifterLMS's own capability for granting access to a course or membership. */
const NIBWP_LLMS_CAP_ENROLL = 'enroll';

/** LifterLMS's own capability for taking that access away. */
const NIBWP_LLMS_CAP_UNENROLL = 'unenroll';

/** Editing a student's completion record is editing a student. */
const NIBWP_LLMS_CAP_STUDENTS = 'edit_students';

/** Creating a course: LifterLMS's mapped publish capability for the post type. */
const NIBWP_LLMS_CAP_PUBLISH = 'publish_courses';

/** How many rows any listing will return, whatever was asked for. */
const NIBWP_LLMS_MAX_ROWS = 200;

/**
 * Is LifterLMS actually loaded?
 *
 * The constant is defined by the main plugin file before anything else
 * (lifterlms.php:39) and LifterLMS is the class the rest of it hangs off, so
 * between them they separate "not installed" from "installed and half-loaded".
 */
function nibwp_lifterlms_guard(): ?WP_Error
{
    if (!defined('LLMS_PLUGIN_FILE') && !class_exists('LifterLMS')) {
        return new WP_Error(
            'nibwp_llms_missing',
            __('LifterLMS is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/** A refusal about the request itself. */
function nibwp_lifterlms_err(string $code, string $message, int $status = 400, array $data = []): WP_Error
{
    return new WP_Error('nibwp_llms_' . $code, $message, ['status' => $status] + $data);
}

/**
 * A refusal about authority.
 *
 * checked => 'capability' is in the data on purpose: it is how a caller, and the
 * check, can tell this apart from a refusal that happened before any capability
 * was consulted.
 */
function nibwp_lifterlms_forbidden(string $what, string $cap): WP_Error
{
    return new WP_Error(
        'nibwp_llms_forbidden',
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name */
            __('%1$s needs the %2$s capability, which this account does not have.', 'nibwp'),
            $what,
            $cap
        ),
        ['status' => 403, 'checked' => 'capability']
    );
}

/** One capability gate. */
function nibwp_lifterlms_require(string $cap, string $what): ?WP_Error
{
    return current_user_can($cap) ? null : nibwp_lifterlms_forbidden($what, $cap);
}

/**
 * The gate on anything that reads a student record in the aggregate.
 *
 * Course structure is editorial; a student's grade is not. This is the bar
 * LifterLMS sets on its own reporting screen and this file does not lower it.
 */
function nibwp_lifterlms_require_reports(string $what): ?WP_Error
{
    return nibwp_lifterlms_require(NIBWP_LLMS_CAP_REPORTS, $what);
}

/**
 * The gate on anything that names ONE student.
 *
 * The role is not enough. LifterLMS's own rule, which this defers to when the
 * function is loaded, is that the wider capability grants it outright and
 * otherwise the account must be an instructor on a course that student is
 * enrolled in (includes/functions/llms.functions.person.php:63). When that
 * function is missing — a partial load, or a version that moved it — this falls
 * back to demanding the wider capability rather than to letting the role answer.
 */
function nibwp_lifterlms_require_student(int $user_id, string $what): ?WP_Error
{
    if ($denied = nibwp_lifterlms_require_reports($what)) {
        return $denied;
    }

    if (function_exists('llms_current_user_can')) {
        try {
            if (llms_current_user_can(NIBWP_LLMS_CAP_REPORTS, $user_id)) {
                return null;
            }
        } catch (\Throwable $error) {
            // Fall through to the wider capability: a throw out of LifterLMS's
            // own check is not permission.
        }

        return nibwp_lifterlms_forbidden($what, NIBWP_LLMS_CAP_OTHERS);
    }

    return current_user_can(NIBWP_LLMS_CAP_OTHERS)
        ? null
        : nibwp_lifterlms_forbidden($what, NIBWP_LLMS_CAP_OTHERS);
}

/** Clamp a row count that arrived over the wire. */
function nibwp_lifterlms_limit(mixed $value, int $default = 25): int
{
    $limit = is_numeric($value) ? (int) $value : $default;

    return max(1, min(NIBWP_LLMS_MAX_ROWS, $limit));
}

/** Where a listing starts. Never negative, never an identifier. */
function nibwp_lifterlms_offset(mixed $value): int
{
    return is_numeric($value) ? max(0, (int) $value) : 0;
}

/**
 * The LifterLMS post types this hub will talk about, by role.
 *
 * Read off class.llms.post-types.php: course :487, section :528, lesson :562,
 * llms_quiz :601, llms_membership :676, llms_engagement :718, llms_order :754,
 * llms_certificate :920, llms_access_plan :1126.
 *
 * @return array<string, array<int, string>>
 */
function nibwp_lifterlms_types(): array
{
    return [
        'course' => ['course'],
        'section' => ['section'],
        'lesson' => ['lesson'],
        'quiz' => ['llms_quiz'],
        'membership' => ['llms_membership'],
        'plan' => ['llms_access_plan'],
        'product' => ['course', 'llms_membership'],
    ];
}

/**
 * Turn an id from a caller into a post of the type the action actually wants.
 *
 * THE TYPE IS CHECKED ALONE, AND FIRST. No capability is consulted in here at
 * all, because the capability that matters is edit_post on a LifterLMS object and
 * an account can hold edit_post on an ordinary page without having any business
 * with the LMS. Checking the capability first would mean a caller could hand
 * update_course the id of a page they may edit and have it written.
 *
 * A missing id and an id of the wrong type refuse with the SAME code, so the
 * refusal cannot be used to learn which ids exist.
 */
function nibwp_lifterlms_post(mixed $raw, string $role): WP_Post|WP_Error
{
    $id = is_numeric($raw) ? (int) $raw : 0;
    $types = nibwp_lifterlms_types()[$role] ?? [];

    if ($id <= 0) {
        return nibwp_lifterlms_err(
            'missing_id',
            sprintf(
                /* translators: %s: the kind of object wanted, e.g. course */
                __('Give the %s id as an integer.', 'nibwp'),
                $role
            ),
            422
        );
    }

    $post = get_post($id);

    if (!$post instanceof WP_Post || !in_array((string) $post->post_type, $types, true)) {
        // Deliberately the same answer for "no such post" and "a post of some
        // other type", and deliberately silent about what WAS found there.
        return nibwp_lifterlms_err(
            'wrong_type',
            sprintf(
                /* translators: 1: the kind of object wanted, 2: the id given */
                __('There is no LifterLMS %1$s with the id %2$d.', 'nibwp'),
                $role,
                $id
            ),
            404,
            ['checked' => 'post_type']
        );
    }

    return $post;
}

/**
 * Having established it IS a LifterLMS object, may this account edit it?
 *
 * Always called after nibwp_lifterlms_post(), never instead of it.
 */
function nibwp_lifterlms_can_edit(WP_Post $post, string $what): ?WP_Error
{
    return current_user_can('edit_post', $post->ID)
        ? null
        : nibwp_lifterlms_forbidden($what, 'edit_post');
}

/**
 * Fields of a student record that may leave this hub, and nothing else.
 *
 * An ALLOW-LIST. The reason is in the file header: the dangerous names live in
 * the same arrays as the harmless ones and a new version adds more of them, so a
 * denylist is wrong by construction rather than merely incomplete.
 *
 * @return array<int, string>
 */
function nibwp_lifterlms_student_fields(): array
{
    return [
        'id',
        'email',
        'display_name',
        'first_name',
        'last_name',
        'username',
        'registered',
        'course_id',
        'membership_id',
        'enrollment_status',
        'enrollment_date',
        'enrollment_trigger',
        'progress_percent',
        'grade',
        'is_complete',
        'completion_date',
        'lessons_completed',
        'lessons_total',
        'last_completed_lesson',
        'last_completed_lesson_id',
        'last_activity',
        'overall_progress',
        'overall_grade',
        'certificates_earned',
    ];
}

/**
 * Shape one student record through the allow-list.
 *
 * A non-scalar value is DROPPED, not cast. json_encode of a capability map under
 * 'display_name' would read as a harmless string in a response and carry the
 * whole map; (string) on an array is a warning and the word "Array", which hides
 * the mistake rather than preventing it.
 *
 * @param array<string, mixed> $raw
 * @return array<string, mixed>
 */
function nibwp_lifterlms_student_record(array $raw): array
{
    $record = [];

    foreach (nibwp_lifterlms_student_fields() as $field) {
        if (!array_key_exists($field, $raw)) {
            continue;
        }

        $value = $raw[$field];

        if ($value === null) {
            $record[$field] = null;
            continue;
        }

        if (!is_scalar($value)) {
            continue;
        }

        $record[$field] = $value;
    }

    return $record;
}

/** Read one LifterLMS model property without letting the plugin throw out. */
function nibwp_lifterlms_prop(mixed $model, string $key, mixed $default = null): mixed
{
    if (!is_object($model) || !method_exists($model, 'get')) {
        return $default;
    }

    try {
        $value = $model->get($key);
    } catch (\Throwable $error) {
        return $default;
    }

    return ($value === '' || $value === null) ? $default : $value;
}

/** A post summary that is editorial content only — no student in it. */
function nibwp_lifterlms_post_row(WP_Post $post): array
{
    return [
        'id' => (int) $post->ID,
        'title' => (string) $post->post_title,
        'slug' => (string) $post->post_name,
        'status' => (string) $post->post_status,
        'date' => (string) $post->post_date_gmt,
        'order' => (int) $post->menu_order,
    ];
}

/**
 * Post statuses this hub will set, and the one it refuses to.
 *
 * 'trash' is absent on purpose: trashing a published course takes it away from
 * every student enrolled in it, which is not a status change, and wp-admin asks
 * before it does that.
 *
 * @return array<int, string>
 */
function nibwp_lifterlms_statuses(): array
{
    return ['publish', 'draft', 'pending', 'private'];
}

/** The ids of every lesson in a course, in order, via LifterLMS's own model. */
function nibwp_lifterlms_course_lesson_ids(int $course_id): array
{
    if (!class_exists('LLMS_Course')) {
        return [];
    }

    try {
        $course = new LLMS_Course($course_id);

        if (!method_exists($course, 'get_lessons')) {
            return [];
        }

        $ids = $course->get_lessons('ids');
    } catch (\Throwable $error) {
        return [];
    }

    return array_values(array_filter(array_map('intval', (array) $ids)));
}

/** A placeholder list for an IN clause. The values still go through prepare(). */
function nibwp_lifterlms_placeholders(array $values): string
{
    return implode(', ', array_fill(0, max(1, count($values)), '%d'));
}

/** The actions this hub offers. */
function nibwp_lifterlms_actions(): array
{
    return [
        // Courses.
        'list_courses',
        'get_course',
        'create_course',
        'update_course',
        'set_course_status',
        // Lessons and quizzes.
        'list_course_content',
        'get_lesson',
        'get_quiz',
        'reorder_lessons',
        // Memberships and access plans.
        'list_memberships',
        'get_membership',
        'list_access_plans',
        // Students.
        'list_course_students',
        'get_student_progress',
        'enroll_student',
        'unenroll_student',
        'mark_lesson_complete',
        'mark_lesson_incomplete',
        // Engagements and certificates.
        'list_engagements',
        'list_certificates',
        // Reporting.
        'enrollment_counts',
        'completion_rates',
        'stalled_students',
        'plan_revenue',
        // Audit.
        'audit_courses',
    ];
}

wp_register_ability('nibwp/lifterlms-manage', [
    'label' => __('LifterLMS – Courses, Students and Enrolment', 'nibwp'),
    'description' => 'Read and manage a LifterLMS site: courses with their sections and lessons, quizzes, memberships and access plans, engagements and certificates, '
        . 'plus reporting — enrolment counts per course, completion rates, students who have stalled on one lesson, and gross revenue per access plan. '
        . 'It can create and update a course, set its status, and reorder lessons. '
        . 'It can enrol and unenrol a student and mark one lesson complete or incomplete: enroll_student and unenroll_student CHANGE WHAT A PAYING STUDENT CAN REACH, and each reports exactly what changed. '
        . 'Unenrolling does not erase a student\'s lesson completions in this version — it marks the enrolment expired and access stops — so re-enrolling restores their progress. '
        . 'Reading anything about a student takes the same capability LifterLMS puts on its own reporting screen, view_lifterlms_reports, and anything naming one student is checked against that student as well as the role. '
        . 'No password hash, session token, application password or order token is ever returned. '
        . 'audit_courses finds published courses with no lessons, lessons with no content, courses nobody has finished, and students left enrolled in a course that was unpublished under them.',
    'category' => 'lms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_lifterlms_actions(),
                'description' => 'Which operation to run. Start with list_courses or audit_courses.',
            ],
            'course_id' => ['type' => 'integer', 'description' => 'A course post id. Checked to really be a course before anything else.'],
            'lesson_id' => ['type' => 'integer', 'description' => 'A lesson post id.'],
            'quiz_id' => ['type' => 'integer', 'description' => 'A quiz post id.'],
            'membership_id' => ['type' => 'integer', 'description' => 'A membership post id.'],
            'plan_id' => ['type' => 'integer', 'description' => 'An access plan post id.'],
            'user_id' => ['type' => 'integer', 'description' => 'The student. A user id, not an email.'],
            'lesson_ids' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'description' => 'For reorder_lessons: the lesson ids of one course in the order wanted.',
            ],
            'title' => ['type' => 'string', 'description' => 'For create_course / update_course.'],
            'content' => ['type' => 'string', 'description' => 'For create_course / update_course: the course body.'],
            'excerpt' => ['type' => 'string', 'description' => 'For create_course / update_course: the short description.'],
            'status' => ['type' => 'string', 'description' => 'publish, draft, pending or private. Never trash.'],
            'search' => ['type' => 'string', 'description' => 'For list_course_students: match a name, login or email.'],
            'enrollment_status' => ['type' => 'string', 'description' => 'For list_course_students: enrolled, expired or cancelled.'],
            'days' => ['type' => 'integer', 'description' => 'For stalled_students: how long with no progress counts as stalled. Default 30, max 365.'],
            'limit' => ['type' => 'integer', 'description' => 'Rows to return. Default 25, max 200.'],
            'offset' => ['type' => 'integer', 'description' => 'Where a listing starts.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string'],
        ],
        'required' => ['action'],
    ],
    'execute_callback' => 'nibwp_lifterlms_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'LifterLMS. Two kinds of data live here and they are not gated the same way.',
                '',
                'START WITH list_courses for structure, or audit_courses for what is wrong:',
                'published courses with no lessons, lessons with no content, a course nobody',
                'has ever finished, and students still enrolled in a course that was',
                'unpublished under them.',
                '',
                'COURSES AND CONTENT: list_courses, get_course (its sections and lessons),',
                'create_course, update_course, set_course_status, list_course_content,',
                'get_lesson, get_quiz, reorder_lessons. For what people pay for:',
                'list_memberships, get_membership and list_access_plans, which says which',
                'courses a plan grants. Also list_engagements, for what fires on its own.',
                'A course, lesson, quiz, membership or',
                'plan id is checked to really be that LifterLMS post type BEFORE any',
                'capability, so handing one of these the id of an ordinary page is refused on',
                'the type and nothing is written.',
                '',
                'STUDENTS ARE PERSONAL DATA. list_course_students, get_student_progress,',
                'enrollment_counts, completion_rates, stalled_students and list_certificates',
                'all take view_lifterlms_reports — the capability LifterLMS puts on its own',
                'reporting screen — and the ones that name a single student are checked against',
                'that student too, not only against the role.',
                '',
                'ENROLLING AND UNENROLLING CHANGE WHAT A PAYING STUDENT CAN REACH:',
                '- enroll_student grants access to a course or membership immediately.',
                '- unenroll_student takes it away immediately. In this version it does NOT',
                '  erase their lesson completions: it marks the enrolment expired, so',
                '  re-enrolling restores the progress. Say both halves of that back to',
                '  whoever asked, and say which student and which course.',
                'Both report exactly what changed, including the status before and after.',
                'They take LifterLMS\'s own enroll / unenroll capabilities, which LifterLMS',
                'withholds from instructors on purpose.',
                '',
                'WHAT IS NOT HERE: deleting a student, deleting an enrolment record, deleting',
                'a course, issuing or revoking a certificate, and refunding an order. Those',
                'end a relationship or move money and belong to a person in wp-admin.',
                '',
                'SECRETS: no password hash, session token, application password or order key',
                'is ever returned, at any nesting depth, however the request is phrased.',
                '',
                'EVERY LISTING IS BOUNDED, and says how many records it skipped because this',
                'account may not read them. A skipped count above zero means the answer is',
                'partial — say so rather than reporting the total as if it were complete.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_manage(array $input): array|WP_Error
{
    $guard = nibwp_lifterlms_guard();
    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = isset($input['action']) ? (string) $input['action'] : '';

    if (!in_array($action, nibwp_lifterlms_actions(), true)) {
        return nibwp_lifterlms_err(
            'unknown_action',
            sprintf(
                /* translators: 1: action name, 2: list of valid actions */
                __('Unknown LifterLMS action "%1$s". Valid actions: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_lifterlms_actions())
            )
        );
    }

    // LifterLMS's models reach the database in their constructors and throw on a
    // site mid-update. One catch here turns any of that into a named refusal
    // rather than a fatal that takes the whole REST request with it.
    try {
        return nibwp_lifterlms_dispatch($action, $input);
    } catch (\Throwable $error) {
        return nibwp_lifterlms_err(
            'plugin_error',
            sprintf(
                /* translators: 1: action name, 2: error message from LifterLMS */
                __('LifterLMS could not complete %1$s: %2$s', 'nibwp'),
                $action,
                $error->getMessage()
            ),
            500
        );
    }
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_dispatch(string $action, array $input): array|WP_Error
{
    $result = match ($action) {
        'list_courses' => nibwp_lifterlms_list_courses($input),
        'get_course' => nibwp_lifterlms_get_course($input),
        'create_course' => nibwp_lifterlms_create_course($input),
        'update_course' => nibwp_lifterlms_update_course($input),
        'set_course_status' => nibwp_lifterlms_set_course_status($input),
        'list_course_content' => nibwp_lifterlms_list_course_content($input),
        'get_lesson' => nibwp_lifterlms_get_lesson($input),
        'get_quiz' => nibwp_lifterlms_get_quiz($input),
        'reorder_lessons' => nibwp_lifterlms_reorder_lessons($input),
        'list_memberships' => nibwp_lifterlms_list_memberships($input),
        'get_membership' => nibwp_lifterlms_get_membership($input),
        'list_access_plans' => nibwp_lifterlms_list_access_plans($input),
        'list_course_students' => nibwp_lifterlms_list_course_students($input),
        'get_student_progress' => nibwp_lifterlms_get_student_progress($input),
        'enroll_student' => nibwp_lifterlms_enroll_student($input),
        'unenroll_student' => nibwp_lifterlms_unenroll_student($input),
        'mark_lesson_complete' => nibwp_lifterlms_mark_lesson($input, true),
        'mark_lesson_incomplete' => nibwp_lifterlms_mark_lesson($input, false),
        'list_engagements' => nibwp_lifterlms_list_engagements($input),
        'list_certificates' => nibwp_lifterlms_list_certificates($input),
        'enrollment_counts' => nibwp_lifterlms_enrollment_counts($input),
        'completion_rates' => nibwp_lifterlms_completion_rates($input),
        'stalled_students' => nibwp_lifterlms_stalled_students($input),
        'plan_revenue' => nibwp_lifterlms_plan_revenue($input),
        'audit_courses' => nibwp_lifterlms_audit_courses($input),
        default => nibwp_lifterlms_err('unhandled_action', __('Unhandled action.', 'nibwp')),
    };

    return $result instanceof WP_Error ? $result : ['action' => $action] + $result;
}

/* ------------------------------------------------------------------ courses */

/**
 * Every course this account may read, bounded, with a count of what it could not.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_list_courses(array $input): array|WP_Error
{
    if ($denied = nibwp_lifterlms_require('edit_posts', __('Listing LifterLMS courses', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_lifterlms_limit($input['limit'] ?? null);
    $offset = nibwp_lifterlms_offset($input['offset'] ?? null);

    $query = new WP_Query([
        'post_type' => 'course',
        'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page' => $limit,
        'offset' => $offset,
        'orderby' => 'date',
        'order' => 'DESC',
        'no_found_rows' => false,
        'suppress_filters' => false,
    ]);

    $rows = [];
    $skipped = 0;

    foreach ((array) $query->posts as $post) {
        if (!$post instanceof WP_Post) {
            continue;
        }

        // A private or draft course belongs to whoever may read it, and an
        // instructor listing courses should not learn about somebody else's.
        if (!current_user_can('read_post', $post->ID) && !current_user_can('edit_post', $post->ID)) {
            $skipped++;
            continue;
        }

        $course = class_exists('LLMS_Course') ? new LLMS_Course($post) : null;

        $rows[] = nibwp_lifterlms_post_row($post) + [
            'lessons' => $course !== null && method_exists($course, 'get_lessons_count')
                ? (int) $course->get_lessons_count()
                : null,
            // The cached count LifterLMS keeps on the course
            // (model.llms.course.php:403). A number, not a person.
            'students_enrolled' => $course !== null && method_exists($course, 'get_student_count')
                ? (int) $course->get_student_count()
                : null,
            'enrollment_open' => $course !== null && method_exists($course, 'is_enrollment_open')
                ? (bool) $course->is_enrollment_open()
                : null,
        ];
    }

    return [
        'returned' => count($rows),
        'skipped_unreadable' => $skipped,
        'total_matching' => (int) $query->found_posts,
        'limit' => $limit,
        'offset' => $offset,
        'courses' => $rows,
        'note' => $skipped > 0
            ? __('Some courses were left out because this account may not read them, so the list is partial. The count is above.', 'nibwp')
            : null,
    ];
}

/**
 * One course with its sections and lessons.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_get_course(array $input): array|WP_Error
{
    $post = nibwp_lifterlms_post($input['course_id'] ?? null, 'course');
    if ($post instanceof WP_Error) {
        return $post;
    }

    if ($denied = nibwp_lifterlms_can_edit($post, __('Reading a LifterLMS course', 'nibwp'))) {
        return $denied;
    }

    $course = class_exists('LLMS_Course') ? new LLMS_Course($post) : null;
    $structure = nibwp_lifterlms_structure($post->ID);

    return [
        'course' => nibwp_lifterlms_post_row($post) + [
            'content' => (string) $post->post_content,
            'excerpt' => (string) $post->post_excerpt,
            // LifterLMS has no 'enrollment_status' course property — the 2026
            // version of this integration read one and always got nothing. The
            // real keys are the enrolment window below
            // (model.llms.course.php:68-82) and is_enrollment_open().
            'enrollment_open' => $course !== null && method_exists($course, 'is_enrollment_open')
                ? (bool) $course->is_enrollment_open()
                : null,
            'enrollment_period' => nibwp_lifterlms_prop($course, 'enrollment_period'),
            'enrollment_start_date' => nibwp_lifterlms_prop($course, 'enrollment_start_date'),
            'enrollment_end_date' => nibwp_lifterlms_prop($course, 'enrollment_end_date'),
            'capacity' => $course !== null && method_exists($course, 'has_capacity')
                ? ['enabled' => nibwp_lifterlms_prop($course, 'enable_capacity') === 'yes', 'has_room' => (bool) $course->has_capacity()]
                : null,
            'students_enrolled' => $course !== null && method_exists($course, 'get_student_count')
                ? (int) $course->get_student_count()
                : null,
            'sections' => $structure['sections'],
            'lessons_count' => $structure['lessons_count'],
            'quizzes_count' => $structure['quizzes_count'],
        ],
    ];
}

/**
 * The sections and lessons of a course, through LifterLMS's own models.
 *
 * @return array{sections: array<int, array<string, mixed>>, lessons_count: int, quizzes_count: int}
 */
function nibwp_lifterlms_structure(int $course_id): array
{
    $sections = [];
    $lessons_count = 0;
    $quizzes_count = 0;

    if (!class_exists('LLMS_Course')) {
        return ['sections' => $sections, 'lessons_count' => 0, 'quizzes_count' => 0];
    }

    try {
        $course = new LLMS_Course($course_id);
        $raw = method_exists($course, 'get_sections') ? (array) $course->get_sections('sections') : [];
    } catch (\Throwable $error) {
        return ['sections' => $sections, 'lessons_count' => 0, 'quizzes_count' => 0];
    }

    foreach ($raw as $section) {
        if (!is_object($section) || !method_exists($section, 'get')) {
            continue;
        }

        $lessons = [];

        if (method_exists($section, 'get_lessons')) {
            foreach ((array) $section->get_lessons('lessons') as $lesson) {
                if (!is_object($lesson) || !method_exists($lesson, 'get')) {
                    continue;
                }

                $has_quiz = method_exists($lesson, 'has_quiz') && $lesson->has_quiz();
                $quizzes_count += $has_quiz ? 1 : 0;
                $lessons_count++;

                $lessons[] = [
                    'id' => (int) $lesson->get('id'),
                    'title' => (string) $lesson->get('title'),
                    'status' => (string) $lesson->get('status'),
                    'order' => (int) $lesson->get('order'),
                    'has_quiz' => $has_quiz,
                    'free' => method_exists($lesson, 'is_free') ? (bool) $lesson->is_free() : null,
                ];
            }
        }

        $sections[] = [
            'id' => (int) $section->get('id'),
            'title' => (string) $section->get('title'),
            'order' => (int) $section->get('order'),
            'lessons' => $lessons,
        ];
    }

    return ['sections' => $sections, 'lessons_count' => $lessons_count, 'quizzes_count' => $quizzes_count];
}

/**
 * Create a course.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_create_course(array $input): array|WP_Error
{
    if ($denied = nibwp_lifterlms_require(NIBWP_LLMS_CAP_PUBLISH, __('Creating a LifterLMS course', 'nibwp'))) {
        return $denied;
    }

    $title = isset($input['title']) ? sanitize_text_field((string) $input['title']) : '';

    if ($title === '') {
        return nibwp_lifterlms_err('missing_title', __('Give the course a title.', 'nibwp'), 422);
    }

    $status = nibwp_lifterlms_status($input['status'] ?? 'draft');
    if ($status instanceof WP_Error) {
        return $status;
    }

    // wp_slash, and no kses of our own. wp_insert_post() unslashes what it is
    // handed, so an unslashed write eats an escaped quote or a block comment's
    // &; and sanitising is core's job — it runs the content_save_pre
    // filters, which include wp_filter_post_kses for any account without
    // unfiltered_html. Applying kses here as well mangles block markup a caller
    // legitimately passed. tests/post-content-slash-check.php pins both halves.
    $id = wp_insert_post([
        'post_type' => 'course',
        'post_title' => $title,
        'post_content' => wp_slash(isset($input['content']) ? (string) $input['content'] : ''),
        'post_excerpt' => wp_slash(isset($input['excerpt']) ? (string) $input['excerpt'] : ''),
        'post_status' => $status,
    ], true);

    if ($id instanceof WP_Error) {
        return $id;
    }

    return [
        'created' => true,
        'course_id' => (int) $id,
        'status' => $status,
        'title' => $title,
        'note' => $status === 'publish'
            ? __('Published with no lessons yet, so anybody enrolling now finds an empty course. Add lessons before telling students about it.', 'nibwp')
            : __('Created as a draft, so no student can see it yet.', 'nibwp'),
    ];
}

/** A post status from input, or a refusal naming the ones this hub will set. */
function nibwp_lifterlms_status(mixed $raw): string|WP_Error
{
    $status = is_string($raw) ? strtolower(trim($raw)) : '';

    if ($status === '') {
        return nibwp_lifterlms_err('missing_status', __('Give the status.', 'nibwp'), 422);
    }

    if (!in_array($status, nibwp_lifterlms_statuses(), true)) {
        return nibwp_lifterlms_err(
            'bad_status',
            sprintf(
                /* translators: 1: the rejected status, 2: the statuses allowed */
                __('"%1$s" is not a status this hub sets. Use one of: %2$s. Trashing a course takes it away from every student enrolled in it, which is done in wp-admin.', 'nibwp'),
                $status,
                implode(', ', nibwp_lifterlms_statuses())
            ),
            422
        );
    }

    return $status;
}

/**
 * Update a course's editorial fields.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_update_course(array $input): array|WP_Error
{
    $post = nibwp_lifterlms_post($input['course_id'] ?? null, 'course');
    if ($post instanceof WP_Error) {
        return $post;
    }

    if ($denied = nibwp_lifterlms_can_edit($post, __('Updating a LifterLMS course', 'nibwp'))) {
        return $denied;
    }

    $changed = [];
    $update = ['ID' => (int) $post->ID];

    if (isset($input['title'])) {
        $title = sanitize_text_field((string) $input['title']);

        if ($title === '') {
            return nibwp_lifterlms_err('missing_title', __('A course cannot be given an empty title.', 'nibwp'), 422);
        }

        $update['post_title'] = $title;
        $changed['title'] = ['from' => (string) $post->post_title, 'to' => $title];
    }

    if (isset($input['content'])) {
        $update['post_content'] = wp_kses_post((string) $input['content']);
        $changed['content'] = ['replaced' => true, 'characters' => strlen((string) $update['post_content'])];
    }

    if (isset($input['excerpt'])) {
        $update['post_excerpt'] = wp_kses_post((string) $input['excerpt']);
        $changed['excerpt'] = ['replaced' => true];
    }

    if ($changed === []) {
        return nibwp_lifterlms_err('nothing_to_do', __('Give at least one of title, content or excerpt. Use set_course_status to change the status.', 'nibwp'), 422);
    }

    $result = wp_update_post($update, true);

    if ($result instanceof WP_Error) {
        return $result;
    }

    return [
        'updated' => true,
        'course_id' => (int) $post->ID,
        'changed' => $changed,
    ];
}

/**
 * Publish, unpublish or otherwise restatus a course.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_set_course_status(array $input): array|WP_Error
{
    $post = nibwp_lifterlms_post($input['course_id'] ?? null, 'course');
    if ($post instanceof WP_Error) {
        return $post;
    }

    if ($denied = nibwp_lifterlms_can_edit($post, __('Changing the status of a LifterLMS course', 'nibwp'))) {
        return $denied;
    }

    $status = nibwp_lifterlms_status($input['status'] ?? null);
    if ($status instanceof WP_Error) {
        return $status;
    }

    $before = (string) $post->post_status;

    if ($before === $status) {
        return [
            'changed' => false,
            'course_id' => (int) $post->ID,
            'status' => $status,
            'note' => __('The course was already in that status, so nothing was written.', 'nibwp'),
        ];
    }

    $result = wp_update_post(['ID' => (int) $post->ID, 'post_status' => $status], true);

    if ($result instanceof WP_Error) {
        return $result;
    }

    // Unpublishing a course with students in it is the quiet version of taking
    // access away, so the response says how many people it affected.
    $enrolled = null;

    if ($before === 'publish' && $status !== 'publish' && class_exists('LLMS_Course')) {
        $course = new LLMS_Course($post);
        $enrolled = method_exists($course, 'get_student_count') ? (int) $course->get_student_count() : null;
    }

    return [
        'changed' => true,
        'course_id' => (int) $post->ID,
        'status_before' => $before,
        'status_after' => $status,
        'students_enrolled' => $enrolled,
        'note' => $enrolled !== null && $enrolled > 0
            ? __('This course is no longer published and students are still enrolled in it. They keep their enrolment and their progress, but the course is not publicly visible. audit_courses lists this case.', 'nibwp')
            : null,
    ];
}

/* ------------------------------------------------------- lessons and quizzes */

/**
 * The content tree of one course as a flat listing.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_list_course_content(array $input): array|WP_Error
{
    $post = nibwp_lifterlms_post($input['course_id'] ?? null, 'course');
    if ($post instanceof WP_Error) {
        return $post;
    }

    if ($denied = nibwp_lifterlms_can_edit($post, __('Listing the content of a LifterLMS course', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_lifterlms_limit($input['limit'] ?? null, 50);
    $structure = nibwp_lifterlms_structure((int) $post->ID);

    $flat = [];

    foreach ($structure['sections'] as $section) {
        foreach ((array) ($section['lessons'] ?? []) as $lesson) {
            if (count($flat) >= $limit) {
                break 2;
            }

            $flat[] = $lesson + ['section_id' => $section['id'], 'section_title' => $section['title']];
        }
    }

    return [
        'course_id' => (int) $post->ID,
        'sections' => count($structure['sections']),
        'lessons_count' => $structure['lessons_count'],
        'quizzes_count' => $structure['quizzes_count'],
        'returned' => count($flat),
        'skipped_unreadable' => 0,
        'truncated' => $structure['lessons_count'] > count($flat),
        'limit' => $limit,
        'lessons' => $flat,
    ];
}

/**
 * One lesson.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_get_lesson(array $input): array|WP_Error
{
    $post = nibwp_lifterlms_post($input['lesson_id'] ?? null, 'lesson');
    if ($post instanceof WP_Error) {
        return $post;
    }

    if ($denied = nibwp_lifterlms_can_edit($post, __('Reading a LifterLMS lesson', 'nibwp'))) {
        return $denied;
    }

    $lesson = class_exists('LLMS_Lesson') ? new LLMS_Lesson($post) : null;

    return [
        'lesson' => nibwp_lifterlms_post_row($post) + [
            'content' => (string) $post->post_content,
            'has_content' => $lesson !== null && method_exists($lesson, 'has_content')
                ? (bool) $lesson->has_content()
                : trim(strip_tags((string) $post->post_content)) !== '',
            'parent_course' => (int) nibwp_lifterlms_prop($lesson, 'parent_course', 0),
            'parent_section' => (int) nibwp_lifterlms_prop($lesson, 'parent_section', 0),
            'quiz_id' => (int) nibwp_lifterlms_prop($lesson, 'quiz', 0),
            'free' => $lesson !== null && method_exists($lesson, 'is_free') ? (bool) $lesson->is_free() : null,
            // An orphan is a lesson with no course, which is invisible to every
            // student and the single most common reason a course looks short.
            'orphan' => $lesson !== null && method_exists($lesson, 'is_orphan') ? (bool) $lesson->is_orphan() : null,
            'drip_days' => nibwp_lifterlms_prop($lesson, 'days_before_available'),
        ],
    ];
}

/**
 * One quiz, as structure. No attempt, no grade, no student.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_get_quiz(array $input): array|WP_Error
{
    $post = nibwp_lifterlms_post($input['quiz_id'] ?? null, 'quiz');
    if ($post instanceof WP_Error) {
        return $post;
    }

    if ($denied = nibwp_lifterlms_can_edit($post, __('Reading a LifterLMS quiz', 'nibwp'))) {
        return $denied;
    }

    $quiz = class_exists('LLMS_Quiz') ? new LLMS_Quiz($post) : null;
    $questions = [];

    if ($quiz !== null && method_exists($quiz, 'get_questions')) {
        foreach ((array) $quiz->get_questions('questions') as $question) {
            if (!is_object($question) || !method_exists($question, 'get')) {
                continue;
            }

            // The correct answer is not returned: a quiz an agent can read the
            // answers out of is not a quiz any more.
            $questions[] = [
                'id' => (int) $question->get('id'),
                'title' => (string) $question->get('title'),
                'type' => (string) nibwp_lifterlms_prop($question, 'question_type', ''),
                'points' => (int) $question->get('points'),
            ];
        }
    }

    return [
        'quiz' => nibwp_lifterlms_post_row($post) + [
            'lesson_id' => (int) nibwp_lifterlms_prop($quiz, 'lesson_id', 0),
            'passing_percent' => nibwp_lifterlms_prop($quiz, 'passing_percent'),
            'attempt_limit' => nibwp_lifterlms_prop($quiz, 'allowed_attempts'),
            'time_limit' => nibwp_lifterlms_prop($quiz, 'time_limit'),
            'questions_count' => count($questions),
            'questions' => $questions,
            'correct_answers_returned' => false,
        ],
    ];
}

/**
 * Reorder the lessons of one course.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_reorder_lessons(array $input): array|WP_Error
{
    $post = nibwp_lifterlms_post($input['course_id'] ?? null, 'course');
    if ($post instanceof WP_Error) {
        return $post;
    }

    if ($denied = nibwp_lifterlms_can_edit($post, __('Reordering the lessons of a LifterLMS course', 'nibwp'))) {
        return $denied;
    }

    $wanted = [];

    foreach ((array) ($input['lesson_ids'] ?? []) as $raw) {
        if (is_numeric($raw)) {
            $wanted[] = (int) $raw;
        }
    }

    if ($wanted === []) {
        return nibwp_lifterlms_err('missing_lesson_ids', __('Give lesson_ids as the lesson ids of this course in the order wanted.', 'nibwp'), 422);
    }

    $belongs = nibwp_lifterlms_course_lesson_ids((int) $post->ID);
    $foreign = array_values(array_diff($wanted, $belongs));

    // A lesson from another course would be silently re-parented by a bare
    // set('order'), which is how a reorder quietly empties somebody else's course.
    if ($foreign !== []) {
        return nibwp_lifterlms_err(
            'lesson_not_in_course',
            sprintf(
                /* translators: 1: list of ids, 2: the course id */
                __('These are not lessons of course %2$d: %1$s. Nothing was reordered.', 'nibwp'),
                implode(', ', $foreign),
                (int) $post->ID
            ),
            422
        );
    }

    $moved = [];

    foreach ($wanted as $index => $lesson_id) {
        $lesson_post = nibwp_lifterlms_post($lesson_id, 'lesson');

        if ($lesson_post instanceof WP_Error) {
            return $lesson_post;
        }

        $order = $index + 1;

        if (class_exists('LLMS_Lesson')) {
            $lesson = new LLMS_Lesson($lesson_post);

            if (method_exists($lesson, 'set')) {
                $lesson->set('order', $order);
                $moved[] = ['lesson_id' => $lesson_id, 'order' => $order];
                continue;
            }
        }

        wp_update_post(['ID' => $lesson_id, 'menu_order' => $order], true);
        $moved[] = ['lesson_id' => $lesson_id, 'order' => $order];
    }

    return [
        'reordered' => true,
        'course_id' => (int) $post->ID,
        'lessons_moved' => count($moved),
        'order' => $moved,
        'note' => count($wanted) < count($belongs)
            ? __('Only the lessons named were renumbered, so the ones left out keep the order they had and may now collide. Pass the whole course next time.', 'nibwp')
            : null,
    ];
}

/* ------------------------------------------- memberships and access plans */

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_list_memberships(array $input): array|WP_Error
{
    if ($denied = nibwp_lifterlms_require('edit_posts', __('Listing LifterLMS memberships', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_lifterlms_limit($input['limit'] ?? null);
    $offset = nibwp_lifterlms_offset($input['offset'] ?? null);

    $query = new WP_Query([
        'post_type' => 'llms_membership',
        'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page' => $limit,
        'offset' => $offset,
        'orderby' => 'date',
        'order' => 'DESC',
        'no_found_rows' => false,
    ]);

    $rows = [];
    $skipped = 0;

    foreach ((array) $query->posts as $post) {
        if (!$post instanceof WP_Post) {
            continue;
        }

        if (!current_user_can('read_post', $post->ID) && !current_user_can('edit_post', $post->ID)) {
            $skipped++;
            continue;
        }

        $membership = class_exists('LLMS_Membership') ? new LLMS_Membership($post) : null;

        $rows[] = nibwp_lifterlms_post_row($post) + [
            'students_enrolled' => $membership !== null && method_exists($membership, 'get_student_count')
                ? (int) $membership->get_student_count()
                : null,
        ];
    }

    return [
        'returned' => count($rows),
        'skipped_unreadable' => $skipped,
        'total_matching' => (int) $query->found_posts,
        'limit' => $limit,
        'offset' => $offset,
        'memberships' => $rows,
        'note' => $skipped > 0
            ? __('Some memberships were left out because this account may not read them, so the list is partial.', 'nibwp')
            : null,
    ];
}

/**
 * One membership, and what it grants.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_get_membership(array $input): array|WP_Error
{
    $post = nibwp_lifterlms_post($input['membership_id'] ?? null, 'membership');
    if ($post instanceof WP_Error) {
        return $post;
    }

    if ($denied = nibwp_lifterlms_can_edit($post, __('Reading a LifterLMS membership', 'nibwp'))) {
        return $denied;
    }

    $membership = class_exists('LLMS_Membership') ? new LLMS_Membership($post) : null;
    $grants = [];

    if ($membership !== null && method_exists($membership, 'get_auto_enroll_courses')) {
        foreach ((array) $membership->get_auto_enroll_courses() as $course_id) {
            $course_post = get_post((int) $course_id);

            if ($course_post instanceof WP_Post) {
                $grants[] = ['course_id' => (int) $course_post->ID, 'title' => (string) $course_post->post_title, 'status' => (string) $course_post->post_status];
            }
        }
    }

    return [
        'membership' => nibwp_lifterlms_post_row($post) + [
            'content' => (string) $post->post_content,
            'students_enrolled' => $membership !== null && method_exists($membership, 'get_student_count')
                ? (int) $membership->get_student_count()
                : null,
            'auto_enrolls_into' => $grants,
            'restriction_redirect' => nibwp_lifterlms_prop($membership, 'restriction_redirect_type'),
        ],
    ];
}

/**
 * The access plans of one course or membership, and what each one grants.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_list_access_plans(array $input): array|WP_Error
{
    $raw = $input['course_id'] ?? ($input['membership_id'] ?? null);
    $post = nibwp_lifterlms_post($raw, 'product');
    if ($post instanceof WP_Error) {
        return $post;
    }

    if ($denied = nibwp_lifterlms_can_edit($post, __('Listing the access plans of a LifterLMS product', 'nibwp'))) {
        return $denied;
    }

    if (!class_exists('LLMS_Product')) {
        return nibwp_lifterlms_err('no_product_api', __('This version of LifterLMS does not expose access plans where this integration can read them.', 'nibwp'), 500);
    }

    $limit = nibwp_lifterlms_limit($input['limit'] ?? null);
    $product = new LLMS_Product($post);
    $plans = method_exists($product, 'get_access_plans') ? (array) $product->get_access_plans(false, false) : [];

    $rows = [];
    $skipped = 0;

    foreach ($plans as $plan) {
        if (count($rows) >= $limit) {
            break;
        }

        if (!is_object($plan) || !method_exists($plan, 'get')) {
            $skipped++;
            continue;
        }

        $plan_id = (int) $plan->get('id');

        if ($plan_id > 0 && !current_user_can('edit_post', $plan_id) && !current_user_can('edit_post', $post->ID)) {
            $skipped++;
            continue;
        }

        // The plan grants access to its own product; when that product is a
        // membership, it also grants the courses the membership auto-enrols into.
        $grants = [['id' => (int) $post->ID, 'type' => (string) $post->post_type, 'title' => (string) $post->post_title]];

        if ($post->post_type === 'llms_membership' && class_exists('LLMS_Membership')) {
            $membership = new LLMS_Membership($post);

            if (method_exists($membership, 'get_auto_enroll_courses')) {
                foreach ((array) $membership->get_auto_enroll_courses() as $course_id) {
                    $course_post = get_post((int) $course_id);

                    if ($course_post instanceof WP_Post) {
                        $grants[] = ['id' => (int) $course_post->ID, 'type' => 'course', 'title' => (string) $course_post->post_title];
                    }
                }
            }
        }

        $rows[] = [
            'id' => $plan_id,
            'title' => (string) $plan->get('title'),
            'price' => method_exists($plan, 'get_price') ? (string) $plan->get_price('price', [], 'float') : null,
            'frequency' => (int) $plan->get('frequency'),
            'period' => (string) $plan->get('period'),
            'is_free' => method_exists($plan, 'is_free') ? (bool) $plan->is_free() : null,
            'is_recurring' => method_exists($plan, 'is_recurring') ? (bool) $plan->is_recurring() : null,
            'on_sale' => method_exists($plan, 'is_on_sale') ? (bool) $plan->is_on_sale() : null,
            'has_trial' => method_exists($plan, 'has_trial') ? (bool) $plan->has_trial() : null,
            'visibility' => method_exists($plan, 'get_visibility') ? (string) $plan->get_visibility() : null,
            'grants_access_to' => $grants,
        ];
    }

    return [
        'product_id' => (int) $post->ID,
        'product_type' => (string) $post->post_type,
        'returned' => count($rows),
        'skipped_unreadable' => $skipped,
        'limit' => $limit,
        'plans' => $rows,
        'note' => __('A plan is how somebody pays for access. Changing one changes what new buyers get, so plans are read-only here.', 'nibwp'),
    ];
}

/* ----------------------------------------------------------------- students */

/**
 * The students enrolled in one course.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_list_course_students(array $input): array|WP_Error
{
    $post = nibwp_lifterlms_post($input['course_id'] ?? null, 'product');
    if ($post instanceof WP_Error) {
        return $post;
    }

    // Students, so the reporting capability — not the course's edit capability.
    if ($denied = nibwp_lifterlms_require_reports(__('Listing the students of a LifterLMS course', 'nibwp'))) {
        return $denied;
    }

    if (!class_exists('LLMS_Student_Query')) {
        return nibwp_lifterlms_err('no_student_api', __('This version of LifterLMS does not expose its student query where this integration can read it.', 'nibwp'), 500);
    }

    $limit = nibwp_lifterlms_limit($input['limit'] ?? null);
    $page = (int) floor(nibwp_lifterlms_offset($input['offset'] ?? null) / $limit) + 1;

    $statuses = ['enrolled', 'expired', 'cancelled'];
    $wanted = isset($input['enrollment_status']) ? strtolower(trim((string) $input['enrollment_status'])) : '';

    if ($wanted !== '' && !in_array($wanted, $statuses, true)) {
        return nibwp_lifterlms_err(
            'bad_enrollment_status',
            sprintf(
                /* translators: %s: the statuses allowed */
                __('enrollment_status must be one of: %s.', 'nibwp'),
                implode(', ', $statuses)
            ),
            422
        );
    }

    $query = new LLMS_Student_Query([
        'post_id' => (int) $post->ID,
        'statuses' => $wanted === '' ? $statuses : [$wanted],
        'per_page' => $limit,
        'page' => max(1, $page),
        'search' => isset($input['search']) ? sanitize_text_field((string) $input['search']) : '',
    ]);

    $students = method_exists($query, 'get_students') ? (array) $query->get_students() : [];
    $rows = [];
    $skipped = 0;

    foreach ($students as $student) {
        if (!is_object($student) || !method_exists($student, 'get')) {
            $skipped++;
            continue;
        }

        $user_id = (int) $student->get('id');

        // The row names one person, so the per-student check applies to it and
        // not only the role that got us into the listing.
        if (nibwp_lifterlms_require_student($user_id, __('Reading a student record', 'nibwp')) !== null) {
            $skipped++;
            continue;
        }

        $rows[] = nibwp_lifterlms_student_record([
            'id' => $user_id,
            'display_name' => method_exists($student, 'get_name') ? $student->get_name() : null,
            'email' => $student->get('user_email'),
            'course_id' => (int) $post->ID,
            'enrollment_status' => method_exists($student, 'get_enrollment_status') ? $student->get_enrollment_status($post->ID) : null,
            'enrollment_date' => method_exists($student, 'get_enrollment_date') ? $student->get_enrollment_date($post->ID) : null,
            'progress_percent' => method_exists($student, 'get_progress') ? $student->get_progress($post->ID, 'course') : null,
            'grade' => method_exists($student, 'get_grade') ? $student->get_grade($post->ID) : null,
        ]);
    }

    return [
        'course_id' => (int) $post->ID,
        'returned' => count($rows),
        'skipped_unreadable' => $skipped,
        'limit' => $limit,
        'students' => $rows,
        'allow_list_applied' => true,
        'note' => $skipped > 0
            ? __('Some students were left out because this account may not read their records, so the list is partial. The count is above.', 'nibwp')
            : __('These are real people. Report what was asked for and nothing more.', 'nibwp'),
    ];
}

/**
 * One student's way through one course.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_get_student_progress(array $input): array|WP_Error
{
    $post = nibwp_lifterlms_post($input['course_id'] ?? null, 'product');
    if ($post instanceof WP_Error) {
        return $post;
    }

    $user_id = is_numeric($input['user_id'] ?? null) ? (int) $input['user_id'] : 0;

    if ($user_id <= 0) {
        return nibwp_lifterlms_err('missing_user_id', __('Give the student as user_id.', 'nibwp'), 422);
    }

    if ($denied = nibwp_lifterlms_require_student($user_id, __('Reading a LifterLMS student\'s progress', 'nibwp'))) {
        return $denied;
    }

    if (!class_exists('LLMS_Student')) {
        return nibwp_lifterlms_err('no_student_api', __('This version of LifterLMS does not expose its student model where this integration can read it.', 'nibwp'), 500);
    }

    $student = new LLMS_Student($user_id);
    $lessons = nibwp_lifterlms_course_lesson_ids((int) $post->ID);
    $completed = 0;
    $per_lesson = [];

    foreach ($lessons as $lesson_id) {
        $done = method_exists($student, 'is_complete') && $student->is_complete($lesson_id, 'lesson');
        $completed += $done ? 1 : 0;

        $lesson_post = get_post($lesson_id);
        $per_lesson[] = [
            'lesson_id' => $lesson_id,
            'title' => $lesson_post instanceof WP_Post ? (string) $lesson_post->post_title : null,
            'complete' => $done,
        ];
    }

    $last = method_exists($student, 'get_last_completed_lesson') ? (int) $student->get_last_completed_lesson((int) $post->ID) : 0;
    $last_post = $last > 0 ? get_post($last) : null;

    return [
        'progress' => nibwp_lifterlms_student_record([
            'id' => $user_id,
            'course_id' => (int) $post->ID,
            'display_name' => method_exists($student, 'get_name') ? $student->get_name() : null,
            'enrollment_status' => method_exists($student, 'get_enrollment_status') ? $student->get_enrollment_status((int) $post->ID) : null,
            'enrollment_date' => method_exists($student, 'get_enrollment_date') ? $student->get_enrollment_date((int) $post->ID) : null,
            'enrollment_trigger' => method_exists($student, 'get_enrollment_trigger') ? $student->get_enrollment_trigger((int) $post->ID) : null,
            'progress_percent' => method_exists($student, 'get_progress') ? $student->get_progress((int) $post->ID, 'course') : null,
            'grade' => method_exists($student, 'get_grade') ? $student->get_grade((int) $post->ID) : null,
            'is_complete' => method_exists($student, 'is_complete') ? $student->is_complete((int) $post->ID, 'course') : null,
            'completion_date' => method_exists($student, 'get_completion_date') ? $student->get_completion_date((int) $post->ID) : null,
            'lessons_completed' => $completed,
            'lessons_total' => count($lessons),
            'last_completed_lesson_id' => $last,
            'last_completed_lesson' => $last_post instanceof WP_Post ? (string) $last_post->post_title : null,
        ]),
        'lessons' => $per_lesson,
        'allow_list_applied' => true,
    ];
}

/**
 * Grant a student access to a course or membership.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_enroll_student(array $input): array|WP_Error
{
    $raw = $input['course_id'] ?? ($input['membership_id'] ?? null);
    $post = nibwp_lifterlms_post($raw, 'product');
    if ($post instanceof WP_Error) {
        return $post;
    }

    if ($denied = nibwp_lifterlms_require(NIBWP_LLMS_CAP_ENROLL, __('Enrolling a student in LifterLMS', 'nibwp'))) {
        return $denied;
    }

    $user_id = is_numeric($input['user_id'] ?? null) ? (int) $input['user_id'] : 0;

    if ($user_id <= 0) {
        return nibwp_lifterlms_err('missing_user_id', __('Give the student as user_id.', 'nibwp'), 422);
    }

    if (!function_exists('llms_enroll_student') || !class_exists('LLMS_Student')) {
        return nibwp_lifterlms_err('no_enroll_api', __('This version of LifterLMS does not expose a way to enrol a student from here.', 'nibwp'), 500);
    }

    $student = new LLMS_Student($user_id);
    $before = method_exists($student, 'get_enrollment_status') ? $student->get_enrollment_status((int) $post->ID) : null;

    if ($before === 'enrolled') {
        return [
            'enrolled' => false,
            'already_enrolled' => true,
            'user_id' => $user_id,
            'product_id' => (int) $post->ID,
            'status_before' => 'enrolled',
            'status_after' => 'enrolled',
            'note' => __('That student was already enrolled, so nothing changed.', 'nibwp'),
        ];
    }

    // The trigger is recorded against the enrolment, so later somebody can see
    // this was not a purchase. LifterLMS reads it back in unenroll().
    $trigger = 'admin_' . (string) get_current_user_id();
    $result = (bool) llms_enroll_student($user_id, (int) $post->ID, $trigger);

    $after = method_exists($student, 'get_enrollment_status') ? $student->get_enrollment_status((int) $post->ID, false) : null;

    return [
        'enrolled' => $result,
        'user_id' => $user_id,
        'product_id' => (int) $post->ID,
        'product_type' => (string) $post->post_type,
        'product_title' => (string) $post->post_title,
        'status_before' => is_string($before) ? $before : null,
        'status_after' => is_string($after) ? $after : null,
        'trigger' => $trigger,
        'note' => $result
            ? __('That student can now reach this course or membership, and everything it grants, immediately. Say which student and which course back to whoever asked.', 'nibwp')
            : __('LifterLMS declined the enrolment and did not say why. Check the student exists and the product has an access plan.', 'nibwp'),
    ];
}

/**
 * Take a student's access away.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_unenroll_student(array $input): array|WP_Error
{
    $raw = $input['course_id'] ?? ($input['membership_id'] ?? null);
    $post = nibwp_lifterlms_post($raw, 'product');
    if ($post instanceof WP_Error) {
        return $post;
    }

    if ($denied = nibwp_lifterlms_require(NIBWP_LLMS_CAP_UNENROLL, __('Unenrolling a student from LifterLMS', 'nibwp'))) {
        return $denied;
    }

    $user_id = is_numeric($input['user_id'] ?? null) ? (int) $input['user_id'] : 0;

    if ($user_id <= 0) {
        return nibwp_lifterlms_err('missing_user_id', __('Give the student as user_id.', 'nibwp'), 422);
    }

    if (!function_exists('llms_unenroll_student') || !class_exists('LLMS_Student')) {
        return nibwp_lifterlms_err('no_enroll_api', __('This version of LifterLMS does not expose a way to unenrol a student from here.', 'nibwp'), 500);
    }

    $student = new LLMS_Student($user_id);
    $before = method_exists($student, 'get_enrollment_status') ? $student->get_enrollment_status((int) $post->ID) : null;

    if ($before === false || $before === null || $before === '') {
        return [
            'unenrolled' => false,
            'was_not_enrolled' => true,
            'user_id' => $user_id,
            'product_id' => (int) $post->ID,
            'note' => __('That student has no enrolment record for this course or membership, so nothing changed.', 'nibwp'),
        ];
    }

    $progress_before = method_exists($student, 'get_progress') ? $student->get_progress((int) $post->ID, 'course') : null;

    // 'expired' is LifterLMS's own default for this call
    // (llms.functions.person.php:526). It writes one status row and nothing else.
    $result = (bool) llms_unenroll_student($user_id, (int) $post->ID, 'expired', 'any');

    $after = method_exists($student, 'get_enrollment_status') ? $student->get_enrollment_status((int) $post->ID, false) : null;
    $progress_after = method_exists($student, 'get_progress') ? $student->get_progress((int) $post->ID, 'course', false) : null;

    return [
        'unenrolled' => $result,
        'user_id' => $user_id,
        'product_id' => (int) $post->ID,
        'product_type' => (string) $post->post_type,
        'product_title' => (string) $post->post_title,
        'status_before' => is_string($before) ? $before : null,
        'status_after' => is_string($after) ? $after : null,
        'progress_percent_before' => $progress_before,
        'progress_percent_after' => $progress_after,
        'progress_destroyed' => false,
        'note' => $result
            ? __('That student can no longer reach this course or membership. Their lesson completions were NOT erased — LifterLMS marks the enrolment expired and leaves the progress records in place, so re-enrolling restores it. If the student was in a membership, the courses it auto-enrolled them into have gone too.', 'nibwp')
            : __('LifterLMS declined the unenrolment, which it does when the enrolment trigger does not match. Nothing changed.', 'nibwp'),
    ];
}

/**
 * Mark one lesson complete or incomplete for one student.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_mark_lesson(array $input, bool $complete): array|WP_Error
{
    $post = nibwp_lifterlms_post($input['lesson_id'] ?? null, 'lesson');
    if ($post instanceof WP_Error) {
        return $post;
    }

    $what = $complete
        ? __('Marking a lesson complete for a student', 'nibwp')
        : __('Marking a lesson incomplete for a student', 'nibwp');

    if ($denied = nibwp_lifterlms_require(NIBWP_LLMS_CAP_STUDENTS, $what)) {
        return $denied;
    }

    $user_id = is_numeric($input['user_id'] ?? null) ? (int) $input['user_id'] : 0;

    if ($user_id <= 0) {
        return nibwp_lifterlms_err('missing_user_id', __('Give the student as user_id.', 'nibwp'), 422);
    }

    if ($denied = nibwp_lifterlms_require_student($user_id, $what)) {
        return $denied;
    }

    $function = $complete ? 'llms_mark_complete' : 'llms_mark_incomplete';

    if (!function_exists($function) || !class_exists('LLMS_Student')) {
        return nibwp_lifterlms_err('no_progress_api', __('This version of LifterLMS does not expose a way to change a completion record from here.', 'nibwp'), 500);
    }

    $student = new LLMS_Student($user_id);
    $before = method_exists($student, 'is_complete') ? (bool) $student->is_complete((int) $post->ID, 'lesson') : null;

    $result = (bool) $function($user_id, (int) $post->ID, 'lesson', 'admin_' . (string) get_current_user_id());

    $after = method_exists($student, 'is_complete') ? (bool) $student->is_complete((int) $post->ID, 'lesson') : null;
    $course_id = (int) nibwp_lifterlms_prop(class_exists('LLMS_Lesson') ? new LLMS_Lesson($post) : null, 'parent_course', 0);

    return [
        'changed' => $result && $before !== $after,
        'complete' => $complete,
        'user_id' => $user_id,
        'lesson_id' => (int) $post->ID,
        'lesson_title' => (string) $post->post_title,
        'course_id' => $course_id,
        'was_complete' => $before,
        'is_complete' => $after,
        'note' => $complete
            ? __('This moves the student forward in the course and can release a dripped lesson, award a certificate, and send whatever engagement is attached to completing it. Those run; they are not previewed.', 'nibwp')
            : __('This moves the student backwards. LifterLMS keeps the record and flips it to "no" rather than deleting it, so the date they first finished is still there.', 'nibwp'),
    ];
}

/* ------------------------------------------- engagements and certificates */

/**
 * The engagements configured on this site.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_list_engagements(array $input): array|WP_Error
{
    if ($denied = nibwp_lifterlms_require('manage_lifterlms', __('Listing LifterLMS engagements', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_lifterlms_limit($input['limit'] ?? null);
    $offset = nibwp_lifterlms_offset($input['offset'] ?? null);

    $query = new WP_Query([
        'post_type' => 'llms_engagement',
        'post_status' => ['publish', 'draft'],
        'posts_per_page' => $limit,
        'offset' => $offset,
        'no_found_rows' => false,
    ]);

    $rows = [];
    $skipped = 0;

    foreach ((array) $query->posts as $post) {
        if (!$post instanceof WP_Post) {
            continue;
        }

        if (!current_user_can('edit_post', $post->ID)) {
            $skipped++;
            continue;
        }

        // The meta keys are LifterLMS's own, read off
        // includes/class.llms.engagements.php:184-190.
        $rows[] = nibwp_lifterlms_post_row($post) + [
            'engagement_type' => (string) get_post_meta($post->ID, '_llms_engagement_type', true),
            'trigger_type' => (string) get_post_meta($post->ID, '_llms_trigger_type', true),
            'engagement_id' => (int) get_post_meta($post->ID, '_llms_engagement', true),
            'delay_days' => (int) get_post_meta($post->ID, '_llms_engagement_delay', true),
        ];
    }

    return [
        'returned' => count($rows),
        'skipped_unreadable' => $skipped,
        'total_matching' => (int) $query->found_posts,
        'limit' => $limit,
        'engagements' => $rows,
        'note' => __('These fire on their own when a student does the thing that triggers them. Marking a lesson complete through this hub can set one off.', 'nibwp'),
    ];
}

/**
 * Certificate templates, and how many have been awarded.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_list_certificates(array $input): array|WP_Error
{
    // Awarded certificates are student records, so this is a report.
    if ($denied = nibwp_lifterlms_require_reports(__('Listing LifterLMS certificates', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    $limit = nibwp_lifterlms_limit($input['limit'] ?? null);
    $offset = nibwp_lifterlms_offset($input['offset'] ?? null);

    $query = new WP_Query([
        'post_type' => 'llms_certificate',
        'post_status' => ['publish', 'draft'],
        'posts_per_page' => $limit,
        'offset' => $offset,
        'no_found_rows' => false,
    ]);

    $awarded = [];

    // Counts per template, not a list of recipients: the question is which
    // certificates get earned, and a count answers it without naming anybody.
    if (is_object($wpdb) && method_exists($wpdb, 'get_results')) {
        $sql = $wpdb->prepare(
            'SELECT pm.meta_value AS template_id, COUNT(*) AS awarded'
            . ' FROM ' . $wpdb->posts . ' AS p'
            . ' INNER JOIN ' . $wpdb->postmeta . ' AS pm ON pm.post_id = p.ID AND pm.meta_key = %s'
            . ' WHERE p.post_type = %s AND p.post_status = %s'
            . ' GROUP BY pm.meta_value LIMIT %d',
            '_llms_certificate_template',
            'llms_my_certificate',
            'publish',
            NIBWP_LLMS_MAX_ROWS
        );

        foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $row) {
            $awarded[(int) ($row['template_id'] ?? 0)] = (int) ($row['awarded'] ?? 0);
        }
    }

    $rows = [];
    $skipped = 0;

    foreach ((array) $query->posts as $post) {
        if (!$post instanceof WP_Post) {
            continue;
        }

        if (!current_user_can('edit_post', $post->ID) && !current_user_can(NIBWP_LLMS_CAP_OTHERS)) {
            $skipped++;
            continue;
        }

        $rows[] = nibwp_lifterlms_post_row($post) + [
            'times_awarded' => (int) ($awarded[(int) $post->ID] ?? 0),
            'recipients_returned' => false,
        ];
    }

    return [
        'returned' => count($rows),
        'skipped_unreadable' => $skipped,
        'total_matching' => (int) $query->found_posts,
        'limit' => $limit,
        'certificates' => $rows,
        'note' => __('Counts per template. Who holds a certificate is not returned here, and nothing in this hub issues or revokes one.', 'nibwp'),
    ];
}

/* ---------------------------------------------------------------- reporting */

/**
 * How many students are enrolled in each course, by status.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_enrollment_counts(array $input): array|WP_Error
{
    if ($denied = nibwp_lifterlms_require_reports(__('Reading LifterLMS enrolment counts', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
        return nibwp_lifterlms_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    $limit = nibwp_lifterlms_limit($input['limit'] ?? null);
    $course_id = is_numeric($input['course_id'] ?? null) ? (int) $input['course_id'] : 0;

    if ($course_id > 0) {
        $post = nibwp_lifterlms_post($course_id, 'product');

        if ($post instanceof WP_Error) {
            return $post;
        }
    }

    // One row per course per status. The table name comes from $wpdb->prefix,
    // never from input; every value is bound.
    $table = $wpdb->prefix . 'lifterlms_user_postmeta';

    if ($course_id > 0) {
        $sql = $wpdb->prepare(
            'SELECT upm.post_id, upm.meta_value AS status, COUNT(DISTINCT upm.user_id) AS students'
            . ' FROM ' . $table . ' AS upm'
            . ' WHERE upm.meta_key = %s AND upm.post_id = %d'
            . ' GROUP BY upm.post_id, upm.meta_value LIMIT %d',
            '_status',
            $course_id,
            $limit
        );
    } else {
        $sql = $wpdb->prepare(
            'SELECT upm.post_id, upm.meta_value AS status, COUNT(DISTINCT upm.user_id) AS students'
            . ' FROM ' . $table . ' AS upm'
            . ' INNER JOIN ' . $wpdb->posts . ' AS p ON p.ID = upm.post_id'
            . ' WHERE upm.meta_key = %s AND p.post_type IN (%s, %s)'
            . ' GROUP BY upm.post_id, upm.meta_value LIMIT %d',
            '_status',
            'course',
            'llms_membership',
            $limit
        );
    }

    $per_course = [];
    $skipped = 0;

    foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $row) {
        $id = (int) ($row['post_id'] ?? 0);

        if ($id <= 0) {
            continue;
        }

        if (!isset($per_course[$id])) {
            // A course this account may not read is a course whose enrolment
            // numbers it has no business knowing.
            if (!current_user_can('edit_post', $id) && !current_user_can(NIBWP_LLMS_CAP_OTHERS)) {
                $per_course[$id] = false;
                $skipped++;
                continue;
            }

            $post = get_post($id);
            $per_course[$id] = [
                'course_id' => $id,
                'title' => $post instanceof WP_Post ? (string) $post->post_title : null,
                'type' => $post instanceof WP_Post ? (string) $post->post_type : null,
                'status' => $post instanceof WP_Post ? (string) $post->post_status : null,
                'by_status' => [],
                'total' => 0,
            ];
        }

        if ($per_course[$id] === false) {
            continue;
        }

        $students = (int) ($row['students'] ?? 0);
        $per_course[$id]['by_status'][(string) ($row['status'] ?? '')] = $students;
        $per_course[$id]['total'] += $students;
    }

    $rows = array_values(array_filter($per_course, static fn ($row): bool => is_array($row)));

    return [
        'returned' => count($rows),
        'skipped_unreadable' => $skipped,
        'limit' => $limit,
        'counts' => $rows,
        'students_named' => false,
        'note' => __('Counts only, by enrolment status. "expired" is what unenrolling leaves behind, so a high expired count is a history of people losing access rather than a problem with the course.', 'nibwp'),
    ];
}

/**
 * What fraction of the people who started a course finished it.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_completion_rates(array $input): array|WP_Error
{
    if ($denied = nibwp_lifterlms_require_reports(__('Reading LifterLMS completion rates', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
        return nibwp_lifterlms_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    $limit = nibwp_lifterlms_limit($input['limit'] ?? null);
    $table = $wpdb->prefix . 'lifterlms_user_postmeta';

    // LifterLMS writes a '_is_complete' = 'yes' row against the COURSE id when a
    // student finishes it, the same row its own get_completed_courses() reads
    // (model.llms.student.php:325-333). So enrolled and finished come out of one
    // table with two conditions, which is cheap enough to do site-wide.
    $sql = $wpdb->prepare(
        'SELECT p.ID AS course_id,'
        . ' SUM(CASE WHEN upm.meta_key = %s AND upm.meta_value = %s THEN 1 ELSE 0 END) AS enrolled,'
        . ' SUM(CASE WHEN upm.meta_key = %s AND upm.meta_value = %s THEN 1 ELSE 0 END) AS finished'
        . ' FROM ' . $wpdb->posts . ' AS p'
        . ' INNER JOIN ' . $table . ' AS upm ON upm.post_id = p.ID'
        . ' WHERE p.post_type = %s'
        . ' GROUP BY p.ID ORDER BY enrolled DESC LIMIT %d',
        '_status',
        'enrolled',
        '_is_complete',
        'yes',
        'course',
        $limit
    );

    $rows = [];
    $skipped = 0;

    foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $row) {
        $course_id = (int) ($row['course_id'] ?? 0);

        if ($course_id <= 0) {
            continue;
        }

        if (!current_user_can('edit_post', $course_id) && !current_user_can(NIBWP_LLMS_CAP_OTHERS)) {
            $skipped++;
            continue;
        }

        $enrolled = (int) ($row['enrolled'] ?? 0);
        $finished = (int) ($row['finished'] ?? 0);
        $post = get_post($course_id);

        $rows[] = [
            'course_id' => $course_id,
            'title' => $post instanceof WP_Post ? (string) $post->post_title : null,
            'status' => $post instanceof WP_Post ? (string) $post->post_status : null,
            'enrolled' => $enrolled,
            'finished' => $finished,
            'completion_rate_percent' => $enrolled > 0 ? round(($finished / $enrolled) * 100, 2) : null,
        ];
    }

    return [
        'returned' => count($rows),
        'skipped_unreadable' => $skipped,
        'limit' => $limit,
        'rates' => $rows,
        'students_named' => false,
        'note' => __('A rate of null means nobody is currently enrolled, which is not the same as nobody finishing. A course with enrolments and no finishers is in the audit.', 'nibwp'),
    ];
}

/**
 * Students who stopped partway through a course, and the lesson they stopped on.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_stalled_students(array $input): array|WP_Error
{
    $post = nibwp_lifterlms_post($input['course_id'] ?? null, 'course');
    if ($post instanceof WP_Error) {
        return $post;
    }

    if ($denied = nibwp_lifterlms_require_reports(__('Reading which LifterLMS students have stalled', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
        return nibwp_lifterlms_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    $days = is_numeric($input['days'] ?? null) ? (int) $input['days'] : 30;
    $days = max(1, min(365, $days));
    $limit = nibwp_lifterlms_limit($input['limit'] ?? null);
    $lessons = nibwp_lifterlms_course_lesson_ids((int) $post->ID);

    if ($lessons === []) {
        return [
            'course_id' => (int) $post->ID,
            'days' => $days,
            'returned' => 0,
            'skipped_unreadable' => 0,
            'stalled' => [],
            'note' => __('This course has no lessons, so nobody can be partway through it. audit_courses reports that as a fault.', 'nibwp'),
        ];
    }

    $table = $wpdb->prefix . 'lifterlms_user_postmeta';
    $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
    $placeholders = nibwp_lifterlms_placeholders($lessons);

    // The self-join finds, for each student, the lesson of THIS course they
    // completed most recently — not merely one they completed a while ago, which
    // would report somebody still working through the course as stalled.
    $args = array_merge(
        ['_is_complete', 'yes'],
        $lessons,
        ['_is_complete', 'yes'],
        $lessons,
        [$cutoff, $limit]
    );

    $sql = $wpdb->prepare(
        'SELECT a.user_id, a.post_id AS lesson_id, a.updated_date'
        . ' FROM ' . $table . ' AS a'
        . ' INNER JOIN ('
        . ' SELECT user_id, MAX(updated_date) AS last_at FROM ' . $table
        . ' WHERE meta_key = %s AND meta_value = %s AND post_id IN (' . $placeholders . ')'
        . ' GROUP BY user_id'
        . ' ) AS b ON b.user_id = a.user_id AND b.last_at = a.updated_date'
        . ' WHERE a.meta_key = %s AND a.meta_value = %s AND a.post_id IN (' . $placeholders . ')'
        . ' AND b.last_at < %s'
        . ' ORDER BY a.updated_date ASC LIMIT %d',
        ...$args
    );

    $rows = [];
    $skipped = 0;
    $student_class = class_exists('LLMS_Student');

    foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $row) {
        $user_id = (int) ($row['user_id'] ?? 0);

        if ($user_id <= 0) {
            continue;
        }

        if (nibwp_lifterlms_require_student($user_id, __('Reading a student record', 'nibwp')) !== null) {
            $skipped++;
            continue;
        }

        $student = $student_class ? new LLMS_Student($user_id) : null;

        // Somebody who finished the course is not stalled, they are done.
        if ($student !== null && method_exists($student, 'is_complete') && $student->is_complete((int) $post->ID, 'course')) {
            continue;
        }

        $lesson_id = (int) ($row['lesson_id'] ?? 0);
        $lesson_post = $lesson_id > 0 ? get_post($lesson_id) : null;

        $rows[] = nibwp_lifterlms_student_record([
            'id' => $user_id,
            'course_id' => (int) $post->ID,
            'display_name' => $student !== null && method_exists($student, 'get_name') ? $student->get_name() : null,
            'enrollment_status' => $student !== null && method_exists($student, 'get_enrollment_status') ? $student->get_enrollment_status((int) $post->ID) : null,
            'progress_percent' => $student !== null && method_exists($student, 'get_progress') ? $student->get_progress((int) $post->ID, 'course') : null,
            'last_completed_lesson_id' => $lesson_id,
            'last_completed_lesson' => $lesson_post instanceof WP_Post ? (string) $lesson_post->post_title : null,
            'last_activity' => (string) ($row['updated_date'] ?? ''),
        ]);
    }

    // Which lesson loses the most people is the actionable half of this.
    $by_lesson = [];

    foreach ($rows as $row) {
        $key = (int) ($row['last_completed_lesson_id'] ?? 0);
        $by_lesson[$key] = (int) ($by_lesson[$key] ?? 0) + 1;
    }

    arsort($by_lesson);
    $worst = [];

    foreach ($by_lesson as $lesson_id => $count) {
        $lesson_post = get_post((int) $lesson_id);
        $worst[] = [
            'lesson_id' => (int) $lesson_id,
            'title' => $lesson_post instanceof WP_Post ? (string) $lesson_post->post_title : null,
            'students_stopped_after' => $count,
        ];
    }

    return [
        'course_id' => (int) $post->ID,
        'days' => $days,
        'returned' => count($rows),
        'skipped_unreadable' => $skipped,
        'limit' => $limit,
        'stalled' => $rows,
        'by_lesson' => $worst,
        'allow_list_applied' => true,
        'note' => __('Stalled means the last lesson of this course they finished was longer ago than the window, and they have not finished the course. A student who never completed a single lesson has no record to read and is not here.', 'nibwp'),
    ];
}

/**
 * Gross order value per access plan.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_plan_revenue(array $input): array|WP_Error
{
    if ($denied = nibwp_lifterlms_require('manage_lifterlms', __('Reading LifterLMS revenue per access plan', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
        return nibwp_lifterlms_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    if (!post_type_exists('llms_order')) {
        return nibwp_lifterlms_err(
            'no_orders',
            __('This site has no LifterLMS order post type, so there is no revenue to report. A site selling through another plugin keeps its money there.', 'nibwp'),
            400
        );
    }

    $limit = nibwp_lifterlms_limit($input['limit'] ?? null);

    // _llms_plan_id and _llms_total: LifterLMS prefixes every model property with
    // _llms_ (abstract.llms.post.model.php:90) and the order declares plan_id and
    // total among them (model.llms.order.php:59,125).
    $sql = $wpdb->prepare(
        'SELECT plan.meta_value AS plan_id, COUNT(*) AS orders,'
        . ' SUM(CASE WHEN total.meta_value IS NULL THEN 0 ELSE CAST(total.meta_value AS DECIMAL(18,2)) END) AS gross'
        . ' FROM ' . $wpdb->posts . ' AS p'
        . ' INNER JOIN ' . $wpdb->postmeta . ' AS plan ON plan.post_id = p.ID AND plan.meta_key = %s'
        . ' LEFT JOIN ' . $wpdb->postmeta . ' AS total ON total.post_id = p.ID AND total.meta_key = %s'
        . ' WHERE p.post_type = %s AND p.post_status IN (%s, %s)'
        . ' GROUP BY plan.meta_value ORDER BY gross DESC LIMIT %d',
        '_llms_plan_id',
        '_llms_total',
        'llms_order',
        'llms-completed',
        'llms-active',
        $limit
    );

    $rows = [];
    $skipped = 0;

    foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $row) {
        $plan_id = (int) ($row['plan_id'] ?? 0);
        $plan_post = $plan_id > 0 ? get_post($plan_id) : null;

        // A plan whose post is gone, or is not a plan, is reported as unresolved
        // rather than looked up as whatever that id is now.
        $resolved = $plan_post instanceof WP_Post && $plan_post->post_type === 'llms_access_plan';

        // A plan this account may not edit is one whose takings it has no
        // business reading, even holding manage_lifterlms in general.
        if ($resolved && !current_user_can('edit_post', $plan_id)) {
            $skipped++;
            continue;
        }

        $product_id = $resolved ? (int) get_post_meta($plan_id, '_llms_product_id', true) : 0;
        $product_post = $product_id > 0 ? get_post($product_id) : null;

        $rows[] = [
            'plan_id' => $plan_id,
            'plan_title' => $resolved ? (string) $plan_post->post_title : null,
            'resolved' => $resolved,
            'product_id' => $product_id > 0 ? $product_id : null,
            'product_title' => $product_post instanceof WP_Post ? (string) $product_post->post_title : null,
            'orders' => (int) ($row['orders'] ?? 0),
            'gross' => (float) ($row['gross'] ?? 0),
        ];
    }

    return [
        'returned' => count($rows),
        'skipped_unreadable' => $skipped,
        'limit' => $limit,
        'revenue' => $rows,
        'currency' => function_exists('get_lifterlms_currency') ? (string) get_lifterlms_currency() : null,
        'basis' => 'order_totals',
        'note' => __('Gross order totals for completed and active orders, not settled transactions and not net of refunds. A recurring plan counts its order once at the total the order carries, so treat this as which plans sell rather than as an accounting figure.', 'nibwp'),
        'buyers_named' => false,
    ];
}

/* -------------------------------------------------------------------- audit */

/**
 * The four faults that make a LifterLMS site look broken to a student.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lifterlms_audit_courses(array $input): array|WP_Error
{
    // Three of the four findings are structural, but the fourth counts students
    // left behind an unpublished course, so the whole audit takes the report bar.
    if ($denied = nibwp_lifterlms_require_reports(__('Auditing LifterLMS courses', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    $limit = nibwp_lifterlms_limit($input['limit'] ?? null, 50);

    $query = new WP_Query([
        'post_type' => 'course',
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => $limit,
        'orderby' => 'date',
        'order' => 'DESC',
        'no_found_rows' => false,
    ]);

    $published_empty = [];
    $empty_lessons = [];
    $never_finished = [];
    $orphaned_students = [];
    $skipped = 0;
    $examined = 0;

    foreach ((array) $query->posts as $post) {
        if (!$post instanceof WP_Post) {
            continue;
        }

        if (!current_user_can('edit_post', $post->ID) && !current_user_can(NIBWP_LLMS_CAP_OTHERS)) {
            $skipped++;
            continue;
        }

        $examined++;
        $lessons = nibwp_lifterlms_course_lesson_ids((int) $post->ID);
        $course = class_exists('LLMS_Course') ? new LLMS_Course($post) : null;
        $enrolled = $course !== null && method_exists($course, 'get_student_count') ? (int) $course->get_student_count() : 0;

        if ($post->post_status === 'publish' && $lessons === []) {
            $published_empty[] = [
                'course_id' => (int) $post->ID,
                'title' => (string) $post->post_title,
                'students_enrolled' => $enrolled,
                'why' => __('Published with no lessons, so anybody who enrols finds nothing to do.', 'nibwp'),
            ];
        }

        foreach ($lessons as $lesson_id) {
            if (count($empty_lessons) >= $limit) {
                break;
            }

            $lesson_post = get_post($lesson_id);

            if (!$lesson_post instanceof WP_Post) {
                continue;
            }

            $has_content = trim(strip_tags((string) $lesson_post->post_content)) !== '';

            if (class_exists('LLMS_Lesson')) {
                $lesson = new LLMS_Lesson($lesson_post);
                $has_content = method_exists($lesson, 'has_content') ? (bool) $lesson->has_content() : $has_content;
            }

            if (!$has_content) {
                $empty_lessons[] = [
                    'lesson_id' => $lesson_id,
                    'title' => (string) $lesson_post->post_title,
                    'course_id' => (int) $post->ID,
                    'why' => __('A lesson with no content. A student reaching it sees an empty page and stops.', 'nibwp'),
                ];
            }
        }

        // Students left behind an unpublished course: their enrolment and their
        // progress survive, but the course is gone from the site under them.
        if ($post->post_status !== 'publish' && $enrolled > 0) {
            $orphaned_students[] = [
                'course_id' => (int) $post->ID,
                'title' => (string) $post->post_title,
                'status' => (string) $post->post_status,
                'students_enrolled' => $enrolled,
                'why' => __('Students are enrolled in a course that is not published. They keep their progress but cannot reach it.', 'nibwp'),
                'students_named' => false,
            ];
        }

        if ($enrolled > 0 && is_object($wpdb) && method_exists($wpdb, 'get_var')) {
            $finished = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(DISTINCT user_id) FROM ' . $wpdb->prefix . 'lifterlms_user_postmeta'
                . ' WHERE post_id = %d AND meta_key = %s AND meta_value = %s',
                (int) $post->ID,
                '_is_complete',
                'yes'
            ));

            if ($finished === 0) {
                $never_finished[] = [
                    'course_id' => (int) $post->ID,
                    'title' => (string) $post->post_title,
                    'students_enrolled' => $enrolled,
                    'lessons' => count($lessons),
                    'why' => __('Students are enrolled and not one has ever finished it.', 'nibwp'),
                ];
            }
        }
    }

    $total = count($published_empty) + count($empty_lessons) + count($never_finished) + count($orphaned_students);

    return [
        'courses_examined' => $examined,
        'skipped_unreadable' => $skipped,
        'limit' => $limit,
        'findings' => $total,
        'published_with_no_lessons' => $published_empty,
        'lessons_with_no_content' => $empty_lessons,
        'never_finished_by_anyone' => $never_finished,
        'students_behind_an_unpublished_course' => $orphaned_students,
        'looks_reasonable' => $total === 0,
        'students_named' => false,
        'note' => sprintf(
            /* translators: %d: number of courses examined */
            _n(
                '%d course examined, bounded by limit. A clean result on a bounded sweep is not a clean site.',
                '%d courses examined, bounded by limit. A clean result on a bounded sweep is not a clean site.',
                $examined,
                'nibwp'
            ),
            $examined
        ),
    ];
}
