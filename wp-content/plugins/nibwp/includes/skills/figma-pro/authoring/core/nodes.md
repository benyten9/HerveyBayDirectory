# core/nodes.md — structure & layout mapping

How to turn a Figma node's geometry into WordPress structure. This is the
STRUCTURE half of the conversion (tokens are in `core/tokens.md`, per-builder
output is in the `builders/*.md` files). `class-figma-normalize.php` in the
integration pre-flattens raw Figma JSON into the shapes below; this file explains
what each field *means for the layout*.

## The conversion hierarchy

Always walk the design top-down through this hierarchy — it keeps structure and
nesting faithful:

```
Figma Frame          →  Page / top-level section wrapper
  Sections           →  Section (full-width band)
    Containers        →  Container / inner-max-width wrapper (flex/grid)
      Components       →  reusable block/pattern (see core/components.md)
        Variables      →  design tokens (see core/tokens.md)
        Styles          →  paint/text/effect styles → CSS
        Assets           →  images / SVG (see below + core/tokens.md §assets)
          → WordPress Elements
```

A `FRAME`/`SECTION` at the top is the page or a section band. Nested frames with
auto-layout are containers. Repeated instances are components — dedupe them, don't
duplicate.

## Table of contents
1. Node types → intent
2. Auto-layout → flexbox
3. Sizing (HUG / FILL / FIXED)
4. Constraints → responsive
5. Fills / strokes → background/border
6. Effects → box-shadow / filter
7. Corner radius
8. Absolute positioning & warnings

---

## 1. Node types → intent

| Figma `type` | Intent | Output |
|---|---|---|
| `FRAME`, `SECTION` | page / section band | section or container wrapper |
| `COMPONENT`, `COMPONENT_SET` | reusable definition | pattern / global component |
| `INSTANCE` | use of a component | block instance (reuse, don't re-emit) |
| `GROUP` | visual grouping | plain container div |
| `TEXT` | text run | heading/paragraph by type-ramp slot |
| `RECTANGLE`, `ELLIPSE` | shape / decoration | div w/ bg, or `<img>` if image-filled |
| `VECTOR`, `BOOLEAN_OPERATION`, `STAR` | vector art | inline SVG export |
| `LINE` | divider | `<hr>` / border |
| any node w/ `fills[].type=IMAGE` | media | `<img>` or `background-image` |

## 2. Auto-layout → flexbox

Figma auto-layout IS flexbox. Emit on the container:

| Figma | Value | CSS |
|---|---|---|
| `layoutMode` | `HORIZONTAL` | `display:flex; flex-direction:row` |
| `layoutMode` | `VERTICAL` | `display:flex; flex-direction:column` |
| `primaryAxisAlignItems` | `MIN`/`CENTER`/`MAX` | `justify-content: flex-start/center/flex-end` |
| `primaryAxisAlignItems` | `SPACE_BETWEEN` | `justify-content: space-between` |
| `counterAxisAlignItems` | `MIN`/`CENTER`/`MAX`/`BASELINE` | `align-items: …` |
| `itemSpacing` | px | `gap` (token-ize to `--space-*` when matched) |
| `paddingTop/Right/Bottom/Left` | px | `padding` (token-ize) |
| `layoutWrap` | `WRAP` | `flex-wrap: wrap` |

A grid of equal columns (horizontal auto-layout, N equal FILL children) may map
better to CSS Grid — see `core/responsive.md` for the column→stack behavior.

## 3. Sizing — HUG / FILL / FIXED

| Figma child field | Meaning | CSS |
|---|---|---|
| `layoutSizingHorizontal=FILL` | fill available | `flex:1` / `width:100%` |
| `layoutSizingHorizontal=HUG` | fit content | `width:fit-content` |
| `layoutSizingHorizontal=FIXED` | fixed px | explicit width (avoid where it hurts responsiveness) |
| `layoutGrow=1` | grow on main axis | `flex-grow:1` |
| `min/maxWidth`, `min/maxHeight` | bounds | `min/max-*` |

Prefer FILL/HUG output over FIXED — fixed widths are the main enemy of a
responsive result. Convert fixed widths to `max-width` + `%` when the intent
allows, and warn on the rest.

## 4. Constraints → responsive

`constraints:{horizontal,vertical}` are breakpoint hints:

| Value | Hint |
|---|---|
| `MIN` (LEFT/TOP) | pin to start |
| `MAX` (RIGHT/BOTTOM) | pin to end |
| `CENTER` | center |
| `STRETCH` (LEFT_RIGHT/TOP_BOTTOM) | full-bleed |
| `SCALE` | fluid |

Feed to the builder's breakpoint logic. Full responsive treatment in
`core/responsive.md`.

## 5. Fills / strokes → background/border

| Figma | Output |
|---|---|
| `fills[].type=SOLID` + `color` + `opacity` | `background`/`color` — match a color token first, else `rgba()` (flag raw) |
| `fills[].type=GRADIENT_LINEAR` | `linear-gradient()` from stops + handles (angle) |
| `fills[].type=GRADIENT_RADIAL/ANGULAR` | `radial/conic-gradient()` |
| `fills[].type=IMAGE` + `imageRef` + `scaleMode` | `background-image` (FILL→cover, FIT→contain, TILE→repeat) or `<img>` |
| `strokes[]` + `strokeWeight` + `strokeAlign` | `border`; `strokeDashes` → dashed |
| multiple fills | stack; topmost solid usually wins for text color |

Figma colors are 0–1 floats → convert to 0–255. Non-`NORMAL` `blendMode` →
`mix-blend-mode` (warn).

## 6. Effects → box-shadow / filter

| `effects[].type` | Output |
|---|---|
| `DROP_SHADOW` | `box-shadow: x y blur spread color` |
| `INNER_SHADOW` | `box-shadow: inset …` |
| `LAYER_BLUR` | `filter: blur()` |
| `BACKGROUND_BLUR` | `backdrop-filter: blur()` |

Multiple shadows → comma-joined.

## 7. Corner radius

`cornerRadius` → `border-radius`. `rectangleCornerRadii:[tl,tr,br,bl]` → four-value
`border-radius`. Token-ize to `--radius-*` when matched.

## 8. Absolute positioning & warnings

Nodes without `layoutMode` are absolutely placed in Figma (`absolute:true` +
`absoluteBoundingBox`). Prefer flowing them into the parent's flex; fall back to
`position:absolute` only for genuinely overlapping/decorative layers, and **warn**
— absolute layouts don't reflow and break responsiveness.

Always surface: absolute layers, leftover fixed pixel widths, blend modes / masks
approximated. An honest layout-warning list is part of a faithful conversion.
