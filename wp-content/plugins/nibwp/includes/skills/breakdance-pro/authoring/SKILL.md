# Breakdance Pro — playbook

You are building on **Breakdance**. Read this before you write anything.

## The one thing that matters most

A Breakdance page is a **JSON node tree in post meta**. It is not `post_content`.
Writing HTML into the post body changes nothing a visitor sees. Every structural
change goes through the tree.

## Read before you write

Three calls, always, in this order:

1. `nibwp/breakdance-info` — is Breakdance active, and in which brand mode?
   Breakdance and Oxygen 6 are the same codebase; the post type slugs differ and
   this tells you the real ones.
2. `nibwp/breakdance-pro-elements` — the elements **this site** registers.
3. `nibwp/breakdance-pro-tokens` — the variables, selectors and presets it
   already uses.

Skipping these is the single biggest cause of a build that renders blank. The
element set is not fixed: it depends on the license, on which subplugins are
active, and on any third-party element packs.

## Element slugs

Slugs are namespaced:

```
EssentialElements\Heading
EssentialElements\Section
EssentialElements\Button
```

**In JSON the backslash must be doubled** — `"EssentialElements\\Heading"`. A
single backslash does not survive the round trip, and the result is not a
registered element. Breakdance does not error on an unknown element. It renders
nothing. The validator catches this specific mistake and names it, because the
generic "unknown element" message sends you looking in the wrong place.

## The payload shape

You hand over a flat list. Parentage is by `ref`, not by nesting:

```json
{
  "nodes": [
    {"ref": "hero",    "type": "EssentialElements\\Section", "parent": null,   "properties": {}},
    {"ref": "title",   "type": "EssentialElements\\Heading", "parent": "hero", "properties": {"content": {"text": "Hello", "tag": "h1"}}},
    {"ref": "sub",     "type": "EssentialElements\\Text",    "parent": "hero", "properties": {"content": {"text": "Something true"}}}
  ]
}
```

Order does not matter — a node may name a parent that appears later. Real numeric
IDs are allocated server-side, because you cannot know what a page already uses.

## Property paths

Every element declares its own controls. Ask for them:

```
nibwp/breakdance-pro-elements  action=get  slug=EssentialElements\\Heading  paths_only=true
```

Properties that the element does not declare are **dropped silently** by
Breakdance. The validator warns about them rather than blocking, because the
schema walk cannot see repeater indices or conditionally-registered controls —
but treat a warning here as probably your mistake.

## Tokens

If `has_token_layer` is true, use the site's variables. When you set a literal
that exactly matches one, the validator tells you which — take the suggestion.
A color that merely happens to match today drifts the next time someone edits
the palette.

If the site defines no variables, literals are the honest choice. Say so rather
than inventing a palette nobody asked for.

## Repetition is a loop

Three or more siblings with the same structure get a `use_loop` recommendation.
Show it to the user and ask one question: **will this content change or grow?**

- A team listing, product cards, testimonials → loop, bound to a post type.
- Three feature blurbs that will never change → static is fine.

`nibwp/breakdance-pro-loop-plan` returns the concrete steps.

## Templates need conditions

A header, footer, popup or template with no display conditions is built and
**invisible**. `nibwp/breakdance-pro-template` takes both together. If you create
one without conditions, say plainly that it will not appear yet.

Headers and footers replace what the theme provides *everywhere they apply*.
Check before assigning one site-wide.

## Changing an existing page

Use `nibwp/breakdance-pro-refine`. Never re-convert a page to change one thing —
rebuilding discards every hand edit made since it was generated.

1. `nibwp/breakdance-tree action=outline` for the node IDs, or
   `nibwp/breakdance-pro-audit` for IDs plus what is wrong.
2. `nibwp/breakdance-pro-refine` with one edit per node. Properties merge, so
   send only what changes.

Check the page has revisions first. That is the undo.

## Figma is structure, not a picture

Never convert a frame by looking at a rendering of it. Read the file:

1. `nibwp/figma-pro-analyze` for a large frame — see the structure first.
2. `nibwp/figma-pro-fetch` — node tree, geometry, auto-layout, Variables.
3. Pass that result straight through as the `figma` argument.

`nibwp/breakdance-pro-figma-to-section` checks for evidence of a real read and
**refuses** an image-derived payload when the site can read Figma properly. With
no connection it refuses once, tells you to warn the user, and then accepts
`allow_image_fallback: true`.

Figma Variables are bridged onto the site's own variables by value — the names
never agree. Unmatched ones are reported, never invented. See
`references/figma.md`.

## Always dry-run first

Every writing ability defaults to `dry_run: true`. Run it, show the user the
recommendations, then run again with `dry_run: false`. A conversion that writes
on the first call gives nobody a chance to look.

## Order of work

```
design-direction → breakdance-info → elements → tokens
   → build payload
   → validate (or convert with dry_run)
   → show recommendations, get a decision
   → convert with dry_run false
   → feedback
```

## References

- `references/tree-shape.md` — the node tree in detail
- `references/elements.md` — element families and what they are for
- `references/tokens.md` — variables, selectors, presets
- `references/dynamic-data.md` — loops and dynamic values
- `references/figma.md` — reading a frame as structure, and token bridging
- `references/anti-patterns.md` — what not to do, and why
- `references/checklists/` — per-section checklists
