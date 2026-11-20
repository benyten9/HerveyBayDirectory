---
name: etchedy-authoring
description: Use when saving a component into the Etchedy library, reusing one on a page, renaming or moving one between folders, browsing or auditing what is stored. Enforces that every saved component is portable (no post ids, upload URLs or unbound field bindings from this site), declares properties with defaults, and passes the EtchWP build rules before anything is written.
---

# Etchedy Library

Etchedy is a library of Etch components, layouts, elements and templates. This skill puts components into it, takes them back out, and keeps them worth storing.

**What it owns.** Anything where the library is named or implied: save, store, reuse, rename, move, delete, browse, audit. **What it does not own.** Building a component from scratch onto a page is `etchwp-pro`'s job (`nibwp/etchwp-pro-html-to-component`). Build there, then save here once the user wants it kept.

You never write to disk. You call abilities; they call Etchedy's own routes under Etchedy's own permission and licence checks. If Etchedy refuses, tell the user what it said — it is usually a licence tier — and stop. Do not look for another way in.

## The tools

| Ability | Use it to |
|---|---|
| `nibwp/etchedy-manage` | `list`, `search`, `get`, `save`, `update`, `rename`, `move`, `duplicate`, `delete`, `folders`, `pins`, `sync` |
| `nibwp/etchedy-pro-save-component` | Validate then save. Always `dry_run: true` first. |
| `nibwp/etchedy-pro-reuse-component` | Place a stored component on a page, with overrides |
| `nibwp/etchedy-pro-audit-library` | Check stored components against the rules below |

## The tree

Four top-level types, each a folder: `layout` (a section-sized arrangement), `element` (one small piece), `component` (a reusable unit with properties), `template` (a whole page). `category` is the sub-folder. `/` in a category becomes ` - `.

Before you propose where something goes, `list` or `search`. Put it in a folder that exists. Invent a new category only when nothing fits, and say you did.

Search results carry `pro` and `locked`. Do not offer a locked component — the user's Etchedy tier will refuse it.

Full folder rules: [references/folders.md](references/folders.md).

## Saving

1. `list` the tree. Choose type and category.
2. Build or fetch the payload: `gutenbergBlock`, `components`, `styles`.
3. `nibwp/etchedy-pro-save-component` with `dry_run: true`. All of these are required: `name`, `type`, `category`, `brand`, `tags` (at least one, three or more to be findable), `description`, `block`.
4. A **failed** rule blocks the save. Fix it and run again. A **warning** does not block — show every one to the user, then decide with them.
5. Run again with `dry_run: false`.

`brand` is the BEM prefix every class starts with. It is the same value you resolved at preflight.

Every save returns a `warning`: Etchedy keeps its library inside its own plugin folder, and a plugin update can replace that folder. Pass it on to the user every time. Do not soften it.

## Properties are the point

A component with no properties is a screenshot. Saving one wastes the slot, because the next person has to edit it, and once they edit it they have a different component.

Go through the tree and ask of every value: would this be the same on a different site, for a different client, in a different section? If not, it is a property.

Always properties: headings, body copy, button labels and links, image sources and alt text, icon choices, and any switch that selects a variant.

Never properties: class names, token references, structural tags, the BEM prefix.

The shape Etchedy stores, from its own library:

```json
{
  "name": "Label",
  "key": "label",
  "keyTouched": false,
  "type": { "primitive": "string" },
  "default": "Get started"
}
```

`key` is what an instance binds by. `name` is the label in the editor. `type.primitive` is `string`, `number` or `boolean`; a select adds `selectOptionsString`. **Every property has a `default`** — every one in the official library does. Without one a fresh instance renders blank.

An instance keeps its own values under `attrs.attributes[key]`, and points at its definition with `attrs.ref`, which is a key in the payload's own `components` map. That is how a payload carries its definitions with it. Keep it that way.

A binding to a custom field — ACF, Meta Box, Pods, JetEngine — needs a property whose `key` matches the field name. The binding reads the field; the property tells the next site which field to supply. A binding with no property is refused.

## Portability

Five rules, run on every save. What each means and how to fix it: [references/portability.md](references/portability.md).

`site_specific_id` · `site_specific_url` · `unbound_field` — these **block**.
`no_properties` · `property_no_default` — these **warn**.

The one that surprises people: a `ref` is fine when it points at a component the payload defines, and a failure when it points at nothing. Never copy a `ref` or a post id off a page you read.

## Reusing

`nibwp/etchedy-pro-reuse-component` takes an `id` and a target (`target_post_id`, or `mode: "new_page"` with a `new_page_title`). `mode` is `append` (default), `replace_section` or `new_page`. `replace_section` replaces where **this same component** was placed on that page before, and appends if it was not there — there is no way to aim at some other section.

Overrides are written onto this placement's instances. The library is never modified, and neither is the shared definition: a component's definition is one post shared by every page that uses it, so changing it for one placement would change the others.

- `properties: { key: value }` sets a value on **every instance** in the placement whose component declares that key — added if the instance was relying on the default.
- `instances: [{ name, properties }]` sets values on one named instance only. Use it for one card among five. If two instances share a name, both change.

`get` the component first and show the user the property keys and instance names if they have not said what to change. An unknown key is refused with the list of real ones.

Every placement is held to the EtchWP build rules, with no exception. A stored component that does not pass them is refused with `etchedy_component_not_buildable` and the findings, and nothing on the page changes. Much of the official library predates those rules — its own class prefix, literal colours, `clamp()` type — so expect this for official components. Tell the user plainly, and offer to rebuild it with the EtchWP Pro skill and save the result as their own component.

A component is placed once per page: placing it again **replaces** the earlier placement. If the user wants two variants of one component on a page, tell them it cannot do that and offer to save the second as a separate component.

## Editing, renaming, moving

Etchedy rewrites a component in place: it never moves the file or changes the id. So:

- `update` changes the details. Leave `block` out to leave the content alone; send a `block` only to replace the content, and then send all of it — `get` first. A block with no `gutenbergBlock` tree and no component definitions is refused, because it would replace the component with fragments. A block you send is held to the portability rules, the same as a save.
- `rename` changes only the display name: a rename keeps the id.
- `update` and `rename` cannot change `type` or `category`. Use `move`.
- `move` makes a copy in the new type or category and then deletes the original, so the component gets a **new id** — use the one it returns. If the copy fails nothing is deleted; if the delete fails you get the copy plus a warning that the original is still there.
- `duplicate` makes a copy under a new name, optionally in another folder, and never touches the original.

`delete` is permanent. Name the component and its folder to the user and wait for a yes.

## Before you answer

Tell the user: where it went (type, category, id), what the dry run warned about, and the storage warning. If something was refused, say which rule and what it needs — not "validation failed".
