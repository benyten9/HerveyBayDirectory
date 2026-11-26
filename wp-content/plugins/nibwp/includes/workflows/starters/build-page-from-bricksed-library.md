# Build a Page from the Bricksed Library

Using **NibWP**, the **Bricksed Pro** skill and the **Bricks Pro** skill, assemble a Bricks page from components already stored in the user's Bricksed library, overriding their content for this page. Where the library has no match, build the missing section once, save it to the library, and reuse it.

## When to use
- "Build the services page from my Bricksed components."
- "Put together a landing page: hero, features, pricing, FAQ, CTA — use what is in the library."
- Producing several pages that must look like one site.

## Principles
- **Library first, generation second.** Stored components are built and consistent. A section generated from scratch drifts from them; generate only what is genuinely missing, and then store it.
- **The page is a plan, then a build.** Agree the section list before placing anything.
- **Content is overrides, not edits.** Each placement carries its own text, links and images; the library and the site's components stay untouched.
- **Shared classes stay shared.** A class the site already has is used as it is; placing never restyles another page.

## Process
1. **Preflight.** Confirm Bricksed and Bricks are active, and whether ACSS is. Resolve the brand prefix.
2. **Plan the sections.** Write the ordered list the page needs and get the user's approval: for each, its purpose and the content that goes in it.
3. **Match each section to the library.** `nibwp/bricksed-manage` `action: search` per section. Skip anything `locked`. Choose one component per section; note any section with no good match.
4. **Fill the gaps.** For a section with no match, build it through the Bricks Pro skill, then save it with the **save to Bricksed library** workflow so the next page can reuse it. Do not place an unsaved one-off and move on.
5. **Read before overriding.** `nibwp/bricksed-pro-reuse-component` with `dry_run: true` for each chosen component; note its property labels and instance refs.
6. **Place in order.** `nibwp/bricksed-pro-reuse-component` for each section — the first with `mode: new_page` (or an existing `target_post_id`), the rest `append` to the page it created, in order. Overrides: `properties` for a value on every instance, `instances` with a `ref` for one item among several.
7. **Check the page.** Open it in Bricks and on the front end. One H1, logical heading order, every image has alt text, links go somewhere, it holds at mobile, tablet and desktop. Fix what fails by re-placing with different overrides (`replace_section`), or by editing the component for the library if the fault is in the component itself.
8. **Hand over a draft.** New pages are drafts. Tell the user where to review.

## Rules
**Do**
- Agree the section list before placing anything.
- Prefer a stored component to a generated one; store what you generate.
- Dry-run each component before overriding it.
- Keep new pages as drafts until the user signs off.

**Don't**
- Place a locked component.
- Change a stored component to fit one page — override at placement, or edit it deliberately as its own step.
- Hand-write Bricks meta or global classes to make a section fit.
- Leave a generated section unsaved when it is clearly reusable.

## Validation
- Every section on the page came from a stored component or was built and then saved.
- Library entries and the site's existing classes and components are unchanged by the page build.
- One H1, alt text on every image, no dead links, no horizontal scroll at any breakpoint.
- The page is a draft.

## Report
**Built:** `<page title>` (draft: `<url>`). **Sections:** ordered list, each with the component used. **Generated and saved:** list of new library entries. **Added to the site:** global classes and Bricks components. **Next:** review the draft, then publish.
