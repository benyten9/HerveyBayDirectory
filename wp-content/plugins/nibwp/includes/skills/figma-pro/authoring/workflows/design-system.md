# Workflow — Figma Component Library → WordPress Design System

> Every convert workflow ends the same way: **pixel-diff verify** (Playwright render vs. Figma 2x export) → **warnings report** → **persist as draft**. Registered components/patterns land as drafts, never over live definitions.

Turn a Figma **components page** into a reusable design system inside the builder:
buttons, cards, forms, nav, headers, footers — one definition each, variants as
modifiers, all bound to a token foundation derived from the file's real Variables.

## Input

- A Figma URL pointing at the components / library page, or a selection of
  component sets.
- Optional target builder (else auto-detected).

## Abilities used

| Ability | Role in this workflow |
|---|---|
| `figma-pro-tokens` | Build the token foundation the components bind to (runs first) |
| `figma-pro-convert` | Component mode: build each component set as a builder component / pattern |
| `figma-pro-detect-builder` | Pick a builder with global components/classes support |
| `figma-pro-preview` | Optional dry-run + diff score on canonical variants |

Figma read integration abilities: `figma-get-file`, `figma-get-node`,
`figma-get-variables`, `figma-get-styles`, `figma-export-node`, plus
`figma_get_components` for component / component-set definitions.

## Steps

1. **Read library.** `figma-get-file` → enumerate the components page; identify
   component sets and their variants (button/primary, button/ghost, card/media…).
2. **Pull tokens first.** `figma-get-variables` + `figma-get-styles` →
   `figma-pro-tokens` builds the token foundation the components will bind to.
3. **Fetch each component.** `figma-get-node` per component set → variant props,
   states (hover / disabled), slots, auto-layout.
4. **Export previews.** `figma-export-node` 2x per canonical variant → verify
   baselines + any raster fills.
5. **Detect builder + map.** `figma-pro-detect-builder`, then generate builder
   components / global classes / patterns: buttons, cards, forms, nav, headers,
   footers — one definition each, variants as modifiers.
6. **Verify representative variants.** Pixel-diff canonical variants vs. their 2x
   exports; flag drift in the warnings report.
7. **Persist.** Register components / patterns and the token set as drafts; report
   coverage — which Figma components mapped, which need manual attention.

## Builder applicability

| Signal | Preferred builder |
|---|---|
| EtchWP / ACSS active | **EtchWP** (components + classes) |
| Bricks active | **Bricks** (global classes + elements) |
| Elementor Pro active | **Elementor** (global widgets / kits) |
| No page builder active | **Gutenberg** → synced patterns fallback |

## Slash commands

| Command | Invokes | Effect |
|---|---|---|
| `/figma analyze` | read-only | Reports component sets / variants / tokens. No writes. |
| `/figma extract tokens` | `figma-pro-tokens` | Maps Figma Variables + styles into a builder token system |
| `/figma create components` | `figma-pro-convert` (component mode) | Builds the reusable component / pattern set from the components page |
| `/figma sync design system` | `figma-pro-tokens` (sync mode) | Maps Figma Variables → builder global colors / fonts / classes |

## Output

Reusable buttons / cards / forms / nav / headers / footers as builder components
or patterns, plus a bound token system — registered as drafts, with a coverage
report, per-variant pixel-diff scores, and a warnings report.

**Builders:** EtchWP (components + classes), Bricks (global classes + elements),
Elementor (global widgets / kits). Gutenberg → synced patterns fallback.
