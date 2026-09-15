# Anti-Patterns — DO NOT repeat these

Real violations harvested from the existing library. Each is followed by the correction. When you see any of these in output you are about to produce, stop and fix before saving.

---

## 1. Hardcoded CTA button color without a token reference

The dominant pattern violation in the library. Most CTA/hero buttons end with raw hex for the background, even in otherwise token-clean files.

### DO NOT

```css
/* a button element */
background: #e52424; color: #fff;
```

### DO

Wrap the value in `var()` so brand themes can override it. The raw hex becomes the fallback:

```css
background: var(--danger, #e52424); color: var(--white, #fff);
```

Or, if a token already exists, use it directly:

```css
background: var(--primary, #2563eb); color: var(--white, #fff);
```

### Why this matters

Every CTA button that bypasses `var()` is un-themeable. Multiply by the number of brands in the library and you get a surface where the design system cannot enforce color consistency. Always route color through a token — even if you have to invent a reasonable fallback.

---

## 2. Token reference with no fallback

### DO NOT

```css
/* a testimonial slider component */
color: var(--font-size);
font-size: var(--headshot-size);
```

These `var()` calls have no fallback. If the consuming theme does not define the token, the property is invalid and gets discarded.

### DO

```css
color: var(--text-color, #111);
font-size: var(--text-m, 1rem);
```

Always provide a sensible fallback. It's forgiving, and it's the convention across the library.

### Exception

`--bo-*` tokens are used without fallback by convention (defined in the `.bo-root` scope in `tools/build-booking-optimiser.js`). Only use them inside BookingOptimiser-scoped files.

---

## 3. Raw color value with no `var()` wrapper (outside brand files)

### DO NOT

```css
/* Inside a component that is not scoped to one brand */
color: #1a1a1a;
color: #ffd24a;
```

No token reference, no override hook. Locked-in color.

### DO

```css
color: var(--text-dark, #1a1a1a);
color: var(--accent, #ffd24a);
```

Wrap even hardcoded values in `var(--token, hex)`. If the token doesn't exist today, it can be defined later without editing the artifact.

### Exception

Inside a payload whose `__libraryMeta.brand` names one brand, that brand's accent hexes (signature gold, teal, etc.) are acceptable raw — the brand scopes them to one visual language. Add them to `nibwp_user_defaults` so the validator recognises them.

---

## 4. Non-BEM class selectors

### DO NOT

```css
.heading { font-size: 2rem; }
.btn { padding: 1rem 2rem; }
.card { background: #fff; }
.container { max-width: 1200px; }
```

Generic names collide across components and break builder scoping. Two components using `.card` will fight over CSS.

### DO

```css
.alpha-cta-banner__heading { font-size: var(--text-xxl, 2rem); }
.alpha-cta-banner__btn { padding: var(--space-m, 1rem) var(--space-xl, 2rem); }
.alpha-features__card { background: var(--white, #fff); }
.alpha-features__container { ... }
```

`{brand}-{component}__{element}` — always. See [bem-naming.md](bem-naming.md).

---

## 5. Separate style objects per breakpoint

### DO NOT

```json
"styles": {
    "alpha-features__grid-style": {
        "selector": ".alpha-features__grid",
        "css": "display: grid; grid-template-columns: repeat(3, 1fr); gap: 2rem;"
    },
    "alpha-features__grid-mobile-style": {
        "selector": "@media (max-width: 768px) .alpha-features__grid",
        "css": "grid-template-columns: 1fr;"
    }
}
```

Invalid selector, duplicated concern, breaks builder assumptions.

### DO

```json
"alpha-features__grid-style": {
    "selector": ".alpha-features__grid",
    "css": "display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--space-l, 2rem); @media (max-width: 768px) { grid-template-columns: 1fr; }"
}
```

One style object per selector. Inline `@media (max-width: …) { … }` inside the same `css` string.

---

## 6. Separate style objects per pseudo-state

### DO NOT

```json
"alpha-features__card-style": { "selector": ".alpha-features__card", "css": "background: #f8f9fa; ..." },
"alpha-features__card-hover-style": { "selector": ".alpha-features__card:hover", "css": "box-shadow: 0 8px 30px rgba(0,0,0,0.08);" }
```

### DO

```json
"alpha-features__card-style": {
    "selector": ".alpha-features__card",
    "css": "background: var(--neutral-ultra-light, #f2f2f2); transition: box-shadow 0.2s; &:hover { box-shadow: 0 8px 30px rgba(0,0,0,0.08); }"
}
```

Inline `&:hover { … }`, `&:focus { … }`, `&:active { … }`, `&:focus-visible { … }` using the nested selector syntax.

---

## 7. An `etch/component` that resolves to nothing

### DO NOT

Emit an `etch/component` block carrying only your own `componentId` with no matching entry in `payload.components`.

### DO

Either reference a component that already exists by its `wp_block` post id:

```json
{ "blockName": "etch/component", "attrs": { "ref": 207, "attributes": { "label": "Docs" } } }
```

— or define it under `payload.components` and let the persister mint the `wp_block` post and rewrite the instance to that `ref`.

Etch resolves a component **only** by `ref`, and returns an empty string when it is missing. An unresolvable instance leaves a blank space where the section should be, so the validator now refuses the payload rather than reporting success over an empty page. This is the #1 "why is my section blank?" cause.

---

## 8. Using PHP rendering or a custom template

### DO NOT

Write a PHP file that outputs HTML for a library artifact. Do not create `.php`, `.twig`, `.blade.php`, or inline `<?php ?>` inside JSON strings.

### DO

Emit blocks only. Etch registers its own blocks and renders them through their PHP render callbacks; your payload describes the tree and the persister writes it into the page.

---

## 9. Inline `<style>` tags or separate `.css` files

### DO NOT

```json
{ "blockName": "etch/element", "attrs": { "tag": "style", ... } }
```

Or a companion `.css` file. There is nowhere to put one.

### DO

All CSS lives as strings in the artifact's top-level `styles` object. No inline `<style>` tags in the block tree. No separate CSS files next to the JSON.

---

## 10. Inventing new `--token-name` values that resolve nowhere

### DO NOT

```css
padding: var(--spacing-m, 1rem);        /* typo — actual token is --space-m */
color: var(--brand-color, #57e9db);     /* does not exist in the taxonomy */
```

### DO

Run the scan command from [acss-tokens.md](acss-tokens.md) and confirm the token actually appears in the library before using it:

```bash
wp option get etch_styles --format=json | grep -ohE 'var\(--[a-z0-9-]+' | sort -u
```

If the token is not in the output, either:
1. Use the closest existing token (`--space-m` instead of `--spacing-m`).
2. Wrap the raw value in a `var()` of your own naming, with the raw value as fallback, only if the raw value by itself would be acceptable (e.g. a brand accent hex). The validator warns that the site does not define that name; for this case that is expected.
3. Do not silently introduce tokens that won't resolve.

---

## 11. Mismatched name and slug

### DO NOT

- `__libraryMeta.name: "My Button"` with `__libraryMeta.slug: "myButton"` (camelCase — wrong)
- `__libraryMeta.category` left empty or inconsistent between related payloads

### DO

- `__libraryMeta.slug` is `sanitize_title(name)`: `"My Button"` → `"my-button"`
- `__libraryMeta.category` names a real grouping and stays consistent across the set
- All six required fields present: `brand`, `type`, `category`, `tags`, `name`, `description`

The id, the folder path, and the metadata must be self-consistent. Inconsistency breaks the REST `/component/{id}` lookup.

---

## 12. Editing the readonly scaffold styles

### DO NOT

```json
"etch-section-style": {
    "readonly": false,
    "css": "inline-size: 80%; background: red;"
}
```

The two scaffold styles (`etch-section-style` + `etch-container-style`) are readonly system-level defaults. If you change them, every artifact in the builder misbehaves.

### DO

Copy them verbatim from any example and leave them alone. Apply section-specific styling in your own BEM-named style object (e.g. `.alpha-cta-banner-style`), which layers on top of the scaffold.

If you genuinely need the section to be narrower or differently laid out, add an additional BEM-named style. Do not mutate the scaffold.

---

## 13. `clamp()` on `font-size`

The validator hard-rejects any `font-size` value containing `clamp(` — including `clamp()` inside the fallback slot of `var(--token, fallback)`. Fluid display type via `clamp()` produces awkward intermediate steps, defeats user font-size preferences, and ignores the ACSS token system.

### DO NOT

```css
.alpha-hero__title { font-size: clamp(1.5rem, 4vw, 2.5rem); }
.alpha-hero__title { font-size: var(--text-xxl, clamp(1.5rem, 4vw, 2.5rem)); }
```

### DO

Switch to a smaller `--text-*` token at the breakpoint inside the same selector:

```css
.alpha-hero__title {
  font-size: var(--text-xxl, 1.75rem);
  @container (inline-size < to-rem(600px)) {
    font-size: var(--text-xl, 1.5rem);
  }
}
```

Layout values (`gap`, `padding`, `margin`, `max-inline-size`) MAY still use `clamp()` inside `var()` — the rule only applies to `font-size`.

---

## 14. Inventing Tailwind-ramp / display-tier tokens

Tailwind / Material muscle memory often leaks into ACSS output as `--base-50`, `--base-100`, `--base-300`, `--text-display-l`, etc. None of these resolve in the canonical taxonomy. The validator rejects them.

### DO NOT

```css
background: var(--base-50, #f8f9fa);
border: 1px solid var(--base-300, #cbd5e1);
font-size: var(--text-display-l, 4.5rem);
```

### DO

```css
background: var(--neutral-ultra-light, #f2f2f2);
border: 1px solid var(--border-color-dark, rgba(0, 0, 0, 0.2));
font-size: var(--text-xxl, 1.75rem);
```

Pick by role and surface, not by name. `--base-*` is a real ramp, but it follows the site's brand colour: `--base-ultra-light` is a pale brand tint, not the grey the fallback suggests. For greys use `--neutral-*`, and use `--border-color-dark` for borders on light surfaces.

See `references/acss-tokens.md` for the canonical taxonomy and the regex blocklist (`^--text-\d+$`, `^--space-\d+$`, `^--base-\d{2,3}$`).

---

## 15. Missing style hoist for `wp:html`

Etch enqueues the CSS for a style ID only when a `wp:etch/element` block references that ID in its `attrs.styles`. A `wp:html` raw-HTML sub-block (the only way to embed shortcodes or third-party widgets) is invisible to that scan — its classes render unstyled because the CSS is never enqueued.

### DO NOT

```html
<!-- wp:etch/element {"tag":"section","attributes":{"class":"alpha-cta"},"styles":["alpha-cta-style"]} -->
  <!-- wp:html -->
  <form class="alpha-cta__form">
    <input class="alpha-cta__input" />
    <button class="alpha-cta__submit">Send</button>
  </form>
  <!-- /wp:html -->
<!-- /wp:etch/element -->
```

The classes `alpha-cta__form`, `alpha-cta__input`, `alpha-cta__submit` resolve to nothing on the rendered page — their style IDs were never enqueued.

### DO

Emit a hidden style-hoist `wp:etch/element` that lists every BEM class used inside the raw HTML in its `attrs.styles`:

```html
<!-- wp:etch/element {"tag":"section","attributes":{"class":"alpha-cta"},"styles":["alpha-cta-style"]} -->
  <!-- wp:etch/element {"tag":"span","attributes":{"hidden":true,"class":"alpha-cta__style-hoist"},"styles":["alpha-cta__form-style","alpha-cta__input-style","alpha-cta__submit-style"]} /-->
  <!-- wp:html -->
  <form class="alpha-cta__form">
    <input class="alpha-cta__input" />
    <button class="alpha-cta__submit">Send</button>
  </form>
  <!-- /wp:html -->
<!-- /wp:etch/element -->
```

The hidden block is a no-op visually but forces Etch to enqueue the listed style IDs. The validator scans `wp:html` blocks for classes and fails the conversion if any class lacks a matching style hoist.

---

## 16. Raw `<form>` HTML when a form plugin is installed

The site likely has Gravity Forms, WPForms, Fluent Forms, Contact Form 7, Ninja Forms, Formidable, Forminator, Happy Forms, or JetFormBuilder installed. Each handles submission, validation, spam protection, conditional logic, and accessibility correctly. A raw `<form>` does NOT — it's a dead UI.

### DO NOT

```html
<!-- wp:html -->
<form action="/submit" method="post">
  <input name="email" required />
  <button type="submit">Subscribe</button>
</form>
<!-- /wp:html -->
```

### DO

Detect via `nibwp/forms-manage` `action: "list_plugins"`, ask the user which plugin to use, emit a `core/shortcode` block wrapping the chosen plugin's shortcode. Style-hoist any wrapper classes:

```html
<!-- wp:etch/element {"tag":"section","attributes":{"class":"alpha-cta"},"styles":["alpha-cta-style"]} -->
  <!-- wp:etch/element {"tag":"div","attributes":{"class":"alpha-cta__form-wrap"},"styles":["alpha-cta__form-wrap-style"]} -->
    <!-- wp:shortcode -->
    [gravityform id="3" title="false" description="false" ajax="true"]
    <!-- /wp:shortcode -->
  <!-- /wp:etch/element -->
<!-- /wp:etch/element -->
```

The validator fails the conversion if the source HTML contained `<form>` and no `core/shortcode` block appears in the output. Etch registers no shortcode block of its own: `etch/shortcode` does not exist and renders as nothing.

**Never put the form shortcode inside `etch/raw-html`.** That block runs its final output through `wp_kses` with the `post` allowlist, which has no `<style>` in it — and it does so *after* expanding shortcodes. A form plugin that prints its own `<style>` block therefore loses its stylesheet, and the CSS text is left behind on the page as visible body copy. `core/shortcode` renders outside that sanitizer, so the form arrives intact. The same allowlist is why `<style>` never survives in `etch/raw-html`: put CSS in `payload.styles`, which the persister writes to Etch's own stylesheet.

One related trap when CSS does reach the page as content: `wptexturize` rewrites a leading `--` into an en dash, so a custom property written in body text comes out as `&#8211;brand-500`. Another reason custom properties belong in `payload.styles` and never in markup.
