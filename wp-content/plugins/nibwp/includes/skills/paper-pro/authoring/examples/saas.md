# Example — a shader hero, and saying so

An illustrative walkthrough, not a transcript. The lesson is **how to handle a
shader layer honestly**, on a site with no builder skill.

## The situation

A SaaS homepage in Paper. One artboard, 1440px wide. The hero has a full-bleed
animated gradient field made with a Paper shader. Below it: a three-up feature row,
a logo strip, a pricing table, a footer. The WordPress site runs a block theme and
has no builder plugin.

## What the agent does differently from the obvious thing

The obvious thing is to convert everything and mention the hero at the end. That
produces a hero that is a flat navy rectangle where the design had a living gradient,
discovered by the client in a review.

Instead, the fidelity decision comes **first**.

## 1. Read, and classify the hero immediately

Walking the tree, the hero's background layer is a shader. Before building anything,
the agent says:

> Your hero background is a Paper shader. There's no CSS that reproduces a
> per-pixel shader, so I have three options: export it as a PNG (looks identical,
> won't animate), approximate it with a CSS gradient (lighter, animates, won't match
> exactly), or load Paper's shader React package on the front end (matches and
> animates, adds a JS dependency). Which do you want?

Three real options, each with its cost named. Not a disclaimer buried in a report.

The user picks the PNG. That decision is recorded:

```php
['code' => 'shader_unsupported', 'node' => 'Hero/Gradient field',
 'detail' => 'Exported at 2x as PNG. Visually identical, static. User chose PNG over CSS approximation.']
```

## 2. Tokens before structure

Six colors after collapsing near-duplicates, five type slots. The most frequent
color is the near-white page background — so it becomes `--color-surface`, and the
button navy becomes `--color-primary`, against the frequency default.
`../core/tokens.md` on why frequency ranking gets this wrong.

## 3. Build the NDO

Noteworthy choices:

- The hero is `type: 'section'` with the exported PNG as `background-image`. The
  shader layer itself is **not** a node — it is the parent's background.
- The feature row is a horizontal flex container with `flex-wrap: wrap`, because
  three cards at 1440px become one column at 390px and wrap is the only responsive
  key the NDO has.
- The pricing table's three tiers are structurally identical: `component` once,
  `component_instance` three times. On a Gutenberg target that will still emit three
  copies, and the agent says so.
- The tag ramp needs fixing. The pricing figures are 48px, which the size heuristic
  calls `h1`. They become `p` with a large font size. The real `h1` is the hero
  headline, and there is exactly one.

## 4. Assets

The hero PNG, four logo SVGs from the logo strip, three small feature icons. Each
goes through `attach_asset`, deduplicated on `image_ref` — the same checkmark icon
appears nine times in the pricing table and uploads once.

## 5. Convert to core blocks

No builder skill, so `builder: "gutenberg"`. `preview_convert` first. The preview
shows the feature row's `align-items: center` only in the inline style, not as a
block attribute — expected, `../builders/gutenberg.md`, and worth one line in the
report.

Then `convert`. A draft.

## 6. Tokens into `theme.json`

`sync_tokens`. The site is new, so replacing the theme's default palette is the
right call rather than mapping onto it.

## 7. Verify

Playwright at 1440, 768 and 390, diffed against the Paper screenshot. At 1440 it
matches. At 768 the pricing table overflows — the tier cards have a min-width the
wrap cannot satisfy. Fixed in the NDO by relaxing it, then re-previewed.

## The report

> Draft is up: /?p=412
>
> Converted: hero, features, logos, pricing, footer. Core blocks, no builder plugin
> needed.
>
> **The hero gradient is a flattened PNG.** It's a Paper shader and CSS can't
> reproduce one. It looks identical and it won't animate. Say the word if you want
> the CSS approximation or the React package instead.
>
> Two things to know: the three pricing tiers are three copies, not one reusable
> block — core blocks have no component system, so editing one won't update the
> others. And "Söhne" isn't loaded by your theme, so it's falling back to the system
> sans. Want me to enqueue it?
>
> Spacing and alignment are inline CSS, so the editor's own controls won't show
> them. That's the trade for pixel fidelity without a builder.

## What this example teaches

- Classify fidelity **before** converting, and give the user the real options.
- Frequency ranking names the background "primary". Override it.
- Fix the tag ramp in the NDO; a 48px price is not an `h1`.
- On a Gutenberg target, say that reuse means copies.
- A missing font is a conversion warning, not a conversion bug.
