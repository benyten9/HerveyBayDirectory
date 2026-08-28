# NibWP Figma Integration — Technical Reference

> The **plumbing** layer. Authenticated, headless access to Figma design data
> (node tree, images, variables, styles, comments). No conversion logic lives
> here — that belongs to the [`figma-pro` skill](../../skills/figma-pro/authoring/SKILL.md),
> which consumes these abilities. Ships bundled in the Pro plugin, gated by the
> `pro` entitlement.

---

## 1. Why this exists

Screenshot-based Figma→WP tools (e.g. instawp's Claude→Gutenberg→MCP flow) work
off a flat exported image and openly describe themselves as "refinement, not
one-click." They guess structure and re-type tokens by hand.

NibWP reads the **actual Figma document model** over the REST API: the node
tree with real geometry, auto-layout, constraints, fills, effects, typography,
and the file's **Variables** (its real design-token system). Structure and
tokens are *derived*, not inferred from pixels. The rendered 2× image is kept
only as **ground truth for a pixel-diff verify loop**, not as the source of
structure.

This integration is the read layer that makes that possible.

---

## 2. Architecture

```
┌─────────────────────────────────────────────────────────────┐
│ NibWP Figma Integration  (includes/integrations/figma/)      │
│                                                              │
│  Auth        OAuth2 app  ─┐                                  │
│              PAT paste   ─┴─► encrypted per-user token store  │
│                                                              │
│  Source      REST reader (default, headless)  ─┐             │
│              Dev Mode MCP (alt, needs Figma app)┴─► fetch()   │
│                                                              │
│  Abilities   figma-get-file / -node / -variables / -styles   │
│              figma-export-node   figma-post-comment          │
└───────────────────────────┬─────────────────────────────────┘
                            │  node JSON · 2× PNG · variables
                            ▼
            figma-pro skill  (orchestrator — separate doc)
```

**Boundary contract:** every ability returns plain PHP arrays (JSON-decoded
Figma payloads or NibWP-normalized shapes). The integration never renders WP,
never picks a builder, never validates a design. It only fetches and normalizes.

### File layout

```
includes/integrations/figma/
├── figma.php                # bootstrap: register integration, hooks, settings
├── class-figma-client.php   # REST client (auth header, rate-limit, retry)
├── class-figma-auth.php     # OAuth2 flow + PAT storage + token refresh
├── class-figma-normalize.php# raw Figma node → NibWP normalized node shape
├── abilities/
│   ├── get-file.php
│   ├── get-node.php
│   ├── export-node.php
│   ├── get-variables.php
│   ├── get-styles.php
│   └── post-comment.php
├── mcp/
│   └── class-devmode-source.php  # alt source behind the same abilities
└── FIGMA-INTEGRATION.md     # this file
```

---

## 3. Authentication

Figma supports two token types. Both go in the `X-Figma-Token` header (PAT) or
`Authorization: Bearer` (OAuth). NibWP supports both; OAuth is the "seamless"
path, PAT is the MVP.

### 3.1 OAuth2 (recommended, production)

Register **one** NibWP OAuth app at <https://www.figma.com/developers/apps>
(NibWP owns the `client_id` / `client_secret`; store the secret on nibwp.com,
never in the shipped plugin).

Authorization-code flow:

| Step | Call |
|------|------|
| 1. Redirect user | `GET https://www.figma.com/oauth?client_id={id}&redirect_uri={cb}&scope=file_read&state={csrf}&response_type=code` |
| 2. Callback | Figma redirects to `{cb}?code={code}&state={csrf}` |
| 3. Exchange | `POST https://api.figma.com/v1/oauth/token` body `client_id, client_secret, redirect_uri, code, grant_type=authorization_code` → `{access_token, refresh_token, expires_in}` |
| 4. Refresh | `POST https://api.figma.com/v1/oauth/token` `grant_type=refresh_token&refresh_token=…` |

- **Scope:** `file_read` for all read abilities. Adding `file_comments`
  (`comments:write` on newer scopes) enables `figma-post-comment`.
- Store `access_token` + `refresh_token` + `expires_at` per WP user, encrypted
  (see §7). Auto-refresh in `class-figma-auth.php` when within 5 min of expiry.
- The nibwp.com side proxies the `client_secret` exchange so the secret is never
  shipped in the plugin — the plugin sends `code` to a nibwp.com endpoint, gets
  tokens back. (Same trust model as the license server.)

### 3.2 Personal Access Token (MVP / fallback)

User generates a PAT at Figma → Settings → Security → Personal access tokens
(scope: file read). Pastes into **NibWP → Integrations → Figma → Token**. Stored
encrypted. Simplest possible first cut; no OAuth app required to prove the flow.

### 3.3 What a token can read

A token reads **every file the token's owner can open** — including that user's
**private files** and team files shared with them. It **cannot** read files
never shared with that user. No integration or MCP changes this; access is the
user's Figma account access. This is why "can it read private designs?" = yes,
as long as it's the user's own token.

---

## 4. The Figma REST endpoints used

Base: `https://api.figma.com`. A Figma URL
`https://www.figma.com/file/{fileKey}/Name?node-id=1-234` yields `fileKey` and a
`node-id` (`1:234` after decoding the `-`).

| NibWP ability | Figma endpoint | Notes |
|---|---|---|
| `figma-get-file` | `GET /v1/files/{key}` | Whole document. Add `?geometry=paths` for vector paths, `&depth=N` to cap tree depth. Large. |
| `figma-get-node` | `GET /v1/files/{key}/nodes?ids={a,b}` | Subtree(s) by id. **Preferred** when a node id is known — far cheaper than the whole file. |
| `figma-export-node` | `GET /v1/images/{key}?ids={id}&scale=2&format=png` | Returns a short-lived S3 URL per node; NibWP streams it down. `format` = png\|svg\|pdf\|jpg, `scale` 1–4. |
| `figma-get-variables` | `GET /v1/files/{key}/variables/local` | **Enterprise-only.** Collections + variables (colors, numbers, strings) with modes. Degrade to styles if 403. |
| `figma-get-styles` | `GET /v1/files/{key}/styles` + node fills | Published paint/text/effect styles. Fallback token source on non-Enterprise files. |
| `figma-post-comment` | `POST /v1/files/{key}/comments` body `{message, client_meta:{node_id, node_offset}}` | The one write path. Needs comment scope. |

### Rate limits & resilience

- Figma REST is rate-limited (per-token, per-endpoint; image renders are the
  tightest). `class-figma-client.php`:
  - Honors `Retry-After` on `429` with capped exponential backoff (max 3 tries).
  - Caches file/node responses in a transient keyed by `fileKey:nodeId:version`
    (the file's `version` field busts it) — default 10 min TTL.
  - Time-boxes image exports (render can be slow for big frames); on timeout,
    returns a partial-with-warning rather than hanging the skill.
- All failures return a normalized `{ ok:false, code, message, http }` shape so
  the skill can branch (e.g. `403 variables` → fall back to `get-styles`).

---

## 5. The Figma node model (reference for consumers)

`class-figma-normalize.php` flattens Figma's raw nodes into a stable NibWP shape
so the skill and builders don't couple to Figma's exact JSON. Key raw fields the
normalizer reads:

### 5.1 Node types → intent

| Figma `type` | Typical intent | Maps toward |
|---|---|---|
| `FRAME` / `SECTION` | page section / page | container / section |
| `COMPONENT` / `INSTANCE` | reusable component | block / pattern |
| `GROUP` | visual grouping | container (often collapsible) |
| `TEXT` | text run | heading/paragraph (by style) |
| `RECTANGLE`/`ELLIPSE`/`VECTOR` | shape / decorative | div w/ bg, or exported SVG |
| `LINE` | divider | `<hr>` / border |
| image fill on any node | media | `<img>` from export or fill ref |

### 5.2 Auto-layout → flexbox (the structural core)

Figma auto-layout is a flexbox model. Normalizer emits a `layout` block:

| Figma field | Value | CSS |
|---|---|---|
| `layoutMode` | `HORIZONTAL` / `VERTICAL` | `flex-direction: row / column` |
| `primaryAxisAlignItems` | `MIN/CENTER/MAX/SPACE_BETWEEN` | `justify-content` |
| `counterAxisAlignItems` | `MIN/CENTER/MAX/BASELINE` | `align-items` |
| `itemSpacing` | px | `gap` |
| `paddingLeft/Right/Top/Bottom` | px | `padding` |
| `layoutWrap` | `WRAP` | `flex-wrap: wrap` |
| `layoutGrow` (child) | 0/1 | `flex-grow` |
| `layoutSizingHorizontal/Vertical` | `FIXED/HUG/FILL` | fixed px / fit-content / 100% |

Nodes **without** auto-layout carry `absoluteBoundingBox` (x/y/w/h) — normalizer
flags them `absolute:true` so the builder can decide (absolute-position vs
best-effort flow). Prefer auto-layout frames for clean output; warn on absolute.

### 5.3 Constraints → responsive hints

`constraints: { vertical, horizontal }` (`MIN/MAX/CENTER/STRETCH/SCALE`) →
responsive intent (e.g. `STRETCH` horizontal → full-width; `SCALE` → fluid).
Advisory to the builder's breakpoint logic.

### 5.4 Fills / strokes / effects

| Figma | Normalized | CSS target |
|---|---|---|
| `fills[].type=SOLID` + `color` + `opacity` | color token ref or rgba | `background` / `color` |
| `fills[].type=GRADIENT_*` | gradient stops | `linear/radial-gradient` |
| `fills[].type=IMAGE` + `imageRef` | image asset | `background-image` / `<img>` |
| `strokes[]` + `strokeWeight` | border | `border` |
| `cornerRadius` / `rectangleCornerRadii` | radius | `border-radius` |
| `effects[] DROP_SHADOW/INNER_SHADOW` | shadow | `box-shadow` |
| `effects[] LAYER_BLUR/BACKGROUND_BLUR` | blur | `filter` / `backdrop-filter` |

Every color is matched against the file's Variables/Styles first; only unmatched
colors become raw values (and are flagged for the skill to token-ize).

### 5.5 Typography

`TEXT` node `style`: `fontFamily, fontWeight, fontSize, lineHeightPx/Percent,
letterSpacing, textAlignHorizontal, textCase, textDecoration` → a type-style
descriptor. Matched against text Variables/Styles → a **type-ramp slot**
(display/h1/h2/body/caption) rather than a one-off size. Per the NibWP font rule:
emit token refs (`var(--text-l, 20px)`), **never `clamp()`** for font-size.

---

## 6. Variables & design tokens (`figma-get-variables`)

The highest-value read. `/v1/files/{key}/variables/local` returns:

- **Collections** (e.g. "Primitives", "Semantic") each with **modes** (Light/Dark).
- **Variables** typed `COLOR | FLOAT | STRING | BOOLEAN`, each with per-mode
  `valuesByMode`, and possible **aliases** (a semantic var pointing at a
  primitive — resolve the chain).

Normalizer output = a flat token map the skill maps to ACSS/global tokens:

| Figma variable | NibWP token intent |
|---|---|
| COLOR (semantic, e.g. `color/primary`) | `--primary` / ACSS palette slot |
| FLOAT under a "space" collection | `--space-*` scale |
| FLOAT under a "radius" collection | `--radius-*` |
| type sizes | `--text-*` ramp |
| mode = Light/Dark | ACSS dark-mode / theme layer |

Enterprise-gated: on `403`, fall back to `figma-get-styles` + inline fills to
reconstruct a (coarser) token set. The skill is told which source it got so it
can set expectations ("tokens derived from styles, not Variables").

---

## 7. Security & data handling

- **Token storage:** encrypt at rest with authenticated encryption (libsodium
  `sodium_crypto_secretbox`; AES-256-GCM where libsodium is unavailable), key from
  a WP salt-derived secret; never plaintext in options/meta. **One token set per WP
  user, never shared; revocable** — the user can disconnect Figma to wipe their
  tokens, and OAuth tokens can be revoked at Figma's end.
- **No secret in the plugin:** OAuth `client_secret` lives on nibwp.com; the
  plugin exchanges `code` through a nibwp.com proxy endpoint.
- **PII / content:** Figma files may contain client content. NibWP fetches only
  on explicit user action, caches transiently, and never forwards Figma payloads
  to third parties. Image export URLs are short-lived S3 links — streamed down,
  not stored as URLs.
- **Least privilege:** default scope `file_read`; comment scope only if the user
  opts into write-back-comments.
- **Entitlement gate:** all abilities check the `pro` entitlement before running.

---

## 8. Two read sources — Dev Mode MCP + REST

Figma exposes structured design context two ways, and NibWP supports **both**
behind the *same* abilities (the fetch differs; everything downstream is
identical — data normalizes into the same shape either way):

- **Figma Dev Mode MCP — recommended when the designer is working live.** Richest
  AI-native context (components, variables, current selection), purpose-built to
  give agents structured design data instead of screenshots. Needs the Figma
  **desktop app** open + a paid **Dev/Full seat**.
- **REST reader — the headless default / fallback.** Works server-side with no app
  open and no paid seat for reads, and reads the user's **private** files via their
  token. This is what runs in NibWP's server-side flow.

Rule of thumb: **MCP for live in-app context, REST for headless/automated.** If the
user wants live context and the MCP isn't reachable, NibWP **suggests installing
Figma desktop + enabling Dev Mode MCP**.

Figma's official **Dev Mode MCP server** runs locally inside the Figma desktop
app and exposes selected-frame context to an AI client. NibWP wires it as an
*alternate source behind the same abilities*:

- `class-devmode-source.php` implements the same `fetch()` contract as the REST
  client. If the user has the Figma desktop app + Dev Mode MCP reachable, NibWP
  can pull the current selection from it instead of a REST file key.
- **Detection & suggestion:** if the user wants live in-app context, NibWP
  detects the MCP and, if absent, **suggests installing Figma desktop + enabling
  Dev Mode MCP** (needs a paid Dev/Full seat + the app open).
- Everything downstream (normalize → skill → builder → verify) is identical.
  MCP vs REST only swaps *how the node JSON arrives*.
- REST stays the **default** because it's headless (no app, no paid seat for
  reads) — the right fit for NibWP's server-side flow.

> Note: the `plugin:figma` MCP connector in an AI session needs interactive
> OAuth and can't be authorized headless — that's a *session-tooling* concern,
> unrelated to this plugin-side integration.

---

## 9. Testing

- **Fixture-based unit tests:** check in a small real Figma file JSON + a
  Variables JSON. Assert `class-figma-normalize.php` produces the expected
  `layout`, token map, typography ramp, and fill/effect mappings.
- **Live smoke:** one real `fileKey` through `get-node` + `export-node` → assert
  2× PNG streamed, size > 0, node subtree shape valid.
- **Auth:** OAuth refresh path (token near expiry → refresh → retry) + PAT path.
- **Degradation:** simulate `403` on variables → assert clean fallback to styles.
- **Rate limit:** simulate `429` + `Retry-After` → assert capped backoff, no hang.

---

## 9b. Import/push ingest (Path B) — companion Figma plugin

The abilities above are the **read** path (NibWP pulls Figma). NibWP also accepts a
**push** path: a companion NibWP Figma plugin (or any client) converts in-Figma and
POSTs the design to REST import routes under the `nibwp/figma` namespace. The push
payload is the **builder-agnostic NDO**, so one import route → any builder, with
dedup and pixel-diff verify. Full contract in the skill KB:
`includes/skills/figma-pro/authoring/integrations/import-api.md`. Essentials:

- `POST nibwp/figma/import` — an NDO payload → emit → sideload → globals-sync →
  verify → draft. `POST …/import/builder/{key}` accepts pre-built builder JSON too.
  `GET …/targets` lists import-into posts; `GET …/config` reports active builders.
- **Auth:** admin cookie+nonce, or a WP **Application Password** minted with a
  recognizable prefix (`nibwp-figma-*`, `nibwp-mcp-*`) that the Figma plugin stores
  alongside the site's `rest_url()` (plain-permalink fallback `index.php?rest_route=`).
- **MCP relay from inside Figma:** hook `rest_pre_dispatch` on NibWP's MCP route to
  auto-mint an `Mcp-Session-Id` when a request arrives without one — a Figma plugin
  relays MCP calls through Figma's main-thread `fetch`, which strips the header the
  client would read the session id from. This makes NibWP's MCP ability surface
  callable from inside Figma, not just from a desktop AI client.
- **First-party media:** images come from the user's Figma export or their own media,
  never a hosted third-party bucket.

## 10. Phase 2 — write-back (out of scope for v1)

Figma REST **cannot create/edit design nodes** (only comments/webhooks, and
Enterprise variable writes). Writing WP → Figma design nodes requires a separate
**Figma plugin** using the Plugin API (`figma.createFrame`, `createText`,
auto-layout setters), running inside Figma desktop, talking to NibWP. Tracked as
a separate deliverable/repo. v1 is read-only except `figma-post-comment`.

---

## 11. Ability quick-reference

| Ability | Params | Returns |
|---|---|---|
| `figma-get-file` | `file_key`, `depth?`, `geometry?` | normalized document tree + file `version` |
| `figma-get-node` | `file_key`, `node_id` | normalized node subtree |
| `figma-export-node` | `file_key`, `node_id`, `scale=2`, `format=png` | local path to streamed image + dimensions |
| `figma-get-variables` | `file_key` | token map (collections/modes/aliases resolved) or `403→styles` fallback flag |
| `figma-get-styles` | `file_key` | published paint/text/effect styles |
| `figma-post-comment` | `file_key`, `node_id`, `message` | comment id |

All return the normalized `{ ok, data?, code?, message?, http? }` envelope.
