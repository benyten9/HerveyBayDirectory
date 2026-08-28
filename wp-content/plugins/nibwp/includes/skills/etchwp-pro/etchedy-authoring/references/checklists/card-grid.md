# card-grid — checklist

## Identify
- [ ] Source has 2+ sibling cards sharing identical structure (image / icon, title, body, optional CTA).
- [ ] BEM block name: `{brand}-{section}__grid` for the grid, `{brand}-{section}__card` for one card.
- [ ] **Repeating siblings MUST become a single `etch/component` with a `properties.items` loop**, not copy-pasted block trees. See SKILL.md §6, json-schema.md § Components.

## Tokens
- [ ] Grid `gap`: `--card-gap` (1lh) or `--space-l` (1.5rem). MAY wrap in `clamp()` inside the `var()` fallback.
- [ ] Card `background`: `--surface-light` (#f8f9fa) on light themes; `--surface-dark` on dark.
- [ ] Card `border-radius`: `--radius-m` (0.75rem) or `--radius-l` (1rem).
- [ ] Card `padding`: `--space-l` to `--space-xl`.
- [ ] Card title `font-size`: `--text-l` (1.125rem) — never `clamp()`.
- [ ] Card body `font-size`: `--text-m` (1rem); `line-height: --leading-relaxed` (1.6).

## Structure
- [ ] Grid uses `display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, NNcqi), 1fr));` — `NN` typically 18–24 (clamps card width without media queries).
- [ ] Cards are equal-height by default (`align-items: stretch` is the grid default; do not override).
- [ ] Card content uses flex column with `gap` for vertical rhythm; CTA pinned to bottom via `margin-block-start: auto`.
- [ ] Card image: `aspect-ratio: 4/3` (or source-matched), `object-fit: cover`, `loading="lazy"`, `decoding="async"`, explicit `width` + `height`.
- [ ] Card icon (if present): 48–64px square, semantic SVG inline (NOT `<img>` for icons).

## Behavior
- [ ] `&:hover` on card: subtle lift via `transform: translateY(-2px)` + `box-shadow` increase. Transition ≤ 200ms.
- [ ] If card is fully clickable: wrap inside `<a>` with `display: block` — NOT nested clickable elements.
- [ ] `&:focus-within` mirrors `:hover` so keyboard users see the hover state.

## Responsive
- [ ] Container-query-first. Grid collapses to single column when container narrower than `to-rem(480px)` via the `minmax(min(100%, …))` trick — no `@media` needed for the grid collapse itself.
- [ ] Card padding shrinks at small container widths.

## Pixel-perfect
- [ ] All cards same height (verify with the inspect panel).
- [ ] Image aspect ratio identical across cards.
- [ ] Vertical spacing between title → body → CTA matches source within ±2px.

## Component system (mandatory for 2+ cards)
- [ ] `properties.items` is an array with sensible defaults (at least 3 placeholder items so the builder preview shows the grid populated).
- [ ] Each item has `title`, `body`, optional `image`, optional `cta_label` + `cta_href`.
- [ ] CTA appears via `etch/condition` `{ isTruthy: "props.cta_label" }` (hidden when label is empty).

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
