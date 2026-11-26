# Mosaic styles

Stored on every element as:

```
data.style.states.<state>.<breakpoint>.<property> = value
```

- **States**: `&` (default), `hover`, `focus`, `focus-visible`, `hover-when-link`, `hover-on-link`.
- **Breakpoints**: `_` base, `_t` tablet (≤1079px), `_m` mobile (≤767px), plus any custom
  breakpoint id from `nibwp/mosaic-list-design`. Breakpoints cascade downward: set the
  base, override only what changes on smaller screens.

The shorthand writes this for you:

```json
"style": {
  "base":   { "padding": "96px 24px", "display": "grid", "gap": "32px" },
  "tablet": { "padding": "72px 24px" },
  "mobile": { "padding": "56px 16px", "css": "grid-template-columns: 1fr;" },
  "hover":  { "background": "#1d4ed8" },
  "hover@mobile": { "opacity": "1" }
}
```

A flat map (`"style": { "color": "#fff" }`) means base, default state.

## Properties Mosaic stores as a plain value

Lengths, colours and keywords; `clamp()`, `calc()` and `var()` are accepted.

`width minWidth maxWidth height minHeight maxHeight` ·
`marginTop/Right/Bottom/Left paddingTop/Right/Bottom/Left` (or `padding`/`margin` shorthand) ·
`columnGap rowGap` (or `gap`) · `gridAutoColumns gridAutoRows flexBasis order` ·
`fontFamily color fontSize lineHeight letterSpacing wordSpacing` ·
`position zIndex top right bottom left` ·
`opacity visibility overflow transformStyle perspective mixBlendMode boxSizing aspectRatio backgroundColor cursor`

## Enumerated — only these values

| property | values |
|---|---|
| display | none block flex grid inline inline-block inline-flex inline-grid contents |
| flexDirection | row row-reverse column column-reverse |
| flexWrap | nowrap wrap wrap-reverse |
| alignItems / justifyItems | normal start center end stretch flex-start flex-end self-start self-end baseline |
| justifyContent / alignContent | normal start center end stretch space-between space-around space-evenly flex-start flex-end (+ left right for justifyContent) |
| alignSelf / justifySelf | auto start center end stretch normal flex-start flex-end self-start self-end baseline |
| fontWeight | 100 200 300 400 500 600 700 800 900 (strings) |
| fontStyle | normal italic |
| textAlign | start center end justify left right inherit |
| textTransform | none lowercase uppercase capitalize |
| textDecorationLine | none underline overline line-through |
| whiteSpace | normal pre-wrap nowrap pre |
| gridAutoFlow | row "row dense" column "column dense" |
| listStyleType | disc circle square decimal none … |

## Structured — write as CSS

Mosaic keeps these in its own nested shape. Write them as CSS (`css` on the node, or
`"css"` inside a breakpoint) and they land in `customDeclarations`, which Mosaic
validates and renders verbatim:

border, border-radius, outline, box-shadow, text-shadow, transform, translate, scale,
rotate, transform-origin, transition, filter, backdrop-filter, mask, object-fit,
background images and gradients, grid-template-columns/rows/areas, grid-column/row,
flex (sizing shorthand).

The shorthand routes these automatically when you write them in `style`; writing one
as a string under its camelCase Mosaic name (`"borderRadius": "12px"`) is an error,
because Mosaic would drop it.

## Classes

A universal class is a named style in the theme's design system (`.btn-primary`),
with the same states and breakpoints. Create with `nibwp/mosaic-create-class` (or
`classes` in build), apply with `classes: ["btn-primary"]`. Keep element styles for
what is truly one-off; everything shared belongs in a class.
