# Generic — Bricks element checklist

Fallback checklist when the specific element type doesn't have a sharpened file yet.

## Identify
- [ ] Source matches a coherent component pattern (criteria: …)
- [ ] BEM global-class block name decided: `{brand}-{component}__…`
- [ ] Template type chosen: header / footer / content / section / archive / error / popup
- [ ] Element type recorded for the feedback loop

## Element catalog
- [ ] Every element `name` is in `references/bricks-elements.md`
- [ ] Layout uses `section` → `container` → `block`/`div` (not nested `div` chains)
- [ ] Content elements: `heading`, `text` (or `text-basic`), `button`, `image`, `icon`
- [ ] Interactive: `accordion-nested`, `tabs-nested`, `slider-nested`, `form`, `nav-nested`
- [ ] Media: `image`, `image-gallery`, `video`, `svg`

## Global classes
- [ ] Each structural element references at least one `_cssGlobalClasses` entry
- [ ] Every global class name starts with `{brand}-`
- [ ] Per-element `_cssCustom` is for one-off overrides only — re-usable styles live on a global class

## Tokens
- [ ] All `font-size` declarations resolve to `var(--text-*)` with px/rem fallback (NOT clamp)
- [ ] All colors resolve to `var(--*)` or are in the brand allowlist
- [ ] All spacing resolves to `var(--space-*)` or `var(--section-space-*)`

## Per-breakpoint
- [ ] No `@media` inside `_cssCustom`
- [ ] Per-breakpoint values use Bricks `{ _base, _mobile_landscape, _mobile_portrait, ... }` shape

## Structure
- [ ] No raw `<style>` tags inside `code`/`html` elements
- [ ] No raw `<link rel="stylesheet">` / `@import`
- [ ] No raw `<form>` — use `form` element or `shortcode` wrap
- [ ] No raw YouTube/Vimeo `<iframe>` — use `video` element
- [ ] No raw `class="..."` without paired global-class reference

## Dynamic data (content/archive only)
- [ ] Headings use `{post_title}` (or static is explicitly intentional)
- [ ] Excerpts use `{post_excerpt:N}` with sane N
- [ ] Featured image: `settings.image.useFeaturedImage = true`
- [ ] Custom data via `{acf:field_name}` / `{wp:post_meta:key}`

## Responsive + pixel-perfect
- [ ] Spacing within ±2px of source
- [ ] Typography weight + tracking matches
- [ ] Decorative elements (shadows, gradients) preserved

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
