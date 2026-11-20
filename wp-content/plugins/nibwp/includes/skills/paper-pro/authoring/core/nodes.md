# Paper nodes → NDO nodes

How to turn what Paper tells you into the six generic types and the CSS key sets
in `schema.md`. Read `schema.md` first; this file is the mapping, not the
contract.

## The advantage you have, and the trap in it

Paper's canvas is HTML and CSS (paper.design, fetched 2 Oct 2026: its "HTML/CSS
canvas means your design exports as code"). So Paper's layers are not vector
shapes you have to *infer* CSS from — they already have CSS. If a tool like the
reported `get_computed_styles` exists in the live listing, **use it**: computed
styles are the design, not an approximation of it.

The trap: computed styles are exhaustive, and the NDO is not. The emitters
concatenate whatever is in `style` and `layout` straight into an inline style
attribute. Dump 60 computed properties per node into it and you get unmaintainable
markup that no builder can round-trip.

So **filter down to the key sets in `schema.md`**. Those are the properties the
emitters were built around and that builders can represent. Everything else is
either noise (`-webkit-font-smoothing`), already implied (`box-sizing`), or a
fidelity decision that belongs in `warnings`.

## Mapping to the six generic types

`type` must be one of `section`, `container`, `component`,
`component_instance`, `text`, `shape`. `figma_type` carries Paper's own string
verbatim.

| What Paper gives you | `type` | Notes |
|---|---|---|
| an artboard | `section` | the conversion root is normally one artboard |
| a top-level layout band inside an artboard | `section` | hero, features, footer — one per visual band |
| a group, a stack, any wrapper | `container` | |
| a component definition | `component` | if Paper distinguishes definitions from uses |
| a placed instance of a component | `component_instance` | |
| a text layer | `text` | must also set `text` and `tag` |
| a rectangle, ellipse, path, vector | `shape` | renders as a plain box — see `assets.md` §5 |
| anything you do not recognize | `container` | the safe default; the generic type degrades, `figma_type` survives |

`container` being the fallback is deliberate. An unmapped Paper node kind still
renders as a box with its styles intact, so the page is imperfect rather than
broken. That is also why `figma_type` must always carry the real Paper string:
when the audit asks why a layer came out as a generic div, the provenance answers
it.

### Sections are a judgement call, not a lookup

Paper will not tell you "this is a hero". Decide from structure: a direct child
of the artboard, full width, with its own vertical rhythm, is a section. Nest
sections only when the design genuinely nests bands. Over-sectioning produces a
page of wrappers; under-sectioning produces one giant div that no builder can
edit.

## Layout

`layout` is written **only** when the node has a real layout direction. A node
without one gets an empty `layout` array, and the Gutenberg emitter then falls
back to a `constrained` block layout — which is usually what you want for a
centered content column.

| Paper gives you | NDO `layout` |
|---|---|
| a horizontal stack | `display: flex`, `flex-direction: row` |
| a vertical stack | `display: flex`, `flex-direction: column` |
| item spacing | `gap: "24px"` — integer px, omit when 0 |
| padding | `padding: "96px 32px 96px 32px"` — **always four values**, top/right/bottom/left, omit when all zero |
| main-axis alignment | `justify-content` |
| cross-axis alignment | `align-items` |
| wrapping | `flex-wrap: "wrap"` |
| CSS Grid | `grid-template-columns` and the rest — **supported**, see below |
| absolute / free positioning | **nothing** — see below |

**Paper's normalizer is more permissive than Figma's here, and you should use
that.** It splits Paper's own CSS map on a layout allow-list rather than writing a
fixed set of keys, so:

- **Grid survives.** `grid-template-columns`, `grid-template-rows`,
  `grid-template-areas`, `grid-auto-flow`, `grid-auto-columns`, `grid-auto-rows`,
  `grid-column`, `grid-row` all land in `layout`. A Paper grid does not have to be
  flattened into flex. The Figma path cannot do this.
- **Per-side padding works.** `padding-top` and friends are on the list, so you are
  not forced into the four-value string — though it stays valid and is better when
  all four sides are set.
- **Separate `row-gap` / `column-gap` work**, as do `align-self`, `justify-items`,
  `align-content`, `place-items` and `place-content`.
- **Alignment is not limited to five values.** The Figma normalizer emits only
  `flex-start`, `center`, `flex-end`, `space-between`, `baseline`; Paper's passes
  through whatever Paper computed. Use real CSS, but prefer those five where they
  fit, because that is what builder adapters reliably parse back.

The full allow-list is in `schema.md`. Anything **not** on it goes to `style` — so a
property with no builder equivalent still reaches the inline style. That is the
argument for filtering at the source rather than piping every computed property
through.

One convenience worth knowing and not relying on: if you set `flex-direction`,
`gap`, `justify-content` or `align-items` without `display`, the normalizer adds
`display: flex` for you. Set it explicitly anyway.

### Absolutely positioned layers

The NDO has **no position keys**. No `position`, no `top`/`left`, no `z-index`,
and `bbox` carries width and height only — there is no x/y in it. That is not an
omission to work around; it is the format saying that overlap is not a
first-class concept here.

Three ways out, in order:

1. **Re-read the design as flow.** Most "absolute" layers in a marketing design
   are really a centered overlay or a stack item with negative intent. Express
   them as flex children.
2. **Nest and center.** A badge over an image becomes a flex parent with
   `align-items: center`, `justify-content: center`, and the image as a
   background on the parent rather than a sibling.
3. **Flatten the group to an image.** When the overlap is genuinely
   pixel-positioned artwork, export it. `assets.md`.

Never invent a `position` key. The emitters would pass it through into the inline
style and the builder would have no idea it exists — you would get a page that
looks right and cannot be edited.

## Visual style

Straight mapping from Paper's computed CSS to the `style` keys in `schema.md`,
with these specifics from the shared code:

- **One fill only.** The first visible fill wins and the walk stops. Layered fills
  need to become nested nodes.
- **One stroke only.** The first solid stroke, as `"2px solid #hex"`. No
  per-side borders, no dashed, no inset/outset.
- **Colors** resolve to `#rrggbb` when fully opaque, `rgba(r,g,b,a)` otherwise.
- **Gradients** become a CSS gradient function. Be aware that the shared
  normalizer emits linear gradients at a **fixed `180deg`** regardless of the
  source's gradient angle, and radial gradients with no explicit shape or
  position. If a Paper gradient has a meaningful angle, you are better off setting
  `background` to the correct full CSS gradient string yourself than letting the
  default stand — and if you cannot, that is a `warnings` entry.
- **Shadows** become `box-shadow`, comma-joined, with `inset ` prefixed for inner
  shadows. Blurs that are not drop or inner shadows are dropped entirely —
  background blur has no `box-shadow` equivalent, so it needs `backdrop-filter`
  set deliberately or a `warnings` entry.
- **Opacity** is written only when below 1.

### Text

Text nodes carry `text` (the content), `tag` (the element), and the type keys.
Two facts that catch people:

- **A text node's fill becomes `color`, not `background`.** The shared fill walker
  explicitly skips background assignment on text nodes.
- **`line-height` is a unitless ratio**, computed as line-height px ÷ font size,
  to three decimals. Hand it a px string and the emitted CSS still works but stops
  scaling with the font.

Never use `clamp()` for font-size. The Etch/ACSS validator rejects it, and it is a
house rule across NibWP. Use a token with a px fallback:
`var(--text-l, 20px)`.

### The heading ramp — better on Paper, still your job

Paper's normalizer takes the designer's word first. Precedence:

1. the node's own `tag`, if it matches `h1`–`h6`;
2. the node's Paper type, if that matches `h1`–`h6`;
3. the size ramp: ≥40 → `h1`, ≥30 → `h2`, ≥24 → `h3`, ≥20 → `h4`, ≥17 → `h5`,
   else `p`.

Steps 1 and 2 are a real advantage of Paper being HTML/CSS-native: a layer the
designer made a genuine `h2` arrives as an `h2` whatever its font size. The Figma
path has no equivalent.

It does not remove the work. The ramp still fires for every text run Paper left
untagged, and it still knows nothing about meaning — a 44px price becomes an `h1`,
a 16px label that *is* a heading becomes a `p`. Both emitters trust `tag` blindly.

So check it during planning, in the NDO, before converting:

- exactly **one** `h1` per page;
- no level skipped;
- a large number, a price or a stat is a `p` with a big font size, not a heading;
- a small all-caps eyebrow above a headline is usually a `p`, not an `h5`.

This is faster and more reliable than correcting headings afterwards inside a
builder, and it is the single highest-value edit the planning step makes.

## Pruning the tree

Paper trees, like all design trees, contain layers that mean nothing in HTML.
Drop them during the walk and the output gets dramatically more editable:

- **Hidden layers.** The shared normalizer already skips children marked
  invisible. Do the same on the Paper side.
- **Single-child wrappers with no styles and no layout.** A group whose only job
  was to let the designer move two things at once. Collapse it into its child.
- **Zero-size and fully transparent layers.** Guides, scratch artwork, notes.
- **Spacer rectangles.** A design-tool idiom. In CSS they are `gap` or `padding`,
  not elements. Convert them to spacing on the parent and delete the node.

Record nothing for these — they are noise, not fidelity loss. Reserve `warnings`
for things the user would care about.
