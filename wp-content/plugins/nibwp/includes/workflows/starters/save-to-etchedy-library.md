# Save a Component to the Etchedy Library

Using **NibWP**, the **Etchedy Pro** skill and the **EtchWP Pro** skill, take a component the user has built — or is about to build — and store it in their Etchedy library so it can be reused on any site. A component that goes into a library is going to a second site, so it is checked for that, not just for how it is built.

## When to use
- "Save this hero to my Etchedy library."
- "Add this to the library under Hero."
- A component was just built with EtchWP Pro and the user wants to keep it.

## Principles
- **A library item is not a snapshot.** Every value that would differ between two uses — copy, links, images, counts, variant switches — becomes a **property with a default**. A component with none has to be edited to be reused, and an edited copy is a different component.
- **Nothing true of this site alone gets stored.** No post ids, no `wp_block` refs that point at nothing, no `wp-content` URLs, no field bindings without a property behind them.
- **Dry run first, every time.** The user sees every warning before anything is written.
- **Find the folder, don't invent it.** Read the tree first and file the component where similar ones already live.
- **Say where it lives.** Etchedy keeps its library inside its own plugin folder, and a plugin update can replace that folder. Tell the user, every time.

## Process
1. **Preflight.** Confirm **Etchedy** and **Etch** are active (`nibwp/etchedy-manage`, `action: list` answers `etchedy_inactive` if not) and that **ACSS** is present. Resolve the BEM brand prefix once (`nibwp/skill-preflight`, `skill_id: etchedy-pro`).
2. **Read the tree.** `nibwp/etchedy-manage` with `action: list`, or `search` with a query. Choose a `type` (`layout`, `element`, `component`, `template`) and a `category` from folders that exist. Say so if you are proposing a new category.
3. **Get the payload.** Either build it through the EtchWP Pro skill (`nibwp/etchwp-pro-html-to-component`) or take the one just built. It must carry `gutenbergBlock`, `components` and `styles`.
4. **Make it reusable.** Walk the tree and lift every value that would change between uses into a property on the component definition: `{ name, key, keyTouched, type: { primitive }, default }`. Instances keep their own values under `attrs.attributes[key]` and point at the definition with `attrs.ref`, a key in the payload's own `components`. Keep that shape.
5. **Describe it.** Give it a clear `name`, a one-sentence `description`, and at least three `tags` — the validator needs one, discoverability needs more.
6. **Dry run.** `nibwp/etchedy-pro-save-component` with `dry_run: true`. A **failed** rule blocks the save — fix it. A **warning** does not — show every one to the user and decide together.
7. **Save.** The same call with `dry_run: false`. Report the id Etchedy returns, and pass on the storage warning word for word.

## Rules
**Do**
- Show the dry-run warnings before the real save.
- Reuse an existing category; name it when you invent one.
- Expose copy, links, images and variant switches as properties, each with a default.
- Pass on the storage warning every time.

**Don't**
- Save a component carrying a post id, a term id, or a `ref` with no matching definition.
- Leave an absolute `wp-content` URL in a style or an attribute — use a property and a CSS custom property with a fallback.
- Bind to a custom field without declaring a property with that key.
- Write to the Etchedy data folder on disk, or call its classes directly. Only `nibwp/etchedy-manage` and the save ability.
- Soften or drop the storage warning.

## Validation
- Dry run returns `would_save: true` with every warning shown to the user.
- No `site_specific_id`, `site_specific_url` or `unbound_field` finding.
- Every property has a `default`; the component declares at least the properties its copy and links need.
- The component appears when searched for by name and by a tag.

## Report
**Saved:** `<name>` → `<type> / <category>` (id `<id>`). **Properties:** N, each with a default. **Warnings reviewed:** list. **Storage:** the warning, passed on — back up before updating Etchedy.
