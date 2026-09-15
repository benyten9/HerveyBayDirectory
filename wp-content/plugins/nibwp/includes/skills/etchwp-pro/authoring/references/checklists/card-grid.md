# card-grid — checklist

## Identify
- [ ] Source has 2+ sibling cards sharing identical structure (image / icon, title, body, optional CTA).
- [ ] BEM block name: `{brand}-{section}__grid` for the grid, `{brand}-{section}__card` for one card.
- [ ] **Repeating siblings become ONE `etch/component` rendered once per card**, each instance with its own `attributes` — never copy-pasted block trees, and never a list-valued prop (Etch has no array property type). Cards that grow (6 or more, or editor-managed) are a loop over a CPT instead. See SKILL.md §11 and §15.

## Tokens
- [ ] Grid `gap`: `--grid-gap` or `--space-l` (1.5rem). MAY wrap in `clamp()` inside the `var()` fallback.
- [ ] Card `background`: `--white` or `--neutral-ultra-light` (#f2f2f2) on light sections; `--bg-dark` on dark ones. Not `--base-*`: it follows the brand colour.
- [ ] Card `border`: `--border-color-dark` on light sections; `--border-color-light` only on dark ones (it is white at 20% and vanishes on a light card).
- [ ] Card `border-radius`: `--radius-m` (0.75rem) or `--radius-l` (1rem).
- [ ] Card `padding`: `--space-l` to `--space-xl`.
- [ ] Card title `font-size`: `--text-l` (1.125rem) — never `clamp()`.
- [ ] Card body `font-size`: `--text-m` (1rem); `line-height: --text-line-height`.

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
- [ ] Up to 5 fixed cards: one component with `title`, `body`, optional `image`, optional `ctaLabel` + `ctaUrl` properties (each with a sensible default), and one `etch/component` instance per card that differs only in its `attributes`.
- [ ] 6 or more cards, or cards an editor will add to: accept the `loop_to_cpt` recommendation and render the card once per CPT entry.
- [ ] The CTA sits in an `etch/condition` so it disappears when the label is empty: `"condition": { "leftHand": "props.ctaLabel", "operator": "isTruthy", "rightHand": null }`, `"conditionString": "props.ctaLabel"`.

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
