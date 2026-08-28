# tutorlms — playbook

Curated guidance for the Tutor LMS integration. The site owner / instructor drives these
abilities through an AI agent to manage their LMS.

## Detection & gating
- Requires Tutor LMS active (`TUTOR_VERSION`). Pro actions additionally need Tutor Pro **with the
  matching addon enabled** — having Pro installed is not enough.
- `[Pro]` actions: course `set_prerequisites`, `list_bundles`/`get_bundle`, quizzes `list_assignments`/`get_assignment`,
  instructors `add_co_instructor`/`remove_co_instructor`. They return `tutor_pro_required` when the addon is off.

## Abilities (7)
- **nibwp/tutorlms-courses** — `list, get, create, update, delete, pricing_summary, get_prerequisites, set_prerequisites[Pro], list_bundles[Pro], get_bundle[Pro]`
- **nibwp/tutorlms-content** — topics + lessons + announcements CRUD; `reorder_topics`/`reorder_lessons` take `order=[id,…]`. Lessons belong to a **topic** (`topic_id`), topics to a **course** (`course_id`).
- **nibwp/tutorlms-students** — `enroll, unenroll, list_enrollments, get_progress, mark_lesson_complete, mark_course_complete, reset_progress`
- **nibwp/tutorlms-quizzes** — quizzes/questions/attempts CRUD + `grade_attempt`; `list_assignments`/`get_assignment`[Pro]
- **nibwp/tutorlms-instructors** — `list, get, make_instructor, list_course_instructors, add_co_instructor[Pro], remove_co_instructor[Pro], approve, block`
- **nibwp/tutorlms-monetization** — `detect_engine, get_price, set_price, coupons CRUD, list_earnings, get_instructor_earnings, list_withdrawals, get_commission`
- **nibwp/tutorlms-reports** — `course_analytics, sales, roster, progress_export` (read-only)

## Order of operations
1. For anything touching price/coupons/earnings, call **`nibwp/tutorlms-monetization` `detect_engine` first**.
   - `engine=tutor` → native coupons + earnings + withdrawals are authoritative.
   - `engine=wc`/`edd` → pricing/coupons live in WooCommerce/EDD on the linked product (`_tutor_course_product_id`); native coupons return `engine_mismatch`.
2. Build content top-down: create course → create_topic → create_lesson / create_quiz.

## Gotchas (write side-effects)
- **enroll**: `send_email` defaults **false** to suppress enrollment emails + earnings rows (`do_enroll fire_hook=false`). Set `send_email=true` only when the learner should be notified. Re-enroll is idempotent.
- **unenroll** `hard=true` permanently deletes the enrollment record (soft-cancel otherwise).
- **delete_*** (course/topic/lesson/quiz/attempt) and **reset_progress** are destructive and not idempotent. Course delete cascades to its topics/lessons/quizzes.
- **make_instructor** grants the `tutor_instructor` role site-wide.
- Every monetization response includes the active `engine`; some custom-table reads carry a `warnings[]` note when only authoritative under `engine=tutor`.

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
