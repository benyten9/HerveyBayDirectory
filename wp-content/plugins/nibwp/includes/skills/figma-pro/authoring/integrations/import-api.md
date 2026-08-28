# integrations/import-api.md — the IMPORT/PUSH path (NibWP Figma plugin → WP)

The **second** ingest path. Where the READ path (`integrations/figma-mcp.md` +
`figma-api.md`) has NibWP reach *into* Figma headlessly, this path lets a
companion **NibWP Figma plugin** (or any authenticated client) convert the design
**in-Figma** and **POST** it to NibWP's REST import routes.

**The design that matters:** NibWP's Figma plugin POSTs the **NDO** (NibWP Design
Object — `core/schema.md`) rather than builder-native JSON. One import → **any**
builder via our per-builder emitters, plus component dedup and pixel-diff verify.
A single builder-agnostic intermediate means no near-duplicate per-builder import
handlers. Images come from the user's own Figma export or media library and are
sideloaded locally — never third-party image hosting.

## Table of contents
1. REST routes (`nibwp/figma` namespace)
2. Route reference + request/response examples
3. Auth for the Figma plugin / MCP clients (App Passwords)
4. MCP relay from inside Figma (`ensure_relay_session`)
5. The two paths converge (READ + IMPORT → one pipeline)
6. Security
7. Design advantages
8. Cross-references

---

## 1. REST routes

All routes are registered on `rest_api_init` under the **`nibwp/figma`** namespace
and share one admin permission callback:

```php
add_action( 'rest_api_init', function () {
  register_rest_route( 'nibwp/figma', '/import', [
    'methods'             => 'POST',
    'callback'            => 'nibwp_figma_import',
    'permission_callback' => 'nibwp_figma_can_import',   // §6
  ] );
  // …/import/builder/(?P<builder>etchwp|bricks|elementor|gutenberg), /targets, /config
} );
```

`nibwp_figma_can_import()` grants access when **either**: the request carries a
valid logged-in admin session (cookie + `X-WP-Nonce`) with `manage_options`, **or**
it authenticates via a **WP Application Password** (Basic auth) belonging to a
`manage_options` user. No public writes, ever.

| Method & route | Body | Does |
|---|---|---|
| `POST /import` | `{ ndo, target, builder?, mode? }` | **preferred** — NDO → emitter → dedup → verify → draft |
| `POST /import/builder/{etchwp\|bricks\|elementor\|gutenberg}` | `{ title, content, page_settings }` | compat — accepts pre-built builder-native JSON |
| `GET /targets` | — | list posts/pages to import INTO (id + title + builder) |
| `GET /config` | — | installed/active builders + versions + active skills |

Base URL is whatever `rest_url()` returns, e.g.
`https://site.com/wp-json/nibwp/figma/import`. On sites with **plain permalinks**
the client must use the fallback base
`https://site.com/index.php?rest_route=/nibwp/figma/import`.

## 2. Route reference + examples

### `POST /import` — generic NDO import (preferred)

```
POST /wp-json/nibwp/figma/import
Authorization: Basic <base64 user:app-password>
Content-Type: application/json

{
  "ndo": { "ndo_version": "1.0", "root": { … }, "tokens": { … },
           "components": { … }, "assets": [ … ], "meta": { … } },
  "target":  { "type": "page", "title": "Homepage" },
  "builder": "auto",                       // auto | etchwp | bricks | elementor | gutenberg
  "mode":    "new_page"                    // new_page | new_post | update
}
```

`builder:"auto"` picks the first active builder in preference order
(etchwp → bricks → elementor → gutenberg). `mode:"update"` requires
`target.post_id`. The handler runs the NDO through the chosen builder emitter,
dedups repeated components, sideloads assets (`core/assets.md`), syncs token
globals (`builders/globals-sync.md`), runs the pixel-diff verify, and persists a
**draft**.

Response:

```json
{
  "ok": true,
  "post_id": 4812,
  "builder": "etchwp",
  "status": "draft",
  "edit_url":    "https://site.com/wp-admin/post.php?post=4812&action=edit",
  "preview_url": "https://site.com/?p=4812&preview=true",
  "verify":  { "score": 0.982, "diff_url": "https://site.com/wp-content/uploads/nibwp/diff-4812.png" },
  "dedup":   { "components": 3, "instances": 11 },
  "assets":  { "sideloaded": 7, "reused": 2 },
  "warnings": [ { "code": "missing_font", "detail": "Inter not on target" } ]
}
```

### `POST /import/builder/{builder}` — builder-native compat

For a client that already built for one specific builder. The body is **not** an
NDO — it is that builder's own payload:

```
POST /wp-json/nibwp/figma/import/builder/elementor
{ "title": "Landing", "content": [ /* Elementor elements JSON */ ],
  "page_settings": { … } }
```

Still runs image **sideload** + **token-globals sync** + optional verify before a
**draft** persist — so even the compat path keeps all media first-party on the
user's own site and never overwrites live. Bricks expects its `content` array,
Gutenberg expects block markup (or blocks JSON), Etch expects its node payload.

### `GET /targets`

```json
{ "ok": true, "targets": [
  { "id": 2, "title": "Sample Page", "type": "page", "builder": "gutenberg" },
  { "id": 4812, "title": "Homepage", "type": "page", "builder": "etchwp" } ] }
```

The Figma plugin uses this to populate its "import into…" dropdown.

### `GET /config`

```json
{ "ok": true,
  "builders": [
    { "slug": "etchwp",    "active": true,  "version": "1.4.0" },
    { "slug": "bricks",    "active": true,  "version": "1.9.9" },
    { "slug": "elementor", "active": false, "version": null },
    { "slug": "gutenberg", "active": true,  "version": "core" } ],
  "skills": { "figma-pro": "1.1.7", "elementor-pro": "1.0.0" },
  "rest_base": "https://site.com/wp-json/nibwp/figma",
  "plain_permalink_base": "https://site.com/index.php?rest_route=/nibwp/figma" }
```

Lets the plugin show the user exactly which builders and enhancer skills their
site supports.

## 3. Auth for the Figma plugin / MCP clients

The Figma plugin authenticates with WP's native **Application Passwords**:

1. From the **NibWP dashboard**, the admin mints an Application Password with a
   recognizable prefix — `nibwp-figma-*` for the Figma plugin, `nibwp-mcp-*` for
   MCP clients — via `WP_Application_Passwords::create_new_application_password()`.
2. NibWP hands the plugin a **connectUrl** (`rest_url( 'nibwp/figma' )`) + the
   app password. The plugin stores both.
3. The plugin authenticates every import call with **Basic auth**
   (`Authorization: Basic base64(user:app-password)`). App Passwords are scoped to
   one user and individually revocable — kill a leaked key without touching the
   login password.
4. If permalinks are plain, the plugin falls back to the
   `index.php?rest_route=/nibwp/figma/…` base (Application Passwords ride Basic
   auth, which does not depend on pretty permalinks).

## 4. MCP relay from inside Figma

To make NibWP's MCP callable **from inside a Figma plugin** (AI-assisted convert,
not just Claude Desktop), NibWP auto-mints a relay session server-side.

A Figma plugin relays MCP calls through Figma's **main-thread `fetch`**, which
**strips the response header** the MCP client would normally read the
`Mcp-Session-Id` from — so the client can never learn its session id and every
subsequent call is sessionless. Fix it server-side: hook **`rest_pre_dispatch`**
on the NibWP MCP route and **auto-mint** an `Mcp-Session-Id` for the current admin
when a request arrives **without** one.

```php
add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
  if ( 0 !== strpos( $request->get_route(), '/nibwp/mcp' ) ) return $result;
  if ( ! $request->get_header( 'mcp_session_id' ) && current_user_can( 'manage_options' ) ) {
    $sid = nibwp_mcp_relay_session_for_current_user();   // stable per-user id, minted once
    $request->set_header( 'Mcp-Session-Id', $sid );      // inject so dispatch sees a session
  }
  return $result;
}, 10, 3 );
```

Because the id is derived per logged-in admin, the relayed call is bound to that
user and survives the stripped header. NibWP ships a **rich MCP ability surface**:
the `nibwp/*` abilities (figma reads, builder emit, import, verify) are live out
of the box.

## 5. The two paths converge

READ and IMPORT differ only at the **front door**. The moment a design becomes an
**NDO**, both flow through the identical pipeline:

```
  PATH A — READ (headless, agent-driven)        PATH B — IMPORT (Figma plugin)
  figma-mcp.md / figma-api.md                   POST /import  (this doc)
        │  NibWP reads Figma                           │  plugin converts in-Figma
        │  (REST or Dev Mode MCP)                      │  and POSTs the NDO
        ▼                                              ▼
        └──────────────►   NDO   ◄──────────────────── (or builder-native → adapted)
                     (core/schema.md)
                            │
                            ▼
        emit (per-builder)  →  dedup (components, not flatten)
                            →  token-globals sync (builders/globals-sync.md)
                            →  pixel-diff verify (vs Figma/plugin export)
                            →  DRAFT persist (+ backup, never overwrite live)
                            ▼
                    post_id + edit_url + verify score
```

One pipeline, one set of emitters, one verify loop — regardless of how the design
arrived. Adding the IMPORT path costs four thin routes, **not** a second builder
stack.

## 6. Security

- **Admin-only routes.** Every route's `permission_callback` requires
  `manage_options` — via cookie+nonce for the dashboard, or a `manage_options`-owned
  App Password for the plugin. No anonymous writes.
- **App Password scoping.** Prefixed (`nibwp-figma-*` / `nibwp-mcp-*`), per-user,
  individually revocable; never the account password.
- **First-party media only.** Images come from the user's Figma export or their own
  media library and are sideloaded **locally** (`core/assets.md`). No third-party
  image hosting.
- **Validate & sanitize the incoming NDO.** Reject unknown `ndo_version`, enforce
  the schema (`core/schema.md`), cap tree depth/asset count, sanitize every text
  node and URL before persist. Treat the payload as untrusted client input.
- **Draft + backup, never overwrite live.** All imports land as **draft**;
  `mode:"update"` snapshots the current post before writing. The user reviews the
  preview and publishes deliberately.
- Full plumbing + threat notes: `includes/integrations/figma/FIGMA-INTEGRATION.md`
  **§7 (Security & data handling)**.

## 7. Design advantages

- **NDO-in, not builder-native-in** → one import targets **any** builder,
  including **Etch**.
- **Component dedup, not flatten** → repeated cards import as one definition + N
  instances.
- **Pixel-diff verify** → the import self-checks against the export and reports a
  score.
- **Local, first-party media** → images stay on the user's own site.
- **Real MCP tools** → NibWP's ability surface works out of the box.
- **Gutenberg tokens** → token-globals sync applies to the block editor too, not
  just page builders.

## 8. Cross-references

- `core/schema.md` — the NDO these routes accept and produce.
- `integrations/figma-mcp.md` + `integrations/figma-api.md` — Path A (READ).
- `builders/globals-sync.md` — token-globals sync step.
- `core/assets.md` — local image sideload (first-party media only).
- `includes/integrations/figma/FIGMA-INTEGRATION.md` §7 — security & data handling.
