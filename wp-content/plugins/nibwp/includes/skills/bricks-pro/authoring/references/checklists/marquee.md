# Marquee — Bricks element checklist

Bricks 1.10+ does NOT have a dedicated `marquee` element. Build it from primitives.

## Identify
- [ ] Source has horizontally-scrolling content (logos, testimonials, news ticker, etc.)
- [ ] BEM block: `{brand}-marquee` (root), `{brand}-marquee__track` (the moving inner)

## Structure
- [ ] Root: `block` with `_cssGlobalClasses = ["{brand}-marquee"]`, `overflow: hidden;`
- [ ] Track: `block` child with `_cssGlobalClasses = ["{brand}-marquee__track"]`, `display: flex; gap: var(--space-l, 2rem); animation: {brand}-marquee-scroll 20s linear infinite;`
- [ ] Items: child elements of the track. DUPLICATE them in payload (once for visual continuity) — Bricks doesn't auto-double-render

## CSS animation (define on the global class)

```css
@keyframes {brand}-marquee-scroll {
  from { transform: translateX(0); }
  to   { transform: translateX(-50%); }
}
```

The `-50%` shift assumes the items are duplicated (full set then partial), so the loop appears continuous.

## States
- [ ] Pause on hover: `&:hover { animation-play-state: paused; }`
- [ ] `prefers-reduced-motion`: `@media (prefers-reduced-motion: reduce) { animation: none; }`

## Speed control
- [ ] Faster: `animation-duration: 15s`. Slower: `30s+`. Default 20s for ~5 logos at desktop.
- [ ] Per-breakpoint speed: faster on mobile so fewer items go off-screen between glances (`{ _base: 20s, _mobile_portrait: 12s }`)

## Alternative — Bricks slider with autoplay

For a more dynamic effect with explicit nav controls, use `slider-nested` with `autoplay = true`, `autoplayDelay = 0` (or very low), `loop = true`, `speed = 5000` (transition duration high → looks continuous). Tradeoff: heavier than pure-CSS marquee.

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
