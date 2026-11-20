# Build a Page from the Etchedy Library

Using **NibWP**, the **Etchedy Pro** skill and the **EtchWP Pro** skill, assemble a page from components already stored in the user's Etchedy library, overriding their content for this page. Where the library has no match, build the missing section once, save it to the library, and reuse it.

## When to use
- "Build the services page from my Etchedy components."
- "Put together a landing page: hero, features, pricing, FAQ, CTA — use what is in the library."
- Producing several pages that must look like one site.

## Principles
- **Library first, generation second.** Stored components are validated and consistent. A section generated from scratch drifts from them; generate only what is genuinely missing, and then store it.
- **The page is a plan, then a build.** Agree the section list before placing anything.
- **Content is overrides, not edits.** Each placement carries its own text, links and images; the library stays untouched.
- **One placement per component per page.** A component placed twice on a page replaces itself. Two sections that need the same layout need two stored components.

## Process
1. **Preflight.** Confirm Etchedy, Etch and ACSS are active. Resolve the brand prefix.
2. **Plan the sections.** Write the ordered list the page needs and get the user's approval: for each, its purpose and the content that goes in it.
3. **Match each section to the library.** `nibwp/etchedy-manage` `action: search` per section. Skip anything `locked`. Choose one component per section; note any section with no good match.
4. **Fill the gaps.** For a section with no match, build it through the EtchWP Pro skill, then save it with the **save to Etchedy library** workflow so the next page can reuse it. Do not place an unsaved one-off and move on.
5. **Read before overriding.** `action: get` each chosen component; note its property keys and instance names.
6. **Place in order.** `nibwp/etchedy-pro-reuse-component` for each section — the first with `mode: new_page` (or an existing `target_post_id`), the rest `append` to the page it created. Overrides: `properties` for a value on every instance, `instances` for one item among several. A section answering `etchedy_component_not_buildable` does not pass the EtchWP build rules every placement is held to, and nothing was placed: treat it like a gap in step 4 — rebuild it with EtchWP Pro, save it, then place your saved version.
7. **Check the page.** Open it. One H1, logical heading order, every image has alt text, links go somewhere, it holds at mobile, tablet and desktop. Fix what fails by re-placing with different overrides, or by editing the component for the library if the fault is in the component itself.
8. **Hand over a draft.** New pages are drafts. Tell the user where to review.

## Rules
**Do**
- Agree the section list before placing anything.
- Prefer a stored component to a generated one; store what you generate.
- Read each component's properties before overriding.
- Keep new pages as drafts until the user signs off.

**Don't**
- Place the same component twice on one page expecting two sections.
- Place a locked component.
- Change a stored component to fit one page — override at placement, or edit it deliberately as its own step.
- Leave a generated section unsaved when it is clearly reusable.

## Validation
- Every section on the page came from a stored component or was built and then saved.
- Library entries are unchanged by the page build.
- One H1, alt text on every image, no dead links, no horizontal scroll at any breakpoint.
- The page is a draft.

## Report
**Built:** `<page title>` (draft: `<url>`). **Sections:** ordered list, each with the component used. **Generated and saved:** list of new library entries. **Gaps / notes:** anything that needed a second component. **Next:** review the draft, then publish.
