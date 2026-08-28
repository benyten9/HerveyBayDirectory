<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Tutor LMS Builder — persister.
 *
 * Walks a validated course tree and persists it by calling the NIBWP Tutor LMS
 * integration (L1) write paths — never raw DB for course/topic/lesson/quiz.
 * Quiz QUESTIONS + ANSWERS are written to Tutor's custom tables using the exact
 * column set verified from Tutor\classes\QuizBuilder (3.9.12):
 *   {prefix}tutor_quiz_questions(quiz_id, question_title, question_description,
 *      question_type, question_mark, question_settings, question_order)
 *   {prefix}tutor_quiz_question_answers(belongs_question_id, belongs_question_type,
 *      answer_title, is_correct, image_id, answer_two_gap_match, answer_view_format,
 *      answer_settings, answer_order)
 *
 * @return array<string,mixed>|WP_Error  diff on success.
 */
function nibwp_tutorlms_builder_persist(array $payload, array $answers = [])
{
    // Ensure the L1 integration functions are loaded (the skill declares
    // integration_files=['tutorlms'], but require defensively).
    if (!function_exists('nibwp_tutorlms_courses_manage')) {
        $int = WP_PLUGIN_DIR . '/nibwp/includes/premium/integrations/tutorlms.php';
        if (file_exists($int)) {
            require_once $int;
        }
    }
    if (!function_exists('nibwp_tutorlms_courses_manage') || !function_exists('nibwp_tutorlms_content_manage')) {
        return new WP_Error('integration_missing', 'The Tutor LMS integration is not loaded. Enable the Tutor LMS integration.');
    }

    $call = static function (string $fn, array $args) {
        $res = $fn($args);
        return $res; // array | WP_Error
    };

    $course = (array) ($payload['course'] ?? []);
    $status = in_array($answers['course_status'] ?? '', ['publish', 'draft', 'pending', 'private'], true)
        ? (string) $answers['course_status']
        : (string) ($course['status'] ?? 'draft');
    $price_type = (string) ($course['price_type'] ?? ($answers['pricing'] ?? 'free'));
    $price = (float) ($course['price'] ?? ($answers['price'] ?? 0));

    // 1) Create the course via L1.
    $res = $call('nibwp_tutorlms_courses_manage', [
        'action'       => 'create',
        'title'        => (string) ($course['title'] ?? 'Untitled course'),
        'content'      => (string) ($course['description'] ?? ''),
        'excerpt'      => (string) ($course['excerpt'] ?? ''),
        'status'       => $status,
        'price_type'   => $price_type,
        'price'        => $price,
        'category_ids' => array_map('intval', (array) ($course['category_ids'] ?? [])),
    ]);
    if (is_wp_error($res)) {
        return $res;
    }
    $course_id = (int) ($res['course']['id'] ?? 0);
    if ($course_id <= 0) {
        return new WP_Error('course_create_failed', 'Course creation did not return an id.');
    }

    // Instructor (= post author) + course settings meta (best-effort).
    $instructor_id = (int) ($answers['instructor_id'] ?? ($course['instructor_id'] ?? 0));
    if ($instructor_id > 0) {
        wp_update_post(['ID' => $course_id, 'post_author' => $instructor_id]);
        add_user_meta($instructor_id, '_tutor_instructor_course_id', $course_id);
    }
    if (!empty($course['difficulty'])) {
        update_post_meta($course_id, '_tutor_course_level', sanitize_key((string) $course['difficulty']));
    }
    if (!empty($course['duration']) && is_array($course['duration'])) {
        update_post_meta($course_id, '_course_duration', [
            'hours'   => (int) ($course['duration']['hours'] ?? 0),
            'minutes' => (int) ($course['duration']['minutes'] ?? 0),
        ]);
    }
    if (!empty($course['prerequisite_ids'])) {
        update_post_meta($course_id, '_tutor_course_prerequisites_ids', array_map('intval', (array) $course['prerequisite_ids']));
    }

    $diff = ['course_id' => $course_id, 'topics' => 0, 'lessons' => 0, 'quizzes' => 0, 'questions' => 0, 'warnings' => []];

    // 2) Topics → lessons → quizzes → questions.
    foreach (array_values((array) ($payload['topics'] ?? [])) as $topic) {
        $topic = (array) $topic;
        $tres = $call('nibwp_tutorlms_content_manage', [
            'action'    => 'create_topic',
            'course_id' => $course_id,
            'title'     => (string) ($topic['title'] ?? 'Untitled topic'),
            'summary'   => (string) ($topic['summary'] ?? ''),
        ]);
        if (is_wp_error($tres)) {
            $diff['warnings'][] = 'Topic failed: ' . $tres->get_error_message();
            continue;
        }
        $topic_id = (int) ($tres['topic_id'] ?? 0);
        $diff['topics']++;

        foreach (array_values((array) ($topic['lessons'] ?? [])) as $lesson) {
            $lesson = (array) $lesson;
            $lres = $call('nibwp_tutorlms_content_manage', [
                'action'   => 'create_lesson',
                'topic_id' => $topic_id,
                'title'    => (string) ($lesson['title'] ?? 'Untitled lesson'),
                'content'  => (string) ($lesson['content'] ?? ''),
            ]);
            if (is_wp_error($lres)) {
                $diff['warnings'][] = 'Lesson failed: ' . $lres->get_error_message();
                continue;
            }
            $diff['lessons']++;
            $lesson_id = (int) ($lres['lesson_id'] ?? 0);
            if ($lesson_id > 0 && !empty($lesson['video']) && is_array($lesson['video'])) {
                update_post_meta($lesson_id, '_video', [
                    'source_type' => sanitize_key((string) ($lesson['video']['source_type'] ?? 'youtube')),
                    'source'      => esc_url_raw((string) ($lesson['video']['source'] ?? '')),
                ]);
            }
        }

        foreach (array_values((array) ($topic['quizzes'] ?? [])) as $quiz) {
            $quiz = (array) $quiz;
            $qres = $call('nibwp_tutorlms_quizzes_manage', [
                'action'   => 'create_quiz',
                'topic_id' => $topic_id,
                'title'    => (string) ($quiz['title'] ?? 'Untitled quiz'),
            ]);
            if (is_wp_error($qres)) {
                $diff['warnings'][] = 'Quiz failed: ' . $qres->get_error_message();
                continue;
            }
            $diff['quizzes']++;
            $quiz_id = (int) ($qres['quiz_id'] ?? 0);
            if ($quiz_id > 0 && !empty($quiz['settings']) && is_array($quiz['settings'])) {
                update_post_meta($quiz_id, 'tutor_quiz_option', $quiz['settings']);
            }
            $diff['questions'] += nibwp_tutorlms_builder_persist_questions($quiz_id, array_values((array) ($quiz['questions'] ?? [])));
        }
    }

    $diff['edit_url'] = (string) get_edit_post_link($course_id, 'raw');
    return $diff;
}

/**
 * Insert quiz questions + answers into Tutor's custom tables. Returns the count
 * of questions written.
 *
 * @param array<int,array<string,mixed>> $questions
 */
function nibwp_tutorlms_builder_persist_questions(int $quiz_id, array $questions): int
{
    global $wpdb;
    $q_table = $wpdb->prefix . 'tutor_quiz_questions';
    $a_table = $wpdb->prefix . 'tutor_quiz_question_answers';
    $written = 0;
    $q_order = 0;

    foreach ($questions as $question) {
        $question = (array) $question;
        $type = (string) ($question['type'] ?? 'true_false');
        $q_order++;
        $ok = $wpdb->insert($q_table, [
            'quiz_id'              => $quiz_id,
            'question_title'       => wp_strip_all_tags((string) ($question['title'] ?? '')),
            'question_description' => wp_kses_post((string) ($question['description'] ?? '')),
            'question_type'        => $type,
            'question_mark'        => max(1, (int) ($question['mark'] ?? 1)),
            'question_settings'    => maybe_serialize([
                'question_type'        => $type,
                'question_mark'        => max(1, (int) ($question['mark'] ?? 1)),
                'answer_required'      => 1,
                'randomize_question'   => 0,
                'show_question_mark'   => 1,
            ]),
            'question_order'       => $q_order,
        ]);
        if (!$ok) {
            continue;
        }
        $question_id = (int) $wpdb->insert_id;
        $written++;

        $a_order = 0;
        foreach (array_values((array) ($question['answers'] ?? [])) as $answer) {
            $answer = (array) $answer;
            $a_order++;
            $wpdb->insert($a_table, [
                'belongs_question_id'   => $question_id,
                'belongs_question_type' => $type,
                'answer_title'          => wp_strip_all_tags((string) ($answer['title'] ?? '')),
                'is_correct'            => (int) ((bool) ($answer['is_correct'] ?? 0)),
                'image_id'              => 0,
                'answer_two_gap_match'  => '',
                'answer_view_format'    => 'text',
                'answer_settings'       => maybe_serialize([]),
                'answer_order'          => $a_order,
            ]);
        }
    }

    return $written;
}
