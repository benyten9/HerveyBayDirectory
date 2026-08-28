# core/parser.md — Design Intelligence Engine

Turns raw Figma (from MCP or REST) into the NDO (`core/schema.md`), and makes the
decisions that separate a *native, maintainable* build from a screenshot copy.
Two cooperating parts:

```
Figma JSON ──► Structure Parser ──►┐
                                   ├──► NDO
Figma Variables ──► Token Engine ──┘
        (see core/tokens.md)
        │
        └──► Decision Engine: complexity score · conversion mode · dynamic-data
```

The Structure Parser builds the node tree + roles; the Token Engine (documented in
`core/tokens.md`) builds the token system; the Decision Engine chooses *how* to
build. This file covers the parser + the decisions.

## Table of contents
1. Figma JSON → NDO node
2. Role inference
3. Component detection (summary)
4. Design Complexity Score
5. Conversion modes
6. Dynamic data / ACF decisions
7. Order of operations

---

## 1. Figma JSON → NDO node

For each Figma node, the parser:
1. Maps raw `type` → NDO `type` (`FRAME`→`section`/`container`, `TEXT`→`text`,
   `INSTANCE`→`component_instance`, etc. — table in `core/nodes.md`).
2. Normalizes auto-layout → the NDO `layout` object (flex/grid/absolute).
3. Resolves fills/strokes/effects → `styles`, matching each against a token first
   (raw + flagged otherwise).
4. Resolves text `style` → a type-ramp slot in `styles.typography`.
5. Recurses into `children`.

Example:
```
Figma:  { "type":"FRAME", "name":"Hero Section",
          "layoutMode":"VERTICAL", "itemSpacing":24, "children":[…] }
NDO:    { "type":"section", "role":"hero", "name":"Hero Section",
          "layout":{ "type":"flex","direction":"column","gap":"--space-l" },
          "children":[…] }
```

## 2. Role inference

Raw Figma has no semantics — the parser infers a `role` so adapters can emit
meaningful elements (a `<header>`, an `<h1>`, a `<footer>`), not anonymous divs.
Signals, in priority:

| Signal | Example → role |
|---|---|
| layer name keywords | "Hero", "Nav/Navbar", "Footer", "CTA", "Pricing", "FAQ" |
| position in frame | first full-width band → `nav`/`hero`; last → `footer` |
| content shape | a row of links at top → `nav`; big headline + button → `hero` |
| repetition | a strip of equal cards → `features`/`logos` |

Role is a hint, not a guarantee — when unsure, leave `role:null` and let the
adapter treat it structurally. Never force a wrong semantic.

## 3. Component detection (summary)

Two paths (full rules in `core/components.md`):
- **Explicit:** `INSTANCE` nodes sharing a `componentId` → one NDO component
  definition + instances.
- **Implicit:** near-identical sibling subtrees (same structure, differing only in
  text/image/icon leaves) → cluster into one definition with fields, even when the
  designer never made them components.

The parser writes definitions into `components{}` and replaces uses with
`component_instance` nodes carrying `props`.

## 4. Design Complexity Score

Before building, score the design so the agent picks the right strategy and sets
user expectations. A 0–100 heuristic from countable signals:

| Signal | Adds |
|---|---|
| # of distinct sections | +3 each |
| # of unique components | +5 each |
| component variants present | +5 each variant group |
| dynamic/repeating data (loops) | +8 each |
| WooCommerce / forms / interactive | +10 each |
| multiple breakpoints defined | +6 |
| absolute-positioned / overlap-heavy layers | +8 (risk) |
| custom effects (blur, blend, masks) | +5 |

Bands:
- **0–35 — Simple** (marketing/landing): direct conversion, few components.
- **36–70 — Moderate** (SaaS site, multi-section): component architecture matters;
  dedupe + variants required.
- **71–100 — Complex** (dashboard, store, theme): needs full component + dynamic-
  data architecture; consider building the design system first, then pages.

The score is advisory — it steers mode + effort and is reported to the user, not a
gate. Store it in `meta.complexity_score`.

## 5. Conversion modes

The mode sets the trade-off between visual exactness and maintainability. Pick from
the user's intent (or ask when ambiguous); store in `meta.conversion_mode`.

| Mode | Goal | Use for | Behavior |
|---|---|---|---|
| **exact_clone** | pixel similarity | one-off marketing/landing pages | favor precise geometry; still tokenized, but less aggressive componentization |
| **native** (default) | maintainability | most sites | reusable components + dynamic fields + tokens; some pixel drift accepted for clean structure |
| **design_system** | a reusable system | a Figma component library / whole file | output tokens + component library + templates, not just one page |

Default to **native** — a maintainable site beats a brittle pixel clone for almost
everyone. Use `exact_clone` when the user explicitly wants a faithful one-off, and
`design_system` when the input is a library or the user asks for a system/theme.

## 6. Dynamic data / ACF decisions

Content that repeats with structure = data, not markup. When the parser detects a
component instanced with **different content each time** (feature cards, team
members, pricing tiers, testimonials, products), the Decision Engine proposes
binding it to **dynamic data** instead of static duplication:

- Derive fields from the component (e.g. Team Member → `name`, `position`, `photo`,
  `bio`).
- Recommend an **ACF field group** (or CPT) for the data, and a **loop/query** in
  the builder (Etch loop, Bricks query loop, Elementor loop grid, `core/query`).
- Static content (a single hero, one CTA) stays inline — don't over-engineer a
  one-off into a CPT.

This is what makes the output a *website*, not a flattened page: edit the data, the
design updates everywhere. Detail per builder in `core/components.md` +
`builders/*.md`; the WooCommerce/theme cases are in `workflows/`.

## 7. Order of operations

1. Fetch (MCP or REST) → raw Figma.
2. **Token Engine** → token system (so structure can reference tokens).
3. **Structure Parser** → node tree + roles.
4. **Component detection** → definitions + instances (dedupe).
5. **Decision Engine** → complexity score, conversion mode, dynamic-data proposals.
6. Emit the **NDO**.
7. **Compose** → pick builder ∩ entitlement; auto-fold active enhancers (acss-pro
   before build, seo-pro after). `core/composition.md`.
8. Hand the NDO to the chosen **builder adapter** (`builders/*.md`).
9. **Verify** (Playwright pixel-diff) → iterate → persist draft.

Tokens before structure, structure before decisions, decisions before build.
