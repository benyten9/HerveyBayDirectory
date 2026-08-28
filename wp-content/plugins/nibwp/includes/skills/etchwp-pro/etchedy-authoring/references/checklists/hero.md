# hero — checklist

## Identify
- [ ] Source is the first above-the-fold section (often `<section>` or top `<div>` after the navbar).
- [ ] Determined hero variant: text-only / text + media / split / fullscreen / centered.
- [ ] BEM block name: `{brand}-hero` (or `{brand}-hero-{variant}` when multiple hero variants ship together).

## Tokens
- [ ] H1 `font-size`: `--text-xxl` (1.75rem fallback) or `--h2` (2.25rem fallback). **NEVER `clamp()`** — switch to `--text-xl` at the breakpoint.
- [ ] H1 `line-height`: `--heading-line-height` (1.15) or `--leading-snug` (1.3) for tighter headings.
- [ ] Subtitle `font-size`: `--text-m` (1rem) or `--text-l` (1.125rem).
- [ ] Subtitle `color`: `--text-muted` or `--base-medium` for de-emphasis.
- [ ] Background: `--surface-light` / `--white` / `--surface-dark`; raw decorative hex inside brand-scoped files only.
- [ ] Section padding-block: `--section-space-l` (6rem). On narrow viewports the validator allows a smaller `--section-space-m` (5rem) at the breakpoint.
- [ ] Content width: `--content-width` (1366px) — never hardcoded.

## Structure
- [ ] Outer wrapper uses the readonly `etch-section-style` scaffold (do NOT redefine it).
- [ ] Container uses `etch-container-style` + a custom BEM container style for hero-specific padding-block.
- [ ] Single `<h1>` per page (Etch does not auto-demote — your hero owns the page's H1).
- [ ] Eyebrow / pre-headline (if present): `<p>` with a small decorative dash via `&::before`.
- [ ] CTA pair: 1 primary + 1 secondary (link or ghost button). Wrapped in a flex container with `gap` + `flex-wrap`.
- [ ] Background image (if present): `<img>` with `loading="eager"` (it's above the fold), `fetchpriority="high"`, explicit `width` + `height`, `object-fit: cover`.

## Behavior
- [ ] No autoplay video unless source has it AND `playsinline muted loop` are set.
- [ ] CTA hover transitions ≤ 250ms.
- [ ] Scroll-triggered animations: `IntersectionObserver` + CSS transitions only (no AOS, no ScrollReveal).

## Responsive
- [ ] Container-query on the hero's main wrapper using `:has(> &) { container-type: inline-size; }`.
- [ ] At narrow widths: H1 token drops one step (`--text-xxl` → `--text-xl`), CTA pair stacks vertically, decorative background image scales `object-position` toward `center`.
- [ ] Background image hidden or simplified below 600px container width if it's purely decorative.

## Pixel-perfect
- [ ] Vertical rhythm: gap between eyebrow → H1 → subtitle → CTAs matches source within ±2px.
- [ ] H1 letter-spacing matches source (often `-0.02em` to `-0.04em` for tight display headings).
- [ ] Font-weight matches source (typically 700–900 for hero headings).

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
