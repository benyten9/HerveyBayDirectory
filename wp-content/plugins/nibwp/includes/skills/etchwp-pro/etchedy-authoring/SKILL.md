---
name: etchedy-authoring
description: Use when creating, converting, or refactoring anything into an Etchedy component, element, layout, or template (JSON files under data/library/). Enforces ACSS token-with-fallback CSS (`var(--token, fallback)`), BEM class grammar (`{brand}-{component}__{element}`), the etch/element + etch/text gutenbergBlock tree, __libraryMeta metadata, stable data-etch-sid IDs, correct file placement at `data/library/{Type}/{Category}/{slug}.json`, and manifest.json registration. Triggers on "create an etchedy component", "convert this HTML into etchedy", "make this into etchedy/etchwp JSON", "refactor to etchwp standards", "add a button/element/layout to the etchedy library", pasted HTML/CSS snippets with intent to port, Figma URLs/screenshots meant for the library, or any edit that produces/modifies a file under data/library/.
---

# Etchedy Authoring

This skill produces JSON artifacts for the Etchedy library that slot cleanly into the EtchWP builder. Every output MUST satisfy the checklist at the bottom of this file.

Etchedy never renders HTML itself. The parent `etch/` plugin's `etchit()` engine renders the JSON. Your job is to emit valid JSON + a manifest entry. Do not add PHP, templates, or inline `<style>` tags.

> **Engine target: Etch 1.5.** The block format (`etch/*` blocks, the `components` map, `attrs.script`) is stable across 1.4.x–1.5.x. Usable in output: the full **dynamic-data + modifier** system (see [references/dynamic-data.md](references/dynamic-data.md)), **dynamic SVG** via `etch/svg` `attributes.src` = attachment ID, the WooCommerce loop's `{item.gallery_images}` (featured image first), and an optional `description` on component properties.

> **Where this skill fits.** Etch ships its own in-builder AI Assistant for conversational field/content CRUD. This skill is the complementary, *vision-first* path: faithfully convert **HTML / URL / image / Figma** into validated, reusable components with strict **ACSS-token + BEM** discipline, run **headless via MCP** (batchable, no builder UI). Lean into that — don't duplicate the builder's native field editing.

> **Flat BEM (Etch hard constraint).** Every BEM class is its own top-level style entry — never nest BEM children/modifiers inside a parent selector (no `.hero { .hero__title {} }`). Inside each flat selector, DO nest its pseudo-states (`&:hover`, `&:focus-visible`), pseudo-elements, and the `@media`/`@container` rules that apply to it. Never emit a root-level `@media` for a component selector.

## Reference index — load lazily, only what the task needs

| File | Purpose | When to open |
|---|---|---|
| [references/json-schema.md](references/json-schema.md) | `__libraryMeta` + `gutenbergBlock` + `styles` + `components` shapes | Every conversion — copy a skeleton |
| [references/acss-tokens.md](references/acss-tokens.md) | Full ACSS token taxonomy + canonical fallbacks + scan command | When picking a non-trivial token value |
| [references/bem-naming.md](references/bem-naming.md) | Brand → component → element → modifier rules | When unsure about a class name |
| [references/anti-patterns.md](references/anti-patterns.md) | 16 hard-no patterns (clamp font-size, invented tokens, raw form, missing style hoist…) | Before final synthesis — verify nothing matches |
| [references/etchwp-engine.md](references/etchwp-engine.md) | Container-query setup, `@keyframes` rules, data-attribute variants, block-types table | When the artifact needs CSS-driven behavior |
| [references/dynamic-data.md](references/dynamic-data.md) | Dynamic data sources, loops, conditions + the full **modifier catalog** (Etch 1.5) | When the artifact binds data, loops a query, or transforms a value |
| [references/examples.md](references/examples.md) | 4 gold-standard live-library quotes (scaffold, hero, two-column, hover) | Before starting — pattern-match |
| [references/file-placement.md](references/file-placement.md) | Folder → type mapping, manifest ID format | When writing the JSON to disk |
| [references/js-libraries.md](references/js-libraries.md) | Standardized JS lib whitelist (Swiper, GSAP, IntersectionObserver…) | When the artifact has JS-driven behavior |
| [references/workflows.md](references/workflows.md) | Per-input-type workflows (HTML, text, Figma, refactor) | Pick the matching § for your input |
| [references/feedback-loop.md](references/feedback-loop.md) | Thumb-up/down storage + injection point | After persist — record + read prior lessons |
| `references/checklists/{type}.md` | Per-element-type checklist (button, hero, card-grid, form, navbar, footer, generic) | §6 — must open one per element |

## EtchWP authoring model — the four pillars (recognize these BEFORE building)

Etch is **not** static HTML. Before you synthesize anything — even a from-scratch build with no source markup — decide which of these four the artifact needs. Reach for them by default; a page of hardcoded, duplicated `etch/element` trees is a failure, not a baseline. Depth: [references/dynamic-data.md](references/dynamic-data.md) (loops/conditions/data/modifiers) + [references/json-schema.md](references/json-schema.md) §Components.

1. **Components** — reusable units. Any repeating sub-unit (cards, tiers, list items, tabs) or configurable variant → define once in the top-level `components` map and consume with `etch/component` `{ ref }`. Configurable knobs go in `properties` (with `description`); composable regions use `etch/slot-placeholder` + `etch/slot-content`; branching internals use `etch/dynamic-element`. Build the piece once, instance it — never copy-paste the tree.
2. **Loops** — dynamic lists. When content comes from a query/CPT/repeater (posts, products, ACF repeater rows) **or ≥3 identical sibling structures appear**, it's a loop, not N hardcoded blocks. Wrap the repeating unit in the loop, bind fields with `{item.*}` (e.g. `{item.title}`, `{item.featured_image}`, WooCommerce `{item.gallery_images}`). Static-where-dynamic is a validator finding — the server will flag it; get ahead of it.
3. **Conditions** — conditional rendering/visibility. Show/hide or swap a subtree by data or prop with `etch/condition` (e.g. render a badge only when `item.on_sale`, a CTA only when a prop is set). Don't emit dead markup you hide with CSS.
4. **Dynamic data + modifiers** — bind, don't hardcode. Pull real values from post/ACF/Woo/options via dynamic-data sources, then transform with the **modifier catalog** (Etch 1.5) — formatting, fallbacks, truncation, math — instead of pre-computing text. See dynamic-data.md §Dynamic data + §Modifiers.

Rule of thumb: **if a human editor would ever want to change it without re-opening the builder, it should be a component prop, a loop item, a condition, or a dynamic binding — not a literal.**

## Mandatory routing — read first

When a user message matches any trigger from this skill's `manifest.triggers` (`convert.*etch`, `etchify`, `html to etch`, etc.), you MUST follow the pipeline below in order. Improvising — calling `nibwp/wp-create-post` with raw HTML, writing CSS inline, picking your own brand prefix — is structurally blocked: `nibwp/etchwp-pro-html-to-component` refuses to run without a valid `_preflight_token`, and `nibwp/wp-create-post` returns `requires_user_input` when its content sniffer detects skill-eligible markup.

Pipeline (5 calls):

1. **`nibwp/skill-preflight { skill_id: "etchwp-pro" }`** — server probes ACSS active+version, EtchWP version, existing brand prefixes, candidate target posts, installed form plugins. Reads cached answers from `nibwp_user_defaults`. Returns either `requires_user_input: true` with the still-missing questions (ask the user) OR `success: true` with a one-hour `preflight_token`. Re-call with `answers: {key: value, ...}` until success.

2. **`nibwp/load-skill-playbook { skill_id: "etchwp-pro", brand, element_type? }`** — loads this SKILL.md + per-element checklist + aggregated lessons-learned for the `(brand, element_type)` pair.

2b. **ACSS design-system gate (conditional)** — if the `nibwp/acss-pro-*` abilities are in your tool list AND you have a design source, establish the global ACSS system from that source FIRST: `acss-pro-detect` → `acss-pro-from-design` (validate) → `acss-pro-update-variables` (inject), all before step 3. If those abilities are absent, skip and rely on the preflight `acss_decision`. Full detail in **Flow §1b**.

3. **`nibwp/etchwp-pro-html-to-component { payload, source, brand, element_type, _preflight_token, dry_run: true }`** — server validates. Returns `validation`, `recommendations[]` (loop→CPT+ACF, iframe→etch/embed, alt-less images, div-soup, raw forms), and `unchecked_items[]` on failure. Surface every recommendation to the user. Patch the payload using `fix_hint` on each unchecked item. Resubmit up to 3 times per token (attempts_used / attempts_max are returned; after 3 failures the token is exhausted).

   **3-strike escalation template** — when `attempts_used == attempts_max` and validation still fails, do NOT retry silently. Reply to the user using exactly this shape so they can intervene:

   ```
   Validation failed 3× on this payload. Surfacing for manual review.

   Last attempt rejected:
   - <unchecked_item.id> @ <path>: <msg>  (fix_hint was: <fix_hint>)
   - <unchecked_item.id> @ <path>: <msg>  (fix_hint was: <fix_hint>)
   …

   Recommendations server flagged (independent of the rejections):
   - <recommendation.id>: <recommendation.summary>

   Pick one to continue:
   (a) Override — accept this payload with the listed issues (requires new preflight token + override flag).
   (b) Manual edit — paste a corrected payload chunk and I resubmit.
   (c) Restart — new preflight token, walk back to step 1 (you may want to change brand, element_type, or source).
   ```

   Never burn another token without an explicit user pick. The token is exhausted; a fresh `skill-preflight` call is required to continue regardless.

4. **`nibwp/etchwp-pro-html-to-component { payload, _preflight_token, dry_run: false }`** — server re-validates (defense in depth), then persists. Brand prefix, target post_id, and push_mode are OVERRIDDEN by the cached preflight answers regardless of what the agent put in the payload — you cannot deviate.

5. **`nibwp/etchwp-pro-feedback { component_id, brand, element_type, rating, reason? }`** — thumb-up/down. Down reasons feed the next playbook load's "Lessons learned" block.

The validator hard-rejects (each failed entry carries a copy-paste `fix_hint`):

- `clamp()` anywhere in a `font-size` value
- `font-size`, hex/rgb/hsl colors outside `var()` fallback
- Invented tokens (Tailwind ramps `--base-50`, `--text-display-l`, anything matching `^--text-\d+$` / `^--space-\d+$` / `^--base-\d{2,3}$`)
- Non-BEM class selectors (no `{brand}-{component}__{element}[--modifier]` shape)
- `wp:html` block classes lacking the `{brand}-` prefix (utility hooks like `is-active` exempt)
- `wp:html` block classes not hoisted via a paired `wp:etch/element attrs.styles`
- Raw `<style>` tag or `<link rel="stylesheet">` / `@import` anywhere in the payload
- Raw `<form>` HTML when a form plugin is installed
- ACSS not detected on the site but the payload uses `var(--text-*)` or `var(--space-*)` tokens (3-choice prompt: install ACSS / bake fallbacks / brand stylesheet)

## Token cheat-sheet (inline so you don't need to open acss-tokens.md for trivial picks)

**Canonical token names you can use without opening any reference file:**

| Need | Token | Fallback |
|---|---|---|
| Display heading (XXL) | `var(--text-xxl, 4rem)` | `4rem` |
| H1 / hero title | `var(--text-xl, 3rem)` | `3rem` |
| H2 / section title | `var(--text-l, 2.25rem)` | `2.25rem` |
| H3 / sub-title | `var(--text-m, 1.5rem)` | `1.5rem` |
| Body | `var(--text-base, 1rem)` | `1rem` |
| Small | `var(--text-s, 0.875rem)` | `0.875rem` |
| Caption | `var(--text-xs, 0.75rem)` | `0.75rem` |
| Section padding-y | `var(--section-padding-y, 6rem)` | `6rem` |
| Container gap | `var(--space-l, 2rem)` | `2rem` |
| Card gap | `var(--space-m, 1rem)` | `1rem` |
| Inline gap (icon-text) | `var(--space-s, 0.5rem)` | `0.5rem` |
| Background — body | `var(--shade-white, #ffffff)` | `#ffffff` |
| Background — alt | `var(--base-ultra-light, #f5f5f5)` | `#f5f5f5` |
| Border | `var(--base-light, #e5e5e5)` | `#e5e5e5` |
| Text muted | `var(--base-medium, #737373)` | `#737373` |
| Text body | `var(--base-dark, #262626)` | `#262626` |
| Heading | `var(--base-ultra-dark, #0a0a0a)` | `#0a0a0a` |
| Primary action | `var(--action, #007aff)` | `#007aff` |
| Primary hover | `var(--action-hover, #0056b3)` | `#0056b3` |

**Hard-rejected invented patterns (validator regex):**

```
^--text-\d+$        # NO --text-50, --text-100, --text-500 (Tailwind-style)
^--space-\d+$       # NO --space-4, --space-16
^--base-\d{2,3}$    # NO --base-50, --base-100, --base-900
--text-display-l    # NO display aliases — use --text-xxl
--text-display-m
--text-display-xl
```

Open [references/acss-tokens.md](references/acss-tokens.md) for the full taxonomy and the regenerate-from-ACSS scan command.

## Source precedence

When the user provides multiple input modalities for one conversion, pick the highest-ranking one as the authoritative source and use the others only as fallback signal:

1. **Explicit HTML/CSS pasted in the message** — highest authority (faithful reproduction goal)
2. **URL** the agent fetches via `nibwp/fetch-url-content` / playwright — second
3. **Image / screenshot** vision read — third (use for layout + spacing + colors when HTML lacks them)
4. **Text description** ("a 3-column pricing section with…") — lowest (used to seed copy + structure when no visual)

If 1 and 2 both exist (paste + URL), trust the paste — the URL render may differ from what the user intends.
Surface the chosen precedence in your first reply: "Treating the pasted HTML as authoritative; URL used only for spacing reference."

## Core principles

1. **Convert what you see.** Whether the input is HTML+CSS, an image, a screenshot, or a text description — faithfully reproduce the visual. Do not add, remove, or "improve" design elements beyond what was given.
2. **Pixel-perfect fidelity.** Match the source's spacing, typography, colors, proportions, and visual weight as closely as possible. Use exact values from the source as token fallbacks.
3. **Always responsive.** Every artifact MUST include responsive behavior. Use container queries (`@container`) when the component's breakpoint depends on its own width, or `@media` queries for viewport-based breakpoints. Collapse grids to single columns, switch to a smaller `--text-*` ACSS token at the breakpoint (NEVER `clamp()` font-size), and hide decorative elements on small screens. Layout values (gap, padding) MAY use `clamp()` inside the `var()` fallback slot.
4. **Define behavior.** If the source shows animations, hover effects, transitions, accordion behavior, or scroll effects — capture them in the CSS and note any JS requirements in the description. Don't silently drop interactivity.
5. **ACSS-first, always with fallbacks.** Use `var(--token, fallback)` for every value that maps to an ACSS token. If ACSS can express it, use the token. But ALWAYS include the fallback — never bare `var(--token)` without a fallback value (the only exception: `--bo-*` tokens inside BookingOptimiser-scoped files).
6. **Use the component system.** When an artifact has repeating sub-units (cards, list items, tabs) or configurable variants (style, visibility, text), use `etch/component` with `properties`, `etch/condition`, `etch/slot-placeholder`, and `etch/dynamic-element`. Build reusable pieces, not hardcoded trees.

## Flow

Follow these steps in order. Open only the reference files you need for the current task.

### 1. Preflight (TWO layers)

**Session preflight** — already handled by `nibwp/skill-preflight` (step 1 of the mandatory pipeline above). The user answered these BEFORE you got to synthesis: brand prefix, target post (or new_page + title), push mode, ACSS decision. These come back in `token_payload.answers` and OVERRIDE anything the agent puts in the html-to-component payload. Do not re-ask.

**Brand prefix discovery** — when `nibwp/skill-preflight` returns `existing_brand_prefixes: []` (site has zero brands defined yet) AND the user did not specify a brand, fall back in this order:

1. Slugified site title from `wp_get_option('blogname')` — e.g. "Tour Deals Rome" → `tour-deals-rome` (truncate to 14 chars: `tour-deals-rom`). Use as the proposal.
2. If site title is too short or generic (`Wordpress`, `My Site`), fall back to the domain root word — `nibwp.com` → `nibwp`.
3. Always confirm the proposal back to the user in the same preflight reply: "No brands found. Proposed brand prefix: `<slug>`. OK or override?" Do NOT proceed silently — brand prefix is permanent for every class name in the output.

`existing_brand_prefixes` comes from scanning `data/library/Brands/*/` folder names on disk. If the site has brands `alpha` + `beta` and the conversion intent doesn't match either by tone (e.g. user pasted luxury jewelry HTML but site brands are `tech-saas` + `dev-tool`), propose a NEW brand rather than forcing into the existing taxonomy.

**Per-payload self-check (silent)** — fill these in your own head, never ask the user again:

- **Artifact type?** `Element` (atomic — buttons, icons, dividers), `Component` (mid-sized — cards, FAQ rows, stat blocks), `Layout` (full section — hero, features, pricing), `Template` (full page), or `Brand` variant scoped under a named brand. Drives the `__libraryMeta.type` field.
- **Category?** PascalCase sub-folder name (e.g. `Buttons`, `Hero`, `Features`, `CTA`, `Pricing`). Drives `__libraryMeta.category` + the persistence path.
- **Slug?** Kebab-case of `__libraryMeta.name`. The slug + persistence path + manifest id all derive from it.
- **Element type for the checklist?** One of `button|hero|card-grid|form|navbar|footer|accordion|tabs|slider|image|divider|marquee|stat-block|testimonial|pricing|cta`. Routes which `references/checklists/{type}.md` gets injected into the playbook response.

### 1b. ACSS design-system gate — establish the global system BEFORE etching

**Detect (playbook-side):** are the `nibwp/acss-pro-*` abilities present in your available tool list? Their presence means the **ACSS Pro skill is active + unlocked** on this site.

- **If they are NOT present → skip this whole step.** Resolve tokens the existing way: the preflight `acss_decision` answer (`install_acss` / `bake_fallbacks` / `brand_stylesheet`). Pass by — do not attempt ACSS config work.
- **If they ARE present AND you have a design source** (screenshot / HTML+CSS / URL / Figma): you MUST establish the global ACSS design system from that **same source before building any component** — run this automatically, no extra prompt to the user:
  1. **`nibwp/acss-pro-detect`** — read the current ACSS settings so you merge, not clobber.
  2. Extract a full config from the source — **palette, type scale, spacing, radius, shadows, breakpoints, light/dark** — and validate: **`nibwp/acss-pro-from-design { config, source, _preflight_token }`**. On `failed[]`, patch with each `fix_hint` and resubmit until `validated: true`.
  3. **`nibwp/acss-pro-update-variables { config, _preflight_token }`** — inject the validated config into the ACSS settings (persist mode comes from preflight). This writes the site's global ACSS variables.
  4. **Only now etch.** Every `var(--token, fallback)` you emit next resolves to the real, just-injected brand system — not a guessed fallback.

**Guards.** Uses the same `_preflight_token`; runs after `skill-preflight`, before `etchwp-pro-html-to-component`. If there is **no design source** (pure text brief, or refining an existing component), do NOT fabricate a config — skip to build and use existing/nearest tokens. Never inject an unvalidated config.

### 2. Build the JSON

Open [references/json-schema.md](references/json-schema.md) for the full `__libraryMeta` + `gutenbergBlock` + `styles` + `components` shape and skeletons to copy.

Every artifact has a section → container scaffold using the readonly styles `etch-section-style` and `etch-container-style` (and optionally `etch-flex-div-style` for flex wrappers), then your BEM-named blocks inside. Do not redefine those readonly styles differently from the canonical values — copy them verbatim from any example.

**When to use the component system:** If the artifact has repeating sub-units (e.g. 3 feature cards sharing the same structure, list items, pricing tiers) or user-configurable variants (show/hide sections, style presets, dynamic tags), define reusable sub-components in the top-level `components` map. Use `etch/component` blocks with `ref` to consume them, `properties` for configurable props, `etch/slot-placeholder` + `etch/slot-content` for composable content slots, and `etch/condition` for conditional rendering. See [references/json-schema.md](references/json-schema.md) § Components.

### 3. Style rules (HARD — non-negotiable)

- **Every CSS value uses ACSS token with fallback**: `var(--token-name, fallback-value)`.
- The only acceptable exceptions are (a) brand accent hexes inside a brand-scoped file (e.g. `#c9a96e` in `luxe-*`), (b) structural values that have no token (e.g. `1fr`, `100%`, `50ch`, `Playfair Display`), and (c) the `--bo-*` tokens which are consumed without fallback by convention.
- **BEM class grammar**: `{brand}-{component}__{element}[--{modifier}]`. No generic `.heading` / `.btn` / `.card`.
- **Every element MUST have a BEM class.** No element, section, container, div, span, or any block may exist without a `class` attribute. Even scaffold elements (`data-etch-element="section"`, `data-etch-element="container"`) get a BEM class in addition to their scaffold role.
- **Container-query-first responsiveness.** Use `:has(> &) { container-type: inline-size; }` on the component's main element + `@container (width >= Xpx)` rules inside the same CSS string. Fall back to `@media` only for viewport-specific needs (full-viewport layouts, print styles). Use `to-rem()` in query conditions for accessibility (e.g. `@container (inline-size > to-rem(800px))`). See [references/etchwp-engine.md](references/etchwp-engine.md) for the full container query pattern.
- **Responsive rules live inline** inside the same `css` string — not separate style objects per breakpoint.
- **Hover/focus states live inline** using `&:hover { … }` nested syntax. Do NOT create separate pseudo-state style objects.
- **`@keyframes` MUST be separate style entries** — EtchWP does NOT support `@keyframes` inside a selector's `css` string. Define each keyframe as its own top-level style with `type: "custom"`, `selector: "@keyframes name"`, and `css` containing only the keyframe body. Reference the animation name from the element's style. See [references/etchwp-engine.md](references/etchwp-engine.md).
- **Data-attribute variants** are preferred for multi-variant components: `[data-style='center' i]` selectors driven by props via `data-*` attributes. BEM modifiers (`--modifier`) are also acceptable for simpler cases.

Open [references/acss-tokens.md](references/acss-tokens.md) for the full token taxonomy, the canonical fallbacks, and the scan command to refresh it.

### 3b. JavaScript & interactivity rules (HARD — non-negotiable)

When a component needs JS-driven behavior (sliders, scroll animations, accordions, tabs, counters), use **only** the standardized libraries from [references/js-libraries.md](references/js-libraries.md):

- **Sliders/carousels** → **Swiper** (not Slick, Flickity, Owl, Splide, or any other)
- **Scroll entrance animations** → **CSS transitions + IntersectionObserver** (no AOS, no ScrollReveal)
- **Complex timelines/parallax** → **GSAP + ScrollTrigger** (only when CSS can't do it)
- **Accordions** → native `<details>` + CSS (no JS library)
- **Tabs** → vanilla JS data-tab pattern (no JS library)
- **Lightbox/modal** → native `<dialog>` + vanilla JS (no GLightbox, no Fancybox)
- **Marquee** → CSS `@keyframes` (no JS library)
- **Counters** → IntersectionObserver + requestAnimationFrame (no library)

**Rule: if CSS can do it, don't load a library.** Scripts are base64-encoded in `attrs.script.code`. Always wrap in an IIFE, poll for the library global before init, and guard against re-initialization. When in doubt, grep the library for the token to confirm it exists and see its typical fallback:

```bash
grep -rohE 'var\(--[a-z0-9-]+(,\s*[^)]*)?\)' data/library/ | sort -u
```

### 4. Place the file and register it

- Write the JSON to `data/library/{Type}/{Category}/{slug}.json` (Type and Category as PascalCase folder names already on disk).
- Add an entry to `data/manifest.json` under the matching `Type → Category → children` array: `{ "name": "<Human Name>", "id": "{type}-{category}-{slug}" }` — the `id` is lowercase and hyphen-separated.
- Brand-scoped variants live under `data/library/Brands/{Brand}/{Category}/{slug}.json` and are NOT added to the root manifest tree (the builder discovers them by scanning).

Open [references/file-placement.md](references/file-placement.md) for the exact folder → type mapping, ID format, manifest-tree navigation, and the optional `POST /wp-json/etchedy/v1/sync-library` refresh.

### 5. Pick the workflow for your input

- Raw HTML/CSS snippet → [references/workflows.md](references/workflows.md) § 1
- Text description (e.g. "a pricing section with 3 tiers") → § 2
- Figma URL or screenshot → § 3
- Existing JSON to refactor / lint → § 4

### 6. Per-element checklist (PICK ONE)

Identify the element type of what you're converting and open the matching file from `references/checklists/` — that file is the authoritative checklist for this conversion. Element types not in this table fall back to `references/checklists/generic.md`.

| Element type | Checklist file |
|---|---|
| button | [checklists/button.md](references/checklists/button.md) |
| hero | [checklists/hero.md](references/checklists/hero.md) |
| card-grid | [checklists/card-grid.md](references/checklists/card-grid.md) |
| form | [checklists/form.md](references/checklists/form.md) — **read FIRST when input contains `<form>`** |
| navbar | [checklists/navbar.md](references/checklists/navbar.md) |
| footer | [checklists/footer.md](references/checklists/footer.md) |
| accordion / tabs / slider / image / divider / marquee / stat-block / testimonial / pricing / cta | [checklists/generic.md](references/checklists/generic.md) |

Load the checklist via `nibwp/load-skill-playbook { skill_id:"etchwp-pro", sections:["checklists/<type>"], element_type:"<type>", brand:"<brand>" }`. The response includes any aggregated thumb-down lessons learned for the same `(brand, element_type)` pair (see §10).

### 7. Pattern-match against gold-standard examples

Before starting, scan [references/examples.md](references/examples.md). It quotes 4 canonical files from the live library that together cover: minimal section scaffold, multi-level content with responsive + hover, fullscreen hero with `content-width`/`content-padding`, and inline two-column → single-column collapse. Copy their structure; only change what your task requires.

### 8. Verify against anti-patterns

Open [references/anti-patterns.md](references/anti-patterns.md). Your output MUST NOT repeat any of them. Notably: never hardcode a CTA button background without a token reference, never use a generic non-BEM selector, never forget the manifest entry, never invent a PHP template, **never `clamp()` font-size** (§13), **never invent Tailwind ramp tokens** (§14), **always pair `wp:html` blocks with a style-hoist `wp:etch/element`** (§15), **never emit raw `<form>` HTML when a form plugin is installed** (§16).

### 9. Style hoisting for raw HTML

If the artifact mixes `wp:etch/element` blocks with a `wp:html` raw-HTML block (e.g. an embedded `[shortcode]` markup wrapper or a third-party widget), every class used inside the raw HTML must be referenced from a hidden `wp:etch/element` whose `attrs.styles` lists those style IDs. Without this, Etch never enqueues the CSS for those classes — the raw HTML renders unstyled. See anti-patterns.md §15. The validator enforces this rule.

### 10. Forms

If the source HTML contains a `<form>`, do NOT convert the form HTML. Stop and call `nibwp/forms-manage` with `action: "list_plugins"` to retrieve installed form plugins. Present the choices to the user, then emit an `etch/shortcode` block (or `wp:shortcode` fallback) that wraps the chosen plugin's shortcode. Style-hoist the wrapper classes so the surrounding section CSS still applies. See [checklists/form.md](references/checklists/form.md).

### 11. Loop detection — dynamise repeating structures

Before final synthesis, count repeating sibling structures (same tag + same first BEM class family). When **≥3** identical structures appear under one parent, STOP and treat as a loop candidate. The server-side recommender returns the same finding under `response.recommendations[]`; surface it to the user before committing.

**Carve-out — do NOT treat as a CPT/component candidate when the parent ancestor is one of these blocks:**

- `etch/slider` — carousels with N slides are inherently repetitive; that's the point. 10 testimonial slides ≠ 10 CPT entries.
- `etch/marquee` — scrolling ticker rows.
- `etch/tabs` — tab labels + panels are siblings by structure.
- `etch/accordion` — Q&A rows under `<details>` are siblings.
- Any block with `repeater: true` in its component definition's `properties[]`.

In these cases, leave the N siblings static or use `properties.items: [{…}, {…}]` array prop on the wrapper component. The validator will skip the `loop_to_cpt` recommendation for these parents but you should also skip it agent-side to avoid confusing the user with a prompt that doesn't fit the context.

Decision routine when a loop is detected:

1. Ask the user: *"Detected N repeating ‹family› cards. Make these dynamic via a CPT + ACF (recommended for content that grows), reuse an existing CPT, or persist them as static?"*
2. If **make dynamic**:
   - Call `nibwp/wp-register-cpt` with the suggested `post_type` + supports.
   - Call `nibwp/acf-manage-fields` with the suggested field set. CONFIRM the field types with the user (the recommender's field shape is a heuristic from the markup, not authoritative).
   - Resubmit the html-to-component payload replacing the N static blocks with ONE `etch/loop-block` that iterates the CPT and renders one card per entry using `{acf.field_name}` tokens.
   - Call `nibwp/wp-create-post` N times seeded with the original card content so the loop renders identically on first paint.
3. If **reuse existing CPT**: ask which slug, query its registered fields, regenerate the payload using those tokens.
4. If **persist static**: continue as-is. Recommendation moves to warnings; no further routing.

NEVER persist 6 identical static cards if the user accepted dynamic conversion. NEVER invent CPT/ACF entries — always run the suggested ability chain.

### 12. HTML semantic upgrades

When the source HTML is a div-soup (Tailwind dump, Webflow export, generic generator), upgrade outer landmarks during synthesis:

- Top-level wrapper → `<section>` (or `<article>` if it has its own heading + standalone meaning).
- Hero / CTA / feature block with a heading → `<article>` or `<section>`.
- Header bar → `<header>`. Footer → `<footer>`. Nav → `<nav>` with `aria-label`.
- Main content region → `<main>` (once per page).
- Sidebar / aside content → `<aside>`.
- Repeated cards inside a loop → `<article>` per item.

The recommender flags `div_soup` when consecutive nested `<div>` depth ≥6. Etch renders any HTML5 tag via `etch/element { tag: "<landmark>" }` — there is no cost to upgrading.

### 13. Image conversion

When the source contains `<img>` tags:

- Every image MUST have a non-empty `alt`. Generate one from: nearest heading > sibling caption > filename without extension > image-vision description ("photo of a person at a desk"). If the image is purely decorative, set `alt=""` AND `role="presentation"`.
- Add `loading="lazy"` to every image below the fold (anything past the first viewport). The first hero image MAY use `loading="eager"` + `fetchpriority="high"`.
- Add `decoding="async"` to all images.
- When multiple sizes are available (URL with `-1200x800`, `-600x400` suffixes), emit `srcset` with at least 3 breakpoints + `sizes="(min-width: 60rem) 50vw, 100vw"` (tune to layout).
- For 4+ sibling images under one parent, treat as a gallery — the recommender flags `gallery_grid` and proposes an ACF gallery field on a CPT.
- Re-upload images that need processing via `nibwp/wp-upload-media` with the generated alt baked into post meta. Do not hot-link external image hosts unless explicitly told.

### 14. Embeds (iframes, video, social)

NEVER persist a raw `<iframe>` for media. Replace with the appropriate block:

| Source | Replace with | Why |
|---|---|---|
| `<iframe src="*.youtube.com/embed/*">` | `etch/embed { provider: "youtube", url }` (fallback: `core/embed`) | Responsive container + privacy mode + lazy load |
| `<iframe src="*vimeo.com*">` | `etch/embed { provider: "vimeo", url }` | Same |
| `<iframe src="*twitter.com*">` / x.com | `core/embed/twitter` | Native Twitter card |
| Any other third-party iframe | `etch/embed { provider: "generic", url }` with explicit aspect-ratio CSS in styles dict | Lazy-load + container query support |

The recommender flags `iframe_provider` per detection. Ask the user once whether to swap; default to swap unless they say keep-raw.

### 15. Components, properties, and conditions (smart reuse)

Before final synthesis, decide whether the artifact should be a **single static tree**, a **dynamic loop**, or a **static component with props**:

| Pattern | Use |
|---|---|
| Repeating cards, content grows (admin adds entries) | Dynamic — `loop_to_cpt` recommendation (CPT + ACF + etch/loop-block) |
| Repeating cards, content is fixed + finite (3 plan tiers, 4 features, 6 services) | Static-with-props — `extract_to_component` recommendation (etch/component + properties) |
| Repeating cards, you genuinely don't know yet | Surface BOTH recommendations to the user. Default to component if count ≤ 5, loop if count ≥ 6 |

The recommender flags both via `loop_to_cpt` (count ≥3) and `extract_to_component` (count ≥2, prefers component for count ≤5).

#### Defining a component

```json
{
  "components": {
    "my-card": {
      "name": "my-card",
      "category": "Cards",
      "properties": [
        { "name": "title",     "type": "string",  "default": "" },
        { "name": "body",      "type": "string",  "default": "" },
        { "name": "icon",      "type": "image",   "default": null },
        { "name": "cta_label", "type": "string",  "default": "Learn more" },
        { "name": "cta_url",   "type": "url",     "default": "#" },
        { "name": "variant",   "type": "select",  "default": "default", "options": ["default", "featured"] }
      ],
      "gutenbergBlock": {
        "blockName": "etch/component",
        "attrs": { "componentId": "my-card", "tag": "article" },
        "innerBlocks": [
          { "blockName": "etch/element", "attrs": { "tag": "h3", "styles": ["alpha-card__title"] }, "innerBlocks": [
            { "blockName": "etch/text", "attrs": { "content": "{props.title}" } }
          ]},
          { "blockName": "etch/text", "attrs": { "tag": "p", "content": "{props.body}", "styles": ["alpha-card__body"] }},
          { "blockName": "etch/condition", "attrs": { "conditions": [{ "source": "{props.icon}", "operator": "isTruthy" }] }, "innerBlocks": [
            { "blockName": "etch/image", "attrs": { "src": "{props.icon}", "styles": ["alpha-card__icon"] }}
          ]},
          { "blockName": "etch/element", "attrs": { "tag": "a", "attributes": { "href": "{props.cta_url}" }, "styles": ["alpha-card__cta"] }, "innerBlocks": [
            { "blockName": "etch/text", "attrs": { "content": "{props.cta_label}" } }
          ]},
          { "blockName": "etch/condition", "attrs": { "conditions": [{ "source": "{props.variant}", "operator": "==", "value": "featured" }] }, "innerBlocks": [
            { "blockName": "etch/element", "attrs": { "tag": "span", "styles": ["alpha-card__ribbon"] }, "innerBlocks": [
              { "blockName": "etch/text", "attrs": { "content": "★ Featured" } }
            ]}
          ]}
        ]
      }
    }
  }
}
```

#### Using component instances

Each instance is a thin `etch/component` block referencing the component id + filling its `props`:

```json
{
  "blockName": "etch/component",
  "attrs": {
    "componentId": "my-card",
    "props": {
      "title": "Starter", "body": "For solo developers.", "icon": null,
      "cta_label": "Pick Starter", "cta_url": "/pricing/starter", "variant": "default"
    }
  }
}
```

Three plan tiers become three instances differing only in their `props` payload. Editing the component template updates all three. The validator enforces that `props.<name>` references match a declared `properties[i].name` in the component definition.

#### Property types

| type | shape |
|---|---|
| `string` | plain text |
| `richtext` | HTML allowed (`<strong>`, `<em>`, `<a>`) |
| `url` | href |
| `image` | media-library id OR external URL |
| `boolean` | true/false |
| `number` | int/float |
| `select` | string from `options[]` array |
| `slot` | wrapped content passed in by the instance (uses `etch/slot-placeholder`) |

#### Conditions — etch/condition block

`etch/condition` wraps content rendered only when its `conditions[]` array evaluates true. Operators:

- `isTruthy` / `isFalsy` — boolean check
- `==` / `!=` — strict equality
- `contains` / `not_contains` — substring
- `&&` (default for multiple rows) — all rows must pass
- `||` — any row passes (set `operator: "||"` on the wrapper)

The recommender's `condition_candidate` flags source HTML with `style="display:none"`, `hidden` attr, or class atoms like `is-active` / `is-hidden` / `x-show` / `v-if`. Bake these into explicit etch/condition blocks; CSS-driven visibility still pays parse + a11y cost.

#### When to use slots

If the instance should pass in arbitrary child content (not just primitive props):

```json
// component definition
{ "blockName": "etch/slot-placeholder", "attrs": { "slotName": "body" } }

// instance
{
  "blockName": "etch/component",
  "attrs": { "componentId": "my-card" },
  "innerBlocks": [
    { "blockName": "etch/slot-content", "attrs": { "slotName": "body" }, "innerBlocks": [
      { "blockName": "etch/text", "attrs": { "content": "Arbitrary content here" } }
    ]}
  ]
}
```

Use slots when the consumer needs to inject formatted content, not just primitive props.

### 16. Feedback loop

After every successful conversion, ask the user "Thumb-up or thumb-down?". Call `nibwp/etchwp-pro-feedback` with the rating and a one-line reason. On the next conversion, `nibwp/load-skill-playbook` returns aggregated thumb-down notes for the same `(brand, element_type)` under "Lessons learned" — read them before synthesizing.

### 17. Accessibility floor (HARD — non-negotiable)

Every artifact must pass these six items BEFORE you submit the final payload. The validator does not enforce them yet — they are your responsibility:

1. **Focus visible** — every interactive element (`<a>`, `<button>`, `<input>`, `<details>`, custom roles) has a `&:focus-visible { outline: 2px solid var(--action, #007aff); outline-offset: 2px; }` rule. NEVER `outline: none` without a paired visible replacement.
2. **Contrast ratios** — body text against its background ≥ 4.5:1, UI components (buttons, borders, icons) ≥ 3:1. When using a brand accent on a brand background, compute it before persisting; do not assume the brand palette is accessible.
3. **Tap target ≥ 44×44px** — buttons, links inside nav, icon-only triggers all need `min-block-size: 44px; min-inline-size: 44px;` (or padding that achieves the same).
4. **Semantic landmarks** — every section emitted with `etch/element { tag: "section" }` includes either an explicit `aria-label` OR contains an `<h2>`/`<h3>` heading; never a section that screen readers see as "section with no name".
5. **Images** — every `<img>` has a non-empty `alt` OR `alt="" + role="presentation"`. No exceptions. Generation order: nearest heading → sibling caption → filename without extension → vision description.
6. **Keyboard reachable** — every interactive thing tab-reachable. No `tabindex="-1"` on a clickable element. Custom `role="button"` elements need `tabindex="0"` + key handler for Enter + Space.

When the source HTML violates any of these, fix during conversion — don't faithfully reproduce a broken pattern. Note the fix in the conversion summary: "Source had `outline:none` on `.btn` — replaced with focus-visible ring."

### 18. Editor preview vs frontend (warning)

The WordPress block editor renders blocks through Gutenberg's React tree. Etchedy components rely on the `etchit()` PHP runtime to compile JSON → HTML at frontend render time. The editor does NOT run that pipeline.

- `etch/component` instances populated via `{props.title}` show the literal string `{props.title}` in the editor — that is correct, not a bug.
- `etch/condition` blocks render every branch in the editor (no condition evaluation).
- `etch/loop-block` shows the template, not the iterated content.
- `etch/slot-placeholder` shows as an empty marker.

QA the final output on the **frontend** (`/?p=<post_id>` or `/<slug>/`), never in the WP block editor. If the user pings about "the editor looks broken", redirect them to the frontend URL.

### 19. Cache invalidation post-persist

When `dry_run:false` succeeds and the payload is persisted, the persister fires `do_action('etch/library_synced', $payload, $diff)`. Subscribers that bust cache:

- `etch/` plugin's compiled-CSS transient `etch_compiled_css_<post_id>` (used by frontend renderer).
- ACSS plugin's variable cache (only when the payload added new style entries that reference ACSS tokens).
- WP object cache key `etch_styles` (one read per request — invalidated automatically by `update_option`).

You do NOT need to call any cache-bust helper from the agent side — the action fires inside the persister. If the user reports stale CSS after a persist, ask them to hard-refresh (`Ctrl+Shift+R`) and check whether a page-cache plugin (W3TC, WP Rocket, LiteSpeed) is holding the HTML; cache plugins are outside Etch's invalidation scope.

### 20. Surface the diff back to the user

The persister returns:

```json
{
  "success": true,
  "component_id": "alpha-pricing-tier-a3c7e1f9",
  "diff": {
    "styles_added":    ["alpha-pricing__title", "alpha-pricing__price", …],
    "styles_updated":  [],
    "blocks_added":    14,
    "blocks_modified": 0,
    "post_id":         42
  }
}
```

Your reply to the user MUST echo the diff verbatim, not paraphrased. Format:

```
Persisted alpha-pricing-tier to post 42:
- 8 new styles: alpha-pricing__title, alpha-pricing__price, alpha-pricing__cta, …
- 14 new blocks added (no blocks modified).
- Frontend URL: https://<site>/?p=42

Component ID: alpha-pricing-tier-a3c7e1f9 (use this to refine later via nibwp/etchwp-pro-refine-component).
```

This gives the user the exact identifiers needed to navigate to the result and to call `refine-component` for follow-up tweaks. Do NOT summarize away the style IDs — they are the audit trail.

## Final verification checklist (run before claiming done)

Every box must be checked. If you cannot honestly check a box, fix the output or explain the exception in your reply.

### Structure
- [ ] `__libraryMeta` has all six fields: `brand`, `type`, `category`, `tags` (≥3 meaningful tags), `name`, `description` (one sentence, non-empty).
- [ ] File is saved at `data/library/{Type}/{Category}/{slug}.json` and `{slug}` equals the kebab-case of `name`.
- [ ] A matching entry `{ name, id: "{type}-{category}-{slug}" }` exists in `data/manifest.json` under the correct Type → Category children array (unless the file is brand-scoped under `Brands/` or `Sites/`, in which case no manifest edit is needed — the scanner handles it).
- [ ] Root `gutenbergBlock` is `etch/element` with a semantic `tag` (`section`, `div`, `button`, `ul`, …) and `data-etch-element="section"` when it is a full section.
- [ ] The section → container scaffold uses the readonly `etch-section-style` and `etch-container-style` verbatim; no edits to those readonly objects.

### Styles & tokens
- [ ] Every class selector in `styles[*].selector` follows `{brand}-{component}__{element}[--{modifier}]`. No generic `.btn`, `.card`, `.heading`.
- [ ] Every CSS value in `styles[*].css` is either `var(--token, fallback)`, a justified brand accent hex inside a brand-scoped file, a justified structural value (`1fr`, `100%`, `50ch`, etc.), or a `--bo-*` token used raw.
- [ ] Responsive rules are inline `@media` or `@container` inside the same css string — not separate style objects.
- [ ] Hover/focus states are inline `&:hover { … }` — not separate style objects.

### Responsiveness (MANDATORY — container-query-first)
- [ ] The artifact uses **container queries** (`@container`) as the primary responsive mechanism, not `@media` (unless viewport awareness is specifically needed).
- [ ] The main layout element has `:has(> &) { container-type: inline-size; }` to establish container context portably.
- [ ] Grid/multi-column layouts collapse to single column below a sensible container-width breakpoint.
- [ ] Display typography switches token (`--text-xxl` → `--text-xl` → `--text-l`) at breakpoints inside the same selector. **NEVER `clamp()` `font-size`.**
- [ ] Decorative/non-essential elements (illustrations, background effects) are hidden or scaled on small screens.
- [ ] Query conditions use `to-rem()` where possible for accessibility (e.g. `@container (inline-size > to-rem(800px))`).

### Component system (when applicable)
- [ ] Repeating sub-units use `etch/component` with a `components` map entry rather than copy-pasted block trees.
- [ ] Component properties have sensible defaults and correct types (string, boolean, select, image).
- [ ] Conditional sections use `etch/condition` with `isTruthy` / `&&` / `||` operators — not hardcoded visibility.
- [ ] Dynamic text uses `{props.xxx}` in `etch/text` content or `etch/raw-html` content.
- [ ] Dynamic tags use `etch/dynamic-element` with `tag: "{props.xxx}"`.
- [ ] Composable content areas use `etch/slot-placeholder` + `etch/slot-content`.

### Accessibility (per §17)
- [ ] Every interactive element has a visible focus state (`&:focus-visible { outline … }`).
- [ ] Body text contrast ≥ 4.5:1 against its background; UI contrast ≥ 3:1.
- [ ] Tap targets ≥ 44×44px on touch surfaces (buttons, nav links, icon triggers).
- [ ] Each `<section>` has an `aria-label` OR contains a heading.
- [ ] Every `<img>` has a non-empty `alt` OR `alt="" + role="presentation"`.
- [ ] Custom-role interactive elements (`role="button"` etc.) include `tabindex="0"` + Enter/Space key handler.

### Final
- [ ] Every `data-etch-sid` (if used) is unique within the file.
- [ ] No inline `<style>` tags, no separate `.css` files, no PHP — all styles live in the `styles` object on the artifact JSON.
- [ ] No fabricated assets — missing images/illustrations use structural placeholders (`<img src="" alt="...">` with proper dimensions), never fabricated SVG approximations.
- [ ] The diff returned by the persister (`styles_added`, `blocks_added`, `component_id`, `post_id`) is echoed back to the user verbatim per §20 — not paraphrased.

When every box passes, output the saved file path, the added manifest id, the persister diff, and a 1-line summary of what was produced.
