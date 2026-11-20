# Workflow — one artboard → one WordPress page

The common case. A designer has a page in Paper and wants it on the site.

## Before you touch anything

Confirm both halves of the bridge:

1. Paper's MCP server answers a tool listing, and Paper Desktop is open.
2. `nibwp/paper-manage { action: "status" }` returns active and `pro`-entitled.

If either fails, name which one. `../integrations/paper-mcp.md` has the failure
table.

Then say the bridge out loud, once:

> I read the design from Paper on your machine and hand it to WordPress. Your
> site can't see Paper directly, so Paper Desktop needs to stay open while I work.

## 1. Pick the target

Ask Paper what is selected. If something is, offer it. If not, list the artboards
with their sizes and ask which one — and ask the question that saves the most work
later:

> Is there a mobile artboard too? I'll read both and match them instead of
> guessing breakpoints.

## 2. Size the job

Get a subtree summary before pulling node data. Report the node count after
pruning and what it implies (`../core/parser.md` §1). A 400-node artboard is a
multi-pass job and the user should hear that now, not in turn nine.

## 3. Read it

Structure, computed styles in batches, text content, and a 2× screenshot. Keep the
screenshot — it is the verify baseline.

Note every shader or effect layer as you go. `../integrations/paper-mcp.md` §5.

## 4. Tokens first

Collect the colors and type styles actually in use, collapse near-duplicates, name
them. `../core/tokens.md`. Do this before building the node tree or you will
hardcode fifty hex values.

## 5. Normalize

Build the NDO. `../core/nodes.md` for the mapping, `../core/schema.md` for the
contract. While you walk:

- prune hidden layers, empty wrappers and spacer rectangles;
- set `figma_type` to Paper's own node type string;
- **fix the tag ramp** — one `h1`, no skipped levels, big numbers are `p` not
  headings;
- set `flex-wrap: wrap` on every horizontal container with more than two children;
- reference tokens as `var(--token, fallback)`, never `clamp()` for font-size;
- write every fidelity compromise into `warnings`.

## 6. Export and attach assets

Every raster fill, every vector that carries meaning, every shader layer you
decided to flatten:

```
nibwp/paper-manage { action: "attach_asset" }
```

Deduplicate on `image_ref`. Do this **before** convert, so the NDO carries real
media URLs. `../core/assets.md`.

## 7. Ingest

```
nibwp/paper-manage { action: "ingest", title: "<something the user will recognise>" }
```

Keep the returned design id.

## 8. Plan, and show it

```
nibwp/paper-pro-plan
```

Show the user: builder and why, section list with roles, components and instance
counts, dynamic-data proposals, the token map, the `h1`, and every fidelity
compromise named by layer. `../core/parser.md` §8.

This is the cheapest moment to be corrected.

## 9. Preview

```
nibwp/paper-manage { action: "preview_convert" }
```

Persists nothing. Read the warnings. Count the `h1`s. Check tokens are referenced
rather than raw hexes. Fix in the NDO and preview again — it is free.

## 10. Convert

```
nibwp/paper-manage { action: "convert" }
```

A draft. Never onto a live page.

For a non-Gutenberg builder, this is the handoff instead: preview as neutral HTML,
read the builder's playbook, synthesize its native payload, `dry_run: true`, fix,
then commit. `../builders/`.

## 11. Sync tokens

```
nibwp/paper-manage { action: "sync_tokens" }
```

Ask first if the site already has a palette worth keeping — mapping beats
replacing. This changes every page. `../builders/globals-sync.md`.

## 12. Verify and audit

Render with Playwright at the artboard width. Diff against the Paper screenshot
from step 3. Then:

```
nibwp/paper-manage { action: "audit" }
```

## 13. Report

In this order:

- the draft's link;
- what converted cleanly;
- every shader or effect that degraded, **by layer name**, with what you did;
- fonts the site does not load;
- responsive defaults you applied and where they came from;
- the skill chain you ran.

The shader line is the one that earns trust. *"The noise field in the hero is a
flattened PNG — it looks identical and it will not animate"* told up front is a
professional note. Discovered by the client later, it is a complaint.
