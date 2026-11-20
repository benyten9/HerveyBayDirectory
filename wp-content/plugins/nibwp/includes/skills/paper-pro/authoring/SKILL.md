---
name: paper-pro
description: >-
  Convert a Paper design into a native, maintainable WordPress build. Use this
  skill whenever the user references a Paper file, artboard, canvas, frame,
  component or selection and wants it turned into a WordPress page, section,
  block, design system or theme — phrasings like "convert this Paper design to
  WordPress", "build this artboard in Etch/Bricks/Elementor", "turn my Paper
  canvas into a page", "import this Paper component", "make a WordPress design
  system from my Paper file", or "I have it open in Paper, recreate it on the
  site". Paper's design data is only reachable from the designer's own machine
  through Paper's local MCP server, so this skill works as a bridge: the agent
  reads the design out of Paper with Paper's own MCP tools, normalizes it into a
  NibWP Design Object, and hands that to WordPress through the NibWP Paper
  integration's abilities. Trigger it even when the user doesn't say "paper-pro"
  or name a builder — a Paper source plus an intent to build in WordPress is
  enough.
compatibility: Requires the NibWP Paper integration (pro entitlement), the Paper
  Desktop app running on the machine the agent runs on, Paper's MCP server added
  to the agent, and either a builder skill (etchwp-pro / bricks-pro /
  elementor-pro / kadence-pro) or Gutenberg core.
---

# paper-pro — Paper designs → native WordPress

## Read this first: the agent is the bridge

Paper's MCP server is **local only**. Per Paper's own documentation
(<https://paper.design/docs/mcp>, fetched 2 Oct 2026), the server is started by
the **Paper Desktop app**, speaks stdio through the `paper mcp` CLI, exposes a
legacy HTTP endpoint at `http://127.0.0.1:29979/mcp`, takes **no API key and no
OAuth**, and there is **no cloud REST API for Paper files**.

That single fact shapes everything else in this skill. Compare the two:

| | Figma | Paper |
|---|---|---|
| Who holds the credential | WordPress (an encrypted token) | nobody — there is no credential |
| Who reaches the design | the **site**, calling `api.figma.com` | the **agent**, calling `127.0.0.1` |
| Direction of travel | WordPress **pulls** | the agent **pushes** |
| What WordPress sees | a file key and a token | a finished design object, posted in |

So:

- **Do not look for a Paper API key in NibWP's settings.** There is none, by
  design. If you go hunting for one you will waste a turn.
- **Do not ask WordPress to fetch the design.** The site cannot reach
  `127.0.0.1` on the designer's laptop. Even on a local dev site, the NibWP
  integration deliberately never makes that call — see
  `integrations/paper-mcp.md`.
- **You read Paper. You hand the result to WordPress.** Paper's MCP tools give
  you the design. NibWP's `nibwp/paper-manage` ability with `action: "ingest"`
  takes it in. Everything after that is identical to the Figma path, because it
  is the same object and the same emitters.

```
Paper Desktop app  ──(local MCP, stdio or 127.0.0.1:29979)──►  THE AGENT
                                                                   │
                                                 normalize to NDO 1.0
                                                                   │
                                                                   ▼
             nibwp/paper-manage { action: "ingest" }  ──►  WordPress
```

If the user's Paper app is not running, or the MCP server is not registered with
your agent, **stop and say so**. There is no fallback source. Setup is one line
and lives in `integrations/paper-mcp.md`.

## Architecture

One ingest path, then the shared pipeline:

```
Paper (designer's machine)
  │
  └─ READ  the agent calls Paper's own MCP tools — selection, structure,
           computed styles, screenshots, image exports.
           integrations/paper-mcp.md
  ▼
  normalize → NDO 1.0  (core/schema.md, core/parser.md)
  ▼
nibwp/paper-manage { action: "ingest" }   ← the handover. integrations/handover.md
  ▼
stored design record in WordPress
  ▼
preview_convert → convert
  ├─ NDO → neutral HTML  (NIBWP_Figma_Html)  → the active builder skill's
  │                                            own html-to-* ability
  └─ NDO → core blocks   (NIBWP_Figma_Gutenberg)  → the built-in fallback
  ▼
audit  → warnings, token coverage, fidelity gaps
```

The **NDO** is the pivot. It is the same `ndo_version: "1.0"` object the Figma
integration produces, which is why Paper gets working builder output on day one:
there are no Paper-specific emitters, and there do not need to be. The format
was defined for Figma first and its node key is still called `figma_type` —
for a Paper design that key carries **Paper's own node type string**. See
`core/schema.md`.

## The pipeline

1. **Confirm the bridge.** Call Paper's tool listing (not this KB's tool names)
   and confirm the server answers. Then `nibwp/paper-manage { action: "status" }`
   to confirm the WordPress side is present and gated correctly.
2. **Resolve the target.** Ask Paper what is selected, or list the file's
   artboards and ask the user which one. Any granularity works: artboard → page,
   component → block, whole file → design system.
3. **Read the design.** Pull structure, computed styles, text content, and a
   reference screenshot. The screenshot is **ground truth for verification**, not
   the source of structure. `integrations/paper-mcp.md`.
4. **Decide fidelity up front, per layer.** Paper's layout is already CSS, so
   most of the artboard translates well. Its **shaders** are a React/WebGL layer
   with no CSS equivalent. Decide for each shader or effect layer: export as an
   image, rebuild the intent, or drop it. `core/assets.md` has the decision
   table. Do this before converting, not in the post-mortem.
5. **Tokens first.** Collect the real colors and text styles in use into a token
   map before building anything. Never `clamp()` for font-size. `core/tokens.md`.
6. **Normalize.** Build the NDO node tree. `core/parser.md`, `core/nodes.md`.
7. **Ingest.** `action: "ingest"` with the NDO. WordPress validates it, stores
   it, and returns a design id. `integrations/handover.md`.
8. **Plan.** `nibwp/paper-pro-plan` — target builder, section breakdown,
   component dedupe, dynamic-data candidates, fidelity warnings. Show the user.
9. **Preview.** `action: "preview_convert"` — builds and returns the markup plus
   warnings, **persists nothing**.
10. **Convert.** `action: "convert"` — persists as a **draft**. Never overwrite
    a live page.
11. **Audit.** `action: "audit"` — plus a Playwright render at the artboard
    width, compared against the Paper screenshot. Report every gap honestly,
    especially the shader ones.

## Hub and abilities

The integration exposes **one hub ability**, `nibwp/paper-manage`, selected by
an `action` argument:

| Action | Does |
|---|---|
| `status` | is the integration active, is Pro entitled, what builders are available, how many designs are stored |
| `ingest` | accept an NDO (or a raw Paper payload to normalize) and store it as a design record |
| `list_designs` | stored design records |
| `get_design` | one record, with its NDO |
| `rename_design` | rename a record |
| `delete_design` | remove a record |
| `preview_convert` | build markup from a record, return it, persist nothing |
| `convert` | build and persist as a draft page/post |
| `list_builders` | which builder targets this site can actually reach |
| `list_tokens` | the token map held on a record |
| `sync_tokens` | push that token map into the builder's native globals |
| `attach_asset` | sideload an image the agent exported from Paper into the media library |
| `list_assets` | assets attached to a record |
| `audit` | fidelity, token coverage, unsupported-feature report for a record |

Six more, all read-only probes — ask before you act:

| Action | Does |
|---|---|
| `setup_instructions` | the Paper-side setup to give the user, straight from the integration |
| `validate_payload` | dry-run the ingest validator, store nothing |
| `get_ndo` | just the NDO from a record |
| `list_conversions` | what has been converted from a record, and to what |
| `list_fonts` | the families the design needs, against what the site loads |
| `token_targets` | where `sync_tokens` would write, before it writes |

`validate_payload` before `ingest`, and `token_targets` before `sync_tokens`, cost
one call each and save a bad write. Use them.

Capability gates, so you know what a call needs: reads and record writes take
`edit_posts`, `attach_asset` takes `upload_files`, and `sync_tokens` alone takes
`edit_theme_options` — because it changes every page on the site.

The skill adds four orchestration abilities on top:

| Ability | Purpose |
|---|---|
| `nibwp/paper-pro-plan` | read a stored design → a build plan: builder, sections, components, tokens, fidelity risks |
| `nibwp/paper-pro-convert` | the full pipeline from step 7 on, including token sync and asset sideload |
| `nibwp/paper-pro-refine` | iterate on an already-converted page against the Paper screenshot |
| `nibwp/paper-pro-audit` | the honest report: what converted, what degraded, what was dropped and why |

The handover, as one line:

```
read from Paper → ingest → plan → preview_convert → convert → audit
```

## Tool names: trust Paper, not this KB

Paper's official documentation page **does not enumerate its MCP tools**.
Secondary sources (a community MCP wrapper on GitHub, MCP directory listings, a
review article) report roughly 24 tools, with reads along the lines of
`get_selection`, `get_jsx`, `get_screenshot` and `get_computed_styles`, and
writes along the lines of `create_artboard`, `write_html`, `set_text_content`
and `update_styles`, plus node rename/duplicate/move/delete and image export.

**Those names are reported, not verified.** Every tool name in this KB is marked
with its provenance. The rule:

> **Always ask Paper for its own tool list before calling anything.** A tool
> name copied out of this file and guessed wrong costs a failed call and costs
> the user confidence. The listing is free; the guess is not.

`integrations/paper-mcp.md` carries the provenance table and the capability
map — what a *kind* of tool is for, independent of what it is called in the
build the user has installed.

## Fidelity: where Paper and CSS stop agreeing

Two primary-source facts set the ceiling, and they pull in opposite directions.

**Paper's canvas is HTML and CSS.** Paper's own homepage (fetched 2 Oct 2026)
says its "HTML/CSS canvas means your design exports as code. nothing gets lost in
translation." That is good news and it is the opposite of the usual
design-tool problem: Paper's layout primitives already *are* flexbox, real CSS
sizing and real CSS type. Structure, spacing and typography should convert
**more** faithfully from Paper than from a vector tool, not less.

**Paper's shaders are not CSS.** Paper Shaders is a separate system, published as
the npm package `@paper-design/shaders-react` and described on
<https://shaders.paper.design> as "ultra fast zero-dependency shaders for your
designs" — a React component library, with a gallery of effects (water,
halftone, textures, gradient fields). Nothing on either page claims a CSS
equivalent, and there is no CSS property that reproduces a per-pixel shader.

So the honest line is **not** "Paper is shader-first and therefore lossy". It is:

> The layout layer converts cleanly because it is already CSS. The shader layer
> does not convert at all, because it is a WebGL/React component living on top
> of that CSS.

That reading is assembled from the two pages above; a widely cited secondary
article frames Paper as GPU-shader-first, which overstates the shader side of a
tool whose own front page leads with HTML/CSS and agents. Treat the ceiling as
per-layer, not per-file.

Raise it **before** you build, not after. Four options for a shader layer, in
order of preference:

1. **Export the layer as an image** and place it. Fidelity is exact; the effect
   becomes static. This is the default. `core/assets.md`.
2. **Rebuild the intent**, not the pixels — a CSS gradient that reads as the same
   mood, a `background-blend-mode`, an SVG filter. Say plainly it is an
   approximation.
3. **Reproduce it in the browser** by loading `@paper-design/shaders-react` on
   the front end. This is genuinely possible because the shaders ship as a public
   React package, but it adds a JavaScript dependency to a WordPress page, NibWP
   does **not** do it for you, and nothing in this skill automates it. Offer it;
   do not assume it.
4. **Drop it and tell the user.** A dropped effect the user knows about beats a
   bad imitation they find later.

Never silently flatten a shader into a flat color and call the conversion done.

## When NOT to use this skill

- The Paper Desktop app is not running, or Paper's MCP server is not registered
  with your agent → set that up first. `integrations/paper-mcp.md`. Do not
  attempt a workaround; there isn't one.
- The user only has a **screenshot** of a Paper design → that is an image
  conversion job, not this skill. Hand it to the builder skill's image path.
- Editing an existing WordPress page with no Paper involved → the builder skill
  directly.
- Pushing WordPress **back into** Paper. Paper's MCP reportedly exposes write
  tools, so this is technically reachable, but NibWP does not support it and
  this skill does not attempt it. Say so.

## Prompt rules

**Always:**

- Call Paper's own tool listing before using any tool name.
- Say the bridge out loud once, early, so the user knows why their machine has
  to stay awake.
- Establish tokens before structure; reference `var(--token, fallback)`.
- Declare the fidelity ceiling before converting, not in the post-mortem.
- Persist as a draft. Report every warning.

**Never:**

- Look for a Paper API key, OAuth app, or cloud endpoint. There is none.
- Ask WordPress to fetch `127.0.0.1`.
- Emit one giant HTML block.
- Use `clamp()` for font-size — the Etch/ACSS validator rejects it.
- Write builder meta directly. Go through the builder's own ability so its
  slashing, CSS regeneration and backups run.
- Invent a schema key. If it is not in `core/schema.md`, the emitters ignore it.

## Knowledge base map

Read the file for the step you are on.

```
paper-pro/authoring/
├── SKILL.md                     ← this index
├── core/
│   ├── schema.md      the NDO 1.0 contract — every real key, read from the code
│   ├── nodes.md       Paper node kinds → NDO types and CSS
│   ├── parser.md      structure, roles, complexity, conversion modes
│   ├── tokens.md      colors + type styles → a CSS token set
│   ├── composition.md skill composition — fold acss-pro/seo-pro, never 2 builders
│   ├── responsive.md  one artboard → breakpoints; column→stack rules
│   ├── components.md  repeated-instance dedupe and dynamic data
│   └── assets.md      image export, sideload, and the shader fidelity decision table
├── integrations/
│   ├── paper-mcp.md   Paper's local MCP server: setup, transports, tool provenance
│   └── handover.md    the bridge — agent reads Paper, posts the NDO to WordPress
├── builders/
│   ├── gutenberg.md   NDO → core blocks (the only built-in emitter besides HTML)
│   ├── etchwp.md      NDO → neutral HTML → EtchWP's own ability
│   ├── bricks.md      NDO → neutral HTML → Bricks' own ability
│   ├── elementor.md   NDO → neutral HTML → Elementor's own ability
│   └── globals-sync.md token map → builder-native globals
├── workflows/
│   ├── landing-page.md   one artboard → one WordPress page
│   ├── design-system.md  a Paper file → tokens + component set
│   └── theme-builder.md  a Paper file → theme templates
└── examples/
    ├── saas.md       shader hero, honest degradation, Gutenberg
    ├── agency.md     typography-led page → Etch via neutral HTML
    └── ecommerce.md  product card component → dedupe + dynamic data
```
