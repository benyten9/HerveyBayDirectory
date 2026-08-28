# core/tokens.md — design tokens & typography

The token system is established **before any structure is built** (pipeline step
3). This is what separates a maintainable native build from a pile of hardcoded
values. Read `integrations/figma-api.md` for how to fetch (`figma-get-variables`, fallback
`figma-get-styles`); this file is how to map what you get.

## Table of contents
1. Why tokens first
2. Figma Variables model
3. Variables → ACSS/global tokens
4. Colors
5. Spacing & radius scales
6. Typography → type ramp
7. Modes (light/dark)
8. Styles fallback (no Variables)
9. Assets as tokens (fonts/images)
10. Rules

---

## 1. Why tokens first

If you build structure first and tokenize later, every element carries a hardcoded
color and px size, and the "design system" is fiction. Establish the token set
first, then every element references `var(--token, fallback)`. The result is
editable, theme-able, and matches the designer's intent — not a screenshot copy.

## 2. Figma Variables model

`figma-get-variables` (`/v1/files/{key}/variables/local`) returns:
- **Collections** (e.g. "Primitives", "Semantic"), each with **modes** (Light/Dark,
  Desktop/Mobile, Brand A/B).
- **Variables** typed `COLOR | FLOAT | STRING | BOOLEAN`, each with `valuesByMode`.
- **Aliases**: a semantic variable pointing at a primitive (`color/primary` →
  `blue/600`). **Resolve the alias chain** to the concrete value.

Enterprise-gated on Figma's side. On `403`, the integration returns a
styles-derived set (see §8) with a flag so you can set expectations.

## 3. Variables → ACSS / global tokens

Map by collection + name heuristics:

| Figma variable pattern | Token intent |
|---|---|
| COLOR `*/primary`, `*/brand`, `*/accent` | `--primary`, `--accent` (ACSS palette slots) |
| COLOR `*/bg`, `*/surface`, `*/base` | `--bg`, `--surface`, `--base` |
| COLOR `*/text`, `*/muted` | `--text`, `--text-muted` |
| FLOAT in a `space`/`spacing` collection | `--space-*` scale (ascending) |
| FLOAT in a `radius`/`corner` collection | `--radius-*` |
| FLOAT/text sizes | `--text-*` ramp |
| STRING font family | font token |
| mode Light/Dark | theme layer (see §7) |

**Respect the builder's allowed-token rules.** The Etch/ACSS validator rejects
invented tokens like `--text-7`, `--space-999`, `--base-XXX`. Map to the real ACSS
scale; if a Figma value has no scale slot, emit the raw value as the fallback and
flag it for the user to promote.

## 4. Colors

- Figma color = `{r,g,b,a}` floats 0–1 → convert to 0–255 / hex.
- Match every fill against a color Variable/Style first; only unmatched colors
  become raw `rgba()`/hex, and those get flagged.
- Semantic over primitive: prefer `--primary` (semantic) to `--blue-600`
  (primitive) for element colors, so a theme change propagates.

Example:
```
Figma  color/primary → blue/600 → #2563EB
Token  --primary: #2563EB;   element uses  color: var(--primary, #2563EB)
```

## 5. Spacing & radius scales

Figma spacing Variables (or the recurring `itemSpacing`/padding values) form a
scale. Map ascending to `--space-*`:
```
4 8 16 24 32 64  →  --space-3xs … --space-2xl (ACSS scale slots)
```
Use tokens for `gap`, `padding`, `margin` — never hardcode the px when a scale
slot exists. Same for `cornerRadius` → `--radius-*`.

## 6. Typography → type ramp

Cluster the file's text sizes into a **ramp**, don't emit per-node sizes:

| Ramp slot | Typical Figma text style |
|---|---|
| display | Hero/Display (e.g. 64/72) |
| h1 / h2 / h3 | Heading XL/L/M |
| body / body-lg | Paragraph |
| small / caption | Caption/Label |

Per `TEXT.style`: `fontFamily, fontWeight, fontSize, lineHeightPx/Percent,
letterSpacing, textAlignHorizontal, textCase, textDecoration`.

**HARD RULE — never `clamp()` for font-size.** The Etch/ACSS validator rejects it.
Emit ACSS text tokens with a px/em fallback:
```
h1 { font-size: var(--text-xl, 40px); line-height: 1.1; }
```
For responsive type, step the token per breakpoint (`core/responsive.md`), never
a fluid clamp.

## 7. Modes (light/dark)

Figma modes (Light/Dark) → a theme layer. Map the Dark mode's `valuesByMode` to an
ACSS dark-theme layer / `[data-theme="dark"]` override — the same token names,
different values per theme. Don't duplicate elements per theme; only the token
values change.

## 8. Styles fallback (no Variables)

When Variables are unavailable (`403`, non-Enterprise), reconstruct a coarser set
from `figma-get-styles` (published paint/text/effect styles) + the inline fills
actually used. Tell the user: "tokens derived from styles — less complete than
Variables; some values may be raw." Still far better than nothing.

## 9. Assets as tokens (fonts / images)

- **Fonts:** the type ramp needs the font loaded on the site. If Figma uses a font
  the site doesn't have, flag it — the type won't match until the user adds it
  (Google Font, ACSS font, or upload). Don't silently substitute.
- **Images/SVG:** raster fills → sideload to the media library at 2×; icons/logos →
  export as SVG and inline. (Detail in `core/nodes.md` §5 and the assets
  handling in the workflows doc.)

## 10. Rules

- ✅ Establish tokens before structure.
- ✅ Semantic tokens over primitives for element styling.
- ✅ `var(--token, fallback)` everywhere; real ACSS scale slots only.
- ✅ Type as a ramp; **never** `clamp()` font-size.
- ✅ Flag every raw (untokenized) color/size and every missing font.
- ❌ Don't invent token names the builder's validator will reject.
