---
name: bricks-authoring
description: Use when creating, converting, or refactoring anything into a Bricks template, section, or element. Enforces the Bricks element catalog (only real Bricks core elements + filter-extended add-ons), global classes (BEM-prefixed, brand-scoped), ACSS / Bricks-design-system tokens (`var(--token, fallback)`), per-breakpoint settings via Bricks built-in editor (NEVER inline @media), dynamic data tags for content/archive templates (`{post_title}`, `{acf:field}`), native Bricks "video" element instead of YouTube/Vimeo iframes, native "form" element OR shortcode wrap (forms-manage) instead of raw <form>, and Query Loop + CPT + ACF for repeating cards. Triggers on "brickify this", "convert HTML to Bricks", "Bricks template from screenshot", "URL to Bricks", "Figma to Bricks section".
---

# Bricks Authoring

This skill produces validated Bricks templates (`bricks_template` post type) or page-level element trees that the agent submits via `nibwp/bricks-pro-html-to-component`. Server-side: a validator enforces the rules below, an orchestrator-recommender suggests cross-ability follow-ups (CPT + ACF for loops, native video for iframes, native form/shortcode for raw forms), then persistence delegates to the existing `nibwp/bricks-create-template` ability.

## Mandatory routing — read first

When a user message matches any trigger from this skill's `manifest.triggers` (`brickify`, `convert to bricks`, `html to bricks`, `bricks template from …`, etc.), you MUST follow the 5-step pipeline. Improvising — calling `nibwp/wp-create-post` with raw HTML, inlining `<style>` blocks into Bricks element settings, picking your own brand prefix — is structurally blocked: `nibwp/bricks-pro-html-to-component` refuses to run without a valid `_preflight_token`, and `nibwp/wp-create-post` returns `requires_user_input` when its content sniffer detects skill-eligible markup.

Pipeline:

1. **`nibwp/skill-preflight { skill_id: "bricks-pro" }`** — server probes ACSS active+version, Bricks version, existing global classes (brand prefix scan), candidate target template posts, installed form plugins. Reads cached answers from `nibwp_user_defaults`. Returns either `requires_user_input: true` with the still-missing questions (brand prefix, template_type, push_mode, optional new_template_title) OR `success: true` with a 1-hour `preflight_token`.
2. **`nibwp/load-skill-playbook { skill_id: "bricks-pro", brand, element_type? }`** — loads this SKILL.md + element catalog + dynamic-data tags + per-element checklist + aggregated thumb-down lessons-learned for the `(brand, element_type)` pair.
3. **`nibwp/bricks-pro-html-to-component { payload, source, brand, element_type, _preflight_token, dry_run: true }`** — server validates. Returns `validation`, `recommendations[]` (loop→Query+CPT+ACF, iframe→Bricks video, raw form→bricks form/shortcode, alt-less images, deep div-soup, dynamic-data binding for content templates), and `unchecked_items[]` on failure. Surface every recommendation to the user. Patch the payload using `fix_hint` on each unchecked item. Resubmit up to 3 times per token.
4. **`nibwp/bricks-pro-html-to-component { payload, _preflight_token, dry_run: false }`** — server re-validates, then persists via `nibwp/bricks-create-template`. Brand prefix, template_type, target_template_id, and push_mode are OVERRIDDEN by the cached preflight answers regardless of what the agent put in the payload.
5. **`nibwp/bricks-pro-feedback { template_id, brand, element_type, rating, reason? }`** — thumb-up/down. Down reasons feed the next playbook load's "Lessons learned" block.

## The validator hard-rejects (each failed entry carries a copy-paste `fix_hint`)

- `bricks_element_unknown` — element `name` not in the Bricks core catalog (see `references/bricks-elements.md`)
- `bricks_element_missing_id` — element has no `settings._id` (persister mints one but explicit IDs make refines stable) — *warning, not reject*
- `bricks_inline_style_attr` — raw `style="..."` attribute inside `settings.text` / `settings.code`
- `bricks_inline_media_query` — `@media` inside `settings._cssCustom` (use Bricks breakpoint settings)
- `bricks_hardcoded_font_size` — font-size literal outside `var()` fallback
- `bricks_hardcoded_color` — color/bg literal outside `var()` and not in `nibwp_user_defaults.bricks_brand_color`
- `bricks_missing_brand_prefix` — `global_classes[i].name` without `{brand}-` prefix (utility hooks like `is-active` exempt)
- `bricks_missing_global_class` — structural element with zero `_cssGlobalClasses` references — *warning*
- `bricks_static_form_html` — raw `<form>` markup inside a `code` / `html` element
- `bricks_raw_iframe_provider` — raw YouTube/Vimeo `<iframe>` inside a `code` / `html` element
- `bricks_template_type_invalid` — `template_type` not in `{header, footer, content, section, archive, error, popup}`
- `bricks_invalid_query_loop` — `posts` element missing `settings.query` dict
- `acss_absent_tokens_used` — ACSS not active but payload references ACSS-namespace tokens (3-choice prompt: install / bake / Bricks Design System)

## Where conversion happens

Agent-side. The server-side ability validates the payload you build and persists it. Do NOT ask the server to "convert" — you build the Bricks element tree yourself, then submit for validation + persistence.

## Core principles

1. **Convert what you see.** Faithfully reproduce the source's layout, copy, image placement, and proportions. No improvising "improvements" beyond the original.
2. **Pixel-perfect fidelity.** Match spacing, typography weights, colors, radii. Use the source's exact values as token fallbacks (`var(--text-l, 1.25rem)`).
3. **Always responsive — via Bricks breakpoint settings.** Bricks renders per-breakpoint blocks automatically. Set values per breakpoint inside the same setting object: `{ paddingTop: { _base: "4rem", _mobile_landscape: "3rem", _mobile_portrait: "2rem" } }`. NEVER `@media` blocks inside `_cssCustom`.
4. **ACSS-first.** Use `var(--token, fallback)` for every value that maps to an ACSS / Bricks design-system token. If ACSS can express it, use the token. Always include the fallback.
5. **Global classes over per-element CSS.** Every structural element should reference at least one BEM-prefixed global class via `settings._cssGlobalClasses`. Per-element `_cssCustom` is for one-off overrides only.
6. **Bricks elements only.** Use the names in `references/bricks-elements.md`. No invented names. Container/section/div/block for layout; heading/text/button/image/icon for content; form/nav-menu/video for interactive; posts for query loops.
7. **Native over raw.** YouTube/Vimeo → `video` element. Forms → `form` element OR `shortcode` wrap. Galleries → `image-gallery`. Repetition → `posts` query loop.
8. **Dynamic data for content/archive templates.** When `template_type` is `content` or `archive`, heading text becomes `{post_title}`, excerpts become `{post_excerpt:25}`, custom data binds via `{acf:field_name}`. See `references/dynamic-data.md`.

## Flow

### 1. Preflight (TWO layers)

**Session preflight** — handled by `nibwp/skill-preflight`. Answers come back in `token_payload.answers`: brand prefix, template_type, push_mode, optional new_template_title, optional ACSS decision. These OVERRIDE anything the agent puts in the payload. Do not re-ask.

**Per-payload self-check (silent)** — fill in your own head:

- **Template type?** `content` (single post), `archive` (post listing), `section` (reusable block), `header` (global header), `footer` (global footer), `error` (404), `popup` (modal).
- **Slug / title?** Used by `target.title` when `push_mode=new_template`.
- **Element type for the checklist?** One of `button|hero|card-grid|form|navbar|footer|accordion|tabs|slider|image|divider|stat-block|testimonial|pricing|cta|section|header|archive`. Routes which `references/checklists/{type}.md` is injected into the playbook response.

### 2. Build the payload

Two top-level pieces:

- `global_classes` — array of `{id, name, settings: {_cssCustom?, …}}`. BEM-prefixed names. Defined once, referenced from elements.
- `elements` — flat array. Each item: `{name, settings: {_id, tag?, text?, image?, code?, _cssGlobalClasses?, _cssCustom?, ...}, parent: <index>, children: [<index>, ...]}`. The persister converts parent/children indices to Bricks element IDs.

Open `references/bricks-elements.md` for the element catalog with common `settings` keys per element.

Open `references/dynamic-data.md` for the full list of dynamic-data tags Bricks supports (`{post_title}`, `{post_excerpt:25}`, `{acf:field_name}`, `{wp:user_meta:key}`, `{taxonomy_name}`, etc.).

Open `references/query-loops.md` for the `posts` element's `settings.query` schema (which mirrors `WP_Query` args).

Open `references/global-classes.md` for the BEM grammar + per-breakpoint settings shape.

Open `references/anti-patterns.md` for the canonical DO/DO-NOT list.

### 3. Per-element checklist

Identify the element type (`hero`, `card-grid`, `form`, `navbar`, `footer`, `button`, or `generic` fallback) and open `references/checklists/{type}.md`. Run every box BEFORE submitting. The checklist matches the validator + adds Bricks-specific items (e.g. "nav-menu element uses Bricks menu picker, NOT a hand-rolled `<nav>` element").

### 4. Submit with `dry_run: true`

Server runs the validator + recommender. Read the response:

- `validation.passed === false` → patch each `unchecked_items[i]` using its `fix_hint`. Resubmit. 3-attempt budget per token.
- `recommendations[]` → surface every entry to the user with its `choices`. Accepted recommendations become a separate ability chain (e.g. `nibwp/wp-register-cpt` → `nibwp/acf-manage-fields` → resubmit Bricks payload with a `posts` query loop replacing the static cards).

### 5. Loop detection — dynamise repeating cards

Before final synthesis, count repeating sibling structures (same `name` + same first global-class family). When **≥3** identical structures appear under one parent, STOP and treat as a loop candidate. The recommender returns the same finding under `response.recommendations[]`; surface it to the user before committing.

Decision routine when a loop is detected:

1. Ask: *"Detected N repeating ‹family› cards. Bind to a CPT + ACF (recommended for content that grows), reuse an existing CPT, or persist them static?"*
2. If **make dynamic**:
   - `nibwp/wp-register-cpt` with the suggested `post_type` + supports
   - `nibwp/acf-manage-fields` with the suggested field set (confirm field types with the user)
   - Resubmit Bricks payload replacing the N static elements with ONE `posts` element. Its child elements use dynamic tags like `{post_title}`, `{post_excerpt}`, `{acf:image}`
   - `nibwp/wp-create-post` N times seeded with the original card content
3. If **reuse existing CPT**: ask which slug, query its registered fields, regenerate payload using those tokens.
4. If **persist static**: continue as-is. Recommendation moves to warnings.

### 6. HTML semantic upgrades

Source is usually div-soup (Tailwind/Webflow/Figma export). Upgrade outer landmarks during synthesis — Bricks renders any HTML5 tag via `settings.tag`:

- Top-level wrapper → `section` element with `settings.tag="section"` (default)
- Hero/CTA wrapper with heading → `section` with `settings.tag="article"`
- Header bar → `section` with `settings.tag="header"` (or use a `header` template type)
- Nav → `section` with `settings.tag="nav"` containing a `nav-menu` element
- Main content region → `section` with `settings.tag="main"` (once per page)
- Sidebar → `section` with `settings.tag="aside"`
- Footer → `section` with `settings.tag="footer"`
- Repeated cards inside a loop → child element of `posts` with `settings.tag="article"`

The recommender flags `div_soup` when consecutive nested `<div>` depth ≥6.

### 7. Image conversion

When the source contains `<img>` tags:

- Use Bricks `image` element. `settings.image = { id, url, alt, title, size }` from the WP attachment.
- Every image MUST have a non-empty `alt`. Generate from: nearest heading > sibling caption > filename without extension > image-vision description. Decorative images: `alt=""` + `role="presentation"`.
- Bricks handles lazy loading automatically via `settings.lazyLoad = true`. Enable on every image except the first hero (`lazyLoad = false`, `fetchpriority = "high"`).
- For 4+ sibling images under one parent → use `image-gallery` element OR ACF gallery field on a CPT.
- Re-upload via `nibwp/wp-upload-media` with the generated alt baked into post meta.

### 8. Embeds — Bricks native video element

NEVER persist a raw `<iframe>` in a `code` element. Replace with the appropriate Bricks element:

| Source | Replace with |
|---|---|
| `<iframe src="*.youtube.com/embed/*">` | `{ name: "video", settings: { videoType: "youtube", url: "https://youtube.com/watch?v=..." } }` |
| `<iframe src="*vimeo.com*">` | `{ name: "video", settings: { videoType: "vimeo", url: "https://vimeo.com/..." } }` |
| `<iframe src="*.mp4">` | `{ name: "video", settings: { videoType: "mp4", url: "...", poster: "..." } }` |
| `<iframe src="*twitter.com*">` | Bricks `shortcode` element wrapping `[embed]https://twitter.com/...[/embed]` |

The recommender flags `iframe_provider` per detection.

### 9. Forms — native or shortcode-wrap

If the source HTML contains a `<form>`, the converter STOPS and asks: which form plugin OR Bricks native form?

- **Option A — Bricks native form**: `{ name: "form", settings: { fields: [{name, label, type, required, ...}], submitButtonText, actions: ["email"|"webhook"|"mailchimp"|"redirect"], ... } }`. Use this for simple contact / newsletter forms. Bricks renders + handles validation + sends via wp_mail by default.
- **Option B — Plugin shortcode**: `nibwp/forms-manage list_plugins` → `create_form` → emit a `shortcode` element wrapping the chosen plugin's shortcode (`[gravityform id=3]`, `[fluentform id=5]`, `[wpforms id=12]`, …). Use this when the user already has a form plugin set up and wants real spam protection / GDPR / advanced routing.

Most agencies pick B; defaults that way.

### 10. Dynamic data — content + archive templates

For `template_type=content` (single post template) and `template_type=archive` (post listing wrapper), replace static text with Bricks dynamic-data tags:

- Page title → `{post_title}`
- Excerpt → `{post_excerpt:25}` (25-word limit)
- Author name → `{post_author_name}`
- Post date → `{post_date}` (or `{post_date:F j, Y}` with PHP date format)
- Featured image → use an `image` element with `settings.image.useFeaturedImage = true`
- Custom field → `{acf:field_name}` or `{wp:post_meta:key}`
- Taxonomy → `{post_taxonomy:category}` or `{post_taxonomy:project_type}`

See `references/dynamic-data.md` for the full list.

### 11. Per-breakpoint settings (NEVER inline @media)

Bricks renders per-breakpoint blocks. Set per-breakpoint values inside the same setting:

```json
{
  "padding": {
    "_base":            { "top": "6rem", "bottom": "6rem", "left": "2rem", "right": "2rem" },
    "_mobile_landscape":{ "top": "4rem", "bottom": "4rem", "left": "1.5rem", "right": "1.5rem" },
    "_mobile_portrait": { "top": "3rem", "bottom": "3rem", "left": "1rem",   "right": "1rem"   }
  }
}
```

NOT:

```css
@media (max-width: 768px) { .my-section { padding: 3rem 1rem; } }
```

See `references/breakpoints.md`.

### 12. Pseudo-components — Bricks' closest analog to props

Bricks 1.10+ does **not** have a native component-with-properties system the way Etch does. The closest analog is:

```
section template (the "component definition" — uses dynamic data tags)
  ↑ embedded via either
  |   • `template` element (static embed, fixed N times) — variant pinned per consumer
  |   • `posts` element (dynamic Query Loop) — variant per iterated post
CPT entries (the "instance prop values")
  • ACF fields = "properties": title, subtitle, icon, body, cta_label, cta_url, variant
```

The recommender flags `extract_to_pseudo_component` when 2-8 sibling structures share a skeleton but differ in copy (3 plan tiers, 4 services, 5 team members).

#### Decision tree

| Count of repetitions | Recommendation |
|---|---|
| 1 (one-off) | Persist statically |
| 2-5, content fixed + finite | **pseudo-component** (section + CPT + ACF) OR plain section template if no props vary |
| 3+, content grows over time (admin will add more) | `loop_to_query_cpt` (Query Loop + CPT + ACF) |
| 2+, only 1-2 ACF fields differ (icon + label, nothing else) | Component too heavy — use `template` element with `_cssCustom` per-instance overrides |
| 6+ identical-except-text cards | Probably content-driven; lean toward Query Loop + CPT |

#### Step-by-step

1. `nibwp/wp-register-cpt { post_type: "plan", supports: ["title"], public: false, show_ui: true }`
2. `nibwp/acf-manage-fields { action: "create_group", location: { post_type: "plan" }, fields: [
    { name: "price",     type: "text" },
    { name: "period",    type: "text" },
    { name: "features",  type: "repeater", sub_fields: [{ name: "label", type: "text" }] },
    { name: "cta_label", type: "text" },
    { name: "cta_url",   type: "url" },
    { name: "variant",   type: "select", choices: { default: "Default", featured: "Featured" } }
   ] }`
3. `nibwp/bricks-pro-html-to-component { template_type: "section", elements: <ONE plan-card subtree using dynamic tags> }` → returns `{ template_id: 142 }`. Subtree:
   - `heading.text = "{post_title}"` (CPT title)
   - `text.text = "{acf:price}"` + small text `{acf:period}`
   - Feature list bound to `{acf:features}` repeater (Bricks supports ACF repeaters in loops)
   - Button `{acf:cta_label}` / `{acf:cta_url}`
   - Ribbon element with `_conditions: [{ key: "acf-field", acfFieldKey: "variant", operator: "==", value: "featured" }]`
4. `nibwp/wp-create-post { post_type: "plan" }` × N to seed entries from original card content
5. `nibwp/bricks-pro-html-to-component { template_type: "content", elements: <consuming template> }` — the consuming template includes a `posts` element with `settings.query = { post_type: ["plan"], posts_per_page: 3, orderby: "menu_order" }`, and ONE child element: a `template` element with `settings.template = 142`. Bricks iterates the CPT and embeds the section per row.

#### When NOT to use pseudo-components

- Two cards with one field differing → just persist two static subtrees (overhead > value)
- The section needs HEAVY custom logic per instance (conditional rendering with 5+ branches) → stay static or use Bricks Forge add-on for true components
- The user prefers content live in the page editor, not in a CPT admin screen → section template + `template` element with `_cssCustom` overrides per instance

#### Native props (future)

Bricks 2.x roadmap includes a true Component Properties feature. When it ships, this pattern will be revisited. For now, the section + CPT + ACF combo IS Bricks' best practice for reusable "components".

### 13. Reuse — section templates and the `template` element

Bricks doesn't have a "component with properties" concept the way Etch does. The closest equivalent is the **section template** (`template_type = "section"`) + the **`template` element** that embeds it.

| Goal | Tool |
|---|---|
| One reusable layout block embedded across many pages | `section` template + `template` element on each page |
| Repeating cards whose content GROWS over time | Query Loop + CPT + ACF (recommender: `loop_to_query_cpt`) |
| Repeating cards whose content is FIXED + reused elsewhere | `section` template (recommender: `extract_to_section_template`) |
| One-off finite repetition (3 plan tiers, ONE page only) | Just persist 3 static element subtrees |

The recommender flags `extract_to_section_template` when it detects ≥3 sibling structures that look like a clean reusable unit.

#### Two-step workflow

1. First call: persist the SINGLE subtree as `template_type="section"`. Server returns `{ template_id }`.
2. Second call: on the consuming template, replace the N static instances with N `template` elements: `{ name: "template", settings: { template: <template_id> } }`.

#### Why this matters

A `section` template is a first-class Bricks object. Editing it updates every consuming page automatically. Copy-pasting subtrees across templates duplicates source-of-truth → 5 places to edit when the design changes.

### 14. Conditions — Bricks element `_conditions` array

Every Bricks element supports per-element visibility conditions via `settings._conditions`. The element + all its descendants are SKIPPED at render time when conditions evaluate false — no CSS hide hack, no DOM cost, no a11y noise.

#### Shape

```json
{
  "settings": {
    "_conditions": [
      { "key": "user-role",     "operator": "==",      "value": "subscriber" },
      { "key": "post-meta",     "postMetaKey": "featured", "operator": "==", "value": "1" },
      { "key": "post-type",     "operator": "in",      "value": ["project", "case-study"] },
      { "key": "is-front-page", "operator": "isTruthy" }
    ]
  }
}
```

Multiple rows = AND by default. For OR logic, wrap the rows in a group with `operator: "any"`.

#### Operators (validator-checked)

`==` `!=` `>` `>=` `<` `<=` `contains` `not_contains` `isTruthy` `isFalsy` `in` `not_in` `any` `all`

The validator rejects any other operator via rule id `bricks_invalid_condition_shape`.

#### Common condition keys

| key | typical value |
|---|---|
| `user-role` | `subscriber`, `customer`, `editor`, `administrator` |
| `user-logged-in` | true/false |
| `post-meta` | requires `postMetaKey` + value |
| `post-taxonomy` | requires `taxonomy` + `terms` array |
| `post-type` | `["project"]` |
| `is-front-page` | bool |
| `is-singular` | bool |
| `is-archive` | bool |
| `acf-field` | requires `acfFieldKey` + value |
| `woo-product-category` | array of slugs (when Woo active) |

#### When to use

The recommender flags `conditional_visibility` when the source HTML has `style="display:none"`, `hidden` attribute, or class atoms like `is-active`, `is-open`, `is-hidden`, `x-show`, `v-if`, `aria-hidden`. Bake those into `_conditions` so Bricks evaluates them server-side.

#### Combine with Query Loops

Inside a `posts` query loop child, conditions evaluate against the CURRENT post in the loop. Example: show a "Featured" ribbon only when `{acf:featured} == "1"`:

```json
{
  "name": "text",
  "settings": {
    "text": "★ Featured",
    "_conditions": [{ "key": "acf-field", "acfFieldKey": "featured", "operator": "==", "value": "1" }]
  }
}
```

### 15. Feedback loop

After every successful conversion, ask the user "Thumb-up or thumb-down?". Call `nibwp/bricks-pro-feedback`. On the next conversion, the playbook response includes aggregated thumb-down notes for the same `(brand, element_type)` under "Lessons learned" — read them before synthesizing.

## Final verification checklist (run before claiming done)

### Structure
- [ ] `template_type` is one of: header / footer / content / section / archive / error / popup
- [ ] `elements[]` is a flat array; parent/children use 0-based indices that the persister maps to Bricks IDs
- [ ] Every element's `name` exists in `references/bricks-elements.md`
- [ ] Every structural element (`section`, `container`, `block`, `div`, `heading`, `text`, `button`, `image`) references at least one global class via `settings._cssGlobalClasses`

### Tokens + styles
- [ ] Every `font-size` / color / spacing literal is wrapped in `var(--token, fallback)` OR documented in the brand allowlist
- [ ] Zero `style="..."` attributes inside `settings.text` / `settings.code`
- [ ] Zero `@media` blocks inside `settings._cssCustom` — per-breakpoint values use Bricks setting shape

### Bricks-native swaps
- [ ] Zero raw YouTube/Vimeo iframes — every one is a `video` element
- [ ] Zero raw `<form>` markup — either `form` element or `shortcode` element wrapping a form plugin
- [ ] 4+ sibling images either `image-gallery` element or backed by ACF gallery + Query Loop
- [ ] Repeating cards (≥3) either Query Loop + CPT + ACF or explicitly confirmed static

### Dynamic data (content/archive only)
- [ ] Headings on content templates use `{post_title}` (or the agent has confirmed static is intentional)
- [ ] Featured image uses `settings.image.useFeaturedImage = true`
- [ ] Excerpts use `{post_excerpt:N}` with sane word limit

### Final
- [ ] Every element's `settings._id` is unique (6-char hex)
- [ ] Brand prefix matches preflight cached answer (you cannot override server-side anyway)
- [ ] Alt text on every image; lazy-load on every image past the fold; eager + fetchpriority="high" on hero
