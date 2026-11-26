---
name: bricksed-authoring
description: Use when saving a Bricks section or component into the Bricksed library, placing a stored one on a page, renaming or moving one between folders, browsing or auditing what is stored. Enforces that every saved component is portable (no attachment, post or template ids, no upload URLs, every global class and Bricks component included), exposes component properties with defaults, and passes the Bricks Pro build rules before anything is written.
---

# Bricksed Library

Bricksed is a library of Bricks components, layouts, elements and templates. This skill puts components into it, takes them back out as native Bricks elements, and keeps them worth storing.

**What it owns.** Anything where the library is named or implied: save, store, reuse, place, rename, move, delete, browse, audit. **What it does not own.** Building a section from scratch in Bricks is `bricks-pro`'s job (`nibwp/bricks-pro-html-to-component`). Build there, then save here once the user wants it kept.

You never write to disk, and you never write `_bricks_page_content_2` or the global classes option yourself. You call abilities; they call Bricksed's own routes under Bricksed's own permission and licence checks, and place components the way Bricksed's own panel does. If Bricksed refuses, tell the user what it said — it is usually a licence tier — and stop. Do not look for another way in.

## The tools

| Ability | Use it to |
|---|---|
| `nibwp/bricksed-manage` | `list`, `search`, `get`, `save`, `update`, `rename`, `move`, `duplicate`, `delete`, `folders`, `set_folders`, `pins`, `sync` |
| `nibwp/bricksed-pro-save-component` | Validate then save. Always `dry_run: true` first. |
| `nibwp/bricksed-pro-reuse-component` | Place a stored component on a page, with overrides. `dry_run: true` lists what can be overridden. |
| `nibwp/bricksed-pro-audit-library` | Check stored components against the rules below |

## The tree

Four top-level types, each a folder: `layout` (a section-sized arrangement), `element` (one small piece), `component` (a reusable unit with properties), `template` (a whole page). `category` is the sub-folder. `/` in a category becomes ` - `.

Before you propose where something goes, `list` or `search`. Put it in a folder that exists. Invent a new category only when nothing fits, and say you did.

Search results carry `pro` and `locked`. Do not offer a locked component — the user's Bricksed tier will refuse it.

Full folder rules: [references/folders.md](references/folders.md).

## Two shapes, one library

Bricksed stores what is saved from Bricks as Bricks data:

```json
{ "type": "block", "bricks": { "content": [ ... ], "globalClasses": [ ... ], "components": [ ... ] } }
```

Much of the official library is stored in a shared format instead, which Bricksed converts to Bricks elements whenever it hands an item to the builder. You never convert anything: `get` with `format: "bricks"` shows any item as the Bricks elements a placement puts on a page, and the reuse ability asks for exactly that.

## Saving

1. `list` the tree. Choose type and category.
2. Get the content. A section already built on a page: pass `source: { post_id, element_id }` — the root element's id from the page's Bricks tree — and the ability copies the element, its descendants, the global classes they use and the Bricks components they are instances of, the way the Bricksed panel copies. Otherwise pass `block` as Bricks data.
3. `nibwp/bricksed-pro-save-component` with `dry_run: true`. All of these are required: `name`, `type`, `category`, `brand`, `tags` (at least one, three or more to be findable), `description`, and `block` or `source`.
4. A **failed** rule blocks the save. Fix it and run again. A **warning** does not block — show every one to the user, then decide with them.
5. Run again with `dry_run: false`.

`brand` is the BEM prefix every global class starts with. It is the same value you resolved at preflight.

Every save returns a `warning`: Bricksed keeps its library inside its own plugin folder, and a plugin update can replace that folder. Pass it on to the user every time. Do not soften it.

## Properties are the point

A section with no properties is a screenshot. Saving one wastes the slot, because the next person has to edit it, and once they edit it they have a different section.

In Bricks, the reusable part is a **Bricks component**, and what varies is its **properties**. Go through the section and ask of every value: would this be the same on a different site, for a different client, in a different section? If not, it is a property.

Always properties: headings, body copy, button labels and links, image sources and alt text, icon choices, and any switch that selects a variant.

Never properties: class names, token references, structural tags, the BEM prefix.

The shape Bricks stores, from the Bricksed library:

```json
{
  "id": "mwx3at",
  "label": "Question",
  "type": "text",
  "default": "Motion & interaction design",
  "connections": { "je059w": ["text"] }
}
```

`label` is what the person editing sees. `connections` binds the property to settings of the component's own elements. An instance points at its component with `cid` and keeps its own values under `properties`, keyed by property id. **Give every property a `default`** — `""` when it is meant to start empty — or a fresh instance renders blank.

## Portability

Run on every save. What each rule means and how to fix it: [references/portability.md](references/portability.md).

`site_specific_id` · `site_specific_class` · `site_specific_url` — these **block**.
`site_field_binding` · `no_properties` · `property_no_default` — these **warn**.

The one that surprises people: an image picked from the media library carries its attachment id, and that id means nothing on another site. Make the image a component property, or use an external URL.

## Reusing

`nibwp/bricksed-pro-reuse-component` takes an `id` and a target (`target_post_id`, or `mode: "new_page"` with a `new_page_title`). Run it with `dry_run: true` first: it lists every property (component, label, default) and every instance by its `ref` — `"Accordion Row #3"`, the component and its position.

- `properties: { "Question": "..." }` sets a value on **every instance** whose component declares that property. Name a property by its label or its id.
- `instances: [{ ref: "Accordion Row #3", properties: { ... } }]` sets values on one instance only. Use it for one card among five. Element ids change each time the shared format is converted, so name instances by `ref`.

`mode` is `append` (default), `replace_section` or `new_page`. `replace_section` replaces where **this same component** was placed on that page before, and appends if it was not there — there is no way to aim at some other section. `append` always adds another.

Placing follows Bricksed's own insert rules. Every element gets a fresh id. A global class the site already has — same id or same name — is used as the site has it and never overwritten; only missing classes are added. A Bricks component the site already has is kept with its edits; only missing ones are added. Overrides go on this placement's instances: the library, the site's classes and the site's component definitions are not changed.

Reuse places what the library holds, as the Bricksed panel would, so it is not held to the authoring rules a save is. It refuses only what cannot work: Bricks not active, a component built with Bricks components on Bricks older than 2.0, an element this site does not have, or a broken tree.

A page built in the WordPress editor shows its own content, not Bricks'. Placing on one is refused with `bricksed_page_uses_wordpress_editor`; ask the user, then run again with `switch_editor: true`, or place on a new page.

## Editing, renaming, moving

Bricksed rewrites a component in place: it never moves the file or changes the id. So:

- `update` changes the details. Leave `block` out to leave the content alone; send a `block` only to replace the content, and then send all of it — `get` first. A block with no Bricks elements is refused, because it would replace the component with fragments. A block you send is held to the portability rules, the same as a save.
- `rename` changes only the display name: a rename keeps the id.
- `update` and `rename` cannot change `type` or `category`. Use `move`.
- `move` makes a copy in the new type or category and then deletes the original, so the component gets a **new id** — use the one it returns. If the copy fails nothing is deleted; if the delete fails you get the copy plus a warning that the original is still there.
- `duplicate` makes a copy under a new name, optionally in another folder, and never touches the original.

`delete` is permanent. Name the component and its folder to the user and wait for a yes.

## Before you answer

Tell the user: where it went (type, category, id), what the dry run warned about, and the storage warning. After a placement: the page, the mode, the overrides, and which classes and components were added to the site. If something was refused, say which rule and what it needs — not "validation failed".
