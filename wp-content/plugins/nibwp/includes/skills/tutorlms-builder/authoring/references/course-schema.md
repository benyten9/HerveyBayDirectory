# Course tree — payload schema

The `payload` you submit to `nibwp/tutorlms-builder-build-course`. One JSON object describing the whole course.

```json
{
  "course": {
    "title": "string  (REQUIRED)",
    "description": "string  (HTML allowed — the course overview)",
    "excerpt": "string  (short summary / subtitle)",
    "difficulty": "beginner | intermediate | expert | all_levels",
    "duration": { "hours": 0, "minutes": 0 },
    "category_ids": [ 12, 34 ],
    "prerequisite_ids": [ 101 ]
  },
  "topics": [
    {
      "title": "string  (REQUIRED)",
      "summary": "string",
      "lessons": [
        {
          "title": "string  (REQUIRED)",
          "content": "string  (HTML — the actual lesson body; no <style>/<script>/<form>)",
          "video": { "source_type": "youtube | vimeo | html5 | external_url", "source": "https://..." }
        }
      ],
      "quizzes": [
        {
          "title": "string  (REQUIRED)",
          "settings": { "passing_grade": 80, "feedback_mode": "default | reveal | retry", "time_limit": { "value": 0, "type": "minutes" } },
          "questions": [
            {
              "title": "string  (REQUIRED — the question text)",
              "description": "string  (optional elaboration, HTML)",
              "type": "true_false | single_choice | multiple_choice | open_ended | short_answer | fill_in_the_blank | matching | ordering",
              "mark": 1,
              "answers": [
                { "title": "string", "is_correct": 0 }
              ]
            }
          ]
        }
      ]
    }
  ]
}
```

## Field notes

- **`course.title`** — required, non-empty.
- **`course.description`** — HTML allowed; this is the course overview shown on the course page.
- **`difficulty`** — written to `_tutor_course_level`.
- **`duration`** — written to `_course_duration` as `{hours, minutes}`.
- **`prerequisite_ids`** — course IDs the learner must complete first (Tutor Pro Prerequisites addon required to *enforce*; the link is stored regardless).
- **`category_ids`** — `course-category` term IDs.
- **NOT in the payload:** `status`, `price_type`, `price`, instructor — these come from the **preflight answers** and are applied server-side. Don't include them.

## Quiz questions

- **`type`** drives answer rules:
  - `true_false` → exactly **2** answers (e.g. True/False), exactly **one** `is_correct:1`.
  - `single_choice` → 2+ answers, exactly **one** `is_correct:1`.
  - `multiple_choice` → 2+ answers, **≥1** `is_correct:1`.
  - `open_ended` / `short_answer` / `fill_in_the_blank` → manually graded; `answers` optional, no correct flag required. (For `fill_in_the_blank`, put the expected answer(s) in `answers[].title`.)
  - `matching` / `ordering` → provide the items as `answers[]` in the intended order.
- **`mark`** — points for the question (integer ≥1, default 1).
- **`is_correct`** — `1` or `0`.

## How it persists

The builder calls the Tutor LMS integration (L1) to create the course → topics → lessons → quizzes, and writes questions + answers to Tutor's `tutor_quiz_questions` / `tutor_quiz_question_answers` tables. No raw course/lesson SQL — it reuses the audited integration write paths.
