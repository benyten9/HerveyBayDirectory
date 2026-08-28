# EtchWP Engine Reference

Sourced from the official EtchWP docs (docs.etchwp.com) and Kevin Geary's patterns (patterns.etchwp.com). This reference covers the rendering engine, component system, responsive approach, and block types that Etchedy artifacts target.

---

## Responsive Development — Container-Query-First

EtchWP promotes a **container-query-first** workflow, not media-query-first.

### Why container queries over media queries

- Media queries respond to the **viewport** — "How big is the screen?"
- Container queries respond to **available space** — "How much space does this element have?"
- Components become truly reusable: a card that works in a full-width section also works in a sidebar or a grid column, adapting to its actual context.
- There are 2,300+ unique device sizes. Arbitrary breakpoints (`768px`, `1024px`) are meaningless.
- Container queries solve both context-based AND device-based responsiveness simultaneously.

### Container query pattern

```css
.component {
  /* Base styles (mobile-first) */
  display: flex;
  flex-direction: column;
  gap: var(--content-gap, 1rem);

  /* Make parent a container automatically */
  :has(> &) {
    container-type: inline-size;
  }

  /* Adapt when container has enough space */
  @container (width >= 500px) {
    grid-template-columns: 200px 1fr;
  }

  @container (width >= 800px) {
    grid-template-columns: repeat(3, 1fr);
  }
}
```

### The `:has(> &)` pattern — "Has Me"

This is EtchWP's signature technique. Instead of manually adding `container-type: inline-size` to a parent class, the component declares it from *itself*:

```css
:has(> &) {
  container-type: inline-size;
}
```

This targets whatever element is the direct parent and makes it a container. The component carries its container context everywhere — fully portable, no coupling to a specific parent.

### When to use media queries instead

Use `@media` only when you specifically need **viewport awareness** — things like:
- Full-viewport layouts (`100vh` hero sections)
- Print styles
- Orientation-specific behavior
- Global layout concerns that depend on the browser window

For component-level responsiveness, always prefer `@container`.

### `to-rem()` function

EtchWP provides `to-rem()` for converting pixel values to rem units:

```css
@container (inline-size > to-rem(800px)) {
  /* This is equivalent to @container (inline-size > 50rem) */
  grid-template-columns: var(--grid-2);
}
```

Used in container/media queries for readability. Base: 16px = 1rem.

### Mobile-first recommended

Build base styles for single-column stacked layouts, then layer on complexity via container queries as space increases. This produces less code and fewer overrides.

---

## Component System

### What are components

Reusable, self-contained blocks with **props** (customizable properties) and **slots** (content drop-zones). A component is defined once and used many times, each instance rendering different content via props.

### Props — types available

| Type | JSON `type` | Description |
|---|---|---|
| Text | `{ primitive: "string" }` | Free text input — titles, descriptions, labels, URLs |
| Boolean | `{ primitive: "boolean" }` | True/false toggle — show/hide, enable/disable |
| Select | `{ primitive: "string", specialized: "select" }` | Dropdown from predefined options. Options in `selectOptionsString` (newline-separated) |
| Media | `{ primitive: "string", specialized: "image" }` | Image URL picker (WP media library) |
| Loop | `{ primitive: "string", specialized: "loop" }` | Loop source selection |
| Object | `{ primitive: "object" }` | Complex data structure (JSON) |
| Class | `{ primitive: "string", specialized: "class" }` | CSS class injection from outside the component |
| Group | (organizational) | Groups multiple props together for UI organization |

### Props in JSON

Each property in the `properties` array has:

```json
{
  "name": "Section Heading",      // Display label in the editor
  "key": "sectionHeading",        // camelCase key used in {props.xxx}
  "keyTouched": false,
  "type": { "primitive": "string" },
  "default": "Write a compelling headline."
}
```

For select props, add `selectOptionsString`:

```json
{
  "name": "Style",
  "key": "style",
  "type": { "primitive": "string", "specialized": "select" },
  "default": "Left",
  "selectOptionsString": "Left\nCenter\nTwo Column"
}
```

### Mapping props — template syntax

Inside a component's `blocks`, bind props with `{props.xxx}`:

| Location | Syntax | Example |
|---|---|---|
| Tag | `"tag": "{props.headingLevel}"` | Renders as `<h1>` or `<h2>` |
| Attribute | `"href": "{props.ctaUrl}"` | Dynamic link |
| Class | `"class": "intro {props.class}"` | Inject external classes |
| Style attribute | `"style": "--gap: {props.gap};"` | Pass props as CSS custom properties |
| Text content | `"content": "{props.label}"` | Dynamic text in `etch/text` or `etch/raw-html` |
| Data attribute | `"data-style": "{props.style}"` | Drive CSS via attribute selectors |
| Condition | `"leftHand": "props.showLede"` | Boolean check in `etch/condition` |

### Slots — composable content drop-zones

Slots let users inject arbitrary content into a component instance without editing the component itself.

**Defining a slot** (inside the component's `blocks`):
```json
{ "blockName": "etch/slot-placeholder", "attrs": { "name": "items" }, ... }
```

**Filling a slot** (when using the component):
```json
{
  "blockName": "etch/slot-content",
  "attrs": { "name": "items" },
  "innerBlocks": [ /* user's custom content */ ]
}
```

**Empty-slot fallback** — use `etch/condition` with the `slots` object:
```json
{
  "blockName": "etch/condition",
  "attrs": {
    "condition": { "leftHand": "slots.items.empty", "operator": "isTruthy", "rightHand": null },
    "conditionString": "slots.items.empty"
  },
  "innerBlocks": [ /* default/placeholder content */ ]
}
```

**Conditional wrapper** — hide a wrapper when its slot is empty:
```json
{
  "blockName": "etch/condition",
  "attrs": {
    "condition": { "leftHand": "slots.footer.empty", "operator": "isTruthy", "rightHand": null },
    "conditionString": "!slots.footer.empty"  // note: negated
  },
  "innerBlocks": [ /* wrapper + slot */ ]
}
```

### Component variations via props

Use data attributes + CSS attribute selectors to drive visual variants from a single prop:

```json
// In component blocks:
"attributes": { "data-intro-style": "{props.style}", "class": "section-intro" }
```

```css
/* In styles: */
.section-intro {
  &[data-intro-style='center' i] {
    @container (inline-size >= to-rem(700px)) {
      align-items: center;
      text-align: center;
    }
  }
  &[data-intro-style='two column' i] {
    @container (inline-size >= to-rem(700px)) {
      display: grid;
      grid-template-columns: var(--grid-3-2);
    }
  }
}
```

The `i` flag makes the attribute selector case-insensitive.

### Conditional logic

Use `etch/condition` to show/hide blocks based on prop values:

```json
{
  "blockName": "etch/condition",
  "attrs": {
    "condition": {
      "leftHand": "props.showPrimaryCta",
      "operator": "isTruthy",
      "rightHand": null
    },
    "conditionString": "props.showPrimaryCta"
  },
  "innerBlocks": [ /* shown only when prop is truthy */ ]
}
```

**Compound conditions** (AND/OR):
```json
{
  "condition": {
    "leftHand": { "leftHand": "props.showPrimaryCta", "operator": "isTruthy", "rightHand": null },
    "operator": "||",
    "rightHand": { "leftHand": "props.showSecondaryCta", "operator": "isTruthy", "rightHand": null }
  },
  "conditionString": "props.showPrimaryCta || props.showSecondaryCta"
}
```

Available operators: `isTruthy`, `isFalsy`, `==`, `!=`, `&&`, `||`

---

## Block Types — Complete Reference

### Core elements

| Block | Purpose | Key attrs |
|---|---|---|
| `etch/element` | Static HTML element | `tag`, `attributes`, `styles`, `metadata.name` |
| `etch/dynamic-element` | Element with dynamic tag | `tag: "{props.xxx}"` — rendered tag from prop |
| `etch/text` | Plain text leaf node | `content` — static text string |
| `etch/raw-html` | Dynamic text / HTML | `content: "{props.xxx}"` — supports template syntax |
| `etch/svg` | Inline OR dynamic SVG | **Inline:** `innerHTML` holds the SVG markup, `attributes` carry viewBox/dimensions. **Dynamic (1.4.15+):** set `attributes.src` to a WP attachment ID to load an uploaded SVG. |
| `etch/dynamic-image` | WP media library image | `attributes.mediaId` references attachment ID (falls back to `src`) |

### Component system

| Block | Purpose | Key attrs |
|---|---|---|
| `etch/component` | Uses a component by ref | `ref` (component ID), `attributes` (prop values) |
| `etch/slot-placeholder` | Defines a slot in component | `name` — slot identifier |
| `etch/slot-content` | Fills a slot when using component | `name` — matches placeholder name |
| `etch/condition` | Conditional rendering | `condition` object, `conditionString` |

### Scaffold data-etch-element values

| Value | Purpose | Readonly style |
|---|---|---|
| `section` | Root section wrapper | `etch-section-style` |
| `container` | Content width container | `etch-container-style` |
| `flex-div` | Generic flex-column wrapper | `etch-flex-div-style` |

### Gutenberg compatibility

| Block | Purpose |
|---|---|
| `core/group` | Standard WP group block (some Kevin patterns use this as root instead of `etch/element`) |

---

## CSS Patterns from Kevin Geary's Library

### Style philosophy

1. **Write minimal CSS.** Let ACSS handle typography, spacing, colors via tokens. Only write CSS for layout logic, animations, and component-specific behavior.
2. **Use CSS custom properties for prop passthrough.** Pass component props as `style="--icon: url({props.icon});"` and consume them in CSS: `mask-image: var(--icon);`
3. **Container queries for responsiveness.** Every grid/multi-column layout should have `:has(> &) { container-type: inline-size; }` and `@container` rules.
4. **Data-attribute selectors for variants.** Drive visual states via `[data-style='center' i]` instead of modifier classes — more flexible.
5. **Empty selectors are valid.** If ACSS handles a selector fully, write `"css": ""` — the class still provides the BEM hook.

### Common CSS tokens from Kevin's patterns (used without fallbacks — but we add fallbacks)

| Kevin's usage | Our equivalent (with fallback) |
|---|---|
| `var(--bg-ultra-light)` | `var(--bg-ultra-light, #f5f5f5)` |
| `var(--content-gap)` | `var(--content-gap, 1rem)` |
| `var(--content-width)` | `var(--content-width, 1366px)` |
| `var(--grid-2)` | `var(--grid-2, 1fr 1fr)` |
| `var(--grid-3-2)` | `var(--grid-3-2, 3fr 2fr)` |
| `var(--h1)` | `var(--h1, clamp(2rem, 5vw, 3.5rem))` |
| `var(--h2)` | `var(--h2, clamp(1.5rem, 4vw, 2.5rem))` |
| `var(--heading-font-weight)` | `var(--heading-font-weight, 700)` |
| `var(--text-s)` | `var(--text-s, 0.875rem)` |
| `var(--text-light)` | `var(--text-light, #eee)` |
| `var(--black)` | `var(--black, #000)` |
| `var(--white)` | `var(--white, #fff)` |
| `var(--primary)` | `var(--primary, #2563eb)` |

### CSS `@keyframes` — MUST be separate style entries

EtchWP does NOT support `@keyframes` nested inside a selector's `css` string. The builder strips them or ignores them. Keyframes MUST be defined as their own top-level style entry with `type: "custom"`:

```json
"bo-marquee-keyframes": {
  "type": "custom",
  "selector": "@keyframes bo-marquee",
  "collection": "default",
  "css": "from { transform: translateX(0); } to { transform: translateX(-50%); }",
  "readonly": false
}
```

Then reference the animation name from the element's style:
```json
"bo-marquee-track-style": {
  "type": "class",
  "selector": ".bo-marquee-track",
  "collection": "default",
  "css": "animation: bo-marquee 40s linear infinite;",
  "readonly": false
}
```

**NEVER** put `@keyframes` inside a `css` string alongside other declarations. Always split them into their own style object.

Same applies to `@keyframes` for pulse animations, fade-ins, vertical marquees, etc. Each gets its own entry.

### Kevin's container query pattern (with our fallbacks)

```css
.component__inner {
  display: grid;
  gap: calc(var(--content-gap, 1rem) * 2);
  :has(> &) {
    container-type: inline-size;
  }
  @container (inline-size > to-rem(800px)) {
    grid-template-columns: var(--grid-2, 1fr 1fr);
  }
}
```

---

## Loops, conditions, dynamic data & modifiers

Full syntax (sources, loop forms, conditions, the complete **modifier catalog**, WooCommerce fields) lives in [dynamic-data.md](dynamic-data.md). Read it before emitting any binding. Essentials for the block tree:

```json
{
  "blockName": "etch/loop",
  "attrs": {
    "metadata": { "name": "Posts Loop" },
    "query": { "post_type": "post", "posts_per_page": 3, "orderby": "date", "order": "DESC", "post_status": "publish" }
  },
  "innerBlocks": [ /* per-iteration template; bind the LOOP VARIABLE */ ]
}
```

- Inside the loop, bind the **loop variable** — `{item.title}`, `{item.permalink}`, `{item.featured_image}`, `{item.acf.field}` — **not** `{post.x}`.
- The `query` takes standard WP_Query args. A WooCommerce product loop also exposes `{item.gallery_images}` (featured image first, 1.4.20+).
- **Nested loops** pass parent data via arguments: `{#loop terms($post: item.id) as term}`.
- **Modifiers** chain on any binding: `{item.tags.pluck('name').join(', ')}`, `{this.acf.price.multiply(1.2).numberFormat(2)}`. Use only the catalog in [dynamic-data.md](dynamic-data.md) — never invent one.
- Wrap **editor-only** scaffolding in `{#if environment.current === "etch"}` so it never reaches the frontend.

---

## Summary of Etchedy authoring rules derived from the docs

1. **Container-query-first.** Use `:has(> &) { container-type: inline-size; }` + `@container` rules. Only fall back to `@media` for viewport-specific needs.
2. **Mobile-first base styles.** Write simple stacked layouts as the default, add complexity via container queries.
3. **Props for variability.** Use typed properties (text, boolean, select, media) with sensible defaults.
4. **Slots for composability.** Use `etch/slot-placeholder` for open-ended content areas.
5. **Conditions for toggling.** Use `etch/condition` with `isTruthy` / boolean operators — not hardcoded visibility.
6. **Dynamic tags.** Use `etch/dynamic-element` when the HTML tag should vary (e.g. heading level).
7. **Data-attribute variants.** Drive visual variants via `[data-style='x' i]` selectors rather than (or in addition to) BEM modifiers.
8. **`to-rem()` in queries.** Use `to-rem(800px)` instead of raw `800px` in container/media query conditions for accessibility.
9. **ACSS tokens with fallbacks.** Always `var(--token, fallback)` — never bare tokens. The fallback preserves rendering when the token is undefined.
10. **Empty CSS is fine.** If ACSS handles a selector fully, `"css": ""` is valid — the class still exists for the BEM hook.
