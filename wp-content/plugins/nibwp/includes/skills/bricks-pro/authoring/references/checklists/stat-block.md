# Stat-block — Bricks element checklist

## Identify
- [ ] Source has a big number (e.g. "98%", "10K+", "24/7") with a short label below or beside it
- [ ] Often grouped 3-4 stats side-by-side
- [ ] BEM block: `{brand}-stat` with children `__number`, `__label`, `__icon` (optional)

## Element choice
- [ ] Static text → Bricks `heading` (number) + `text` (label)
- [ ] Animated count-up → Bricks `counter` element (`settings.targetNumber`, `settings.duration`, `settings.numberFormat`)
- [ ] Percentage with visual bar → Bricks `progress-bar` element (`settings.percentage`)
- [ ] Pie/donut percentage → Bricks `pie-chart` element

## Static stat structure
- [ ] `block` root (one per stat) with `_cssGlobalClasses = ["{brand}-stat"]`
- [ ] `heading` with `tag="span"` (not h1-h6 unless this is the page's headline statistic)
- [ ] Number font-size: `var(--text-xxl, 3rem)` desktop, drops to `var(--text-xl, 2.25rem)` on `_mobile_portrait`
- [ ] Label font-size: `var(--text-s, 0.875rem)`, color `var(--text-muted, rgba(0,0,0,.65))`

## Counter element (when source implies animation)
- [ ] `settings.targetNumber = 98`
- [ ] `settings.numberPrefix = ""` / `settings.numberSuffix = "%"` for "98%"
- [ ] `settings.duration = 2000` (ms — default)
- [ ] `settings.easing = "easeOut"`
- [ ] `settings.trigger = "viewport"` — counts up when scrolled into view

## Multi-stat grid
- [ ] Wrap N stats in a `block` with `display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr)); gap: var(--space-l, 2rem);`
- [ ] Single-column on `_mobile_portrait` via grid auto-fit (no @media needed)

## Tokens
- [ ] Number color: `var(--primary, var(--heading-color, #1a1a1a))`
- [ ] Background of stat card (optional): `var(--surface-light, #f5f5f5)`
- [ ] Padding: `var(--space-l, 2rem) var(--space-m, 1rem)`

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
