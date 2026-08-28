# button — checklist

## Identify
- [ ] Source is a clickable element (`<a>`, `<button>`, `<input type="submit">`).
- [ ] Decided variant: primary / secondary / outline / ghost / icon-only.
- [ ] BEM block name: `{brand}-btn` or `{brand}-{component}__btn` when scoped to a parent component (CTA, hero, form).

## Tokens
- [ ] `font-size`: canonical `--text-s` / `--text-m` token with `px` / `rem` / `em` fallback. **Never `clamp()`**.
- [ ] `padding`: `--space-s` / `--space-m` tokens (block + inline).
- [ ] `border-radius`: `--radius` / `--radius-m` / `--radius-full` (full for pill buttons).
- [ ] `background`: `--primary` / `--secondary` for fill; raw transparent for ghost / outline.
- [ ] `color`: `--base-ultra-light` on filled buttons; `--heading-color` on outline / ghost.
- [ ] `transition` ≤ 250ms.

## Structure
- [ ] Tag is semantic: `<button type="button|submit|reset">` for actions, `<a href>` for navigation. Never `<div>`.
- [ ] Icon + label spacing uses `gap` on the button itself (flex container), not margin on the icon.
- [ ] Minimum tap target: `min-block-size: 44px;` (WCAG 2.5.5 AAA — required even when the visible label is shorter).
- [ ] `cursor: pointer` only on `<a>` / `<button>` (NOT on disabled or `<div>` proxies).

## Behavior
- [ ] `&:hover` — background shift via `--primary-dark` or `filter: brightness(0.95)`.
- [ ] `&:focus-visible` — explicit 2px outline using `--primary` and `outline-offset: 2px`. Never `outline: none` without a replacement.
- [ ] `&:active` — `transform: translateY(1px)` or a darker background.
- [ ] `&:disabled` / `&[aria-disabled="true"]` — `opacity: 0.5`, `cursor: not-allowed`, no hover/active states fire.
- [ ] Loading state (if applicable): inline SVG spinner OR CSS `@keyframes` rotation; `aria-busy="true"` while loading. No JS library.

## Responsive
- [ ] Container-query-first; on narrow widths, button stretches to `inline-size: 100%` only when it's the only / primary CTA in a card.
- [ ] Label text never truncates with `text-overflow: ellipsis` — that's a design bug, not a button concern.

## Pixel-perfect
- [ ] Letter-spacing matches source (often `-0.01em` on tight buttons).
- [ ] Font-weight matches source (typically 500 or 600).
- [ ] Border thickness matches source exactly (1px ≠ 1.5px ≠ 2px).

## Accessibility
- [ ] `aria-label` when the button has no visible text (icon-only).
- [ ] Focus ring contrast ratio ≥ 3:1 against the surrounding background.
- [ ] When inside a form, the submit button's `type="submit"` is explicit.

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
