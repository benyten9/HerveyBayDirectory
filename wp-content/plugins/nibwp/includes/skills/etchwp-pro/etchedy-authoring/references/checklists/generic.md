# generic — checklist (fallback for non-core element types)

Use this checklist when the element type is one of: **accordion, tabs, slider, image, divider, marquee, stat-block, testimonial, pricing, cta**. Each of these has a per-type-sharpening TODO at the bottom — until that is done, this generic file is authoritative.

## Identify
- [ ] Source matches the element-type pattern.
- [ ] BEM block name decided: `{brand}-{element-type}` or `{brand}-{section}__{element}`.

## Tokens
- [ ] All `font-size` values use canonical `--text-*` tokens with `px` / `rem` / `em` fallback. **Never `clamp()`**.
- [ ] All colors drawn from the canonical taxonomy or justified brand-accent hex inside `Brands/` files.
- [ ] All spacing values use canonical `--space-*` / `--section-space-*` tokens.
- [ ] All radii use `--radius` / `--radius-m` / `--radius-l` / `--radius-full`.

## Structure
- [ ] Outer wrapper uses readonly `etch-section-style` / `etch-container-style` scaffold.
- [ ] Every element has a BEM class — no element, span, div, or section without one.
- [ ] Semantic HTML where the source warrants it (`<dl>` for definition lists, `<details>` for accordions, `<blockquote>` for testimonials, `<table>` for pricing comparison, `<hr>` for dividers).

## Behavior
- [ ] If interactive: JS lib from the [js-libraries.md](../js-libraries.md) whitelist only.
- [ ] No autoplay video / audio without `muted playsinline`.
- [ ] Transitions ≤ 250ms; respect `prefers-reduced-motion`.

## Responsive
- [ ] Container-query-first using `:has(> &) { container-type: inline-size; }` on the wrapper.
- [ ] Grid / multi-column layouts collapse to single column below a sensible container-width breakpoint.
- [ ] Decorative elements hide or scale at narrow widths.

## Pixel-perfect
- [ ] Spacing matches source within ±2px.
- [ ] Typography (size, weight, tracking) matches source.
- [ ] Shadows, gradients, border treatments preserved.

## Accessibility
- [ ] Interactive elements are reachable via keyboard.
- [ ] Color contrast ≥ 4.5:1 for body text, ≥ 3:1 for large text and UI elements.
- [ ] Element has an accessible label (`<h2>`/`<h3>`, `aria-label`, or visible text).

## Type-specific TODOs

The following types still use this generic checklist. When sharpened, move the bullets into their own `references/checklists/{type}.md` file.

- [ ] **accordion**: native `<details>` + CSS, no JS library; `prefers-reduced-motion` guard.
- [ ] **tabs**: `role="tablist"`, arrow-key navigation, `aria-selected` toggle.
- [ ] **slider**: Swiper only (lazy-imported); `prefers-reduced-motion` disables autoplay.
- [ ] **image**: `loading="lazy"`, `decoding="async"`, dimensions set, art-direction via `<picture>` when source has multiple breakpoints.
- [ ] **divider**: semantic `<hr>` (not `<div>`); border-color from token.
- [ ] **marquee**: CSS `@keyframes` only; pause on hover; `prefers-reduced-motion` disables motion.
- [ ] **stat-block**: IntersectionObserver counter with `requestAnimationFrame`; no library.
- [ ] **testimonial**: `<blockquote>` + `<cite>`; quote mark via `&::before`.
- [ ] **pricing**: featured-card data-attribute variant; tabular numerals (`font-variant-numeric: tabular-nums`).
- [ ] **cta**: `--section-space-l` padding; primary + secondary button pair; eyebrow + headline + sub.

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
