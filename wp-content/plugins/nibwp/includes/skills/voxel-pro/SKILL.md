# Voxel Pro — building Voxel templates

A Voxel site is made of templates: a preview card that renders once per
listing, a single-post layout, an archive, a header, a search page, a style
kit. Each one is an Elementor document, and each one is assigned to its job in
Voxel's configuration rather than by anything stored on the template itself.

Three things make these documents different from ordinary Elementor pages, and
all three are places where a page that looks built turns out not to work:

1. **Voxel's own widgets** — `ts-search-form`, `ts-post-feed`, `ts-map`,
   `ts-advanced-list` and the rest. They do things no ordinary widget can.
2. **Dynamic tags** — `@tags()@post(:title)@endtags()`. A card without them
   renders the same text for every listing.
3. **The search wiring** — which lives in post meta, is stored as positions,
   and is invisible in the element tree.

You compose the document. `nibwp/voxel-pro-build` checks it against this site
and writes it.

## The routine

1. `nibwp/voxel-info` — post type keys, which modules are on. Everything you
   name has to exist here.
2. `nibwp/design-direction` — how this site should look, before you decide
   anything visual.
3. `nibwp/skill-preflight { skill_id: "voxel-pro" }` — mints the token the
   build needs.
4. `nibwp/load-skill-playbook { skill_id: "voxel-pro", element_type: "<kind>" }`
   — this file plus the checklist for what you are building.
5. `nibwp/voxel-pro-catalog` — widget names and required settings, and
   `{ topic: "widget", widget: "…" }` for one widget's full control list, read
   live from this install.
6. `nibwp/voxel-pro-build { dry_run: true }` — fix every `failed[]` item, read
   the warnings.
7. `nibwp/voxel-pro-build { dry_run: false }` — write it, and assign it.

To change something that already exists, use `nibwp/voxel-pro-refine` instead
of rebuilding. Call it with no operations first: it returns the outline with
the element ids your operations will name.

## Element trees

An element is `{id, elType, settings, elements}`. `elType` is `container` or
`widget`; a widget also has `widgetType`. Ids are seven hex characters and are
minted for you — if you supply one that is not valid hex it will be replaced,
and the response tells you what it became under `renamed_ids`.

Ordinary Elementor widgets are welcome. Use Voxel's where Voxel owns the
behavior: search, feeds, maps, submission forms, galleries bound to fields,
work hours, review stats, the actions row on a card.

Any element accepts four extras that are pure Voxel:

| Key | What it does |
|---|---|
| `_voxel_visibility_behavior` + `_voxel_visibility_rules` | Show or hide this element under conditions — `[[{"type":"post:is_verified"}]]`. Works on repeater rows too. |
| `_voxel_loop` | Repeat this element once per entry in a list: `"@tags()@post(gallery)@endtags()"`. |
| `_voxel_dynamic_css` | Raw CSS for this element, dynamic tags allowed. |
| `_voxel_dynamic_class` | Class names, dynamic tags allowed. |

## Dynamic tags

A value carrying a tag starts with `@tags()` and ends with `@endtags()`. In
between: `@post(property.sub)` with optional `.modifier(arg)` calls.

```
@tags()@post(:title)@endtags()
@tags()@post(_thumbnail_id.id)@endtags()
@tags()@post(location.address)@endtags()
@tags()@post(:reviews.average).fallback(0)@endtags()
@tags()@post(work_hours.status_label)@endtags()
```

Properties come in two flavours: aliases for the WordPress-level things
(`:title`, `:url`, `:content`, `:status`, `:reviews`) and the post type's own
field keys, which differ per site — read them from
`nibwp/voxel-pro-catalog { post_type: "…" }`.

**A property Voxel does not recognize renders as an empty string, not as an
error.** A misspelled field key produces a blank heading on every listing and
nothing anywhere says so. The build ability checks every property name against
the group's vocabulary and refuses the document if one does not exist — but
only because guessing here is otherwise invisible. Read the field keys first.

`nibwp/voxel-render { action: "catalog" }` is the full vocabulary, and
`{ action: "render", content, post_id }` evaluates one expression against a
real listing if you want to see what it produces before committing to it.

## Search pages, and the trap in them

A search form does not know about its feed through the element tree. The
connection lives in the post meta `_voxel_page_settings`, and each side is
stored as a **position** — `"1.2"` meaning the third child of the second
top-level element.

This means the connection breaks whenever anything moves. On the demo content
that ships with Voxel, the home page's stored connections have drifted so far
that they resolve to a heading, or to nothing at all.

So: **never write those positions yourself.** Leave `relations` at its default
of `"auto"` and the build computes them from the tree it is about to write,
pairing each feed and map with the search form nearest to it in the layout. If
a page needs a pairing that is not the obvious one, name the two elements by
id — `{feedToSearch: [{left: <search id>, right: <feed id>}]}` — and the paths
are still computed rather than taken from you. Refining a template recomputes
them too, which is the point: inserting one element above a search form shifts
every position below it.

A feed sourced from a search form with no search form on the page is refused.
That is a page that renders an empty list forever with no error.

## Assigning

Building a template does not put it to work. Assign it:

- `{post_type: "places", pt_slot: "card"}` — becomes the preview card for that
  post type, replacing whatever was there.
- `{post_type: "places", pt_slot: "card", mode: "custom", label: "Map popup"}`
  — becomes a named alternate, chosen per feed with
  `ts_card_template__places: <id>`. Prefer this when the user wants a variant
  rather than a replacement.
- `{slot: "header"}` and the other site slots — see
  `nibwp/voxel-pro-catalog { topic: "slots" }`.

Style kits (`kit_popups`, `kit_timeline`) restyle every popup or the whole
timeline across the site, not just one page. They need `confirm_kit: true`,
and the user should know what they are agreeing to before you pass it.

Whatever you replace comes back in the response so it can be put back.

## Kinds

| Kind | What it is | Post type |
|---|---|---|
| `card` | Renders once per search result | required |
| `single-post` | A listing's own page | required |
| `archive` | The post type's archive | required |
| `single-term` | A taxonomy term's page | — |
| `header`, `footer` | Site chrome | — |
| `search-page` | An ordinary page carrying a search form and feed | — |
| `style-kit-popup`, `style-kit-timeline` | One kit widget, nothing else | — |
| `page` | Anything else | — |

## What goes wrong

- **A card with no dynamic tags.** It validates and it is still wrong: every
  listing renders identical text. Bind the title, the image, the address.
- **Ten static blocks where a feed belongs.** If the content is listings, a
  `ts-post-feed` with a card template stays right as the content changes.
- **Widget names from memory.** They are read from this install for a reason;
  Voxel renames things between versions.
- **Writing `_elementor_data` or `_voxel_page_settings` through a generic
  post-meta ability.** It skips every check here, and the wiring will be wrong.
- **Assigning a style kit to make one popup look different.** It changes all
  of them.

Verified against Voxel 1.7.8.6. Voxel publishes no stable API; if an ability
starts refusing widgets that plainly exist, check the theme version first.
