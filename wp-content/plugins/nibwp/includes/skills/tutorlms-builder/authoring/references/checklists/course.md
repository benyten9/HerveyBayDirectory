# course — checklist

Run this before submitting `dry_run:false`.

## Structure
- [ ] `course.title` set; `course.description` is a real overview (not a placeholder).
- [ ] `difficulty` + `duration` set.
- [ ] 3–6 topics forming a learning arc (or a single flat topic if the user asked).
- [ ] No empty topics — each has lessons and/or a quiz.

## Lessons
- [ ] Every lesson has a title AND real body content (≥40 chars).
- [ ] No `<style>`, `<script>`, or `<form>` in any lesson content.
- [ ] Videos set where the source implies one.

## Assessment
- [ ] At least one quiz (ideally one per major topic).
- [ ] Each quiz has ≥1 question; every auto-graded question is correctly keyed.
- [ ] `mark` ≥ 1 on every question.

## Commit
- [ ] Pricing matches the preflight answer (free⇒0, paid⇒>0).
- [ ] `dry_run:true` returned `validation.passed=true`.
- [ ] `recommendations[]` surfaced to the user.

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
