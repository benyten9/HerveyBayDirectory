# Components and dynamic data

## What the NDO can and cannot do here

The NDO has two component types: `component` for a definition and
`component_instance` for a use. Set them accurately.

Then be clear about what that buys you: **nothing, inside the integration.** Both
`NIBWP_Figma_Html` and `NIBWP_Figma_Gutenberg` render `component` and
`component_instance` as plain boxes, identical to a `container`. There is no
deduplication pass. Six instances emit six copies of the markup.

The types are a **signal** — for your plan, and for the builder adapter you hand
the HTML to. Real reuse happens in the builder, on your instruction. If you set the
types and do nothing else, you get six copies and a page that is painful to
maintain.

So the work in this file is: find the repetition, decide what it should become, and
ask the builder for it explicitly.

## Finding repetition

Walk the tree looking for sibling subtrees with the same **shape**: same depth,
same child types in the same order, same layout keys. Content differs; structure
does not.

Three signals that a group of siblings is really one component:

1. **Structural identity.** Three subtrees each of an image, an `h3` and a `p`, in
   that order, with the same gap. That is a card.
2. **A layer name that repeats.** `Card`, `Card`, `Card`, or `Feature 1`,
   `Feature 2`, `Feature 3`. Designers name their repeats.
3. **Paper told you.** If the design uses real Paper components, the node kind says
   so. Read it from `figma_type` rather than inferring.

Near-matches matter too. Four cards where one has a badge is one component with a
variant, not two components. Note the difference, because the variant becomes a
prop or a condition in the builder.

## Then decide what it should become

Three outcomes, and the right one depends on where the content comes from.

**A reusable component with props.** The structure is fixed, the content is
authored per instance. Pricing tiers, feature cards, a team row. The builder gets
one definition and N uses.

**A query loop.** The content comes from the database. A blog grid, a product grid,
a testimonial set, a staff list. This is the outcome that turns a converted design
into a working website, and it is the one most often missed.

**Nothing.** Two similar-looking sections that are not actually the same thing.
Over-componentising is a real failure mode: a "component" used once, with every
value overridden, is worse than a plain section.

### How to tell a loop from a component

Ask what happens when the client adds a fourth one.

- If they would edit the page → component with props.
- If they would add a post, a product, a CPT entry → query loop.

If you cannot tell, ask. Guessing wrong here produces a site the client cannot
update, which is the specific failure that makes people distrust generated pages.

## Dynamic data: propose, do not implement

Flag candidates in the plan with the mapping you intend:

```
Testimonials section — 3 identical cards
  → query loop over a `testimonial` CPT
  → fields: quote (post_content), name (ACF text), role (ACF text), avatar (featured image)
  → needs: the CPT and 3 fields created first
```

Then let the user decide. Creating a CPT and four custom fields because a design
had three similar cards is a decision about their site's architecture, not a
conversion detail. Some users want it; some have a CMS already and want you to bind
to it.

## Asking the builder for real reuse

Per target, what to request in the handoff:

| Builder | Ask for |
|---|---|
| Etch | a component with props; variants become conditions, repeats become loops |
| Bricks | a global class set plus a template or component; a query loop for data-backed repeats |
| Elementor | a template or global widget; a loop grid for data-backed repeats |
| Gutenberg | a synced pattern — the integration's emitter cannot make one, so this is a separate editor-side step |

Gutenberg deserves the plain warning: **the core-block emitter has no pattern or
reusable-block support at all.** On a Gutenberg target, six cards are six copies
unless someone creates a synced pattern afterwards. Say so rather than implying
reuse you did not deliver.

## Header and footer are not page sections

If a repeated band appears across multiple artboards — a site header, a footer —
it is a **template part**, not a section of this page. Converting it into the page
means the client edits it once per page forever.

Flag it, and convert it separately into the builder's own header/footer template.
`../workflows/theme-builder.md`.

## What to report

In the plan, before converting:

- each component, with its instance count and which node is the definition;
- each variant, and what distinguishes it;
- each dynamic-data candidate, with the proposed source and fields;
- each template-part candidate;
- plainly, for a Gutenberg target: that reuse will be copies.
