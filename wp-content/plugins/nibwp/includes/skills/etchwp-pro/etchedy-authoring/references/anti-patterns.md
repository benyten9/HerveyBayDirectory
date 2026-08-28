# Anti-Patterns — DO NOT repeat these

Real violations harvested from the existing library. Each is followed by the correction. When you see any of these in output you are about to produce, stop and fix before saving.

---

## 1. Hardcoded CTA button color without a token reference

The dominant pattern violation in the library. Most CTA/hero buttons end with raw hex for the background, even in otherwise token-clean files.

### DO NOT

```css
/* data/library/Elements/Buttons/delete-split.json */
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
/* data/library/Components/Testimonials/testimonial-split-slider.json */
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
/* Inside a non-brand-scoped file, e.g. data/library/Components/Testimonials/*.json */
color: #1a1a1a;
color: #ffd24a;
```

No token reference, no override hook. Locked-in color.

### DO

```css
color: var(--heading-color, #1a1a1a);
color: var(--star-rating, #ffd24a);
```

Wrap even hardcoded values in `var(--token, hex)`. If the token doesn't exist today, it can be defined later without editing the artifact.

### Exception

Inside `data/library/Brands/{Brand}/` files, brand accent hexes (signature gold, teal, etc.) are acceptable raw. The brand folder scopes them to one brand's visual language. See `hero-fullscreen.json` (Luxe) for the canonical example.

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
    "css": "background: var(--surface-light, #f8f9fa); transition: box-shadow 0.2s; &:hover { box-shadow: 0 8px 30px rgba(0,0,0,0.08); }"
}
```

Inline `&:hover { … }`, `&:focus { … }`, `&:active { … }`, `&:focus-visible { … }` using the nested selector syntax.

---

## 7. Forgetting the manifest entry

### DO NOT

Create `data/library/Elements/Buttons/book-now.json` and stop there.

### DO

Also add to `data/manifest.json` under `tree[*] where slug="elements"` → `children[*] where slug="buttons"` → `children`:

```json
{ "name": "Book Now", "id": "elements-buttons-book-now" }
```

Without the manifest entry, the file exists but the builder cannot see it. This is the #1 "why doesn't my component show up?" cause.

**Exception**: files under `data/library/Brands/{Brand}/` are discovered by folder scan and are NOT added to the manifest. Do not add brand-scoped entries to the manifest.

---

## 8. Using PHP rendering or a custom template

### DO NOT

Write a PHP file that outputs HTML for a library artifact. Do not create `.php`, `.twig`, `.blade.php`, or inline `<?php ?>` inside JSON strings.

### DO

Emit JSON only. The parent `etch/` plugin's `etchit()` engine consumes the JSON and renders it. Etchedy is a **library** plugin — it does not render. Every artifact lives as a single `.json` file under `data/library/` and the builder + `etchit()` handle rendering.

---

## 9. Inline `<style>` tags or separate `.css` files

### DO NOT

```json
{ "blockName": "etch/element", "attrs": { "tag": "style", ... } }
```

Or a companion `data/library/Elements/Buttons/my-btn.css` file.

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
grep -rohE 'var\(--[a-z0-9-]+' data/library/ | sort -u
```

If the token is not in the output, either:
1. Use the closest existing token (`--space-m` instead of `--spacing-m`).
2. Wrap the raw value in `var(--new-name, raw)` only if the raw value by itself would be acceptable (e.g. brand accent hex).
3. Do not silently introduce tokens that won't resolve.

---

## 11. Mismatched id, slug, and folder

### DO NOT

- File: `data/library/Elements/Buttons/myButton.json` (camelCase slug — wrong)
- `__libraryMeta.category: "Btns"` (mismatched with folder `Buttons`)
- Manifest id: `elements-Buttons-my-btn` (capital B)

### DO

- File: `data/library/Elements/Buttons/my-btn.json`
- `__libraryMeta.category: "Buttons"` (exact folder name, PascalCase)
- Manifest id: `elements-buttons-my-btn` (all lowercase, matches folder + slug exactly)

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
background: var(--base-ultra-light, #f5f5f5);
border: 1px solid var(--base-light, #c8c8c8);
font-size: var(--text-xxl, 1.75rem);
```

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

Detect via `nibwp/forms-manage` `action: "list_plugins"`, ask the user which plugin to use, emit an `etch/shortcode` (or `wp:shortcode` fallback) block wrapping the chosen plugin's shortcode. Style-hoist any wrapper classes:

```html
<!-- wp:etch/element {"tag":"section","attributes":{"class":"alpha-cta"},"styles":["alpha-cta-style"]} -->
  <!-- wp:etch/element {"tag":"div","attributes":{"class":"alpha-cta__form-wrap"},"styles":["alpha-cta__form-wrap-style"]} -->
    <!-- wp:shortcode -->
    [gravityform id="3" title="false" description="false" ajax="true"]
    <!-- /wp:shortcode -->
  <!-- /wp:etch/element -->
<!-- /wp:etch/element -->
```

The validator fails the conversion if the source HTML contained `<form>` and no `etch/shortcode` / `wp:shortcode` block appears in the output.
