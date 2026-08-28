---
name: figma-pro
description: >-
  Convert a Figma design into a native, maintainable WordPress build. Use this
  skill whenever the user references a Figma file, frame, component, or link and
  wants it turned into a WordPress page, section, block, design system, or theme —
  phrasings like "convert this Figma to WP", "build this frame in Etch/Bricks/
  Elementor", "turn my Figma design into a page", "import this Figma component",
  "make a WordPress design system from this Figma library", "create a theme from
  this Figma file", or pasting a figma.com URL and asking to recreate it. The
  skill reads the REAL Figma node tree and Variables (not a screenshot),
  normalizes them into an internal design object, establishes a design-token
  system, drives the site's active builder to build natively, and verifies the
  result by image-diffing the rendered page against Figma's own export. Trigger it
  even when the user doesn't say "figma-pro" or name a builder — a Figma source
  plus an intent to build in WordPress is enough.
compatibility: Requires the NibWP Figma integration (pro entitlement) and at least
  one builder skill (etchwp-pro / bricks-pro / elementor-pro) or Gutenberg core.
---

# figma-pro — Figma intelligence → native WordPress

## What this is (and what it is NOT)

Most Figma→WP tools do `screenshot → HTML`. They guess structure and re-type
colors. figma-pro does:

```
Figma intelligence → design-system understanding → semantic components
                   → native WordPress → maintainable website
```

It reads the actual Figma **document** (node tree, auto-layout, constraints,
Variables), so structure and tokens are **derived**, not inferred from pixels. It
is an **orchestrator**: it does not hand-write builder markup and it does not call
Figma's API directly — it stands between them.

## Architecture

Two ingest paths converge on one NDO, one pipeline:

```
Figma
  │
  ├─ Path A · READ    NibWP reads Figma itself (MCP primary / REST fallback),
  │                   headless, AI-driven — no Figma plugin needed.
  │                   integrations/figma-mcp.md · figma-api.md
  │
  └─ Path B · IMPORT  a companion NibWP Figma plugin converts in-Figma and
                      POSTs the NDO to REST import routes. integrations/import-api.md
  ▼
NibWP Figma Connector  (integration abilities)
  ▼
Design Intelligence Engine  (core/parser.md)
  ├─ Structure Parser  ──►┐
  └─ Design Token Engine ─┴─►  NDO  (NibWP Design Object — core/schema.md)
  ▼
WordPress Translation Layer  (builders/*.md adapters)
  ├─ EtchWP   ├─ Bricks   ├─ Elementor   └─ Gutenberg
  │   + token → builder-native globals sync   (builders/globals-sync.md)
  │   + asset sideload + dedup                 (core/assets.md)
  ▼
Pixel-diff verify → WordPress database  (draft + backup)
```

The **NDO** is the pivot: however the design arrives (read or import), it becomes
one NDO; every builder adapter builds from it. One skill, any builder, no
per-target re-parsing. The import path (Path B) accepts the **builder-agnostic
NDO** — so one import → any builder, with dedup, pixel-diff verify, and first-party
media, all local. See `integrations/import-api.md`.

## The conversion hierarchy

Always walk the design top-down:

```
Figma Frame → Sections → Containers → Components → Variables → Styles → Assets
            → WordPress Elements
```

## The pipeline

1. **Resolve** the target node (URL → fileKey + node-id). Any granularity: frame→
   page, component→block. If given a bare file link, list frames and ask which.
2. **Fetch** via the connector (MCP or REST) — node tree, 2× PNG export (kept as
   verify ground-truth), Variables (→ styles fallback on non-Enterprise).
   `integrations/figma-mcp.md`, `integrations/figma-api.md`.
3. **Token Engine** — establish tokens **first**: Variables → ACSS/global
   `var(--token, fallback)`; text styles → a type ramp; spacing/radius scales.
   Never `clamp()` font-size. `core/tokens.md`.
4. **Structure Parser** — build the NDO node tree + inferred roles.
   `core/parser.md`, `core/nodes.md`.
5. **Component detection** — dedupe repeated instances into ONE definition + N
   instances; propose dynamic data (ACF/loop) for content-repeating components.
   `core/components.md`.
6. **Decision Engine** — compute the **complexity score**, pick a **conversion
   mode**, flag dynamic-data candidates. `core/parser.md`.
7. **Pick builder + compose** — auto-detect the site's active builder ∩ the user's
   entitlement; prefer active; **fallback Gutenberg**. Announce + allow one-word
   override. Then scan for active **enhancer** skills and **auto-fold** them (no
   asking): **acss-pro** (real ACSS tokens, runs *before* the builder) and
   **seo-pro** (semantics/meta, runs *after*). One convert can chain
   figma-pro → acss-pro → builder → seo-pro. `core/composition.md`.
8. **Delegate** the NDO to the builder adapter, which runs its own validated spine
   (`validate → score → dry_run → persist`). `builders/*.md`. The adapter also
   **syncs tokens into the builder's native globals** (Elementor Kit/Atomic, Bricks
   classes, Gutenberg `theme.json`, ACSS) — `builders/globals-sync.md` — and
   **sideloads assets** (`core/assets.md`). Never two builders on one output —
   enhancers only.
9. **Verify** — render with Playwright at the frame width, pixel-diff vs the Figma
   export, triage + iterate until under threshold or gaps are explained.
10. **Persist** as a **draft** (backup, never overwrite live) + a warnings report.

## Conversion modes (`core/parser.md`)

| Mode | Goal | Use for |
|---|---|---|
| `exact_clone` | pixel similarity | one-off marketing pages |
| `native` (default) | maintainability | most sites — reusable components + dynamic fields + tokens |
| `design_system` | a reusable system | a Figma library / whole file → tokens + component library + templates |

Default to **native**. A maintainable site beats a brittle pixel clone.

## Abilities (`abilities/*.php`)

| Ability | Purpose |
|---|---|
| `figma-pro-convert` | the full pipeline above |
| `figma-pro-tokens` | steps 2–3 only — extract + map the token system |
| `figma-pro-preview` | fetch + dry_run build + return diff score, **no persist** |
| `figma-pro-detect-builder` | recommend the builder + why, and list active enhancers to auto-fold |

## Slash commands (`workflows/`)

| Command | Runs |
|---|---|
| `/figma analyze` | read-only report: pages/frames/sections/components/tokens |
| `/figma convert <target>` | `figma-pro-convert` on a frame/component |
| `/figma extract tokens` | `figma-pro-tokens` (design-system setup) |
| `/figma create components` | build the reusable component set |
| `/figma sync design system` | Variables → builder global colors/fonts/classes |

## AI prompt rules — the difference between native and a screenshot copy

**Always:**
- ✅ Use native WordPress elements (the target builder's real primitives).
- ✅ Establish design tokens first; reference `var(--token, fallback)` everywhere.
- ✅ Create reusable components; bind repeating content to dynamic data.
- ✅ **Auto-compose** active enhancer skills (acss-pro, seo-pro) — don't ask; report
  the chain at the end. `core/composition.md`.
- ✅ Respect the original spacing, hierarchy, and responsive intent.
- ✅ Verify against the Figma export; report every warning honestly.

**Never:**
- ❌ Emit one giant HTML block.
- ❌ Ignore components / duplicate repeated sections.
- ❌ Hardcode colors or sizes that have a token.
- ❌ Use `clamp()` for font-size (the Etch/ACSS validator rejects it).
- ❌ Write builder meta directly (bypasses slashing/CSS-regen/backups → blank
  pages). Always go through the builder adapter.

## When NOT to use this skill

- Editing an existing WP page with no Figma involved → the builder skill directly.
- Pushing WP → Figma (write-back) → phase-2 Figma plugin, not built yet. Say so.
- No Figma connected / no token → guide the user to connect Figma first.

## Knowledge base map

Read the file for the step you're on — kept out of this file for progressive
disclosure.

```
figma-pro/authoring/
├── SKILL.md                     ← this index
├── core/
│   ├── parser.md      Design Intelligence Engine: parse, roles, complexity, modes, dynamic data
│   ├── schema.md      the NDO (NibWP Design Object) intermediate format
│   ├── nodes.md       Figma node/layout → structure & CSS mapping
│   ├── components.md  component detection, dedupe, variants, dynamic data
│   ├── tokens.md      Variables/Styles → ACSS tokens + type ramp
│   ├── responsive.md  breakpoints, frame-matching, column→stack rules
│   ├── composition.md skill composition — auto-fold acss-pro/seo-pro, never 2 builders
│   └── assets.md      image sideload + dedup, inline SVG, fonts (first-party media)
├── integrations/
│   ├── figma-mcp.md   Figma Dev Mode MCP (primary read source — Path A)
│   ├── figma-api.md   Figma REST API (fallback — Path A) + ability mapping
│   └── import-api.md  Path B — REST import routes, Figma-plugin push, MCP relay
├── builders/
│   ├── etchwp.md      NDO → EtchWP (priority #1)
│   ├── bricks.md      NDO → Bricks
│   ├── elementor.md   NDO → Elementor (wp_slash hazard, live registry)
│   ├── gutenberg.md   NDO → core blocks (universal fallback)
│   └── globals-sync.md tokens → builder-native globals (Kit/Atomic/Bricks/theme.json/ACSS)
├── workflows/
│   ├── landing-page.md     Figma page → WordPress page
│   ├── ecommerce.md        Figma → WooCommerce product template
│   ├── design-system.md    Figma library → WordPress design system
│   └── theme-builder.md    Figma file → WordPress theme
└── examples/
    ├── agency.md      homepage → Etch (worked end-to-end)
    ├── saas.md        SaaS site → Bricks
    └── ecommerce.md   product page → Elementor + Woo
```

## Killer feature (the pitch)

Not `screenshot → HTML`. It's: **Figma intelligence → design-system understanding
→ semantic reusable components → native WordPress → production website** — verified
pixel-for-pixel against the source. That's what makes figma-pro the strongest skill
in the NibWP catalog: it bridges AI agent → Figma → WordPress → builders, natively.
