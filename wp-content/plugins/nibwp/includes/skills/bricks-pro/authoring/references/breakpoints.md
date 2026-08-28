# Breakpoints — per-breakpoint setting shape

Bricks renders separate `@media` blocks AUTOMATICALLY based on per-breakpoint values in setting objects. NEVER write `@media` yourself inside `_cssCustom`.

## Default breakpoints (Bricks 1.10+)

| Key                | Range (default)        | Use for                                |
|--------------------|------------------------|----------------------------------------|
| `_base`            | All sizes              | Desktop-first default                  |
| `_tablet_portrait` | ≤ 1024px               | Tablets in portrait                    |
| `_mobile_landscape`| ≤ 768px                | Phones in landscape; small tablets     |
| `_mobile_portrait` | ≤ 480px                | Phones in portrait                     |

Users can rename / re-range via Bricks → Settings → Breakpoints. Refer to keys by name; the agent doesn't need to know the px ranges to write valid payloads.

## Shape

Every setting that takes a value can take a per-breakpoint object:

```json
{
  "_padding": {
    "_base":            { "top": "6rem", "right": "2rem", "bottom": "6rem", "left": "2rem" },
    "_tablet_portrait": { "top": "5rem", "right": "1.5rem", "bottom": "5rem", "left": "1.5rem" },
    "_mobile_landscape":{ "top": "4rem", "right": "1.5rem", "bottom": "4rem", "left": "1.5rem" },
    "_mobile_portrait": { "top": "3rem", "right": "1rem",   "bottom": "3rem", "left": "1rem"   }
  }
}
```

OR cascade-style (only set the breakpoints that differ from `_base`):

```json
{
  "_padding": {
    "_base":            { "top": "6rem", "bottom": "6rem" },
    "_mobile_portrait": { "top": "3rem", "bottom": "3rem" }
  }
}
```

Bricks resolves missing breakpoints from the next-larger one (cascading).

## Common per-breakpoint candidates

- `_padding`, `_margin`
- `_typography.font-size`, `_typography.line-height`, `_typography.text-align`
- `_background` (especially `image`, `position`, `size`)
- `_display` (e.g. hide decorative element on mobile: `{ "_mobile_portrait": "none" }`)
- `_flex.direction` (column on mobile)
- `_grid.columns` / `_grid.gap`
- `_position`, `_top`, `_left`, etc.

## Hiding elements per breakpoint

To hide an element on mobile only:

```json
{ "_display": { "_base": "block", "_mobile_portrait": "none" } }
```

NEVER write `@media (max-width: 480px) { .x { display: none } }` in `_cssCustom`.

## Typography switching (NEVER clamp() font-size)

To shrink the heading on small screens:

```json
{
  "_typography": {
    "_base":            { "font-size": "var(--text-xxl, 3rem)",   "line-height": "1.1" },
    "_mobile_landscape":{ "font-size": "var(--text-xl, 2.25rem)", "line-height": "1.15" },
    "_mobile_portrait": { "font-size": "var(--text-l, 1.5rem)",   "line-height": "1.2" }
  }
}
```

Match ACSS's "switch the token at the breakpoint" rule. NEVER `clamp()` font-size.

## Grid + Flex direction per breakpoint

```json
{
  "_display": "flex",
  "_flex": {
    "_base":            { "direction": "row",    "wrap": "wrap", "gap": "var(--space-l, 2rem)" },
    "_mobile_portrait": { "direction": "column", "gap": "var(--space-m, 1rem)" }
  }
}
```

## Anti-patterns

- `@media (...) { ... }` inside `_cssCustom` — use per-breakpoint setting shape instead. Rule id `bricks_inline_media_query`.
- Setting only `_mobile_portrait` without a `_base` — works (Bricks defaults to no value at larger breakpoints) but makes intent unclear. Prefer setting `_base` then overriding at smaller breakpoints.
- Different units across breakpoints (`6rem` then `48px`) — pick rem or em throughout for consistency with ACSS / Bricks Design System.
- Hiding the hero image on `_mobile_portrait` to "fix" overflow — usually means the layout itself is wrong; consider a different image position or a content-first stack instead.
