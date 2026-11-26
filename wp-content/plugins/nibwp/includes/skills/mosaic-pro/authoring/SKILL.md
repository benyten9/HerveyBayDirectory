# Mosaic Pro — playbook

You are building in **Mosaic**, a theme builder with its own data layer. Read this before you write anything.

## The one thing that matters most

Mosaic **drops what it does not accept, without an error**. A `display: "middle"`, a
`fontWeight: "bold"`, a border written as a style string, a key a button does not
have — Mosaic saves the rest and renders the element without it. Nobody is told.

So every build has two checks, and you run both:

1. **Before**: `nibwp/mosaic-pro-convert` validates every element type, child, data
   key, state, breakpoint and style value. An error there is a value Mosaic would
   have thrown away.
2. **After**: every write returns `dropped` — what Mosaic did not keep, read back
   from Mosaic itself. An empty list is the only clean result.

## Where Mosaic content lives

A Mosaic **theme** holds everything: **masters** (the page shell: header, footer and a
slot), **templates** (what a URL renders; a page can have its own), **components**,
**universal classes**, **variables** and **breakpoints**. It renders only while that
theme is the live WordPress theme. `nibwp/mosaic-status` tells you which theme is live.

- **Never switch the site theme.** If nothing is live, build anyway and say plainly
  that visitors will not see it until the owner makes the theme live in wp-admin → Mosaic.
- A page gets **its own template**: `mosaic-pro-build { title }` creates the page and the
  template; `{ post_id }` gives an existing page one, or adds to the one it has.
- A header or footer for every page goes in the **master**
  (`document_type: "master"`, with `parent_id` or `relative_to` from `nibwp/mosaic-get-tree`).

## The pipeline

1. `nibwp/mosaic-status` — set up? live theme?
2. `nibwp/design-direction` — decide the look first.
3. `nibwp/mosaic-list-design` — the theme's classes and variables. Reuse before inventing.
4. `nibwp/mosaic-pro-convert` — `html`, `url`, `section_type` + `brief`, or your own `tree`.
5. `nibwp/mosaic-pro-build { dry_run: true }` — where it lands, what would be dropped.
6. `nibwp/mosaic-pro-build { dry_run: false }` — write it.
7. `nibwp/mosaic-pro-inspect` — read the result back; fix warnings with `nibwp/mosaic-pro-refine`.

## The tree

Nested nodes. Write the shorthand; the integration turns it into Mosaic's storage.

```json
{
  "type": "section", "name": "Hero", "tag": "header",
  "style": {
    "base":   { "padding": "120px 24px", "background": "#0b1220" },
    "tablet": { "padding": "88px 24px" },
    "mobile": { "padding": "64px 16px" }
  },
  "children": [
    { "type": "div", "name": "Inner",
      "style": { "max-width": "1200px", "margin": "0 auto", "display": "flex", "flex-direction": "column", "gap": "24px" },
      "children": [
        { "type": "text", "tag": "h1", "text": "Rome, without the queues",
          "style": { "base": { "font-size": "64px", "color": "#fff" }, "mobile": { "font-size": "40px" } } },
        { "type": "text", "tag": "p", "text": "Skip-the-line tours picked by locals." },
        { "type": "button", "url": "/tours/", "text": "Browse tours", "classes": ["btn-primary"] },
        { "type": "image", "image": "https://…/colosseum.jpg", "alt": "The Colosseum at sunset",
          "css": "border-radius: 24px; box-shadow: 0 30px 60px rgba(0,0,0,.4);" }
      ] }
  ]
}
```

| Shorthand | Becomes |
|---|---|
| `tag` | `data.tagName` (h1–h6, p, span, header, footer, nav, ol…) |
| `text` on text / button / list-item / link | the wysiwyg-text runs that hold the words |
| `style` | per state and breakpoint: `base`, `tablet`, `mobile`, `hover`, `focus`, `focus-visible`, and `"hover@mobile"` |
| `css` | CSS declarations Mosaic validates and keeps verbatim (base, default state). Inside `style.<key>` use `"css": "…"` for that breakpoint |
| `classes` | Mosaic universal classes **by name** |
| `cssClasses` | plain class names, only for CSS the site already has |
| `url`, `target`, `alt`, `image`, `svg` | the element's data |
| `data` | Mosaic's own data object, merged last — for keys with no shorthand |

CSS in `style` may be kebab or camel case. `padding`/`margin` shorthands and `gap`
are expanded; `background: <colour>` becomes `backgroundColor`; `font-weight: bold` → `700`.
Anything Mosaic stores in its own structure (border, border-radius, box-shadow,
transform, transition, filter, grid-template-columns/rows, background images and
gradients, object-fit, text-shadow) is written into `customDeclarations` automatically.
Values may use `clamp()`, `calc()` and `var(--…)`.

Full element list: [references/elements.md](references/elements.md). Style rules:
[references/styles.md](references/styles.md).

## Rules that are errors

- A **text** holds only inline runs (`wysiwyg-text`, `wysiwyg-link`, `wysiwyg-span`,
  `wysiwyg-linebreak`). Its words are never in its own data. Block elements go beside it.
- Inline runs live only inside a text, link or span.
- **Leaves** (image, icon, video, youtube, vimeo, maps, code) take no children.
- A **list** takes `list-item` children; widget parts sit directly under their widget.
- `template-internal`, `template-external`, `master-root`, `document`, `body`,
  `component-internal`, `component-root` are Mosaic's. Never author them.
- Enumerated style values must be Mosaic's (`fontWeight` is `"100"`…`"900"`,
  `textAlign` is `start|center|end|justify|left|right|inherit`, …).

## Make it great, not just valid

- **Classes over repetition.** A style on three elements is a class. Class rules in a
  `<style>` block become Mosaic classes automatically when converting HTML; for your own
  trees, create them with `classes: [{ name, style }]` in build. Names are
  lowercase-with-hyphens.
- **Components for anything reused**: a card, a CTA band, a footer. Build once with
  `component_name`, place with `{ "type": "component-instance/<id>" }`. Editing the
  component updates every instance.
- **Every desktop size has a phone value.** Font sizes ≥ 40px and section padding ≥ 96px
  without a `mobile` value are flagged. Grids stack on phones (blueprints do this).
- **One h1 per page**, no skipped heading levels, alt text on every meaningful image.
- **Accordion, tabs and navbar**: send them with no children; Mosaic builds its own
  working structure. Then set the words with `nibwp/mosaic-pro-refine`
  (`match: { text: "Lorem ipsum dolor sit amet?" }`).
- **Variables**: if `mosaic-list-design` shows a matching colour or size variable, use
  it — `{ "var": "<id>" }` as the value in `data.style`, or `var(--name)` in CSS.

## Screenshots and briefs

No markup to convert. Use `section_type` (`hero`, `features`, `cta`, `pricing`,
`testimonials`, `faq`, `stats`, `header`, `footer`, `section`) for a responsive
blueprint, then replace every `[bracketed]` placeholder and restyle it to the design
direction. Checklists per section: [references/checklists.md](references/checklists.md).

## Changing what exists

`nibwp/mosaic-pro-inspect { post_id }` → outline with node ids and problems.
`nibwp/mosaic-pro-refine { post_id, changes: [...] }` → each change by `node_id` or by
`match: { name, text, type, tag }` that hits exactly one element:

```json
{ "post_id": 42, "changes": [
  { "match": { "tag": "h1" }, "text": "Rome, minus the queues", "style": { "mobile": { "font-size": "36px" } } },
  { "match": { "text": "Browse tours" }, "url": "/tours/rome/" },
  { "match": { "name": "Stats" }, "remove": true }
] }
```

Style changes merge per breakpoint and state; `replace_style: true` replaces the whole style.

## After every write

- Read `dropped`. Each entry is a value Mosaic did not keep: fix it (usually: move it to `css`).
- If someone has that page open in the Mosaic editor, tell them to reload it.
- If `live` is false, say the page is built but not visible until the Mosaic theme is live.
