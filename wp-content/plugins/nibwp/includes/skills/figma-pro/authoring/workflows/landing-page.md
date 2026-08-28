# Workflow — Figma Landing Page → WordPress page

> Every convert workflow ends the same way: **pixel-diff verify** (Playwright render vs. Figma 2x export) → **warnings report** → **persist as draft**. Live pages are never overwritten.

Convert a single Figma landing-page frame into a complete, responsive WordPress
page with an established token system, deduped components, and a verified match.
Figma-pro reads the **real node tree + Variables** — it never paints from a
screenshot — establishes a design-token system, delegates the build to a builder
skill (EtchWP / Bricks / Elementor / Gutenberg), then verifies with a Playwright
pixel-diff against Figma's own 2x export.

## Input

- A Figma URL (`file_key` + `node-id` of the landing-page frame).
- Optional target builder (else auto-detected).
- Optional viewport widths — default `1440 / 768 / 390`.

## Abilities used

| Ability | Role in this workflow |
|---|---|
| `figma-pro-convert` | Full pipeline: resolve → fetch → tokens → detect → build → verify → draft |
| `figma-pro-tokens` | Establish the token system the page binds to |
| `figma-pro-detect-builder` | Pick EtchWP / Bricks / Elementor / Gutenberg |
| `figma-pro-preview` | Optional dry-run + diff score before anything is written |

Figma read integration abilities: `figma-get-file`, `figma-get-node`,
`figma-get-variables`, `figma-get-styles`, `figma-export-node`.

## Steps

1. **Resolve node.** Parse the URL → `file_key` + `node-id`. Call
   `figma-get-file` to locate the frame and confirm it is a top-level page frame.
2. **Fetch structure.** `figma-get-node` on the frame → full subtree: auto-layout
   stacks, constraints, text runs, fills, effects, image refs.
3. **Establish tokens.** `figma-get-variables` + `figma-get-styles` →
   `figma-pro-tokens` builds the token map (colors, type scale, spacing, radii,
   shadows). Raw hex values not bound to a Variable are flagged for the warnings
   report.
4. **Export baseline.** `figma-export-node` at 2x → reference PNG per viewport.
5. **Detect builder.** `figma-pro-detect-builder` → EtchWP / Bricks / Elementor
   (see matrix below).
6. **Dedupe components.** Identify repeated instances (cards, buttons, list rows)
   → build once as a component/class, reuse instances instead of duplicating.
7. **Delegate build.** Hand tokens + node tree + builder choice to the builder
   skill. Map auto-layout → flex/grid, constraints → responsive behavior, text
   styles → token classes. Produce native page structure at all breakpoints.
8. **Pixel-diff verify.** Playwright renders the draft at each viewport → diff
   vs. the 2x export → diff score + heatmap of offending regions.
9. **Persist as draft.** Save the WordPress page as **draft**; attach the
   warnings report; optionally `figma-post-comment` the status back to Figma.

## Builder applicability

`figma-pro-detect-builder` picks per active plugins and design shape.

| Signal | Preferred builder |
|---|---|
| EtchWP / ACSS active, token-first design | **EtchWP** (preferred) |
| Bricks active, complex layout | **Bricks** |
| Elementor Pro active | **Elementor** |
| No page builder active | **Gutenberg** (fallback) |

## Slash commands

| Command | Invokes | Effect |
|---|---|---|
| `/figma analyze` | read-only | Reports pages / frames / sections / components / tokens. No writes. |
| `/figma convert <target>` | `figma-pro-convert` | Runs the full pipeline on the named frame → verified draft |
| `/figma extract tokens` | `figma-pro-tokens` | Maps Figma Variables + styles into a builder token system |
| `/figma detect builder` | `figma-pro-detect-builder` | Surfaces the recommended builder + reasons before building |

## Output

A draft WordPress page, responsive across breakpoints, token system installed,
plus a pixel-diff score and warnings report. Warnings flag fixed/absolute widths,
absolutely-positioned layers, missing fonts, and raw colors not bound to a token.

**Builders:** EtchWP (preferred), Bricks, Elementor. Gutenberg as fallback.
