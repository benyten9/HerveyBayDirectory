# NibWP Paper Integration — Technical Reference

> The **receiving** layer. Paper design data cannot be fetched from a web server,
> so this integration does not fetch it. It accepts a normalized design object
> handed in by an agent, stores it, converts it, and audits the result. No
> conversion intelligence lives here — that belongs to the
> [`paper-pro` skill](../../skills/paper-pro/authoring/SKILL.md), which consumes
> these abilities. Ships bundled in the Pro plugin, gated by the `pro`
> entitlement.

---

## 1. Why this exists, and why it is not the Figma integration

The Figma integration is a **client**. It stores a token, calls
`api.figma.com`, and pulls a design into WordPress. The site is the active party.

Paper does not permit that shape. Per Paper's own documentation
(<https://paper.design/docs/mcp>, fetched 2 Oct 2026):

- the MCP server is started by the **Paper Desktop app**;
- it speaks **stdio** through the `paper mcp` CLI, with a legacy Streamable HTTP
  endpoint at `http://127.0.0.1:29979/mcp`;
- there is **no API key and no OAuth**;
- there is **no cloud REST API for Paper files**.

There is therefore no credential for WordPress to store and no host for
WordPress to call. The design exists on the designer's machine and nowhere a web
server can reach it.

So the architecture inverts. **The agent is the bridge.** It runs next to Paper
Desktop, reads the design with Paper's own MCP tools, normalizes it, and posts
the result into WordPress through an ability. WordPress is the receiving party.

```
Figma      WordPress ──token──► api.figma.com         the SITE pulls
Paper      the AGENT ──local MCP──► Paper Desktop     the AGENT reads
                 └──ingest ability──► WordPress       the AGENT pushes
```

This is not a limitation we are working around; it is the only architecture the
local-only server permits. It has two genuine advantages: the design never
transits a third-party API, and the site holds no secret that can leak.

The practical consequences, which every surface of this integration has to make
obvious:

- **No token field.** There is nothing to configure. An agent that goes hunting
  for a Paper API key in NibWP's settings has misunderstood the architecture, and
  the admin page's job is to prevent that.
- **No outbound loopback request, ever.** The integration must not call
  `127.0.0.1`. On a hosted site it cannot work; on a local dev site it would work
  by accident and become a support burden pretending to be a feature.
- **No scheduled or background refresh.** Nothing on a cron can reach Paper. Every
  ingest requires a live agent session on the designer's machine.

---

## 2. Architecture

```
┌──────────────────────────────────────────────────────────────┐
│ NibWP Paper Integration  (includes/integrations/paper/)       │
│                                                               │
│  Auth        none — by design. No token, no OAuth, no secret. │
│                                                               │
│  Input       nibwp/paper-manage { action: "ingest" }           │
│              an agent-supplied NDO. UNTRUSTED INPUT.           │
│                                                               │
│  Storage     design records (NDO + tokens + assets + warnings) │
│                                                               │
│  Output      NDO → neutral HTML      (NIBWP_Figma_Html)        │
│              NDO → core blocks       (NIBWP_Figma_Gutenberg)   │
│                                                               │
│  Hub         nibwp/paper-manage — 14 actions                   │
└───────────────────────────┬──────────────────────────────────┘
                            │  NDO 1.0
                            ▼
            paper-pro skill  (orchestrator — separate doc)
```

**Boundary contract:** the integration validates, stores, converts and reports.
It never reads Paper, never chooses a builder on its own initiative, and never
judges a design. The skill decides; the integration executes and records.

### What is reused, and what is Paper's own

**Reused from the Figma integration, unchanged — the emitters:**

- `NIBWP_Figma_Html` — NDO → neutral semantic HTML, the artifact every builder
  skill's `html-to-*` ability consumes.
- `NIBWP_Figma_Gutenberg` — NDO → core block markup, the only built-in emitter
  besides HTML.

`paper.php` autoloads both from `../figma/` and refuses to convert unless both
classes are present. That reuse is the whole reason Paper has working builder
output on day one, and it is why the NDO's node key is still called `figma_type`
— see §4.

**Paper's own, because Paper's input is not Figma's:**

- `NIBWP_Paper_Normalize` — Paper's node tree → NDO 1.0. Not a copy of the Figma
  normalizer: Paper's canvas is HTML/CSS, so it takes Paper's **CSS map** and
  splits it on a layout-property allow-list rather than writing a fixed key set.
  Consequences: CSS Grid survives, per-side padding and separate row/column gaps
  survive, `display: flex` is inferred when flex properties appear without it, and
  a designer's own `h1`–`h6` tag beats the font-size ramp. It also **populates
  `warnings`**, which the Figma normalizer does not.
- `NIBWP_Paper_Tokens` — palette + type ramp with `'source' => 'paper'`, plus a
  static `css()` that renders the `:root` block. Same slot names as the Figma
  extractor (`--color-primary`/`-secondary`/`-accent`/`--color-N`;
  `--text-display`/`-h1`/`-h2`/`-h3`/`-body`/`-small`) so a site that converts from
  both tools gets one token vocabulary, not two. `NIBWP_Figma_Tokens` could not be
  reused: it walks raw Figma JSON, looking for `SOLID` fills and nodes typed
  `TEXT`.
- `NIBWP_Paper_Ingest`, `NIBWP_Paper_Assets`, `NIBWP_Paper_Library` — the receiving
  side: validation, media sideload, and the design-record store.

---

## 3. The hub and its actions

One ability, `nibwp/paper-manage`, dispatched on an `action` argument. This is
the standard NibWP integration-hub shape (`wp_register_ability('nibwp/<slug>-manage', …)`
with an `action` enum), so agents that already know one NibWP integration know
this one.

| Action | Reads/Writes | Purpose |
|---|---|---|
| `status` | read | integration active, `pro` entitlement, reachable builders, stored-design count. **Also states plainly that there is no token to configure and that reading Paper is the agent's job.** |
| `ingest` | write | accept an NDO (or a raw Paper payload to normalize) → store a design record, return its id |
| `list_designs` | read | stored records: id, title, created, builder hints, warning count |
| `get_design` | read | one record with its NDO |
| `rename_design` | write | rename a record |
| `delete_design` | write | remove a record and its asset links |
| `preview_convert` | read | build markup from a record and return it with warnings. **Persists nothing.** |
| `convert` | write | build and persist as a **draft** post/page |
| `list_builders` | read | which builder targets this site can actually reach, with the honest note that only Gutenberg and neutral HTML are emitted here |
| `list_tokens` | read | the token map held on a record |
| `sync_tokens` | write | push that map into the builder's native globals |
| `attach_asset` | write | sideload an agent-exported image into the media library and link it to the record |
| `list_assets` | read | assets linked to a record |
| `audit` | read | fidelity, token coverage and unsupported-feature report for a record |

Six further actions round out the hub:

| Action | Reads/Writes | Purpose |
|---|---|---|
| `setup_instructions` | read | the Paper-side setup an agent or user needs: the install commands, the "Paper Desktop must be open" precondition, the same-machine requirement |
| `validate_payload` | read | dry-run the ingest validator without storing anything — the cheapest way to find out a payload is malformed |
| `get_ndo` | read | just the NDO from a record, without the surrounding metadata |
| `list_conversions` | read | what has been converted from a record, and to what |
| `list_fonts` | read | the font families a record needs, against what the site loads |
| `token_targets` | read | where `sync_tokens` would write on this site, before it writes |

The read-only probes are the useful pattern here. `validate_payload`,
`token_targets` and `setup_instructions` all exist so an agent can find out what
will happen before it happens — the same reason `preview_convert` is separate from
`convert`.

### Capabilities, exactly

| Constant | Value | Applies to |
|---|---|---|
| `NIBWP_PAPER_CAP_READ` | `edit_posts` | every read action |
| `NIBWP_PAPER_CAP_WRITE` | `edit_posts` | `ingest`, `rename_design`, `delete_design`, `convert` |
| `NIBWP_PAPER_CAP_UPLOAD` | `upload_files` | `attach_asset` |
| `NIBWP_PAPER_CAP_SITE` | `edit_theme_options` | `sync_tokens` only |

`sync_tokens` is the only action on `edit_theme_options`, and correctly so: it
writes the site's global design tokens, which changes every page. That is the same
capability WordPress gates the Customizer on.

### Why `preview_convert` is separate from `convert`

Because the two mistakes that cost the most are both visible in a preview and
invisible beforehand: a wrong builder target, and a heading ramp that produced
four `h1` elements. One extra read-only call catches both. The skill is instructed
never to skip it.

### Why `convert` always drafts

A user's live page is not ours to overwrite. Promoting a draft is one click;
recovering an overwritten page is a support ticket.

### Skill-side abilities

The `paper-pro` skill registers four orchestration abilities on top of the hub:
`nibwp/paper-pro-plan`, `nibwp/paper-pro-convert`, `nibwp/paper-pro-refine`,
`nibwp/paper-pro-audit`. They compose hub actions; they do not bypass them.

The documented handover is one line:

```
read from Paper → ingest → plan → preview_convert → convert → audit
```

---

## 4. The NDO 1.0 contract

A design enters WordPress as a **NibWP Design Object**, the same builder-agnostic
shape the Figma integration produces. Defined in
`../figma/class-figma-normalize.php`; that file is the source of truth, and the
skill's `core/schema.md` documents it key by key.

```php
[
  'ndo_version' => '1.0',
  'target'      => ['type' => 'page', 'title' => string],
  'tokens'      => array,
  'root'        => node,
  'warnings'    => array,
]

node = [
  'figma_type' => string,   // the SOURCE TOOL's node type
  'name'       => string,
  'type'       => string,   // generic: section|container|component|
                            //          component_instance|text|shape
  'style'      => array,    // CSS property => value, visual
  'layout'     => array,    // CSS property => value, box/flex
  'children'   => array,
  'bbox'       => ['w' => float, 'h' => float],   // optional
]
```

Optional node keys, set only when the source provides them: `bbox`, `text`,
`tag`, `font_family`, `image_ref`, `image_url`.

**On `figma_type`.** The format was defined for Figma first and the key kept that
name. For a Paper design it carries **Paper's own node type string**. It is a
provenance field, not a claim about Figma. Renaming it would fork the NDO and
double the emitter surface for no gain; carrying Paper's real vocabulary in it
costs nothing and keeps the audit honest when the generic `type` degrades to
`container`.

Paper's normalizer adds one node key the Figma path does not: **`alt`**, set when
the Paper node carried alt text. The Gutenberg emitter does not read it — an image
fill becomes a `background-image` on a group, never a `core/image` — but it is the
right place to carry alt text for a builder handoff or an `seo-pro` pass.

**Three things implementers should know**, because two of them contradict the
Figma class's own docblock:

1. **`warnings` is populated here, and is not on the Figma path.** The Figma
   normalizer declares the property, places it in the document and never appends to
   it, so Figma always returns `'warnings' => []`. `NIBWP_Paper_Normalize` does
   append — a deduplicated list of plain translated strings, for the mechanical
   problems it can see (an `image_ref` with no resolved URL, for example). It cannot
   see the judgements: a flattened shader, a missing font, an overridden heading
   level. The **agent** adds those, as strings, and `ingest` must preserve them.
   One array, one shape.
2. **Neither normalizer resolves colors against the source file's variables or
   styles**, despite a Figma docblock that says it does. The Figma `color()` helper
   converts RGBA to hex or `rgba()` and stops. Token mapping is always a separate
   pass.
3. **`style` and `layout` are a split on the Paper path, not a fixed key set.**
   Anything in the layout allow-list goes to `layout`; everything else goes to
   `style`. So an unusual CSS property still reaches the inline style rather than
   being dropped — which is correct for a CSS-native source, and a reason the skill
   is told to filter at the source rather than piping every computed property
   through.

---

## 5. The admin page

Where the Figma integration shows a **token form**, the Paper integration shows
an **explanation and a state readout**. There is no credential, so there is
nothing to submit.

It should show, in this order:

1. **How this works, in three lines.** Paper's server is local; the agent reads
   the design; WordPress receives it. Stated before anything else, because a user
   looking for a settings field needs to be redirected, not left searching.
2. **Setup for the designer's machine** — the two routes from Paper's docs:
   - current, recommended:
     `claude plugin marketplace add paper-design/agent-plugins && claude plugin install paper-desktop@paper`
   - legacy HTTP, still documented:
     `claude mcp add paper --transport http http://127.0.0.1:29979/mcp --scope user`
   Plus the precondition that is easy to miss: **Paper Desktop must be open**,
   and the agent must run on the same machine.
3. **The entitlement state** — `pro` present or not, with the usual upsell path
   when absent.
4. **Stored designs** — title, when ingested, warning count, and what was
   converted from each. This is the page's real working content: a list of records
   the user can rename, convert, re-convert or delete.
5. **Reachable builders** — what this site can actually target, with the honest
   note that the integration emits core blocks and neutral HTML, and that any
   other builder is reached through that builder's own ability.
6. **No connection indicator for Paper.** The page must not claim to know whether
   Paper is running. It cannot know, and a red dot that is always red teaches
   users to ignore indicators.

---

## 6. Security posture

The short version: this integration adds **no new secret** and **no new
unauthenticated surface**, and treats its one input as hostile.

- **No credential at all.** Nothing to encrypt, nothing to rotate, nothing to
  leak. The Figma integration's token store, OAuth proxy and refresh logic have
  no counterpart here, and that is a reduction in attack surface, not a missing
  feature.
- **No new REST route.** Everything goes through the registered ability, which
  inherits NibWP's existing authentication, capability checks and audit logging.
  There is deliberately no public endpoint for an agent to POST a design to —
  an unauthenticated ingest route would be a content-injection vector aimed
  straight at `wp_insert_post`.
- **The ingest payload is untrusted input.** This is the central security fact of
  the integration. An NDO arrives from an agent, which assembled it from a
  third-party design file. It must be treated exactly as a form submission is:
  - validate `ndo_version` against the known value and reject anything else;
  - walk the node tree with a **depth cap and a node-count cap**, because a
    recursive emitter plus an adversarial or merely enormous tree is a memory
    exhaustion;
  - allow-list `type` against the six generic values rather than passing it
    through;
  - treat every `style` / `layout` value as a CSS string to be sanitized, never
    as markup. `url(...)` values in particular carry a URL that must be validated
    before it reaches a `style` attribute;
  - escape all text on output — the shared emitters do use `esc_html()`,
    `esc_attr()` and `sanitize_html_class()`, and that must not regress;
  - cap the overall payload size. A design object is not a file upload.
- **Capability gates.** `edit_posts` for reads and for record/post writes,
  `upload_files` for `attach_asset`, and `edit_theme_options` for `sync_tokens` —
  the capability WordPress gates the Customizer on, because syncing tokens changes
  every page on the site. The full map is in §3.
- **Asset sideload.** `attach_asset` takes an agent-exported image. Validate the
  MIME type by inspecting the file, not the extension, and route it through
  WordPress's own sideload path so the upload filters run.
- **Entitlement gate.** Every action checks the `pro` entitlement before running.
- **Designer content.** A Paper file may contain a client's unreleased work. The
  integration stores only what the agent handed it, never forwards it anywhere,
  and `delete_design` removes it. Nothing is phoned home.

---

## 7. Deliberately not supported

Each of these is a decision, not a gap. Say so plainly when a user asks.

| Not supported | Why |
|---|---|
| WordPress reading Paper directly | the server is local; the site cannot reach it. No loopback call is implemented even where it would work. |
| A Paper API key / OAuth connection | Paper exposes neither. There is nothing to connect. |
| Scheduled sync, cron refresh, webhook | nothing on a schedule can reach Paper. Every ingest needs a live agent session on the designer's machine. |
| Re-reading a stored design from the source | no resolvable reference exists server-side. A refresh means a new agent session. |
| Write-back — WordPress → Paper | Paper reportedly exposes write tools, so it is technically reachable. Not implemented. An unexpected edit to a designer's live file is the worst possible failure. |
| Shader-faithful conversion | Paper Shaders ship as the React package `@paper-design/shaders-react`; there is no CSS property that reproduces a per-pixel shader. Flatten to an image, approximate the intent, or drop it — and record the choice in `warnings`. |
| Per-builder emitters beyond Gutenberg | the integration emits neutral HTML and core blocks. Every other builder is reached through that builder's own `html-to-*` ability, which owns its slashing, CSS regeneration and backups. |

### On fidelity, stated accurately

Two primary facts, pulling opposite ways:

- Paper's homepage (fetched 2 Oct 2026) says its "HTML/CSS canvas means your
  design exports as code. nothing gets lost in translation." Paper's layout
  primitives already *are* CSS, so structure, spacing and typography should
  convert **better** from Paper than from a vector tool.
- Paper Shaders, per <https://shaders.paper.design>, are "ultra fast
  zero-dependency shaders for your designs", published as
  `@paper-design/shaders-react`. Neither page claims a CSS equivalent.

So the accurate statement is not "Paper is shader-first and therefore lossy". It
is: **the layout layer converts cleanly because it is already CSS; the shader
layer does not convert at all, because it is a React/WebGL component sitting on
top of that CSS.** The ceiling is per-layer, not per-file. A commonly cited
secondary article frames Paper as GPU-shader-first, which overstates the shader
side of a tool whose own front page leads with HTML/CSS and agents.

---

## 8. Setting Paper up on the designer's machine

All from <https://paper.design/docs/mcp> (fetched 2 Oct 2026).

1. **Install and open Paper Desktop.** "That starts the MCP server in the
   background and installs the Paper CLI used to connect agents." Closing the app
   stops the server.
2. **Connect the agent.** Current route, which is what Paper's own "Connect your
   agent" dialog installs — stdio via the `paper mcp` CLI:

   ```
   claude plugin marketplace add paper-design/agent-plugins && claude plugin install paper-desktop@paper
   ```

   Legacy route, still documented, for older builds or when the plugin route is
   unavailable:

   ```
   claude mcp add paper --transport http http://127.0.0.1:29979/mcp --scope user
   ```

3. **Run the agent on the same machine as Paper Desktop.** A remote agent, a CI
   runner or a cloud sandbox cannot reach a loopback server.
4. **Verify by listing the server's tools**, not by assuming. Paper's docs do not
   enumerate its tools, and the tool names circulating in secondary sources are
   unverified — one of them even claims the server is authenticated, which the
   official docs contradict. Always read the live listing.

### Diagnosing a failed handover

| Symptom | Cause | Fix |
|---|---|---|
| no Paper tools in the agent at all | server not registered with this agent | run the install line |
| tools present, every call errors | Paper Desktop closed | open it |
| connection refused on `:29979` | newer build, stdio only | use the plugin route |
| works locally, fails on the live site | expected — the server is local | explain the bridge; nothing is broken |
| `status` says not entitled | no `pro` entitlement | the usual licence path |

---

## 9. File layout

```
includes/integrations/paper/
├── paper.php                  # bootstrap, the nibwp/paper-manage hub, admin page
├── class-paper-ingest.php     # validate + accept an agent-supplied payload
├── class-paper-normalize.php  # Paper node tree → NDO 1.0
├── class-paper-tokens.php     # palette + type ramp  (source: 'paper')
├── class-paper-assets.php     # media sideload for agent-exported images
├── class-paper-library.php    # the design-record store
└── PAPER-INTEGRATION.md       # this file
```

Emitters are **not** here. `paper.php` autoloads `NIBWP_Figma_Html` and
`NIBWP_Figma_Gutenberg` from `../figma/` and declines to convert if either is
missing.

This tree was verified against the directory. Worth noting as a caution: the Figma
integration's own reference document lists several files that do not exist in that
directory — including an auth class, an `abilities/` directory and an `mcp/`
directory. Prefer describing behaviour over inventory, and re-read the directory
before trusting a tree in any doc.

---

## 10. Testing

There is no remote API to mock, which makes this unusually testable. The whole
input surface is one array.

- **Golden NDOs.** A handful of fixture NDOs — a flat artboard, a deep tree, a
  text-heavy page, one with a shader warning — through `preview_convert`, with
  the emitted HTML and block markup snapshotted.
- **Hostile NDOs.** This is where the real coverage is: a 500-deep tree, a
  10,000-node tree, `style` values containing `"` and `</style>`, an
  `image_url` pointing at `javascript:`, a `type` outside the six allowed values,
  a missing `children` key, `ndo_version` set to `"2.0"`. Each must be rejected or
  neutralized, never emitted.
- **Capability tests.** Every write action refused to a subscriber.
- **Entitlement tests.** Every action refused without `pro`.
- **No-network assertion.** A test that fails if any action attempts an outbound
  HTTP request. That invariant is the architecture; it deserves a test.
