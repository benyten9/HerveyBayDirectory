# Section schema

`payload = { page: { title (REQUIRED), slug? }, sections: [ ... ] }`. Each section is `{ "type": "...", ...fields }`.

| type | fields | notes |
|---|---|---|
| `hero` | `heading` (REQUIRED), `eyebrow?`, `sub?`, `cta_label?`, `cta_url?` | The promise. Give it a `cta_url` or add a `cta` section. |
| `learn` | `heading?`, `items: [string]` | "What you'll learn" — 4–8 concrete outcomes. |
| `curriculum` | `heading?`, `modules: [ { title, summary?, lessons?: [string] } ]` | Mirror the real topics/lessons from the course DTO. |
| `about` / `text` | `heading?`, `body` (HTML) | Free prose block. |
| `instructor` | `name` (REQUIRED), `bio?` (HTML), `heading?` | From the course author. |
| `pricing` | `heading?`, `price?`, `cta_label?`, `cta_url?` | Show the real price ("Free" for free courses). |
| `testimonials` | `heading?`, `items: [ { quote, author, role? } ]` | Optional social proof. |
| `faq` | `heading?`, `items: [ { q, a } ]` (a = HTML) | Native `<details>` accordions. |
| `cta` | `heading` (REQUIRED), `cta_label` (REQUIRED), `cta_url` (REQUIRED) | The closing enroll band. |

## Rules
- Exactly one `hero` (first). At least one enroll CTA (`cta` section or a `cta_url` on hero/pricing).
- `cta_url` = the course permalink (from the DTO / `get_permalink(course_id)`).
- HTML is allowed in `body` / `bio` / faq `a` (sanitized with `wp_kses_post`). **No `<script>`.**
- `status` + `course_id` come from preflight — not the payload.

## Rendering
The page renders as one self-contained block: semantic HTML + a single scoped `<style>` (`.nibwp-cms`), theme-neutral, light, with a blue accent. Saved as a WordPress page; the course gets `_nibwp_course_minisite_page_id` and the page gets `_nibwp_minisite_course_id`.
