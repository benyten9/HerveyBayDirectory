# NDO → Gutenberg core blocks

The only full builder emitter that ships in the integration, and therefore the
universal fallback. `NIBWP_Figma_Gutenberg` in
`includes/integrations/figma/class-figma-gutenberg.php`. Paper reuses it
unchanged.

Use it when no builder skill is active, when the site runs core block editing, or
when the user wants output that survives a theme change.

## What it emits

Two block types. That is the whole vocabulary.

| NDO node | Block |
|---|---|
| `type: 'text'` with `tag` matching `h1`–`h6` | `core/heading` with `level` set, the heading element carrying the inline style |
| `type: 'text'` otherwise | `core/paragraph` |
| everything else — `section`, `container`, `shape`, `component`, `component_instance` | `core/group` with `tagName: 'div'` |

The root group also gets the class `nibwp-figma-root`. That class name is shared
with the Figma path; it is not a bug and nothing keys off a Paper-specific name.

### Layout handling

A node whose `layout.display` is `flex` becomes a group with block-editor flex
layout attributes:

- `type: 'flex'`
- `orientation`: `vertical` when `flex-direction` is `column`, otherwise
  `horizontal`
- `flexWrap`: `wrap` or `nowrap`
- `justifyContent`, translated to the editor's own names: `center` stays
  `center`, `flex-end` becomes **`right`**, `space-between` stays, anything else
  becomes **`left`**

A node with **no** layout becomes a group with `layout: {type: 'constrained'}` —
a centered content column, which is usually what you want for a text block.

Everything in `layout` and `style` is also merged into one inline `style`
attribute on the rendered `<div>`, with `style` winning on a key collision.

## What it can represent

- Vertical and horizontal flex sections with gap, padding and alignment.
- A full heading hierarchy, with the level taken from the NDO's `tag`.
- Backgrounds: solid, gradient, image — as inline CSS.
- Borders, radii, shadows, opacity.
- Arbitrary nesting depth.

## What it cannot

Be straight with the user about these.

- **`align-items` is not a block attribute here.** It survives only in the inline
  style. The editor's own controls will not show it, so a user who changes
  alignment in the sidebar may see it fight the inline value.
- **No `gap` or `padding` as block attributes.** Also inline-only. Same
  consequence: correct rendering, limited editability.
- **No image block.** An image fill becomes a `background-image` on a group, never
  a `core/image`. So no alt text, no `srcset`, no lazy loading, no media-library
  link. For a content image that matters — a product shot, a team photo — either
  accept the loss or restructure that node in the NDO so the builder step can
  place a real image block.
- **No columns block.** A horizontal flex row is a flex group, not
  `core/columns`, so the editor's column controls do not apply.
- **No buttons, no lists, no spacer, no cover.** A button in the design becomes a
  group containing a paragraph. It will look right and it will not be a button.
  Links in particular are lost — the NDO has no href key at all.
- **No reusable blocks or patterns.** `component` and `component_instance` both
  render as plain groups. There is no deduplication; six instances emit six
  copies. Real reuse needs a separate pass through the editor's own pattern
  creation.
- **No responsive behaviour.** Inline styles are inline styles. See
  `../core/responsive.md`.
- **No shader anything.** `../core/assets.md`.

## Inline styles: say the trade-off out loud

This emitter produces heavy inline CSS. That is deliberate — it is what makes the
output pixel-faithful without a stylesheet — but it means:

- the page is faithful and awkward to edit;
- tokens referenced as `var(--token, fallback)` do work inline, so use them
  (`../core/tokens.md`) and the page becomes a lot more maintainable for one extra
  step at planning time;
- a user who restyles in the editor is fighting the inline value.

If the user's priority is editability over fidelity, a builder skill's own
`html-to-*` ability will produce more native markup than this will. Say so and let
them choose.

## Calling it

```
nibwp/paper-manage { action: "preview_convert", builder: "gutenberg" }   // read the warnings
nibwp/paper-manage { action: "convert",         builder: "gutenberg" }   // persists a draft
```

Then `sync_tokens`, which for Gutenberg means `theme.json`
(`globals-sync.md`), and `audit`.
