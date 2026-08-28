# Accordion — Bricks element checklist

## Identify
- [ ] Source has click-to-expand panels with one panel open at a time (typically)
- [ ] BEM block: `{brand}-accordion` with children `__item`, `__trigger`, `__panel`, `__icon`
- [ ] Bricks element: **`accordion-nested`** (preferred — children are individual nested elements). DO NOT use legacy `accordion` (settings.items array) for new builds.

## Structure
- [ ] `accordion-nested` root with `_cssGlobalClasses = ["{brand}-accordion"]`
- [ ] Each accordion item is a CHILD element of the accordion-nested (Bricks renders the toggle automatically)
- [ ] Item structure: `block` (item wrapper) → `block` (trigger row with heading + icon) → `block` (panel with content)
- [ ] Item heading: `heading.settings.tag = "button"` (semantic — accordion triggers ARE buttons) OR `text-basic` styled as a button

## Behavior
- [ ] `settings.singleOpen = true` for one-at-a-time
- [ ] `settings.openFirst = true` if first item should be open on render
- [ ] `settings.animation = "slide"` (default) or "fade"
- [ ] `settings.iconClosed` / `settings.iconOpen` set explicitly (Bricks icon picker)

## Accessibility
- [ ] Trigger is a `<button>` (Bricks handles aria-expanded toggle automatically)
- [ ] Panel `role="region"` with `aria-labelledby` pointing at the trigger (Bricks renders this when configured)
- [ ] Focus visible on trigger keyboard navigation
- [ ] `prefers-reduced-motion`: animation disabled (CSS `@media (prefers-reduced-motion: reduce)` on the global class)

## Tokens
- [ ] Panel max-height transitions via Bricks animation, NOT custom CSS
- [ ] Trigger background-hover: `var(--surface-light, #f5f5f5)`
- [ ] Border between items: `1px solid var(--border-color-light, rgba(0,0,0,.08))`
- [ ] Icon transition: `transform 200ms ease` (rotate the `iconClosed` to become `iconOpen`)

## Per-breakpoint
- [ ] Trigger padding shrinks on `_mobile_portrait` via per-breakpoint settings, never inline @media
- [ ] Icon size scales: `_base: 1.25rem`, `_mobile_portrait: 1rem`

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
