# Slider / carousel — Bricks element checklist

## Identify
- [ ] Source has a horizontal slider/carousel with navigation arrows + optional dots
- [ ] BEM block: `{brand}-slider` with children `__slide`, `__arrow`, `__dot`
- [ ] Bricks element: **`slider-nested`** (preferred — each slide is a child element). Legacy `slider` (items array) only for image-only sliders.

## Structure
- [ ] `slider-nested` root with `_cssGlobalClasses = ["{brand}-slider"]`
- [ ] Each slide is a CHILD element (can be ANY layout, not just images)
- [ ] Arrow controls + dot pagination rendered by Bricks via settings — DO NOT hand-roll

## Behavior
- [ ] `settings.slidesPerView = 1` (or N for multi-card carousels)
- [ ] `settings.spaceBetween = "var(--space-m, 1rem)"`
- [ ] `settings.loop = true` to wrap around
- [ ] `settings.autoplay = false` (default; only enable if source clearly autoplays)
- [ ] `settings.autoplayDelay = 5000` (ms) when autoplay enabled
- [ ] `settings.pauseOnHover = true` when autoplay enabled
- [ ] `settings.showArrows = true` / `settings.showDots = true` configured per source

## Accessibility
- [ ] Arrows are `<button>` with `aria-label="Previous slide" / "Next slide"`
- [ ] Each slide gets `aria-roledescription="slide"` + `aria-label="N of M"`
- [ ] Autoplay pauses on focus (Bricks built-in)
- [ ] Keyboard: Left/Right arrows navigate (Bricks Swiper.js binding)
- [ ] `prefers-reduced-motion`: autoplay disabled, snap-scroll instead of slide animation

## Per-breakpoint
- [ ] `slidesPerView` shrinks on smaller breakpoints: `{ _base: 3, _mobile_landscape: 2, _mobile_portrait: 1 }`
- [ ] Arrows hide on mobile (touch swipe is the primary interaction)

## Tokens
- [ ] Dot color (inactive): `var(--border-color-light, rgba(0,0,0,.2))`
- [ ] Dot color (active): `var(--primary, #2271b1)`
- [ ] Arrow background: `var(--surface-light, #fff)` with `box-shadow: var(--shadow-s, 0 1px 2px rgba(0,0,0,.05))`

## Common variants
- [ ] Testimonial slider — 1 slide per view, autoplay 8s, no dots, arrows centered vertically
- [ ] Logo carousel — 5-6 slides per view, no arrows/dots, autoplay 3s, loop, slow easing
- [ ] Hero slider — 1 slide per view, no autoplay, dots only

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
