# The NDO 1.0 contract

> Sources of truth, both read directly:
> `includes/integrations/figma/class-figma-normalize.php` defines the format;
> `includes/integrations/paper/class-paper-normalize.php` is the one Paper
> actually runs. The emitters both tools share are
> `includes/integrations/figma/class-figma-html.php` and
> `class-figma-gutenberg.php`. If a key is not here, no emitter reads it, and
> inventing one does nothing.
>
> **Paper has its own normalizer, not Figma's.** `NIBWP_Paper_Normalize` produces
> the same NDO 1.0 document, which is why the emitters are shared — but it differs
> from the Figma one in four ways that matter, and §"Paper's normalizer
> specifically" at the end of this file is the part you must read before building
> a tree by hand.

A design enters WordPress as an **NDO** — a NibWP Design Object. It is
builder-agnostic: one object, any builder. Paper and Figma both produce it,
which is why Paper needed no new emitters.

## Why the key is called `figma_type`

The format was defined for the Figma integration first and the node's
source-type key kept that name. **For a Paper design, `figma_type` carries
Paper's own node type string.** It is a provenance field, not a claim about
Figma. Do not rename it, do not leave it empty, and do not translate Paper's
vocabulary into Figma's — put Paper's own string in and let `type` carry the
generic meaning.

## Document

```php
[
  'ndo_version' => '1.0',
  'target'      => ['type' => 'page', 'title' => string],
  'tokens'      => array,   // the token map; may be empty
  'root'        => node,    // one node, the subtree root
  'warnings'    => array,   // see the note below
]
```

`target.type` is `'page'` in the normalizer as written. `target.title` comes from
the root node's name.

**`warnings` behaves differently in the two normalizers, and the difference is a
trap.** The **Figma** normalizer declares a `$warnings` property, places it in the
document, and never appends to it — the Figma path always returns
`'warnings' => []`. The **Paper** normalizer does populate it, as a deduplicated
list of **plain translated strings** (for example: an image reference with no URL
yet).

So on the Paper path `warnings` arrives partly filled, with the mechanical problems
the normalizer could see. It cannot see the ones that matter most — a shader you
chose to flatten, a font the theme does not load, a heading level you overrode.
Those are judgements, and you add them. Strings, to match what the normalizer
produces; do not mix shapes in one array.

## Node

Always present:

```php
[
  'figma_type' => string,   // the source tool's node type (Paper's, for Paper)
  'name'       => string,   // the layer name
  'type'       => string,   // the GENERIC type — see the table below
  'style'      => array,    // CSS property => value, visual
  'layout'     => array,    // CSS property => value, box/flex
  'children'   => array,    // list of nodes
]
```

Conditionally present:

| Key | Set when | Shape |
|---|---|---|
| `bbox` | the source node reported a bounding box | `['w' => float, 'h' => float]` — width and height only, **no x/y** |
| `text` | the node is a text node | `string` |
| `tag` | the node is a text node | `'h1'`…`'h5'` or `'p'` |
| `font_family` | a text node declared a family | `string` (also mirrored into `style['font-family']`) |
| `image_ref` | a fill is an image | `string` — the source tool's image reference |
| `image_url` | that reference resolved to a URL | `string` (also drives the `background-*` style keys) |
| `alt` | **Paper only** — the node carried alt text | `string` |

`bbox` is **optional**. The Figma normalizer only sets it when
`absoluteBoundingBox` exists. Emit it when Paper gives you a size; do not fake
it.

## The generic `type` vocabulary

`map_type()` produces exactly six values. Nothing else is legal.

| `type` | Figma source types that map to it | What to map Paper to |
|---|---|---|
| `section` | `FRAME`, `SECTION` | an artboard, or a top-level layout frame |
| `container` | `GROUP`, **and everything unrecognized** | a group, a stack, any wrapper |
| `component` | `COMPONENT`, `COMPONENT_SET` | a component definition |
| `component_instance` | `INSTANCE` | a placed instance of a component |
| `text` | `TEXT` | a text layer |
| `shape` | `RECTANGLE`, `ELLIPSE`, `VECTOR`, `STAR`, `LINE`, `REGULAR_POLYGON`, `BOOLEAN_OPERATION` | a rectangle, ellipse, path, or any vector |

`container` is the default fallback, so an unmapped Paper node kind lands there
and still renders as a box. That is the safe failure, and it is why you should
always set `figma_type` to the real Paper string: the generic type degrades, but
the provenance survives for the audit.

## `style` — the visual keys the normalizer actually writes

These are literal CSS property names, with string values ready to drop into a
`style` attribute. The emitters do nothing but concatenate them.

| Key | Value shape | Source |
|---|---|---|
| `opacity` | float, 3dp, only when `< 1` | node opacity |
| `border-radius` | `"12px"`, or four space-separated px values | uniform corner radius, or per-corner radii |
| `background` | `"#rrggbb"` / `"rgba(r,g,b,a)"` / a gradient function | first visible solid or gradient fill |
| `background-image` | `"url(https://…)"` | first visible image fill, once resolved |
| `background-size` | `"cover"` or `"contain"` | the fill's scale mode |
| `background-position` | `"center"` | fixed |
| `background-repeat` | `"no-repeat"` | fixed |
| `border` | `"2px solid #rrggbb"` | first solid stroke + stroke weight |
| `box-shadow` | one or more comma-joined shadows, `inset ` prefixed for inner | visible drop/inner shadows |
| `color` | `"#rrggbb"` / `"rgba(…)"` | **text nodes only** — first solid fill |
| `font-size` | `"18px"`, integer px | text style |
| `font-weight` | integer | text style |
| `font-family` | string | text style |
| `line-height` | **unitless ratio**, 3dp | line-height px ÷ font size |
| `letter-spacing` | `"0.5px"`, 2dp | text style, omitted when exactly 0 |
| `text-align` | `"center"` / `"right"` / `"justify"` | omitted when left |
| `text-transform` | `"uppercase"` / `"lowercase"` / `"capitalize"` | text case |

Two things worth knowing because they bite:

- **A text node's solid fill becomes `color`, not `background`.** The fill walker
  explicitly skips the background assignment for text nodes.
- **`line-height` is a ratio, not a length.** It divides the pixel line height by
  the font size. If you hand it a px string the emitted CSS still works, but it
  stops scaling with the font — so don't.

## `layout` — the box keys

Written only when the source node had a real auto-layout / stack direction.
A node with no layout mode gets an **empty** `layout` array, and the Gutenberg
emitter then falls back to a `constrained` block layout.

| Key | Value shape |
|---|---|
| `display` | `"flex"` |
| `flex-direction` | `"row"` or `"column"` |
| `gap` | `"24px"`, integer px, omitted when 0 |
| `padding` | `"16px 24px 16px 24px"` — always four values, in top/right/bottom/left order, omitted when all four are 0 |
| `justify-content` | see the alignment values below |
| `align-items` | see the alignment values below |
| `flex-wrap` | `"wrap"`, only when the source wraps |

The alignment vocabulary is exactly five values: `flex-start` (the default),
`center`, `flex-end`, `space-between`, `baseline`.

`layout` and `style` are merged into one inline CSS string by both emitters,
with **`style` winning** on a key collision. Keep them separate anyway — a
builder adapter that wants structure without decoration reads `layout` alone.

## The text tag ramp

`tag` is derived from `font-size` alone, in px, before any token mapping:

| Font size | `tag` |
|---|---|
| ≥ 40 | `h1` |
| ≥ 30 | `h2` |
| ≥ 24 | `h3` |
| ≥ 20 | `h4` |
| ≥ 17 | `h5` |
| otherwise | `p` |

This is a heuristic and it is frequently wrong about semantics — a 44px price is
not an `h1`. The emitters trust it blindly: `class-figma-html.php` validates
`tag` against `/^h[1-6]$/` and falls back to `p`, and
`class-figma-gutenberg.php` emits `core/heading` with the matching level. So
**fix `tag` in the NDO before you convert**, during the planning step, rather
than fixing headings afterwards in the builder. There must be exactly one `h1`.

## A minimal valid NDO

```php
[
  'ndo_version' => '1.0',
  'target' => ['type' => 'page', 'title' => 'Pricing'],
  'tokens' => ['--color-primary' => '#1b2a4a'],
  'root' => [
    'figma_type' => 'Artboard',          // Paper's own string
    'name'       => 'Pricing',
    'type'       => 'section',
    'style'      => ['background' => '#ffffff'],
    'layout'     => [
      'display'        => 'flex',
      'flex-direction' => 'column',
      'gap'            => '48px',
      'padding'        => '96px 32px 96px 32px',
      'align-items'    => 'center',
    ],
    'bbox'     => ['w' => 1440.0, 'h' => 2180.0],
    'children' => [
      [
        'figma_type' => 'Text',
        'name'       => 'Headline',
        'type'       => 'text',
        'text'       => 'Simple pricing',
        'tag'        => 'h1',
        'style'      => [
          'font-size'   => '56px',
          'font-weight' => 700,
          'line-height' => 1.1,
          'color'       => '#1b2a4a',
        ],
        'layout'   => [],
        'children' => [],
      ],
    ],
  ],
  'warnings' => [
    'Hero/Field is a Paper shader — exported as a 2x PNG. It will not animate.',
  ],
]
```

`warnings` entries are **plain strings**, because that is what
`NIBWP_Paper_Normalize` produces and one array must not hold two shapes. Write
them for a human: name the layer, say what happened, say what it means. They are
what `nibwp/paper-pro-audit` reports back.

## Invariants to hold yourself to

1. `ndo_version` is the string `'1.0'`.
2. `root` is a single node, not a list.
3. `type` is one of the six generic values. `figma_type` is Paper's own string.
4. Every `style` / `layout` key is a real CSS property name, lowercase,
   hyphenated, with a value that is valid CSS as-is.
5. `children` is always present, even when empty. The emitters loop it without
   checking.
6. Put every fidelity compromise in `warnings`, as a string. The normalizer adds
   the mechanical ones; the judgement calls are yours and nothing else will notice
   them for you.

---

## Paper's normalizer specifically

`NIBWP_Paper_Normalize` produces the document above, so everything already said
holds. Four differences from the Figma normalizer, all of which make it more
capable — and all of which you need to know before hand-building a tree.

### 1. `style` and `layout` are a split, not a fixed set

The Figma normalizer writes a fixed list of CSS keys. The Paper normalizer takes
**Paper's own CSS map** and splits it: anything in its layout allow-list goes to
`layout`, everything else goes to `style`. That is the right design for a tool
whose canvas is already CSS — nothing is thrown away for not being on a list.

The layout allow-list, verbatim:

```
display  flex-direction  flex-wrap  flex-flow  gap  row-gap  column-gap
justify-content  justify-items  align-items  align-content  align-self
place-items  place-content
padding  padding-top  padding-right  padding-bottom  padding-left
grid-template-columns  grid-template-rows  grid-template-areas  grid-auto-flow
grid-auto-columns  grid-auto-rows  grid-column  grid-row
```

Three consequences worth acting on:

- **CSS Grid is supported.** `grid-template-columns` survives into `layout`. A Paper
  grid does not have to be flattened into flex. The Figma path cannot do this.
- **Per-side padding and separate row/column gaps are supported.** You are not
  forced into the Figma normalizer's four-value `padding` string, though it stays
  valid.
- **Everything not on that list lands in `style`.** So a property with no builder
  equivalent still reaches the inline style. That is a reason to filter at the
  source (`nodes.md`) rather than piping every computed property through.

Empty and non-scalar values are dropped on the way in.

### 2. `display: flex` is inferred

If `flex-direction`, `gap`, `justify-content` or `align-items` is present and
`display` is not, the normalizer adds `display: flex`. So a hand-built tree that
forgets `display` still lays out. Set it anyway — relying on an inference is how a
tree becomes hard to reason about.

### 3. `tag` respects the designer, then falls back to the ramp

Precedence, in order:

1. the node's own `tag`, if it matches `h1`–`h6`;
2. the node's Paper type (`ptype`), if that matches `h1`–`h6`;
3. the size ramp (≥40 `h1`, ≥30 `h2`, ≥24 `h3`, ≥20 `h4`, ≥17 `h5`, else `p`).

This is materially better than the Figma path, because Paper's canvas is HTML: a
designer who made something a real `h2` gets an `h2`, whatever its font size. It
does **not** remove your job. The ramp still fires for every text run Paper did not
tag, and it still calls a 44px price an `h1`. Check the ramp during planning.

### 4. The generic type map is Paper's vocabulary, and images are shapes

Paper's node types are HTML-ish, and the map reflects that:

- → `section`: `frame`, `artboard`, `board`, `page`, `canvas`, `screen`, `section`,
  `main`, `header`, `footer`, `nav`, `aside`, `article`
- → `container`: `stack`, `group`, `flex`, `grid`, `container`, `box`, `div`,
  `figure`, `ul`, `ol`, `li`, `table`, `tbody`, `tr`, `td`
- → `component`: `component`, `symbol`, `componentset`
- → `component_instance`: `instance`, `componentinstance`, `use`
- → `text`: `text`, `label`, `paragraph`, `heading`, `string`, `p`, `h1`–`h6`,
  `span`, `a`, `strong`, `em`, `blockquote`, `figcaption`, `small`, `code`, `pre`
- → `shape`: `image`, `img`, `picture`, `rect`, `rectangle`, `ellipse`, `oval`,
  `circle`, `vector`, `path`, `line`, `star`, `polygon`, `svg`, `icon`, `shape`,
  `hr`
- otherwise: `text` when the node has text content, `container` when it does not

Two things to notice. **An image is a `shape`**, rendered as a box with a
`background-image` plus `object-fit` — the same treatment as the Figma path, and
the reason the Gutenberg emitter still produces no `core/image`. And the fallback
is smarter than Figma's: an unknown node **with text** becomes `text` rather than
an empty container, so a tagged-but-unrecognized Paper element still shows its
words.

### 5. Images with no URL warn for you

An `image_ref` that has not been resolved to a URL produces a warning telling the
user to run `attach_asset` and re-convert. So the asset ordering in
`assets.md` is enforced, not merely advised — but the warning only appears after a
convert, so do it in the right order anyway.
