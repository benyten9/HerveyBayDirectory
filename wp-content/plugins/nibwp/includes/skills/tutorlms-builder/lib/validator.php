<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Tutor LMS Builder — course-tree validator.
 *
 * Enforces the pedagogy + Tutor-schema guardrails before anything is written.
 * Returns the house validation shape:
 *   [
 *     'passed'          => bool,
 *     'failed'          => string[],                       // human-readable rule failures
 *     'warnings'        => string[],
 *     'recommendations' => [ ['id'=>..,'summary'=>..], ],  // non-blocking improvement nudges
 *     'unchecked_items' => [ ['id'=>..,'path'=>..,'msg'=>..,'fix_hint'=>..], ],  // each carries a copy-paste fix
 *   ]
 *
 * The payload shape (course tree) is documented in authoring/references/course-schema.md.
 */

/** Valid Tutor question types. */
function nibwp_tutorlms_builder_question_types(): array
{
    return ['true_false', 'single_choice', 'multiple_choice', 'open_ended', 'short_answer', 'fill_in_the_blank', 'matching', 'ordering'];
}

/** Question types that auto-grade and therefore need a correct answer. */
function nibwp_tutorlms_builder_choice_types(): array
{
    return ['true_false', 'single_choice', 'multiple_choice'];
}

/**
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $answers Preflight answers (instructor_id, pricing, price, depth, course_status).
 * @return array{passed:bool, failed:string[], warnings:string[], recommendations:array<int,array{id:string,summary:string}>, unchecked_items:array<int,array{id:string,path:string,msg:string,fix_hint:string}>}
 */
function nibwp_tutorlms_builder_validate(array $payload, array $answers = []): array
{
    $failed = [];
    $warnings = [];
    $recommendations = [];
    $unchecked = [];

    $fail = static function (string $id, string $path, string $msg, string $fix) use (&$unchecked): void {
        $unchecked[] = ['id' => $id, 'path' => $path, 'msg' => $msg, 'fix_hint' => $fix];
    };

    $course = (array) ($payload['course'] ?? []);
    $topics = array_values((array) ($payload['topics'] ?? []));

    // ── Course ────────────────────────────────────────────────────────────
    if (trim((string) ($course['title'] ?? '')) === '') {
        $fail('course_title', 'course.title', 'Course title is empty.', 'Set course.title to a clear, specific course name.');
    }

    $price_type = (string) ($course['price_type'] ?? ($answers['pricing'] ?? 'free'));
    $price = (float) ($course['price'] ?? ($answers['price'] ?? 0));
    if ($price_type === 'paid' && $price <= 0) {
        $fail('price_paid_zero', 'course.price', 'price_type is "paid" but price is 0.', 'Set course.price > 0, or change price_type to "free".');
    }
    if ($price_type === 'free' && $price > 0) {
        $fail('price_free_nonzero', 'course.price', 'price_type is "free" but a non-zero price is set.', 'Set course.price to 0, or change price_type to "paid".');
    }

    // ── Topics ────────────────────────────────────────────────────────────
    if (count($topics) === 0) {
        $fail('no_topics', 'topics', 'The course has no topics.', 'Add at least one topic with lessons. Use the preflight "depth=flat" option for a single-topic course.');
    }

    $total_lessons = 0;
    $total_quizzes = 0;
    $total_questions = 0;

    foreach ($topics as $ti => $topic) {
        $topic = (array) $topic;
        $tpath = "topics[$ti]";
        if (trim((string) ($topic['title'] ?? '')) === '') {
            $fail('topic_title', "$tpath.title", 'Topic title is empty.', 'Set a title for every topic.');
        }
        $lessons = array_values((array) ($topic['lessons'] ?? []));
        $quizzes = array_values((array) ($topic['quizzes'] ?? []));
        if (count($lessons) === 0 && count($quizzes) === 0) {
            $fail('empty_topic', $tpath, 'Topic has no lessons and no quizzes.', 'Add at least one lesson or quiz to every topic, or remove the topic.');
        }
        $total_lessons += count($lessons);

        foreach ($lessons as $li => $lesson) {
            $lesson = (array) $lesson;
            $lpath = "$tpath.lessons[$li]";
            if (trim((string) ($lesson['title'] ?? '')) === '') {
                $fail('lesson_title', "$lpath.title", 'Lesson title is empty.', 'Set a title for every lesson.');
            }
            $content = (string) ($lesson['content'] ?? '');
            if (preg_match('/<\s*(style|script|form)\b/i', $content)) {
                $fail('lesson_unsafe', "$lpath.content", 'Lesson content contains <style>, <script> or <form>.', 'Remove raw style/script/form markup from lesson content.');
            }
            if (mb_strlen(trim(wp_strip_all_tags($content))) < 40) {
                $warnings[] = "$lpath: lesson body looks very short (<40 chars).";
            }
        }

        foreach ($quizzes as $qi => $quiz) {
            $quiz = (array) $quiz;
            $qpath = "$tpath.quizzes[$qi]";
            $total_quizzes++;
            if (trim((string) ($quiz['title'] ?? '')) === '') {
                $fail('quiz_title', "$qpath.title", 'Quiz title is empty.', 'Set a title for every quiz.');
            }
            $questions = array_values((array) ($quiz['questions'] ?? []));
            if (count($questions) === 0) {
                $fail('quiz_no_questions', "$qpath.questions", 'Quiz has no questions.', 'Add at least one question, or remove the quiz.');
            }
            foreach ($questions as $qqi => $question) {
                $question = (array) $question;
                $qqpath = "$qpath.questions[$qqi]";
                $total_questions++;
                if (trim((string) ($question['title'] ?? '')) === '') {
                    $fail('question_title', "$qqpath.title", 'Question title is empty.', 'Set the question text in question.title.');
                }
                $type = (string) ($question['type'] ?? '');
                if (!in_array($type, nibwp_tutorlms_builder_question_types(), true)) {
                    $fail('question_type', "$qqpath.type", sprintf('Invalid question type "%s".', $type), 'Use one of: ' . implode(', ', nibwp_tutorlms_builder_question_types()) . '.');
                }
                if ((int) ($question['mark'] ?? 1) < 1) {
                    $fail('question_mark', "$qqpath.mark", 'Question mark must be >= 1.', 'Set question.mark to a positive integer (default 1).');
                }
                $ans = array_values((array) ($question['answers'] ?? []));
                if (in_array($type, nibwp_tutorlms_builder_choice_types(), true)) {
                    $correct = 0;
                    foreach ($ans as $a) {
                        $correct += (int) (((array) $a)['is_correct'] ?? 0);
                    }
                    if ($type === 'true_false') {
                        if (count($ans) !== 2) {
                            $fail('tf_answers', "$qqpath.answers", 'true_false needs exactly 2 answers (True / False).', 'Provide exactly two answers, one with is_correct=1.');
                        }
                        if ($correct !== 1) {
                            $fail('tf_correct', "$qqpath.answers", 'true_false needs exactly one correct answer.', 'Mark exactly one of the two answers is_correct=1.');
                        }
                    } else {
                        if (count($ans) < 2) {
                            $fail('choice_answers', "$qqpath.answers", sprintf('%s needs at least 2 answers.', $type), 'Provide 2+ answer options.');
                        }
                        if ($type === 'single_choice' && $correct !== 1) {
                            $fail('single_correct', "$qqpath.answers", 'single_choice needs exactly one correct answer.', 'Mark exactly one answer is_correct=1.');
                        }
                        if ($type === 'multiple_choice' && $correct < 1) {
                            $fail('multi_correct', "$qqpath.answers", 'multiple_choice needs at least one correct answer.', 'Mark one or more answers is_correct=1.');
                        }
                    }
                }
            }
        }
    }

    // ── Recommendations (non-blocking) ────────────────────────────────────
    if (count($topics) === 1 && $total_lessons >= 6) {
        $recommendations[] = ['id' => 'group_topics', 'summary' => sprintf('%d lessons in a single topic — consider grouping into 2-4 topics for a clearer curriculum.', $total_lessons)];
    }
    if ($total_quizzes === 0 && $total_lessons > 0) {
        $recommendations[] = ['id' => 'add_assessment', 'summary' => 'No quizzes — add at least one assessment so learners can check understanding.'];
    }
    if ($total_lessons >= 12 && empty($course['prerequisite_ids'])) {
        $recommendations[] = ['id' => 'consider_prereqs', 'summary' => 'Long course with no prerequisites — consider gating it behind a foundational course.'];
    }

    return [
        'passed'          => $unchecked === [] && $failed === [],
        'failed'          => $failed,
        'warnings'        => $warnings,
        'recommendations' => $recommendations,
        'unchecked_items' => $unchecked,
        'summary'         => [
            'topics'    => count($topics),
            'lessons'   => $total_lessons,
            'quizzes'   => $total_quizzes,
            'questions' => $total_questions,
        ],
    ];
}
