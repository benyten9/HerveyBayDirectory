# Figma

A frame is a **structure**, not a picture. Read the file.

## Why it matters

| Read from the file | Looked at as an image |
|---|---|
| Auto-layout says *why* spacing is what it is | You estimate the gaps |
| `absoluteBoundingBox` gives real measurements | You guess sizes |
| Variables name the colors | You copy hex codes |
| The node tree gives real hierarchy | You infer grouping from proximity |

The section builds either way. The difference only shows later — when someone
edits the palette and the estimated version does not follow.

## The path

1. `nibwp/figma-pro-analyze` — for a large frame, see the structure first.
2. `nibwp/figma-pro-fetch` — get the node tree, geometry, auto-layout, Variables.
3. Pass that straight through as the `figma` argument to
   `nibwp/breakdance-pro-figma-to-section`.

## What the ability checks

It looks for evidence of a real read: node tree, frames, `layoutMode`,
`absoluteBoundingBox`, Variables, file reference. Three of six is the line.

- **Connection available, no structure** → refused. The good path existed and
  was not used, and a screenshot conversion wearing a Figma label is worse than
  an honest one.
- **No connection** → refused once, with an explanation. Tell the user output
  will be estimated, then pass `allow_image_fallback: true`.
- **Structure present** → converts, and bridges the tokens.

## Token bridging

Figma Variables are matched to this site's variables **by value**, because the
names never agree — `Brand/Primary` and `brand-primary` are the same color
under two conventions and only the value proves it.

Matched → use the site's variable.
Unmatched → reported. Say which, and let the user decide whether to add them.
**Never invent variables on someone's design system.**
