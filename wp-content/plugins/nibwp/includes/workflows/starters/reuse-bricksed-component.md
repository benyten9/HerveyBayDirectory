# Place a Bricksed Component on a Page

Using **NibWP** and the **Bricksed Pro** skill, pull a stored component out of the Bricksed library and place it on a page as native Bricks elements, with its text, links and images overridden for this placement. The stored component is never changed, and neither are the site's own classes and components.

## When to use
- "Use the editorial accordion from Bricksed on the FAQ page."
- "Put my pricing table on a new landing page."
- Placing the same stored component on several pages with different content each time.

## Principles
- **Reuse beats regeneration.** A stored component is already built and consistent with the rest of the library. Generating a new one that looks similar drifts.
- **Placed the way Bricksed places it.** Fresh element ids; a global class or Bricks component the site already has is used as it is and never overwritten; only missing ones are added.
- **Overrides belong to the placement.** They are written onto this placement's instances. The library and the site's component definitions are not changed. To change the component for everyone, edit it — a different workflow.
- **Look before you place.** A dry run lists the property labels and the instances, so overrides name things that exist.
- **Never offer what the tier refuses.** Locked components cannot be read.

## Process
1. **Find it.** `nibwp/bricksed-manage` with `action: search` and a query. Results carry `type`, `category`, `brand`, `pro` and `locked`. Do not offer a locked component.
2. **Read it.** `nibwp/bricksed-pro-reuse-component` with the `id` and `dry_run: true`. Note each property's label and default, and each instance's `ref` — `"Accordion Row #3"`.
3. **Decide the overrides.** From what the user asked for:
   - a value that should change **everywhere** it appears → `properties: { "Label": value }`;
   - a value on **one** instance among several (one row of five) → `instances: [{ ref, properties }]`.
4. **Choose the target.** An existing page: `target_post_id` with `mode: append` (default), or `replace_section` to replace where this same component was placed on that page before. There is no way to aim at another section. A fresh page: `mode: new_page` with a `new_page_title` and no target id.
5. **Place it.** The same ability without `dry_run`. An unknown property or instance is refused with the list of real ones — use it. A page built in the WordPress editor answers `bricksed_page_uses_wordpress_editor`: ask the user, then run again with `switch_editor: true`, or use a new page.
6. **Verify.** Open the page in Bricks (`edit_url`) and on the front end, and confirm the component renders with the overridden content. Note which classes and components were added to the site.

## Rules
**Do**
- Search before you place, and dry-run before you override.
- Use `instances` with a `ref` for one item among several; `properties` sets a value everywhere.
- Tell the user which global classes and components the placement added to the site.

**Don't**
- Place a locked component, or retry one the tier refused.
- Write `_bricks_page_content_2` or the global classes option by hand.
- Switch a WordPress-editor page to Bricks without the user's yes.
- Guess a component id, a property label or an instance.

## Validation
- The component renders on the target page with the overridden content, in the builder and on the front end.
- The library entry is unchanged after placement, and so are the site's existing classes and components.
- No `bricksed_unknown_property` or `bricksed_unknown_instance` outstanding.

## Report
**Placed:** `<name>` on `<page or new page>` (mode `<mode>`). **Overrides:** list, global and per-instance. **Added to the site:** global classes, Bricks components. **Library:** unchanged.
