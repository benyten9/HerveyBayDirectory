# Place an Etchedy Component on a Page

Using **NibWP** and the **Etchedy Pro** skill, pull a stored component out of the Etchedy library and place it on a page, with its text, links and images overridden for this placement. The stored component is never changed.

## When to use
- "Use the split hero from Etchedy on the home page."
- "Put my pricing table on a new landing page."
- Placing the same stored component on several pages with different content each time.

## Principles
- **Reuse beats regeneration.** A stored component is already validated and already consistent with the rest of the site. Generating a new one that looks similar drifts.
- **Overrides belong to the placement.** They are written onto this placement's instances. The library is not changed, and neither is the shared definition other pages use. To change the component for everyone, edit it — a different workflow.
- **Look before you place.** Read the component's property keys and instance names first, so overrides name things that exist.
- **Never offer what the tier refuses.** Locked components cannot be read.

## Process
1. **Find it.** `nibwp/etchedy-manage` with `action: search` and a query. Results carry `type`, `category`, `brand`, `pro` and `locked`. Do not offer a locked component.
2. **Read it.** `action: get` with the id. Note the property keys on the component definitions and the names of the instances in the tree.
3. **Decide the overrides.** From what the user asked for:
   - a value that should change **everywhere** it appears → `properties: { key: value }`;
   - a value on **one** instance among several (one card of five) → `instances: [{ name, properties }]`.
4. **Choose the target.** An existing page: `target_post_id` with `mode: append` (default), or `replace_section` to replace where this same component was placed on that page before. There is no way to aim at another section. A fresh page: `mode: new_page` with a `new_page_title` and no target id.
5. **Place it.** `nibwp/etchedy-pro-reuse-component`. An unknown property or instance name is refused with the list of real ones — use it. If it answers `etchedy_component_not_buildable`, the component does not pass the EtchWP build rules every placement is held to; nothing on the page changed. Much of the official library is in this position. Tell the user, and offer to rebuild it with EtchWP Pro and save it as their own component.
6. **Verify.** Open the page and confirm the component renders with the overridden content, then re-read the library entry and confirm it is unchanged.

## Rules
**Do**
- Search before you place, and read the component before you override it.
- Use `instances` for one item among several; `properties` sets a value everywhere.
- Tell the user that a component is placed once per page.

**Don't**
- Place a locked component, or retry one the tier refused.
- Change the stored component as a side effect — overrides apply to the copy only.
- Place the same component twice on one page expecting two placements: the second **replaces** the first.
- Guess a component id, a property key or an instance name.

## Validation
- The component renders on the target page with the overridden content.
- The library entry is byte-for-byte unchanged after placement.
- No `etchedy_unknown_property` or `etchedy_unknown_instance` outstanding.

## Report
**Placed:** `<name>` on `<page or new page>` (mode `<mode>`). **Overrides:** list, global and per-instance. **Library:** unchanged. **Note:** one placement per component per page — a second would replace this one.
