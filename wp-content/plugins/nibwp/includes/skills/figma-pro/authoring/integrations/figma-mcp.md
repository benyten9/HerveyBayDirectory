# integrations/figma-mcp.md — reading Figma via the Dev Mode MCP server

The **recommended / primary** way figma-pro reads a live design. This is the
in-app path: the AI agent talks to Figma's official **Dev Mode MCP server**,
which runs locally inside the Figma **desktop** app and hands over the real
design context — the selected frame's node tree, its components/variants,
Variables, styles and layout metadata — over the Model Context Protocol. It is
purpose-built to give an AI *structured design context* instead of a screenshot.

The REST fallback (headless, token-based) is the sibling doc:
`integrations/figma-api.md`. Either source normalizes into the **same** NibWP
Design Object — see `core/schema.md`. **MCP vs REST only changes HOW the node
JSON arrives; nothing downstream (tokens → build → verify) differs.**

## Table of contents
1. What the Dev Mode MCP server is
2. Architecture
3. Why it beats REST/screenshots
4. Requirements & honest constraints
5. How NibWP wires it in (alternate source, same abilities)
6. The MCP tools an agent sees → NibWP abilities
7. When to use MCP vs REST (decision table)
8. Auth model
9. Cross-references

---

## 1. What the Dev Mode MCP server is

Figma's **Dev Mode MCP server** is a first-party server shipped with the Figma
desktop app. When enabled (Dev Mode → Preferences → *Enable local MCP server*),
it listens locally and exposes the current file/selection as MCP tools. An AI
agent connects to it and reads **structured design data** — real node
properties, component definitions, variant sets, Variables (design tokens),
paint/text/effect styles, and auto-layout metadata. This is *design context an
LLM can reason over*, not pixels it has to guess at.

## 2. Architecture

```
  ┌────────────┐   MCP Protocol   ┌───────────────────┐        ┌─────────────┐
  │  AI Agent  │ ───────────────► │ Figma MCP Server  │ ─────► │  Figma File │
  │ (figma-pro)│ ◄─────────────── │ (Dev Mode, local, │ ◄───── │ (current    │
  │            │  structured JSON │  in desktop app)  │  reads │  selection) │
  └────────────┘                  └───────────────────┘        └─────────────┘
        │                                                              ▲
        │ normalizes every response into ───────────────────────────┐ │
        ▼                                                            │ │
  NibWP Design Object (NDO)  ──►  tokens ──►  build ──►  verify      └─┘
  (core/schema.md)                                        (identical to REST path)
```

The server is **local and interactive** — it rides the desktop app's live
session and its current selection. It is not a cloud endpoint.

## 3. Why it beats REST / screenshots

| Dimension | Dev Mode MCP | Screenshot analysis | REST |
|---|---|---|---|
| AI context | native, structured JSON purpose-built for LLMs | pixels only — everything inferred | structured JSON (fetched) |
| Components/variants | real `COMPONENT` / `COMPONENT_SET` / variant props | invisible — guessed from visuals | present, but resolved by id lookups |
| Design metadata | Variables, styles, auto-layout delivered directly | none | present via extra calls |
| Live selection | **yes** — whatever the designer has selected right now | n/a | no — you address nodes by id/URL |
| Fidelity | highest — measured values, not measured pixels | lowest — OCR/vision error | high |
| Freshness | current in-app state, no publish needed | whatever the image shows | file `version` at fetch time |

The headline win: **component + variant understanding** and **exact
Variables/styles/auto-layout**, delivered as data, from the **current
selection**, with no screenshot-vision guessing.

## 4. Requirements & honest constraints

- Needs the Figma **desktop app** open (the server runs *inside* it).
- Needs a **paid Dev or Full seat** — Dev Mode/MCP is not on the free tier.
- Operates on the user's **current file/selection** in that app — it is scoped to
  what the desktop session is looking at, not an arbitrary file key.
- It is an **interactive, local** connection. In a NibWP **server-side / headless**
  run there is no desktop app and no local socket, so the **REST reader is used
  instead** — same NDO, no functional loss.
- If the user wants live in-app context and the MCP isn't present, NibWP should
  **SUGGEST installing the Figma desktop app + enabling the Dev Mode MCP server**
  — never fabricate a connection, just fall back to REST and offer the upgrade.

## 5. How NibWP wires it in

The MCP is an **alternate source behind the same NibWP integration abilities**.
The skill still *never* talks to Figma (or the MCP) directly — it calls the same
`nibwp/figma-*` abilities documented in `figma-api.md`. Under the hood the
integration decides whether a given ability is satisfied by a REST call or by an
MCP tool call, then **normalizes the result into the NibWP Design Object (NDO)**,
the internal intermediate schema in `core/schema.md`.

Consequences:
- The skill code is **source-agnostic** — it branches on the `{ ok, data?, … }`
  envelope, not on where the JSON came from.
- Tokens, build, and verify are **byte-for-byte the same pipeline** regardless of
  source. MCP vs REST is purely a transport choice made at read time.

## 6. The MCP tools an agent sees → NibWP abilities

A Dev Mode MCP session typically surfaces tools for: get current selection /
frame, get node tree, get components, get variables, get styles, and
export/get image. NibWP maps each onto its own ability (which normalizes to the
NDO):

| MCP-provided capability | NibWP ability | Feeds |
|---|---|---|
| get selection / current frame | `nibwp/figma-get-node` (selection as root) | structure |
| get node tree (subtree by id) | `nibwp/figma-get-node` | structure |
| get components / variant sets | `nibwp/figma-get-node` (COMPONENT/INSTANCE/SET scan) | component detection |
| get variables (token collections/modes) | `nibwp/figma-get-variables` | tokens |
| get styles (paint/text/effect) | `nibwp/figma-get-styles` | tokens (fallback + supplement) |
| export / get image | `nibwp/figma-export-node` | ground-truth image for verify |

Same four ability names as the REST path (`figma-get-node`,
`figma-get-variables`, `figma-get-styles`, `figma-export-node`) — the MCP just
sources them from the live app instead of the web API.

## 7. When to use MCP vs REST

| Use **MCP** when… | Use **REST** when… |
|---|---|
| designer is working **live in Figma desktop** | run is **headless / server-side** |
| user wants the **current selection** built | conversion is **automated**, no human at the app |
| user has a **paid Dev/Full seat** | **no app open** / no paid seat (reads need no seat) |
| newest in-app edits, not yet published, matter | reading **private files via the user's token** |

Default to **REST** unless the user explicitly wants the live-selection flow;
suggest MCP only as the interactive upgrade. Downstream is identical either way.

## 8. Auth model

- The Dev Mode MCP server uses the **logged-in Figma desktop session** — so it
  sees exactly what that signed-in user can see, **private files included**. No
  separate token is passed for the design reads; access = whatever that account
  has.
- Separately, the **session-level `plugin:figma` MCP connector** requires an
  **interactive OAuth** handshake and **cannot be authorized in a headless
  session**. That is a *session-tooling* concern (getting the connector
  authorized at all) and is distinct from how figma-pro reads design data — do
  not conflate the two. When headless, use the REST reader.

## 9. Cross-references

- `integrations/figma-api.md` — the REST fallback: abilities, node addressing,
  rate limits, `403`/`429` handling, caching.
- `core/schema.md` — the NibWP Design Object (NDO) that both sources normalize
  into; the single shape everything downstream consumes.
