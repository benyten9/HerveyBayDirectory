# figma-pro — Component Intelligence Reference

How figma-pro recognizes reusable patterns in a Figma node tree and reproduces them as **native, reusable WordPress components** — never as duplicated markup. This is the differentiator vs. screenshot converters: a screenshot shows *pixels*; the node tree tells you that "10 cards" is **one component used 10× with different data**.

**Read this file whenever a design contains any repetition.** Every repeated block is a deduplication opportunity. If you emit the same subtree twice, you have failed this reference.

## Contents

- [1. Figma's component model](#1-figmas-component-model)
- [2. Component properties → editable fields](#2-component-properties--editable-fields)
- [3. The core rule: detect repetition and DEDUPE](#3-the-core-rule-detect-repetition-and-dedupe)
- [4. Detection path A — explicit instances](#4-detection-path-a--explicit-instances)
- [5. Detection path B — implicit clones](#5-detection-path-b--implicit-clones)
- [6. Mapping a component to each builder](#6-mapping-a-component-to-each-builder)
- [7. Dynamic data & loops](#7-dynamic-data--loops)
- [8. Variants → builder variations](#8-variants--builder-variations)
- [9. Naming & normalization](#9-naming--normalization)
- [10. Worked example — Feature Card ×6](#10-worked-example--feature-card-6)
- [11. Rules recap](#11-rules-recap)

## 1. Figma's component model

Figma has three node types that together express reuse. Read them from the raw node tree (via the Figma REST/plugin API), never infer from render.

| Node `type` | Meaning | Key fields to read |
|---|---|---|
| `COMPONENT` | The **definition** — a master/main component | `id`, `name`, `children`, `componentPropertyDefinitions` |
| `COMPONENT_SET` | A group of `COMPONENT`s that are **variants** of one thing | `name`, `children` (each a variant `COMPONENT`), `componentPropertyDefinitions` |
| `INSTANCE` | A **use** of a component on a frame | `componentId` → points at the main `COMPONENT`; `componentProperties`; `overrides` |

Rules for reading:

- An `INSTANCE.componentId` is the join key. Every instance sharing a `componentId` is the **same component** rendered with different overrides.
- A `COMPONENT_SET` holds N variant `COMPONENT` children. Each child's `name` is a variant string like `Size=md, Type=primary`. Parse it into `{Size: md, Type: primary}`.
- `componentPropertyDefinitions` lives on the `COMPONENT` or `COMPONENT_SET` and declares the editable surface (see §2).
- Instance content deltas live in `componentProperties` (typed props) and `overrides` (raw per-node overrides for text, fills, visibility). These deltas are exactly your **dynamic field values**.

## 2. Component properties → editable fields

Figma component properties define what a consumer may change per instance. Map each to a builder-side editable field / attribute.

| Figma property `type` | What it controls | Becomes (WordPress) |
|---|---|---|
| `TEXT` | Editable string on a text node | A text field / dynamic bound content slot |
| `BOOLEAN` | Show/hide a layer | A conditional / visibility toggle attribute |
| `INSTANCE_SWAP` | Swap a nested instance (e.g. which icon) | An icon/media picker field or block variation |
| `VARIANT` | Pick a variant from the set (Size, Type…) | A modifier class / block style variation (see §8) |

Each property key (Figma often suffixes an id, e.g. `Title#8:0`) normalizes to a clean field name (`title`). The set of properties **is** the component's public API — reproduce that exact surface as the reusable component's fields, no more, no less.

## 3. The core rule: detect repetition and DEDUPE

> **One structure repeated N times = ONE reusable component instanced N times with data. Never N copies of markup.**

Two detection paths, run both:

- **Path A (explicit):** the designer already made a component — trust `componentId`. Cheap, exact.
- **Path B (implicit):** the designer copy-pasted a subtree and edited text/images — no component exists. You must **cluster by structural similarity** and synthesize one component anyway.

Both paths converge on the same output: a single reusable definition + a repeated data set bound through a loop/pattern.

## 4. Detection path A — explicit instances

1. Walk the tree; collect every `INSTANCE`.
2. Group by `componentId`.
3. Any group with `count ≥ 2` → emit **one** reusable component (from the referenced `COMPONENT`), plus a data row per instance built from that instance's `componentProperties` + `overrides`.
4. Singletons (`count == 1`) may still become components if they're conceptually reusable (buttons, badges), but at minimum inline them once.

## 5. Detection path B — implicit clones

Designers frequently duplicate frames without componentizing. Detect these structurally.

**Similarity heuristic** — two sibling subtrees are the same component when ALL hold:

1. **Same child type signature** — the ordered tree of node `type`s matches (e.g. `FRAME > [ INSTANCE(icon), TEXT, TEXT, INSTANCE(link) ]`).
2. **Same layout** — matching `layoutMode` (auto-layout direction), alignment, and item spacing; near-equal box dimensions.
3. **Differences are confined to leaf values only** — the deltas are text strings, image/fill refs, and icon swaps at leaf nodes. Structure, sizing, and style are identical.

If 1–3 hold across ≥2 siblings under a common parent (a grid/row/auto-layout container), cluster them and **propose ONE component** whose fields are exactly the differing leaves.

Practical scoring: compute a signature hash of the type-ordered skeleton (ignore leaf text/fill). Siblings sharing a hash and living in the same auto-layout parent are one cluster. Tolerate minor variance (an optional badge present on some cards) by making that leaf a `BOOLEAN`/conditional field rather than splitting the cluster.

## 6. Mapping a component to each builder

figma-pro reads the tree; the **builder skill** emits native output. Map the detected component to each builder's real reusable primitive:

| Builder | Reusable primitive | Styling | Repeat mechanism |
|---|---|---|---|
| **EtchWP** | Etch **COMPONENT** (reusable) | BEM class + ACSS tokens; dynamic data slots | Loop over CPT/repeater bound to slots |
| **Bricks** | Global element / reusable **class** | Bricks class + variables | Query loop container |
| **Elementor** | Saved **template** / global widget | Global classes / conditions | Loop grid + loopable container |
| **Gutenberg** | Block **pattern** or **synced (reusable) pattern** | theme.json tokens + block styles | Query Loop block |

Always prefer the *synced/global* form (Etch component, Gutenberg synced pattern, Bricks global class) so one edit propagates — that is the entire point of dedup.

## 7. Dynamic data & loops

For **content-repeating** components (feature cards, team members, pricing tiers, logos, testimonials): don't bind static values into N copies. Extract the repeated content into **fields**, then bind to a **loop/query** where the builder supports it.

- Define the component fields once (e.g. `icon`, `title`, `description`, `link`).
- Move the N instances' data into a data source: a **CPT** (e.g. `feature`), an ACF/meta **repeater**, or an inline array/pattern data set when no CMS source is warranted.
- Render via the builder's loop primitive (§6) so the component appears once in the build and the loop produces N rendered items.
- Fall back to a static repeated *pattern* only when the content is truly fixed and tiny (e.g. 3 hard-coded steps) — even then it's one pattern definition, not duplicated hand-written markup.

Decision: **repeats with editable/growing content → loop + data source; repeats with fixed content → single pattern.** Never → duplicated blocks.

## 8. Variants → builder variations

A `COMPONENT_SET`'s variant properties map to modifiers, not to separate components.

| Figma variant | Example values | EtchWP | Bricks | Elementor / Gutenberg |
|---|---|---|---|---|
| `Size` | `sm` / `md` / `lg` | BEM `--sm` `--md` `--lg` | class variant | style variation / control preset |
| `Type` | `primary` / `secondary` | BEM `--primary` `--secondary` | class variant | block style / condition |
| `State` | `default` / `hover` / `disabled` | `:hover`, `--disabled` | pseudo/class | state condition |

Rule: one base class + modifier classes (`btn` + `btn--lg btn--primary`). Every variant `COMPONENT` in the set becomes a modifier combination on the **same** base component, never a new component. An instance's selected `VARIANT` property values choose which modifiers to apply.

## 9. Naming & normalization

Derive names from Figma layer/component names; normalize to the builder's convention.

- Take the `COMPONENT` / `COMPONENT_SET` name (e.g. `Feature Card`, `Button`).
- Slugify: lowercase, hyphenate, strip Figma id suffixes (`#8:0`) and page/section prefixes.
- **EtchWP BEM**: `{brand}-{component}__{element}--{modifier}` — e.g. `acme-feature-card`, `acme-feature-card__title`, `acme-button--primary`.
- Elements come from meaningful child layer names (`Title` → `__title`, `Icon` → `__icon`); modifiers come from variant values (`Size=lg` → `--lg`).
- Keep names stable and semantic; do not encode pixel values or Figma node ids into class names.

## 10. Worked example — Feature Card ×6

**Figma:** a `COMPONENT` named `Feature Card` with properties `Icon#1:0 (INSTANCE_SWAP)`, `Title#2:0 (TEXT)`, `Description#3:0 (TEXT)`, `Link#4:0 (TEXT)`. It is instanced **6×** inside an auto-layout grid `Features`, each instance overriding the four properties.

**Wrong output:** 6 near-identical card blocks with hard-coded text. (What a screenshot converter produces.)

**Right output — ONE component + a loop:**

Fields (from the component properties):

| Field | Figma prop | Type |
|---|---|---|
| `icon` | `Icon` | instance-swap → icon/media |
| `title` | `Title` | text |
| `description` | `Description` | text |
| `link` | `Link` | text/URL |

EtchWP component `acme-feature-card`:

```
acme-feature-card                (auto-layout → flex, ACSS gap token)
  acme-feature-card__icon        ← {{ icon }}
  acme-feature-card__title       ← {{ title }}   (var(--text-l, 20px), no clamp)
  acme-feature-card__desc        ← {{ description }}
  acme-feature-card__link        ← {{ link }}
```

Data: 6 rows in a `feature` CPT (or repeater). The build contains the component **once**, wrapped in a loop over those 6 rows → 6 rendered cards, one source of truth. Edit the card once, all 6 update. Gutenberg equivalent: a synced pattern + Query Loop over the `feature` CPT. Bricks: a global element in a query-loop container. Elementor: a loop grid bound to the CPT.

## 11. Rules recap

- **Never duplicate a repeated section.** ≥2 matching subtrees = one component.
- **Always create the reusable primitive** native to the target builder (§6) — synced/global form preferred.
- **Run both detection paths** — trust `componentId` (A) *and* cluster structural clones the designer forgot to componentize (B).
- **The component's fields = its Figma component properties** (text/boolean/instance-swap/variant), no more.
- **Variants are modifiers, not new components** — one base class + `--modifier`s.
- **Content repeats bind to a loop/query + data source**; only fixed tiny repeats stay as a single static pattern.
- **Preserve hierarchy** — reproduce the node tree's nesting and auto-layout, not a flattened visual.
- **Use design tokens, never hardcoded values** — ACSS/theme.json tokens for color, spacing, type; no `clamp()` for font-size (use `var(--text-l, 20px)`).
- **Derive names from Figma layer names**, normalized to the builder convention (§9).
