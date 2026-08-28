# Button — Bricks element checklist

## Identify
- [ ] Source has a single CTA (label + optional icon + optional sub-label)
- [ ] BEM block decided: `{brand}-button__…` with variant modifiers (`--primary`, `--secondary`, `--ghost`)
- [ ] Bricks element: `button` (NOT `text-link`, unless it should look like text)

## Settings
- [ ] `settings.text` set (or use dynamic data for content templates: `{cta_label}`)
- [ ] `settings.link.type` set: `internal` (post link), `external`, `lightbox`, `popup`, `anchor`, or `none` (when JS-driven)
- [ ] `settings.icon` set if source has icon (use Bricks icon picker, NOT raw SVG inside the button text)
- [ ] `settings.iconPosition` set: `left` or `right`

## Global classes
- [ ] References `{brand}-button` (base style) + variant class (`--primary`/`--secondary`/`--ghost`)
- [ ] Variant style lives on the global class, NOT inline `_cssCustom`

## States (defined on the global class)
- [ ] `:hover` — transition ≤ 250ms (color / background / transform); preserve label legibility
- [ ] `:focus-visible` — visible outline (`outline: 2px solid var(--primary, #2271b1); outline-offset: 2px;`)
- [ ] `:disabled` (when applicable) — reduced opacity + cursor: not-allowed
- [ ] Loading state (when applicable) — pseudo-element spinner; never JS-rendered text replacement

## Accessibility
- [ ] Label minimum 44px tap target (height) — Bricks `_padding` value matches
- [ ] If icon-only: `settings.label` (aria-label equivalent) set
- [ ] Hover transition < 250ms

## Tokens
- [ ] `font-size`: `var(--text-m, 1rem)` or `var(--button-text, 1rem)`
- [ ] `padding`: `var(--button-padding-y, 0.75rem) var(--button-padding-x, 1.5rem)`
- [ ] `border-radius`: `var(--radius, 6px)` or `var(--radius-full, 9999px)` for pill

## Per-breakpoint
- [ ] If the source button shrinks on mobile, set `{ padding: { _base: ..., _mobile_portrait: ... } }` — NEVER an `@media`

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
