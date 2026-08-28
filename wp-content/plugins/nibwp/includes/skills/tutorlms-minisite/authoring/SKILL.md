---
name: tutorlms-minisite
description: Use when building a landing page, sales page, or micro-site for a Tutor LMS course. Reads the live course data and generates a styled, self-contained WordPress page linked to the course.
---

# Tutor LMS Course Mini-site

Build a polished landing / sales page for a Tutor LMS course, bound to the **live course data**, and save it as a WordPress page linked back to the course. You synthesize a **section tree (JSON)**; the skill validates it and renders a self-contained, theme-neutral page.

## Mandatory routing

1. **`nibwp/skill-preflight { skill_id: "tutorlms-minisite" }`** — resolves which `course_id` and the page status. Mints the `_preflight_token`.
2. **`nibwp/load-skill-playbook { skill_id: "tutorlms-minisite" }`** — this file + the section schema.
3. **Read the course** — call `nibwp/tutorlms-courses { action: "get", course_id }` to get the course DTO (title, price, instructor_ids, topics, category). **Build the page from real data — never invent course facts.**
4. **`nibwp/tutorlms-minisite-build { payload, dry_run:true, _preflight_token }`** — validate; patch `unchecked_items[]` via `fix_hint`; surface `recommendations[]`.
5. **`nibwp/tutorlms-minisite-build { payload, dry_run:false, _preflight_token }`** — persist the page.
6. **`nibwp/tutorlms-minisite-feedback { rating, reason? }`**.

## The section tree

See [references/section-schema.md](references/section-schema.md). Shape:

```json
{
  "page": { "title": "Mastering X — Online Course", "slug": "mastering-x" },
  "sections": [
    { "type": "hero", "eyebrow": "ONLINE COURSE", "heading": "Master X in 4 weeks",
      "sub": "...", "cta_label": "Enroll now", "cta_url": "https://site.com/courses/mastering-x/" },
    { "type": "learn", "heading": "What you'll learn", "items": ["...", "..."] },
    { "type": "curriculum", "heading": "Curriculum",
      "modules": [ { "title": "Module 1", "lessons": ["Lesson A", "Lesson B"] } ] },
    { "type": "instructor", "name": "Jane Doe", "bio": "<p>...</p>" },
    { "type": "pricing", "heading": "Enroll today", "price": "$149", "cta_label": "Enroll now", "cta_url": "..." },
    { "type": "faq", "items": [ { "q": "...", "a": "<p>...</p>" } ] },
    { "type": "cta", "heading": "Ready to start?", "cta_label": "Enroll now", "cta_url": "..." }
  ]
}
```

`status` and `course_id` come from the preflight answers — don't put them in the payload.

## Rules (the validator enforces these)
- A **hero** section is required (with a heading).
- An **enroll CTA** is required — a `cta` section, or a `cta_url` on the hero/pricing.
- No `<script>` anywhere.
- Pull the CTA URL from the course permalink (in the DTO / `get_permalink`).

## Authoring guidance
- Lead with the **promise** in the hero (outcome, not features).
- `learn` = 4–8 concrete outcomes. `curriculum` = the real topics/lessons from the DTO.
- `instructor` from the course author. `pricing` shows the real price (free → "Free").
- 5–8 sections makes a convincing page; fewer triggers a "thin page" recommendation.

## Final checklist
- [ ] Built from the real course DTO (title, price, curriculum, instructor).
- [ ] Hero + enroll CTA present; CTA points at the course URL.
- [ ] 5–8 sections; no `<script>`.
- [ ] `dry_run:true` passed; recommendations surfaced.

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
