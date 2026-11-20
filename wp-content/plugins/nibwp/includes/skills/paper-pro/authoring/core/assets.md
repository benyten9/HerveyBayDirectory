# Assets, and the fidelity decision

Two jobs in this file. Getting images into WordPress properly, and deciding what
to do about the parts of a Paper design that CSS cannot express.

## 1. The fidelity decision table

Walk the Paper tree once and classify every layer before you convert anything.

| What the layer is | Converts? | Do this |
|---|---|---|
| frame / stack with a layout direction | yes, cleanly | `layout` flex keys — `schema.md` |
| text | yes | `text` + `tag` + type styles |
| solid or gradient fill | yes | `style.background` |
| stroke | yes, one | `style.border` — single solid stroke only |
| corner radius | yes | `style.border-radius` |
| drop / inner shadow | yes | `style.box-shadow` |
| raster image fill | yes, after sideload | export → `attach_asset` → `image_url` |
| vector / path artwork | partly | export as SVG or PNG and place as an image |
| **shader / procedural effect** | **no** | see §2 |
| blend modes beyond the basics | no | flatten the affected group to an image |
| animation, interaction, canvas-time generation | no | static frame, or rebuild in CSS/JS deliberately |
| multiple layered fills on one node | **no — only the first is kept** | split into nested nodes, one fill each |

That last row is a real constraint in the shared normalizer, not a
simplification: the fill walker takes the **first visible fill** and returns. A
node with an image fill over a gradient loses the gradient silently. If a Paper
layer stacks fills, model it as a parent plus a child in the NDO so each gets its
own `background`.

Same shape for strokes: only the **first solid stroke** becomes a `border`.

## 2. Shaders

### What is actually true

Paper's canvas is HTML and CSS — its own homepage (fetched 2 Oct 2026) says the
"HTML/CSS canvas means your design exports as code. nothing gets lost in
translation." So most of a Paper artboard is *already* in the target language.

Paper Shaders is a different system. Per <https://shaders.paper.design> it is
"ultra fast zero-dependency shaders for your designs", published as the npm
package `@paper-design/shaders-react` — a React component library, with a gallery
of effects (water, halftone dots, textures, gradient fields). Neither page claims
a CSS equivalent, and there is no CSS property that evaluates a per-pixel shader.

The honest framing, which is this KB's reading of those two primary sources
rather than a quote from either:

> The layout layer converts cleanly because it is already CSS. The shader layer
> does not convert at all, because it is a React/WebGL component on top of that
> CSS.

The ceiling is **per layer**, not per file. Do not tell a user their Paper design
"can't be converted" because one hero has a noise field.

### The four options, in order

**1. Flatten to an image.** Export the shader layer at 2× and place it as a
background image or an `<img>`. Fidelity is exact at the exported size; the effect
becomes static. This is the default and it is usually right — most shader use in
a marketing design is a decorative background that nobody interacts with.

Say it like this: *"The gradient field in the hero is a shader, so I exported it
as a PNG. It will look identical and it will not animate."*

**2. Approximate the intent in CSS.** A multi-stop `linear-gradient` plus
`background-blend-mode`, or an SVG `feTurbulence` filter, can read as the same
mood at a fraction of the weight. Choose this when the layer is large (a
full-bleed PNG is a performance problem) or when it must respond to viewport
size. Always label it as an approximation.

**3. Reproduce it in the browser.** `@paper-design/shaders-react` is a public
package, so the effect can in principle run on the WordPress front end. Real
option, real cost: a React dependency, a bundle, and a thing that breaks when the
package majors. NibWP does not do this for you and no ability in this skill
automates it. Offer it when the user explicitly wants the motion; never assume it.

**4. Drop it.** Record it in `warnings`, tell the user, move on. A dropped effect
the user knows about beats a bad imitation they discover in a client review.

### Never

Do not silently sample a shader's average color into a flat `background` and
report a clean conversion. That is the one outcome that destroys trust, because
the page looks finished and is wrong.

## 3. Getting images in

Every image the agent exports from Paper lives on the designer's machine. A page
that references it works on exactly one computer. So:

```
export from Paper → nibwp/paper-manage { action: "attach_asset" } → image_url
```

`attach_asset` sideloads into the WordPress media library and links the
attachment to the design record. Only then does the NDO get a usable
`image_url`.

Order matters. Attach assets **before** `convert`, so the NDO carries real media
URLs rather than placeholders you then have to chase through builder markup.

### How the NDO carries an image

Set both keys when a fill is an image:

- `image_ref` — Paper's own reference for the image. Keep it: it is how you
  deduplicate.
- `image_url` — the WordPress attachment URL returned by `attach_asset`.

With `image_url` set, the normalizer also writes `background-image`,
`background-size`, `background-position: center` and
`background-repeat: no-repeat` into `style`. `background-size` is `contain` when
the source fill scales to fit and `cover` otherwise — that is the only distinction
the shared code makes, so a Paper fill mode with no cover/contain analogue needs a
`warnings` entry.

### Deduplicate by reference

A design that uses one logo in six places should produce **one** attachment.
Key your uploads on `image_ref` and reuse the returned URL. `list_assets` shows
what a record already holds, so check before exporting again.

### Alt text

An image fill carries no alt text in any design tool. Derive a sensible default
from the layer name — a layer called `Hero/Product shot` is better alt text than
nothing — and flag decorative backgrounds as empty-alt deliberately rather than by
omission. If `seo-pro` is active, let it do this pass; otherwise do it yourself.

## 4. Fonts

The NDO records `font_family` on text nodes and `style['font-family']`. It does
**not** ship the font. A Paper design using a font the WordPress site does not
load will render in a fallback and the page will look wrong in a way that is easy
to misdiagnose as a conversion bug.

So: collect the set of families used, check what the theme actually loads, and
report the gap as a `warnings` entry naming each missing family. Offer the
options — enqueue a webfont, swap to the closest family the theme has, or
self-host — but do not pick one silently.

## 5. Vector artwork

Vector paths become `type: 'shape'` nodes, which the emitters render as a plain
box. A box is not an icon. For any vector that carries meaning — logos, icons,
illustrations — export it:

- **SVG** for flat, single-color or few-color artwork. Smaller, scales, can be
  recolored with `currentColor`.
- **PNG at 2×** for anything with gradients, effects or shader fills.

Then attach it as an asset like any other image. A `shape` node left
unexported is a silent hole in the page, so every one you skip belongs in
`warnings`.
