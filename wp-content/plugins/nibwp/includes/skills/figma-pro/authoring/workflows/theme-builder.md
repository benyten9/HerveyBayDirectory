# Workflow — Figma File → WordPress Theme

> Every convert workflow ends the same way: **per-template pixel-diff verify** (Playwright render vs. Figma 2x export) → **warnings report** → **persist as draft**. Nothing is activated over a live theme.

The **most complex, highest-complexity-score** figma-pro workflow. Where the other
workflows convert a single frame or a components page, this one consumes an
**entire Figma file** — every page and frame — and produces a coherent WordPress
**theme**: a token/style system, reusable components, and the full set of
templates (header, footer, page, single, archive, index). It is the natural
trigger for "Create a WordPress theme from this Figma file."

Because it spans the whole file, its aggregate **Design Complexity Score**
(see `core/parser.md`) is the highest of any workflow — figma-pro sizes the job
from that score, sequences templates from it, and surfaces it up front so the
user knows a theme build is a multi-frame, multi-verify operation.

## Input

- A Figma URL for the **whole file** (`file_key`), or the file plus a set of
  page/frame node-ids to include.
- Optional target: block theme vs. builder templates (else auto-detected).
- Optional child-theme parent (block themes) and viewport widths — default
  `1440 / 768 / 390`.

## Abilities used

| Ability | Role in this workflow |
|---|---|
| `figma-pro-tokens` | Extract the whole-file token/style system first — every template and component binds to it |
| `figma-pro-detect-builder` | Decide block theme (Gutenberg / theme.json) vs. Etch / Bricks / Elementor templates |
| `figma-pro-convert` | Run **per template and per page**: convert each frame into a native template / template part |
| `figma-pro-convert` (component mode) | Build the reusable component / pattern set shared across templates (`/figma create components`) |
| `figma-pro-preview` | Optional dry-run + diff score per template before writing |

Figma read integration abilities: `figma-get-file` (enumerate all pages/frames),
`figma-get-node`, `figma-get-variables`, `figma-get-styles`, `figma-export-node`,
plus `figma_get_components` for shared components.

## Steps

1. **Analyze the whole file.** `figma-get-file` → enumerate every page and frame;
   classify frames by role (home, generic page, blog post, archive/listing,
   header, footer, 404). Compute the aggregate **Design Complexity Score** and
   report the template plan before building.
2. **Extract the token/style system.** `figma-get-variables` + `figma-get-styles`
   → `figma-pro-tokens` builds the foundation once: colors, type scale, spacing,
   radii, shadows. This becomes `theme.json` presets for a block theme, or the
   builder's global styles (Etch/ACSS classes, Bricks global classes, Elementor
   global kit).
3. **Detect builder / theme target.** `figma-pro-detect-builder` → block theme
   (theme.json + block templates) or a builder template set (see applicability).
4. **Generate reusable components.** `figma-pro-convert` (component mode) → build
   shared parts once (buttons, cards, nav, media blocks) so every template reuses
   them instead of duplicating markup.
5. **Generate templates — one `figma-pro-convert` per template/page.** Map each
   classified frame onto its WordPress template, binding tokens and reusing
   components:

   | WordPress template | Source frame(s) | Notes |
   |---|---|---|
   | `header` (template part) | header frame | shared across templates |
   | `footer` (template part) | footer frame | shared across templates |
   | `page` | generic page frame | default content template |
   | `single` | blog-post / article frame | dynamic post data wired in |
   | `archive` | listing / category frame | Query Loop / loop element for the feed |
   | `index` | home / fallback frame | required fallback template |

6. **Baseline export per frame.** `figma-export-node` 2x → one reference PNG per
   template per viewport.
7. **Assemble the theme.** Collect templates, template parts, components, and the
   token system into a **theme / child-theme** (block theme) or a **full set of
   builder templates** (Etch/Bricks/Elementor theme builder).
8. **Per-template pixel-diff verify.** Playwright renders each draft template at
   each viewport → diff vs. its 2x export → per-template diff score + heatmap.
9. **Persist as drafts.** Save the theme scaffold / template set as drafts; attach
   the aggregate warnings report; never activate over a live theme.

## Builder applicability

| Signal | Theme target |
|---|---|
| No page builder / block-first site | **Block theme** — `theme.json` + block templates & template parts (Gutenberg) |
| EtchWP / ACSS active | **Etch templates** — global classes + header/footer/loop templates |
| Bricks active | **Bricks Theme Builder** — templates + global classes |
| Elementor Pro active | **Elementor Theme Builder** — theme parts + global kit |

## Slash commands

| Command | Invokes | Effect |
|---|---|---|
| `/figma analyze` | read-only | Enumerates pages/frames, classifies templates, reports the complexity score & plan. No writes. |
| `/figma extract tokens` | `figma-pro-tokens` | Builds the theme.json / global-styles token system |
| `/figma create components` | `figma-pro-convert` (component mode) | Builds the shared component / pattern set |
| `/figma convert <target>` | `figma-pro-convert` | Converts one frame into its template (run per template) |
| `/figma detect builder` | `figma-pro-detect-builder` | Surfaces block-theme vs. builder-template target + reasons |

## Output

A **theme scaffold** — a block theme / child-theme (`theme.json` + templates +
template parts), or a full set of builder templates — with a bound token system,
reusable components, and every template (header, footer, page, single, archive,
index). Delivered as **drafts**, with per-template pixel-diff scores and an
aggregate warnings report; the user reviews and activates.

**Builders:** Gutenberg block theme (theme.json), EtchWP, Bricks, Elementor Pro
theme builders.
