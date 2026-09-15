# Element settings, not custom CSS

Bricks styling belongs in the element's **settings**. Custom CSS is the last resort, not the default.

## Why this is a hard rule

A page styled through `_cssCustom` renders correctly and is **inert in the builder**:

- the control panels are empty, so the site owner sees nothing to change
- the breakpoint switcher does nothing, because Bricks generates media queries from setting values it can see
- the styling cannot be adjusted without going back to an agent

Building in Bricks is only worth anything if the result is editable in Bricks. Styling written as CSS is a decision to hide it.

## The order to reach for

1. **Element settings** — `settings._padding`, `settings._typography`, `settings._background`. One-off styling for one element.
2. **A global class carrying settings** — the same setting keys, inside `global_classes[i].settings`. Anything used more than once. This is what real Bricks sites look like: a class holds `_padding`, `_background`, `_typography`, and only what has no control falls to `_cssCustom`.
3. **`_cssCustom`** — only for what genuinely has no control: `:hover` and other states, `::before` / `::after`, descendant selectors, and properties absent from the table below.

A global class is not "a place to put a stylesheet". It is a named bundle of settings.

```json
{
  "name": "acme-card",
  "settings": {
    "_padding":    { "top": "var(--space-m, 24px)", "bottom": "var(--space-m, 24px)" },
    "_background": { "color": { "hex": "#ffffff" } },
    "_border":     { "radius": { "top": "8", "right": "8", "bottom": "8", "left": "8" } },
    "_cssCustom":  ".brxe-{id}:hover { transform: translateY(-2px); }"
  }
}
```

## Properties Bricks already controls

Writing any of these as CSS fails validation with `bricks_css_for_native_setting`. Verified against the Bricks control definitions (2.1.4; the same keys exist in 1.10.3).

| CSS | Setting | CSS | Setting |
|---|---|---|---|
| `padding` | `_padding` | `display` | `_display` |
| `margin` | `_margin` | `flex-direction` | `_flexDirection` |
| `gap` | `_gap` | `align-items` | `_alignItems` |
| `row-gap` | `_rowGap` | `align-self` | `_alignSelf` |
| `column-gap` | `_columnGap` | `justify-content` | `_justifyContent` |
| `width` | `_width` | `flex-grow` | `_flexGrow` |
| `min-width` | `_widthMin` | `flex-shrink` | `_flexShrink` |
| `max-width` | `_widthMax` | `flex-basis` | `_flexBasis` |
| `height` | `_height` | `order` | `_order` |
| `min-height` | `_heightMin` | `overflow` | `_overflow` |
| `max-height` | `_heightMax` | `position` | `_position` |
| `aspect-ratio` | `_aspectRatio` | `top`/`right`/`bottom`/`left` | `_top`/`_right`/`_bottom`/`_left` |
| `font-size` | `_typography.font-size` | `z-index` | `_zIndex` |
| `font-family` | `_typography.font-family` | `background` | `_background` |
| `font-weight` | `_typography.font-weight` | `background-color` | `_background.color` |
| `font-style` | `_typography.font-style` | `background-image` | `_background.image` |
| `line-height` | `_typography.line-height` | `border` | `_border` |
| `letter-spacing` | `_typography.letter-spacing` | `border-radius` | `_border.radius` |
| `text-align` | `_typography.text-align` | `box-shadow` | `_boxShadow` |
| `text-transform` | `_typography.text-transform` | `opacity` | `_opacity` |
| `text-decoration` | `_typography.text-decoration` | `transform` | `_transform` |
| `color` | `_typography.color` | `transition` | `_cssTransition` |
| `filter` | `_cssFilters` | `cursor` | `_cursor` |
| `mix-blend-mode` | `_mixBlendMode` | `pointer-events` | `_pointerEvents` |
| `isolation` | `_isolation` | `visibility` | `_visibility` |

`aspect-ratio` has had a control since Bricks 1.9 — do not write it as CSS.

## Setting shapes

Tokens work inside settings exactly as they do in CSS, so nothing is lost by moving:

```json
"_padding":    { "top": "var(--space-l, 32px)", "bottom": "var(--space-l, 32px)" },
"_typography": { "font-size": "var(--text-l, 20px)", "color": { "hex": "#111111" } },
"_width":      "100%",
"_display":    "flex",
"_gap":        "var(--space-s, 12px)"
```

Per breakpoint, add the breakpoint key beside the base value — never an `@media` block. See [breakpoints.md](breakpoints.md).

## What still belongs in `_cssCustom`

```css
.brxe-{id}:hover { transform: translateY(-2px); }
.brxe-{id}::before { content: ""; inset: 0; }
.brxe-{id} .child-that-is-not-an-element { scroll-snap-align: start; }
```

States, pseudo-elements, descendants, and properties with no control. `{id}` is replaced with the element's real id by the persister, so the rule stays scoped to that element.
