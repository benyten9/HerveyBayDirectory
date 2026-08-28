# Workflows — by input type

Pick the section that matches what you were given. All four flows end at the same checklist in `SKILL.md`.

---

## 1. From raw HTML/CSS snippet

**Input**: a block of HTML and matching CSS pasted or linked by the user.

### Steps

1. **Identify the root tag.** If it's `<section>`, this is a layout/component; `<button>`/`<a>` alone is an element; mid-sized (`<article>`, `<div class="card">`) is a component.
2. **Pick the brand, type, category, slug** (see SKILL.md § 1 Preflight). If the user has not specified a brand, default to `etched`.
3. **Walk the HTML tree**, mapping each node:
   - HTML element → `etch/element` block with `tag` set to the HTML tag and `attributes` carrying `class`, `href`, `src`, `alt`, `aria-*`, etc.
   - Text content → `etch/text` child with `attrs.content` = the text.
   - For full-section artifacts: wrap in `section[data-etch-element="section"]` → `div[data-etch-element="container"]`, then your content. If the source HTML already has this wrapping, reuse it; if not, add it (it's the canonical Etchedy scaffold).
4. **Rename classes to BEM.** Drop any framework classes (Tailwind `flex p-4 rounded`, Bootstrap `row col-md-6`, etc.) — the CSS translation will carry their effect. Replace generic class names with `{brand}-{component}__{element}`.
5. **Translate each CSS rule to a style object.**
   - One `styles[*]` entry per distinct selector.
   - Start with the scaffold pair (`etch-section-style`, `etch-container-style`) verbatim.
   - For each BEM class in your tree, create a `{ type: "class", selector: ".brand-component__element", collection: "default", css: "...", readonly: false }` entry.
6. **Substitute raw values with ACSS tokens.** For every CSS value, look up the closest token in [acss-tokens.md](acss-tokens.md) and replace with `var(--token, original-value)`. Examples:
   - `padding: 16px` → `padding: var(--space-m, 1rem)`
   - `font-size: 14px` → `font-size: var(--text-s, 0.875rem)`
   - `border-radius: 8px` → `border-radius: var(--radius, 0.5rem)`
   - `color: #111` → `color: var(--heading-color, #111)`
   - Keep the original value as the fallback so behavior is preserved if the token is undefined.
7. **Keep `@media` queries inline.** If the source CSS has `@media (max-width: 768px) { .x { grid-template-columns: 1fr; } }`, inline it inside the same `.x` css string:
   ```json
   "css": "display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--space-l, 2rem); @media (max-width: 768px) { grid-template-columns: 1fr; }"
   ```
8. **Fill `__libraryMeta`** — brand, type, category, tags (≥3), name, description. Write a genuine description of what the artifact is.
9. **Place the file + update the manifest** (see [file-placement.md](file-placement.md)).
10. **Run the checklist.**

### HTML → block mapping cheatsheet

| HTML | Etchedy block |
|---|---|
| `<section class="x">...</section>` | `etch/element`, `tag: "section"`, `attributes: { data-etch-element: "section", class: "..." }` |
| `<div class="container">...</div>` (scaffold wrapper) | `etch/element`, `tag: "div"`, `attributes: { data-etch-element: "container", class: "..." }` |
| `<h1>Title</h1>` | `etch/element`, `tag: "h1"` → `etch/text` child `{ content: "Title" }` |
| `<p>text</p>` | `etch/element`, `tag: "p"` → `etch/text` child |
| `<a href="#">label</a>` | `etch/element`, `tag: "a"`, `attributes: { href, class }` → `etch/text` child |
| `<img src="..." alt="...">` | `etch/element`, `tag: "img"`, `attributes: { src, alt, loading, class }`, `innerBlocks: []` |
| `<hr>` | `etch/element`, `tag: "hr"`, `innerBlocks: []` |

---

## 2. From a text description

**Input**: a natural-language spec like "a pricing section with 3 tiers, middle one highlighted" or "contact form with name, email, message".

### Steps

1. **Find the closest example** in [examples.md](examples.md). For "pricing section with 3 tiers", the closest shape is the `feature-grid.json` 3-card pattern — start from it.
2. **Copy the JSON skeleton** of that example (structure, scaffold styles, tag choices).
3. **Rewrite `__libraryMeta`** for your new artifact (new brand if different, new name/category/slug, honest description, refreshed tags).
4. **Swap content**: replace text, heading, card labels, CTA copy, image srcs.
5. **Adjust structure only where needed**: if the spec calls for a 4th card, add one more card block (copy-paste an existing card, rename metadata). If a card must be highlighted, add a `--highlighted` modifier class and one extra style object for it.
6. **Preserve the BEM grammar** — classes still follow `{brand}-{component}__{element}`, styles still use tokens with fallbacks, `@media` still inline.
7. **Place the file + update the manifest.**
8. **Run the checklist.**

Never generate JSON from scratch when a close example exists — you'll drift from conventions. Always start from a library file.

---

## 3. From a Figma URL or screenshot

**Input**: a Figma link (if the figma MCP is connected) or an image.

### Steps

1. **Extract layout primitives** from the design:
   - Outer container: full-bleed section or constrained to `content-width`?
   - Content arrangement: flex column, flex row, grid (how many columns?), two-column split?
   - Gaps and padding: map px values to the closest ACSS space token.
   - Breakpoints: note any shown responsive variants (usually collapse to 1 column at ~768px).
2. **Extract typography**: heading size (map to `--text-xxl` or a fluid `clamp()`), body size (`--text-m` / `--text-l`), weight, line-height.
3. **Extract colors**:
   - Structural colors (backgrounds, text, borders) → match to core taxonomy tokens.
   - Brand accent colors (golds, teals, signature colors) → keep as raw hex inside brand-scoped files.
4. **Pick type/category/slug/brand** based on the design's role (hero, feature grid, testimonial, etc.).
5. **Find the closest existing example** for the shape and start from its JSON.
6. **Substitute values** — tokens + fallbacks matching what you measured from the design.
7. **Add responsive behavior** inline as a `@media (max-width: 768px)` block inside the affected style's css string.
8. **Place the file + update the manifest.**
9. **Run the checklist.**

Visual fidelity caveat: Etchedy styles are single-string CSS. Complex layered effects (multi-layer shadows, conic gradients, SVG masks) are fine in CSS but harder to read. Prefer the simplest CSS that matches the design.

---

## 4. Refactor existing JSON (lint mode)

**Input**: an existing file under `data/library/` that you've been asked to normalize or bring up to etchwp standards.

### Steps

1. **Read the file** fully.
2. **Audit against the SKILL.md checklist.** For each checkbox, note pass/fail.
3. **Common fixes** (in order):
   - **Missing `__libraryMeta` fields** — add `tags` (≥3), fill `description` if empty.
   - **Non-BEM class selectors** — rename `.btn` to `.{brand}-{component}__btn`, update both the style's `selector` and the block's `attributes.class` that reference it.
   - **Missing fallback on tokens** — `color: var(--font-size);` → `color: var(--font-size, 1rem);`. Pick the canonical fallback from [acss-tokens.md](acss-tokens.md).
   - **Raw CSS values without `var()` wrapper** — `padding: 24px` → `padding: var(--space-l, 1.5rem)`. Pick the closest token.
   - **Hardcoded accent colors outside a brand file** — if the file is under `Brands/{Brand}/`, accent hex is OK; otherwise wrap in `var()` with the hex as fallback: `background: #57e9db` → `background: var(--primary, #57e9db)` (or a more appropriate token if there is one).
   - **Separate responsive style objects** — collapse into inline `@media` inside the parent style's css. Delete the separate object.
   - **Separate hover style objects** — collapse into `&:hover { ... }` inline inside the parent style's css. Delete the separate object.
   - **Opaque style IDs** (`7g69qvg`) — acceptable to leave as-is; only rename to BEM-with-`-style` if doing a full rewrite.
5. **Check manifest entry** — confirm `{name, id}` exists in `data/manifest.json` under the right category. Add if missing (unless brand-scoped).
6. **Check ID matches file path** — `id: "elements-buttons-my-btn"` ↔ `data/library/Elements/Buttons/my-btn.json`.
7. **Run the checklist.**

### Refactor safety rules

- **Preserve behavior**: the fallback value after any new `var(--token, ...)` should equal the original raw value whenever possible. This way rendering is identical if the token is unresolved.
- **Preserve builder state**: do not renumber or rearrange blocks unless necessary. The builder may have references to block order.
- **Do not change `data-etch-sid` values** that already exist — they may be referenced elsewhere.
- **One concern per commit**: if you're fixing ACSS tokens, do that and stop. Don't also rewrite class names in the same pass.

---

## 5. From a React/Tailwind component (e.g. 21st.dev)

**Input**: React JSX source code (usually with Tailwind CSS classes), from a component library like 21st.dev, shadcn/ui, or similar.

### Steps

1. **Fetch the source.** Use the browser (Playwright) or WebFetch to navigate to the component page, read its source code, and capture a screenshot of the rendered preview for visual reference.
2. **Identify the component tree.** Map the JSX tree to Etchedy blocks:
   - `<div>`, `<section>`, `<span>`, `<a>`, etc. → `etch/element` with the matching `tag`.
   - Text content → `etch/text` children.
   - React props that control content (text, URLs, booleans) → `etch/component` properties with `{props.xxx}` bindings.
   - Conditional rendering (`{show && <X/>}`, ternaries) → `etch/condition` blocks.
   - `children` or render-prop slots → `etch/slot-placeholder` / `etch/slot-content`.
   - Dynamic tags (`const Tag = level; <Tag>`) → `etch/dynamic-element` with `tag: "{props.xxx}"`.
   - `<img>` / `<svg>` → `etch/element` tag="img" or `etch/svg` with `innerHTML`.
3. **Convert Tailwind to ACSS-token CSS.** Do NOT keep Tailwind classes. Translate every Tailwind utility to its CSS equivalent, then map to the closest ACSS token with fallback:
   - `p-4` → `padding: var(--space-m, 1rem);`
   - `text-lg` → `font-size: var(--text-l, 1.125rem);`
   - `text-gray-500` → `color: var(--text-muted, #6b7280);`
   - `rounded-lg` → `border-radius: var(--radius-m, 0.75rem);`
   - `bg-white` → `background: var(--white, #fff);`
   - `gap-4` → `gap: var(--space-m, 1rem);`
   - `max-w-4xl` → `max-inline-size: 56rem;`
   - Responsive prefixes (`md:grid-cols-2`) → inline `@container` or `@media` rules.
   - Hover/focus (`hover:bg-gray-100`) → inline `&:hover { ... }`.
   - Animation classes (`animate-fade-in`) → write the equivalent CSS `@keyframes` + `animation:` in the style's css string.
4. **BEM-rename all classes.** Drop Tailwind class names entirely. Create BEM classes: `{brand}-{component}__{element}[--{modifier}]`.
5. **Build the `components` map** for any reusable sub-component. Define `properties` with types and defaults. Wire `{props.xxx}` template syntax into the block tree.
6. **Responsive: mandatory.** Every artifact must adapt to small screens. Convert Tailwind responsive prefixes to container/media queries. If the source doesn't have responsive behavior, add sensible collapse rules anyway.
7. **Animations and interactions.** If the source has Framer Motion, CSS animations, or transition effects, reproduce them as CSS `transition`, `animation`, `@keyframes`, or note JS requirements in the description.
8. **Fill `__libraryMeta`**, place the file, update the manifest.
9. **Run the checklist.**

### Tailwind → ACSS token quick-reference

| Tailwind | ACSS token | Canonical fallback |
|---|---|---|
| `p-1` / `p-2` / `p-3` / `p-4` / `p-6` / `p-8` | `--space-2xs` / `--space-xs` / `--space-s` / `--space-m` / `--space-l` / `--space-xl` | `0.25rem` / `0.5rem` / `0.75rem` / `1rem` / `1.5rem` / `3rem` |
| `text-xs` / `text-sm` / `text-base` / `text-lg` / `text-xl` / `text-2xl` | `--text-xs` / `--text-s` / `--text-m` / `--text-l` / `--text-xl` / `--text-xxl` | `0.75rem` / `0.875rem` / `1rem` / `1.125rem` / `1.5rem` / `1.75rem` |
| `rounded` / `rounded-md` / `rounded-lg` / `rounded-full` | `--radius` / `--radius-m` / `--radius-l` / `--radius-full` | `0.25rem` / `0.5rem` / `0.75rem` / `999px` |
| `text-gray-900` / `text-gray-600` / `text-gray-400` | `--heading-color` / `--text-dark` / `--text-muted` | `#111` / `#374151` / `#6b7280` |
| `bg-white` / `bg-gray-50` / `bg-gray-900` | `--white` / `--surface-light` / `--surface-dark` | `#fff` / `#f8f9fa` / `#0f1117` |

---

## 6. From an image or screenshot only (no source code)

**Input**: a PNG/JPG/screenshot of a UI component. No HTML, no CSS, no source code.

### Steps

1. **Measure the visual.** Extract from the image:
   - **Layout**: flex/grid direction, number of columns, alignment, gap estimates.
   - **Typography**: heading size (map to `clamp()` or `--text-*` token), body size, weight, line-height, font family if identifiable.
   - **Spacing**: padding and gap values in px, map to closest `--space-*` token.
   - **Colors**: extract hex values for backgrounds, text, accents, borders. Map to ACSS tokens where possible; use raw hex as fallback.
   - **Border radius**: estimate px, map to `--radius-*` token.
   - **Shadows**: estimate offset, blur, spread, color.
2. **Define behavior.** If the image shows:
   - **Hover states** (e.g. a button with a lighter shade) → add `&:hover { ... }` with the alternate style.
   - **Animations** (e.g. a carousel, accordion, fade-in) → add CSS `transition` / `animation` / `@keyframes` and note in the description.
   - **Interactive elements** (tabs, toggles, dropdowns) → use `etch/condition` with props to model the states, note any JS needed in description.
3. **Find the closest existing example** in [examples.md](examples.md) and start from its JSON.
4. **Build the block tree**, using `etch/component` + properties for any repeating elements or configurable parts.
5. **Write all CSS with tokens + fallbacks.** Use the measured values as fallbacks.
6. **Add responsive rules.** Even if the image only shows desktop, add sensible collapse behavior: grids → single column below 768px, headings scale with `clamp()`, decorative elements hide on small screens.
7. **Missing assets** (photos, illustrations): use structural placeholders (`<img src="" alt="...">` with measured dimensions). Never fabricate SVG approximations.
8. **Fill `__libraryMeta`**, place the file, update the manifest.
9. **Run the checklist.**
