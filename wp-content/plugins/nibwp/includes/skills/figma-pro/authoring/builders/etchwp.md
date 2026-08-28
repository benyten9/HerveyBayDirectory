# builders/etchwp.md — Figma → EtchWP (highest priority)

The reference builder target. Etch is priority #1 because etchwp-pro already ships
a `figma-to-component` ability — the plumbing exists; it just needs the real node
tree + token map instead of a screenshot. figma-pro **never writes Etch data
directly**; it hands the payload to the etchwp-pro builder skill, whose validated
spine (`validate → score → dry_run → persist`) handles correctness.

## Table of contents
1. Etch payload shape
2. Hand-off contract
3. Frame → Etch structure mapping
4. Classes (BEM) & tokens
5. Components & reuse
6. Persist rules & validator
7. Worked example (Primary Button)

---

## 1. Etch payload shape

An Etch component/page is:
```
{ __libraryMeta, styles, gutenbergBlock, components? }
```
- `gutenbergBlock` = an `etch/element` + `etch/text` tree (the DOM structure).
- `styles` = a dict keyed by BEM style-id → CSS (ACSS-token-based).
- `__libraryMeta` = component metadata.
- Verbatim readonly scaffold styles `etch-section-style` / `etch-container-style`
  must be preserved as-is.

## 2. Hand-off contract

figma-pro passes the generic payload (see the SKILL pipeline):
```
{ target:{type:"page"|"block", title, post_id?},
  tokens:{colors,space,radius,typeRamp, source:"variables"|"styles"},
  tree:<normalized node tree — layout/fills/typography mapped>,
  assets:[{node_id,kind:"image"|"svg",local_path,attachment_id?}],
  options:{breakpoints,draft:true,backup:true} }
```
The etchwp-pro skill converts `tree` → the `etch/element`+`etch/text` tree, `tokens`
→ ACSS `var(--token)` in `styles`, and persists.

## 3. Frame → Etch structure mapping

| Figma | Etch |
|---|---|
| top `FRAME`/`SECTION` | Etch **Section** (with the readonly section scaffold) |
| inner auto-layout frame | Etch **Container** (flex: direction/gap/padding/align) |
| `GROUP` / div-like | `etch/element` (div) |
| `TEXT` | `etch/element` (h1–h6/p by type-ramp slot) wrapping `etch/text` |
| `COMPONENT`/`INSTANCE` | Etch **Component** (reusable), instanced |
| image fill | `etch/element` img (sideloaded attachment) |
| `VECTOR`/icon | inline SVG in an `etch/element` |

Auto-layout maps straight to the container's flex (see `core/nodes.md` §2).

## 4. Classes (BEM) & tokens

- **Flat BEM:** `{brand}-{component}__{element}--{modifier}`, e.g.
  `nibwp-hero__inner`, `nibwp-hero__cta`, `nibwp-btn--secondary`. Derive
  `{component}` from the Figma frame/component name (normalized).
- **Tokens:** ACSS `var(--token, fallback)` only. The Etch validator **rejects**:
  - invented tokens (`--text-\d+`, `--space-\d+`, `--base-\d{2,3}`),
  - `clamp()` for font-size,
  - non-BEM classes,
  - raw `<form>`/`<style>` tags.
  So the token mapping in `core/tokens.md` must land on real ACSS scale slots.

## 5. Components & reuse

Repeated Figma instances → **one** Etch Component reused N times (never N
duplicated subtrees). Component properties (text/icon/link) → the component's
dynamic slots. See `core/components.md` for detection + dynamic-data binding.

## 6. Persist rules & validator

- Persist through `nibwp/etchwp-pro-html-to-component` (or the figma ability) with
  `dry_run:true` first → check `passed:true`, then `dry_run:false`.
- **Strip innerHTML/innerContent** from `etch/element` nodes before persist so
  `serialize_block` regenerates them — avoids `inner_content_mismatch` warnings.
- `draft:true`, `backup:true` — never overwrite live.
- If validation fails, read the errors, fix the offending token/class/structure in
  figma-pro's mapping, and re-delegate. Don't force past the validator.

## 7. Worked example — Primary Button

Figma component "Primary Button": bg `color/primary` (#2563EB), radius 8, padding
16×32, text 600/16.

figma-pro maps → hands to etchwp-pro → Etch Component:
```
tree:  etch/element button, class "nibwp-btn nibwp-btn--primary"
         └ etch/text "{label}"
styles:
  nibwp-btn {
    padding: var(--space-s, 16px) var(--space-l, 32px);
    border-radius: var(--radius-s, 8px);
    font-weight: 600;
    font-size: var(--text-m, 16px);   /* never clamp() */
  }
  nibwp-btn--primary {
    background: var(--primary, #2563EB);
    color: var(--base, #fff);
  }
tokens used: --primary --space-s --space-l --radius-s --text-m
```
Instanced everywhere the Figma "Primary Button" appears — one component, many uses.
