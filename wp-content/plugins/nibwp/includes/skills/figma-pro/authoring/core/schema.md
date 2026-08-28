# core/schema.md — the NibWP Design Object (NDO)

The **intermediate format** that decouples reading Figma from building WordPress.
Figma is parsed **once** into the NDO; every builder adapter (Etch/Bricks/
Elementor/Gutenberg) then builds from the NDO. This is why one skill can target
any builder without re-parsing Figma per target:

```
Figma (MCP or REST)  →  parser  →  NDO  →  any builder adapter  →  WordPress
```

The NDO is builder-agnostic and source-agnostic: it looks the same whether the
data came from the Figma REST API or the Dev Mode MCP, and it carries everything a
builder needs (structure, layout, tokens, assets, component definitions) with none
of Figma's raw noise.

## Table of contents
1. Why an intermediate schema
2. Top-level document shape
3. The node object
4. Layout object
5. Style object (token-referenced)
6. Component definitions & instances
7. Token system
8. Assets
9. Responsive / breakpoints
10. Metadata & warnings
11. Full example

---

## 1. Why an intermediate schema

Without it, each builder adapter would have to understand raw Figma JSON, and
every Figma quirk (0–1 color floats, `absoluteBoundingBox`, alias chains) would be
re-solved four times. With the NDO:
- The **parser** (`core/parser.md`) solves Figma once → clean, normalized nodes.
- Each **adapter** is small: NDO → its native format. No Figma knowledge needed.
- The **verify loop** and **token engine** operate on the NDO, not Figma.
- New builders = one new adapter, nothing else changes.

## 2. Top-level document shape

```json
{
  "ndo_version": "1.0",
  "source": { "kind": "rest" | "mcp", "file_key": "...", "node_id": "1:234",
              "file_version": "..." },
  "target": { "type": "page" | "block" | "template", "title": "Homepage" },
  "tokens": { /* §7 */ },
  "components": { /* §6 — definitions keyed by component id */ },
  "assets": [ /* §8 */ ],
  "breakpoints": [ /* §9 */ ],
  "root": { /* §3 — the top node */ },
  "meta": { /* §10 — complexity score, warnings, source flags */ }
}
```

## 3. The node object

Every element is a node. Uniform recursive shape:

```json
{
  "id": "hero-001",
  "type": "section" | "container" | "text" | "image" | "svg" | "shape"
          | "component_instance",
  "role": "hero" | "nav" | "features" | "cta" | "footer" | null,
  "name": "Hero Section",
  "layout": { /* §4 */ },
  "styles": { /* §5 */ },
  "content": { "text": "...", "level": "h1" } ,   // for text nodes
  "component_ref": "cmp-feature-card",             // if type=component_instance
  "props": { "title": "...", "icon": "...", "link": "..." }, // instance overrides
  "children": [ /* nodes */ ],
  "absolute": false,
  "warnings": []
}
```

- `role` is inferred by the parser (see `core/parser.md`) from name/position/
  content — it lets adapters pick semantic elements (a `role:"hero"` section, an
  `<h1>` for the hero headline).
- `type` is the normalized structural kind, not Figma's raw `type`.

## 4. Layout object

Auto-layout normalized to a flex/grid model (mapping detail in `core/nodes.md`):

```json
"layout": {
  "type": "flex" | "grid" | "flow" | "absolute",
  "direction": "row" | "column",
  "gap": "--space-l" ,            // token ref (or px fallback)
  "padding": { "top": "--space-2xl", "right": "--space-l",
               "bottom": "--space-2xl", "left": "--space-l" },
  "align": "center", "justify": "space-between",
  "wrap": true,
  "sizing": { "h": "fill" | "hug" | "fixed", "v": "fill" | "hug" | "fixed" },
  "columns": 3                    // when type=grid
}
```

## 5. Style object (token-referenced)

Styles reference tokens first, raw values only when unmatched (and flagged):

```json
"styles": {
  "background": "--surface",             // token ref
  "color": "--text",
  "radius": "--radius-s",
  "border": { "width": "1px", "color": "--border" },
  "shadow": "0 10px 30px rgba(0,0,0,.08)",
  "typography": "h2",                     // type-ramp slot (see §7)
  "raw": [ { "prop": "background", "value": "#2563EB", "reason": "no matching variable" } ]
}
```

The `raw[]` array is the honest audit trail — anything that couldn't be tokenized,
surfaced to the user for promotion.

## 6. Component definitions & instances

Repeated Figma components are stored **once** as definitions; nodes reference them:

```json
"components": {
  "cmp-feature-card": {
    "name": "Feature Card",
    "fields": [
      { "key": "icon", "type": "image" },
      { "key": "title", "type": "text" },
      { "key": "description", "type": "text" },
      { "key": "link", "type": "url" }
    ],
    "variants": [],                       // e.g. Button: primary/secondary/outline
    "tree": { /* the component's own node subtree, fields as placeholders */ }
  }
}
```

A node with `type:"component_instance"`, `component_ref:"cmp-feature-card"`, and a
`props` object is one *use*. Ten uses = one definition + ten instance nodes — never
ten duplicated trees. Detection rules in `core/components.md`.

## 7. Token system

```json
"tokens": {
  "source": "variables" | "styles",
  "colors": { "--primary": "#2563EB", "--surface": "#fff", "--text": "#0F172A" },
  "space":  { "--space-xs": "4px", "--space-s": "8px", "...": "..." },
  "radius": { "--radius-s": "8px" },
  "typeRamp": {
    "display": { "size": "--text-3xl", "weight": 700, "line": 1.1, "family": "Inter" },
    "h2":      { "size": "--text-xl",  "weight": 700, "line": 1.15 },
    "body":    { "size": "--text-m",   "weight": 400, "line": 1.6 }
  },
  "theme_modes": ["light", "dark"]
}
```

Ramp slots (`display/h1/h2/body/...`) are referenced by nodes via
`styles.typography`. Mapping rules in `core/tokens.md`. **Never** emit `clamp()`
font-size — the ramp carries token steps, responsiveness steps per breakpoint.

## 8. Assets

```json
"assets": [
  { "node_id": "img-hero", "kind": "image", "src_export": "<figma export url>",
    "local_path": "/uploads/.../hero@2x.png", "attachment_id": 812,
    "alt": "Product dashboard", "optimize": ["webp"] },
  { "node_id": "logo", "kind": "svg", "inline": "<svg…>" }
]
```

## 9. Responsive / breakpoints

```json
"breakpoints": [
  { "name": "desktop", "min": 1024, "figma_frame": "1440" },
  { "name": "tablet",  "min": 768, "max": 1023, "figma_frame": "768" },
  { "name": "mobile",  "max": 767, "figma_frame": "390" }
]
```

Populated from the Figma frames the designer actually made; inferred behavior
(3-col → 1-col) lives on the nodes' layout. Rules in `core/responsive.md`.

## 10. Metadata & warnings

```json
"meta": {
  "complexity_score": 62,                // see core/parser.md
  "conversion_mode": "native",           // exact_clone | native | design_system
  "warnings": [
    { "code": "missing_font", "detail": "Inter not loaded on target site" },
    { "code": "fixed_width", "node": "hero-001", "detail": "480px → max-width" },
    { "code": "absolute", "node": "badge-002" },
    { "code": "tokens_from_styles", "detail": "Variables unavailable (non-Enterprise)" }
  ]
}
```

`warnings[]` flows straight to the user-facing report — a faithful conversion tells
you what didn't map cleanly.

## 11. Full (trimmed) example

```json
{
  "ndo_version": "1.0",
  "source": { "kind": "rest", "file_key": "abc", "node_id": "1:2", "file_version": "9981" },
  "target": { "type": "page", "title": "Homepage" },
  "tokens": { "source": "variables",
    "colors": { "--primary": "#2563EB", "--text": "#0F172A", "--surface": "#fff" },
    "space": { "--space-l": "24px", "--space-2xl": "64px" },
    "typeRamp": { "display": { "size": "--text-3xl", "weight": 700, "line": 1.1 } } },
  "components": { "cmp-feature-card": { "name": "Feature Card",
    "fields": [ {"key":"icon","type":"image"}, {"key":"title","type":"text"} ] } },
  "root": {
    "id": "hero", "type": "section", "role": "hero", "name": "Hero",
    "layout": { "type": "flex", "direction": "column", "gap": "--space-l",
                "padding": { "top": "--space-2xl", "bottom": "--space-2xl" },
                "align": "center" },
    "styles": { "background": "--surface" },
    "children": [
      { "id": "hero-h1", "type": "text", "content": { "text": "Ship faster", "level": "h1" },
        "styles": { "typography": "display", "color": "--text" } }
    ]
  },
  "meta": { "complexity_score": 34, "conversion_mode": "native", "warnings": [] }
}
```

This NDO hands cleanly to any adapter in `builders/`.
