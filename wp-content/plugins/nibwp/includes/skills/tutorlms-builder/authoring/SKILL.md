---
name: tutorlms-builder
description: Use when building, authoring, or generating a Tutor LMS course, curriculum, lessons, or quizzes from a brief, outline, transcript, PDF or URL. Plans a full course tree and persists it through the Tutor LMS integration.
---

# Tutor LMS Builder

Turn a brief, outline, transcript, PDF, or URL into a **complete, validated Tutor LMS course** — topics, lessons, and quizzes with real questions — then persist it through the NIBWP Tutor LMS integration. Your job is to **synthesize a course tree (JSON)**, validate it, and commit it. You never write to the database yourself.

## Mandatory routing (read first)

This is a premium, routed skill. Follow the pipeline in order — do not improvise:

1. **`nibwp/skill-preflight { skill_id: "tutorlms-builder" }`** — resolves the instructor, course status (draft/publish), pricing (free/paid + price), and structure (topics/flat). Mints the one-hour `_preflight_token` the builder requires. Re-call with `answers:{...}` until it returns `success:true`.
2. **`nibwp/load-skill-playbook { skill_id: "tutorlms-builder" }`** — this file + the course-schema + curriculum rules + checklist + lessons-learned.
3. **`nibwp/tutorlms-builder-build-course { payload, dry_run:true, _preflight_token }`** — validate. On failure, patch each `unchecked_items[]` using its `fix_hint` and resubmit (≤3 attempts per token). Surface `recommendations[]` to the user.
4. **`nibwp/tutorlms-builder-build-course { payload, dry_run:false, _preflight_token }`** — persist after a clean pass + user confirmation.
5. **`nibwp/tutorlms-builder-feedback { rating, reason? }`** — record 👍/👎.

To change an existing course, use **`nibwp/tutorlms-builder-refine { course_id, patch, dry_run, _preflight_token }`**.

## The course tree (payload)

See [references/course-schema.md](references/course-schema.md) for the full shape. In short:

```json
{
  "course": { "title": "...", "description": "<p>html</p>", "difficulty": "beginner",
              "duration": {"hours": 2, "minutes": 30}, "prerequisite_ids": [], "category_ids": [] },
  "topics": [
    { "title": "Getting started", "summary": "...",
      "lessons": [ { "title": "Welcome", "content": "<p>...</p>",
                     "video": {"source_type": "youtube", "source": "https://..."} } ],
      "quizzes": [ { "title": "Module 1 check", "settings": {"passing_grade": 80},
        "questions": [ { "title": "WordPress is open source.", "type": "true_false", "mark": 1,
          "answers": [ {"title": "True", "is_correct": 1}, {"title": "False", "is_correct": 0} ] } ] } ] }
  ]
}
```

`status`, `price_type`, `price`, and the instructor come from the **preflight answers** — do not put them in the payload (they're applied server-side).

## Pedagogy rules (the validator enforces these — see [references/curriculum-rules.md](references/curriculum-rules.md))

- Every course has ≥1 topic; every topic has ≥1 lesson **or** quiz (no empty topics).
- Every quiz has ≥1 question. Question types: `true_false, single_choice, multiple_choice, open_ended, short_answer, fill_in_the_blank, matching, ordering`.
- **Auto-graded** types need correct answers: `true_false` = exactly 2 answers, one correct; `single_choice` = exactly one correct; `multiple_choice` = ≥1 correct. `open_ended`/`short_answer`/`fill_in_the_blank` are manually graded — no correct flag required.
- Lesson content is clean HTML — **no `<style>`, `<script>`, or `<form>`**. Aim for ≥40 chars of real body per lesson.
- `paid` ⇒ price > 0; `free` ⇒ price 0.

## Authoring guidance

- Plan a **learning arc**: foundations → core skills → application → assessment. Group into 3–6 topics unless the user asked for a flat structure.
- Write **lesson content**, not just titles — a short intro, the substance, and a takeaway. Use headings/lists. Embed a video when the source implies one.
- Add an **assessment** per major topic (a short quiz). Mix question types.
- Respect the preflight pricing/instructor; never invent a price.

## Final checklist (before dry_run:false)

- [ ] Course title + description present; difficulty + duration set.
- [ ] 3–6 topics (or flat if requested); no empty topics.
- [ ] Every lesson has real content (not just a title).
- [ ] At least one quiz with valid, correctly-keyed questions.
- [ ] Pricing matches the preflight answer (free⇒0, paid⇒>0).
- [ ] `dry_run:true` returned `validation.passed = true` and you surfaced `recommendations[]`.

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
