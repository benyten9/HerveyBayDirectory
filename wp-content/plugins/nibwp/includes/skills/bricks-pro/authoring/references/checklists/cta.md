# CTA (call-to-action) — Bricks element checklist

## Identify
- [ ] Source has a standalone call-to-action band: short headline + sub + 1-2 buttons + optional background image
- [ ] BEM block: `{brand}-cta` with children `__title`, `__sub`, `__actions`, `__media`
- [ ] Often used between sections to drive conversion

## Structure
- [ ] `section` root (`tag="section"`) with `_cssGlobalClasses = ["{brand}-cta"]`
- [ ] `container` child constraining to `var(--content-width-narrow, 60rem)` (CTAs are often narrower than full content)
- [ ] Content stack: heading → text → button row, centered

## Heading
- [ ] `heading.settings.tag = "h2"` (CTAs are usually mid-page, not page-headline)
- [ ] Token: `var(--text-xxl, 2.5rem)` or `var(--h2, 2rem)`
- [ ] Center-aligned: `_typography.text-align = "center"` (per-breakpoint if it differs)

## Button row
- [ ] `block` with `display: flex; gap: var(--space-m, 1rem); justify-content: center; flex-wrap: wrap;`
- [ ] Primary CTA: `button` with `{brand}-button--primary`
- [ ] Secondary CTA (optional): `button` with `{brand}-button--ghost` or `text-link`
- [ ] Below `_mobile_portrait`: buttons stack via `flex-direction: column` + `width: 100%` on each

## Background
- [ ] Solid: `_background.color = "var(--primary, #2271b1)"` (high-contrast CTA)
- [ ] Gradient: `_background.gradient = "linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%)"`
- [ ] Image: `_background.image` + `_backgroundOverlay` for text legibility
- [ ] When CTA is dark-on-light, text color must override: `color: var(--white, #fff)`

## Padding (generous)
- [ ] `var(--section-space-l, 4rem) var(--content-padding, 2rem)` on desktop
- [ ] Per-breakpoint shrinks: `_mobile_portrait: 2rem 1rem`

## Accessibility
- [ ] If background is a busy image, overlay opacity ≥ 0.5 OR text shadow / contrast adjustment
- [ ] Heading must achieve 4.5:1 contrast against background; large heading (≥ 24px bold or 18.66px regular) = 3:1 minimum

## Variants
- [ ] **Newsletter CTA**: replace button row with a `form` element OR `shortcode` element wrapping a form-plugin shortcode
- [ ] **Anchor link CTA**: button `link.type = "anchor"`, `link.url = "#section-id"`
- [ ] **External CTA**: button `link.type = "external"`, opens new tab automatically

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
