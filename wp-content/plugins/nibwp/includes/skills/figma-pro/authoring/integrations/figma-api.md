# integrations/figma-api.md — reading Figma from the skill

How figma-pro gets design data. The skill **never calls Figma directly** — it
calls NibWP's Figma integration abilities, which wrap the REST API (or the Dev
Mode MCP alt-source). Full plumbing detail lives in the integration doc:
`includes/integrations/figma/FIGMA-INTEGRATION.md`. This file is the skill-side
view: which ability to call, when, and what you get back.

## Table of contents
1. Node addressing (URL → fileKey + node-id)
2. The abilities (and the MCP tool names they satisfy)
3. Which to call at each pipeline step
4. Dual source: REST vs Dev Mode MCP
5. Rate limits, caching, failure handling

---

## 1. Node addressing

A Figma link looks like:
`https://www.figma.com/file/{fileKey}/Some-Name?node-id=1-234`
(newer: `/design/{fileKey}/…`). Parse:
- `fileKey` = the path segment after `/file/` or `/design/`.
- `node-id` = the query param; **decode `1-234` → `1:234`** (Figma uses `:` in the
  API, `-` in URLs).

If the user pastes a bare file link with no `node-id`, call `figma-get-file`
with a shallow `depth` to list top-level frames and ask which one — don't pull
the whole document blind.

## 2. The abilities

The user's spec named MCP-style tools (`figma_get_file`, `figma_get_nodes`,
`figma_get_components`, `figma_get_variables`, `figma_get_styles`,
`figma_export_assets`, `figma_generate_context`). In NibWP these are satisfied by
the integration abilities:

| MCP tool name (spec) | NibWP integration ability | Returns |
|---|---|---|
| `figma_get_file` | `nibwp/figma-get-file` | normalized full document tree + file `version` |
| `figma_get_nodes` | `nibwp/figma-get-node` | normalized subtree(s) by id (preferred) |
| `figma_get_components` | `nibwp/figma-get-file` (component set) / node scan | COMPONENT / COMPONENT_SET / INSTANCE map |
| `figma_get_variables` | `nibwp/figma-get-variables` | token collections/modes/aliases (Enterprise; 403→styles) |
| `figma_get_styles` | `nibwp/figma-get-styles` | published paint/text/effect styles |
| `figma_export_assets` | `nibwp/figma-export-node` | 2× PNG / SVG streamed to a local path |
| `figma_generate_context` | (composite) | the normalized tree + tokens + export bundled for the agent |
| — | `nibwp/figma-post-comment` | the one write path (comment on a node) |

Every ability returns the envelope `{ ok, data?, code?, message?, http? }`.

## 3. Which to call at each pipeline step

| Pipeline step | Ability |
|---|---|
| Resolve node | parse URL; `figma-get-file` (shallow) only if no node-id given |
| Fetch structure | `figma-get-node` (known id) — cheaper than `figma-get-file` |
| Component detection | the node tree's `COMPONENT`/`INSTANCE`/`COMPONENT_SET` + `componentId` refs |
| Establish tokens | `figma-get-variables` → on 403, `figma-get-styles` |
| Ground-truth image | `figma-export-node` at `scale=2, format=png` (SVG for icons/logos) |
| Verify loop | reuse the same `figma-export-node` PNG as the diff target |
| Approval note (optional) | `figma-post-comment` |

Prefer `figma-get-node` over `figma-get-file` whenever you have a node id — full
files are large and rate-limit-heavy.

## 4. Dual source — REST vs Dev Mode MCP

Both feed the *same* normalized shape; only the fetch differs:
- **REST reader (default):** headless, needs the user's token, reads private files
  the user can open, works with no Figma app and no paid seat for reads.
- **Dev Mode MCP (nicety):** pulls the user's *current selection* from the Figma
  desktop app. Needs the app open + a paid Dev/Full seat. If the user wants live
  in-app context and it's not present, NibWP **suggests installing Figma desktop
  + enabling Dev Mode MCP**.

Pick REST unless the user explicitly wants the live-selection flow. Downstream
(tokens → build → verify) is identical either way.

## 5. Rate limits, caching, failure handling

The integration handles these; the skill just needs to branch on the envelope:
- `429` → integration backs off (capped, honors `Retry-After`); if it still
  fails, tell the user to retry shortly rather than hammering.
- `403` on `figma-get-variables` → **expected on non-Enterprise files**; fall back
  to `figma-get-styles` and tell the user the token set is "derived from styles"
  (coarser than Variables).
- File/node responses are cached per `fileKey:nodeId:version`; a file edit bumps
  `version` and busts the cache — so a re-convert after the designer changes the
  file picks up the change.
- Image exports are time-boxed; on a slow/huge frame you may get a partial with a
  warning — surface it, don't silently proceed as if complete.
