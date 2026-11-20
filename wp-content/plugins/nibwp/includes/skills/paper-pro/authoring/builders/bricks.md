# NDO → Bricks

## No Bricks emitter exists

Same as every builder except Gutenberg: the integration ships
`NIBWP_Figma_Gutenberg` and `NIBWP_Figma_Html` and nothing else. There is no
Bricks branch in the integration code.

```
NDO ──NIBWP_Figma_Html──► neutral HTML ──► nibwp/bricks-pro-html-to-component
```

`nibwp/bricks-pro-figma-to-component` also exists and is **not usable for Paper**
— it takes a Figma URL or file key and reads Figma itself. Paper has neither
(`../integrations/paper-mcp.md`). Use `html-to-component`.

## The handoff

1. `preview_convert` with `builder: "html"` → neutral HTML + token CSS.
2. `nibwp/load-skill-playbook { skill_id: "bricks-pro" }` — read Bricks' rules
   before synthesizing anything.
3. `nibwp/bricks-pro-html-to-component` with `dry_run: true`, fix every failure,
   then `dry_run: false`.
4. `sync_tokens`, then `audit`.

## What Bricks represents well

- **Nestable elements with real flex controls.** Bricks' container model lines up
  closely with what `layout` holds — direction, gap, padding, justify, align, wrap
  all have native controls, so they stop being inline CSS and become settings the
  user can edit.
- **Global classes.** The biggest win in the handoff. The `figma-`-prefixed class
  names the HTML emitter derives from Paper layer names are the natural seed for
  Bricks global classes, which turns per-element inline styles into a reusable
  system.
- **Global colors and typography**, so the Paper palette and type ramp become
  site-level settings. `globals-sync.md`.
- **Templates and components**, which is where a repeated Paper subtree belongs.
- **Query loops**, for the card grids that `../core/parser.md` tells you to flag as
  dynamic-data candidates.

## What it cannot take from the design

Mostly NDO limits rather than Bricks limits:

- **No links.** The NDO has no href key. Every button and nav item arrives as a
  styled box with text in it. You will set the links by hand or via a refine pass.
- **No overlap or z-order.** No position keys, and `bbox` carries width and height
  only. `../core/nodes.md` has the three ways out.
- **No layered fills, one stroke only, no per-side borders.**
- **No real image elements** unless you restructure the node — an image fill is a
  `background-image`, so no alt, no `srcset`.
- **No animation or interaction.** Bricks has interactions; the design does not
  travel with them.
- **No shaders.** `../core/assets.md`.

## Specific to Bricks

- Bricks stores its structure as post meta. **Do not write it directly.** Go
  through the ability so its validation and CSS regeneration run.
- Bricks regenerates CSS files from its own settings. A page built with heavy
  inline styles instead of global classes will render correctly and then look
  unmaintainable in the Bricks editor. Push for global classes during the handoff
  — it is the difference between a converted page and a usable one.
- Never `clamp()` for font-size.
- One builder per output. `../core/composition.md`.
