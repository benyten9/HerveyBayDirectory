# NDO → Elementor

## No Elementor emitter exists

The integration ships `NIBWP_Figma_Gutenberg` and `NIBWP_Figma_Html`. There is no
Elementor branch in it.

```
NDO ──NIBWP_Figma_Html──► neutral HTML ──► nibwp/elementor-pro-html-to-page
```

Note the name: Elementor's ability is **`html-to-page`**, not
`html-to-component`. It targets a whole page, which fits the usual Paper case —
one artboard, one page.

Unlike Etch and Bricks, Elementor's skill has no `figma-to-*` ability, so there is
no Figma-only path to be tempted by here.

## The handoff

1. `preview_convert` with `builder: "html"`.
2. `nibwp/load-skill-playbook { skill_id: "elementor-pro" }`. Read it. Elementor's
   payload format is the least forgiving of the four.
3. Check the live widget registry before synthesizing — `nibwp/elementor-pro-list-widgets`
   and `nibwp/elementor-pro-widget-schema`. Which widgets exist depends on the
   site's Elementor version, its Pro status and its active addons. **Never assume a
   widget is available.** Synthesizing a payload against a widget that is not
   installed produces a page that renders empty.
4. `nibwp/elementor-pro-html-to-page` with `dry_run: true`, fix every failure,
   then `dry_run: false`.
5. `sync_tokens`, then `audit`. `nibwp/elementor-pro-repair` exists for a page that
   persisted badly.

## What Elementor represents well

- **Containers with flex controls.** Modern Elementor containers map onto `layout`
  cleanly — direction, gap, padding, justify, align, wrap.
- **Global colors and global fonts**, via the Kit. The Paper palette and type ramp
  become Site Settings the user can edit. `globals-sync.md`.
- **Real widgets.** Heading, text editor, image, button. Where you can route an NDO
  node to a real widget instead of a styled container, do — a heading widget is
  editable in a way a div with inline CSS is not.
- **Responsive controls per breakpoint**, which is more than the NDO carries, so
  the breakpoint work in `../core/responsive.md` has somewhere real to land.
- **Templates and global widgets** for repeated subtrees.

## What it cannot take from the design

- **No links.** NDO has no href. Buttons arrive as text in boxes.
- **No overlap or z-order.** No position keys in the NDO at all.
- **No layered fills, single stroke only, no per-side borders.**
- **No animation or interaction**, though Elementor has plenty of both — they just
  do not travel with the design.
- **No shaders.** `../core/assets.md`.

## The hazard specific to Elementor

Elementor stores page structure as **JSON inside post meta**, and WordPress
slashes meta on write. Writing that meta yourself — or writing it without the
slashing the ability performs — is a known way to produce a page that loads blank
or loses its structure silently.

So: **never write `_elementor_data` directly.** Always go through
`nibwp/elementor-pro-html-to-page`. This is not a style preference; it is the
difference between a working page and a support ticket.

Two more Elementor-specific notes:

- **Elementor caches rendered CSS per page.** After a successful convert, the page
  may need its CSS regenerated before it looks right. The ability handles this;
  a manual write does not.
- **Check whether the site uses containers or the legacy section/column model.**
  The registry call tells you. A container payload on a flexbox-disabled site does
  not work.

Never `clamp()` for font-size. One builder per output —
`../core/composition.md`.
