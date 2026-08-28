# Curriculum rules

The pedagogy + schema guardrails the validator enforces. Build to these so `dry_run` passes first time.

## Structure
- **≥1 topic.** A course with no topics fails.
- **No empty topics.** Every topic needs ≥1 lesson **or** ≥1 quiz.
- **Group sensibly.** Prefer 3–6 topics that form a learning arc (foundations → core → application → assessment). A single topic with 6+ lessons triggers a "group your topics" recommendation.
- **Flat is allowed** when the user asked for it (preflight `depth=flat`): one topic, lessons only.

## Lessons
- **Title required.** Real **content** required — a short intro, the substance, a takeaway. Don't ship title-only lessons (a <40-char body warns).
- **Clean HTML only.** No `<style>`, `<script>`, or `<form>` in lesson content (hard fail).
- **Video** is optional; when the source implies one, set `video.source_type` + `source`.

## Quizzes & questions
- **Every quiz needs ≥1 question.**
- **Auto-graded types need a key:**
  - `true_false`: exactly 2 answers, exactly one correct.
  - `single_choice`: exactly one correct.
  - `multiple_choice`: at least one correct.
- **Manually graded types** (`open_ended`, `short_answer`, `fill_in_the_blank`) need no correct flag.
- **`mark` ≥ 1.**
- Mix question types across a quiz; keep each question unambiguous.

## Pricing
- Comes from preflight. `paid` ⇒ price > 0; `free` ⇒ price 0. The validator fails a mismatch.
- The active monetization engine (tutor / WooCommerce / EDD) is detected by the integration; native pricing is set on the course, or on the linked product for WC/EDD.

## Recommendations (non-blocking)
The validator may suggest: group a flat topic, add an assessment when there are none, or add prerequisites for a long course. Surface these to the user before committing.
