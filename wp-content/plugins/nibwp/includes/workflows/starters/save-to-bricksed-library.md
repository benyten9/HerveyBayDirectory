# Save a Component to the Bricksed Library

Using **NibWP**, the **Bricksed Pro** skill and the **Bricks Pro** skill, take a section the user has built in Bricks — or is about to build — and store it in their Bricksed library so it can be reused on any site. A component that goes into a library is going to a second site, so it is checked for that, not just for how it is built.

## When to use
- "Save this hero to my Bricksed library."
- "Add the pricing section on the home page to the library under Pricing."
- A section was just built with Bricks Pro and the user wants to keep it.

## Principles
- **A library item is not a snapshot.** Every value that would differ between two uses — copy, links, images, counts, variant switches — becomes a **Bricks component property with a default**. A section with none has to be edited to be reused, and an edited copy is a different component.
- **Nothing true of this site alone gets stored.** No media-library attachment ids, no internal links to post ids, no template or menu ids, no `wp-content` URLs, and every global class and Bricks component the elements use travels with them.
- **Copy from the page, don't retype it.** A section already built is saved with `source: { post_id, element_id }`, which takes its classes and components with it.
- **Dry run first, every time.** The user sees every warning before anything is written.
- **Find the folder, don't invent it.** Read the tree first and file the component where similar ones already live.
- **Say where it lives.** Bricksed keeps its library inside its own plugin folder, and a plugin update can replace that folder. Tell the user, every time.

## Process
1. **Preflight.** Confirm **Bricksed** and **Bricks** are active (`nibwp/bricksed-manage`, `action: list` answers `bricksed_inactive` if not) and whether **ACSS** is present. Resolve the BEM brand prefix once (`nibwp/skill-preflight`, `skill_id: bricksed-pro`).
2. **Read the tree.** `nibwp/bricksed-manage` with `action: list`, or `search` with a query. Choose a `type` (`layout`, `element`, `component`, `template`) and a `category` from folders that exist. Say so if you are proposing a new category.
3. **Get the content.** Built on a page already: read the page's Bricks tree and take the section's root element id — then pass `source: { post_id, element_id }`. Not built yet: build it through the Bricks Pro skill (`nibwp/bricks-pro-html-to-component`) first, on the page or a template, then use `source`. Pass `block` as Bricks data only when you already hold it.
4. **Make it reusable.** The part that repeats or varies should be a Bricks component, and what changes between uses its properties, each with a `default`. If the section has none, say so and offer to make one before saving.
5. **Describe it.** Give it a clear `name`, a one-sentence `description`, and at least three `tags` — the validator needs one, discoverability needs more. If it reads custom fields, name them in the description.
6. **Dry run.** `nibwp/bricksed-pro-save-component` with `dry_run: true`. A **failed** rule blocks the save — fix it. A **warning** does not — show every one to the user and decide together.
7. **Save.** The same call with `dry_run: false`. Report the id Bricksed returns, and pass on the storage warning word for word.

## Rules
**Do**
- Show the dry-run warnings before the real save.
- Reuse an existing category; name it when you invent one.
- Save built sections with `source`, so their classes and components come along.
- Expose copy, links, images and variant switches as component properties, each with a default.
- Pass on the storage warning every time.

**Don't**
- Save a component carrying an attachment, post, template or menu id from this site.
- Leave an absolute `wp-content` URL in a setting or a class — use a property, or a CSS custom property with a fallback.
- Save elements whose global classes or Bricks components are not included.
- Write to the Bricksed data folder on disk, or call its classes directly. Only `nibwp/bricksed-manage` and the save ability.
- Soften or drop the storage warning.

## Validation
- Dry run returns `would_save: true` with every warning shown to the user.
- No `site_specific_id`, `site_specific_class` or `site_specific_url` finding.
- Every property has a `default`; the component declares at least the properties its copy and links need.
- The component appears when searched for by name and by a tag.

## Report
**Saved:** `<name>` → `<type> / <category>` (id `<id>`). **Contents:** N elements, N global classes, components with N properties, each with a default. **Warnings reviewed:** list. **Storage:** the warning, passed on — back up before updating Bricksed.
