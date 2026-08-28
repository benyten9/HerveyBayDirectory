# builders/gutenberg.md — Figma → WordPress core blocks (universal fallback)

Maps a normalized NibWP Design Object (NDO) tree + token map onto **WordPress core blocks** (Gutenberg) as `post_content` block markup, plus `theme.json` global-styles presets.

> **This is the always-available FALLBACK builder.** Core blocks ship with WordPress itself — no page-builder plugin, no Pro entitlement, no license check. figma-pro selects Gutenberg whenever the site has no supported builder (EtchWP, Bricks, Elementor) or the user asks for portable native output. Trade-off: **lowest fidelity ceiling, maximum portability** — the markup survives theme switches, exports, and migration to any WordPress install.

Unlike the Bricks/Etch adapters, figma-pro emits Gutenberg markup **directly** and persists it itself via `wp_insert_post` / `wp_update_post` — there is no separate builder-skill persister to delegate to. The validated spine (`map → emit → validate → persist-as-draft`) lives in this adapter.

## Table of contents

1. [The hand-off payload](#the-hand-off-payload)
2. [Output format — block markup](#output-format--block-markup)
3. [Node → core block mapping](#node--core-block-mapping)
4. [Auto Layout → block layout & spacing](#auto-layout--block-layout--spacing)
5. [Design tokens → theme.json](#design-tokens--themejson)
6. [Type ramp → headings & font-size presets](#type-ramp--headings--font-size-presets)
7. [Component reuse — patterns, synced patterns, query loop](#component-reuse--patterns-synced-patterns-query-loop)
8. [Responsive](#responsive)
9. [Worked example — Features section](#worked-example--features-section)
10. [Rules that always apply](#rules-that-always-apply)

---

## The hand-off payload

Gutenberg receives the generic builder payload unchanged:

```
{ target:{type:"page"|"block",title,post_id?},
  tokens:{colors,space,radius,typeRamp,source:"variables"|"styles",theme_modes},
  tree:<normalized NDO node tree — layout/fills/typography already mapped>,
  assets:[{node_id,kind:"image"|"svg",local_path,attachment_id?}],
  options:{breakpoints,draft:true,backup:true} }
```

Returns: `{ ok, post_id, validation:{passed,warnings[]}, score, preview_url }`. figma-pro then runs the pixel-diff against `preview_url`.

## Output format — block markup

A block is an HTML comment delimiter pair wrapping the block's saved HTML. Attributes are a JSON object on the opening comment; nesting is literal comment nesting.

```html
<!-- wp:group {"tagName":"section","layout":{"type":"constrained"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60"}}}} -->
<section class="wp-block-group"> … inner blocks … </section>
<!-- /wp:group -->
```

- `post_content` = the concatenated block markup string. Persist with `wp_insert_post`/`wp_update_post`; **`post_status:"draft"` always**.
- The saved HTML inside the comments must match what the block's `save()` produces (correct wrapper class, e.g. `wp-block-group`, `is-layout-flex`) or the editor shows "Attempt Block Recovery". When unsure, emit the wrapper markup exactly as core does.
- Token references use the **preset var syntax** in attributes: `"var:preset|color|primary"`, `"var:preset|spacing|50"`, `"var:preset|font-size|large"`. These resolve to the CSS custom properties defined in `theme.json`.

## Node → core block mapping

| NDO node / property | Core block | Key attributes |
|---|---|---|
| **Frame / Section** (page-width) | `core/group` (`tagName:"section"`) | `layout:{type:"constrained"}`, `style.spacing.padding` |
| Frame with full-bleed background image | `core/cover` | `url`, `dimRatio`, `overlayColor`, `contentPosition` |
| Auto Layout frame (flex row/column) | `core/group` | `layout:{type:"flex","orientation":"horizontal"\|"vertical"}` |
| Auto Layout **equal columns** | `core/columns` + `core/column` | per-column `width`; stacks on mobile by default |
| **TEXT** — heading type-ramp slot | `core/heading` | `level` (from ramp), `fontSize`/`style.typography` |
| **TEXT** — body / caption | `core/paragraph` | `fontSize`, `style.color.text` |
| **Button** component / instance | `core/buttons` + `core/button` | `core/button` `style`, `backgroundColor`, `text`, `url` |
| **Image** (raster fill) | `core/image` | `id:<attachment_id>`, `url`, `sizeSlug`, `style.border.radius` |
| **Icon / vector** | inline SVG via `core/html`, **or** `core/image` with an SVG asset | `core/html` raw `<svg>`; image needs SVG upload support |
| **Columns / grid** | `core/columns` + `core/column` | `isStackedOnMobile:true` (default) |
| **Divider / LINE** | `core/separator` | `style`, `backgroundColor`; `is-style-wide`/`dots` |
| **Spacer** | `core/spacer` | `height` (prefer a spacing preset) |

Rules:
- Choose `core/heading` vs `core/paragraph` by the **type-ramp slot** the NDO text resolves to, never by geometry-guessed font size. The heading `level` comes from the ramp slot (Display/H1 → `1`, etc.).
- A Frame is `core/cover` only when it has a full-bleed image/video background with content over it; otherwise `core/group` with a `style.background`.
- Icons that are simple vectors → inline SVG in `core/html` (portable, no upload). Bespoke logos/illustrations → `core/image` with the sideloaded asset (requires the site to allow SVG uploads; flag if it does not).
- Never emit absolute-positioned layers; core blocks have no first-class absolute positioning. Surface it as a `warnings[]` entry (`absolute layer`) and fall back to normal flow.

## Auto Layout → block layout & spacing

Figma Auto Layout maps onto core's **layout support** (`layout` attribute) and **spacing support** (`style.spacing`). Block gap replaces per-child margins.

| Figma Auto Layout | Block attribute | Value mapping |
|---|---|---|
| Direction HORIZONTAL / VERTICAL | `layout.orientation` | `horizontal` / `vertical` (with `layout.type:"flex"`) |
| Item spacing (gap) | `style.spacing.blockGap` | `var:preset|spacing|NN` from the space scale |
| Padding (T/R/B/L) | `style.spacing.padding` | `{top,right,bottom,left}` as spacing-preset vars |
| Margin | `style.spacing.margin` | spacing-preset vars |
| Primary-axis align | `layout.justifyContent` | `left` / `center` / `right` / `space-between` |
| Counter-axis align | `layout.verticalAlignment` (flex) | `top` / `center` / `bottom` |
| Wrap ("Wrap") | `layout.flexWrap` | `wrap` / `nowrap` |
| Equal-width children | `core/columns` (not flex group) | each `core/column` shares width |
| Content max-width frame | `layout.type:"constrained"` | uses theme `contentSize` |

Emit spacing as **presets** (`var:preset|spacing|50`) wherever the raw px snaps to a scale step; only fall back to a literal (`"24px"`) when no preset matches — and then log a `fixed width`/`raw spacing` warning.

## Design tokens → theme.json

Resolve Figma **Variables** first (`tokens.source:"variables"`); fall back to **Styles** only when no Variables exist. NDO tokens become `theme.json` settings, referenced from blocks by preset slug.

| NDO token | theme.json target | Referenced in block as |
|---|---|---|
| Color | `settings.color.palette[]` `{slug,color,name}` | `"var:preset|color|primary"` / `backgroundColor:"primary"` |
| Spacing (number) | `settings.spacing.spacingSizes[]` `{slug,size,name}` | `"var:preset|spacing|50"` |
| Radius | (no core preset) → per-block `style.border.radius` | literal token value or CSS var |
| Font size | `settings.typography.fontSizes[]` `{slug,size,name}` | `fontSize:"large"` or `"var:preset|font-size|large"` |
| Font family | `settings.typography.fontFamilies[]` | `fontFamily:"heading"` |
| Variable **modes** (light/dark) | style variations / `:root` + `[data-theme]` custom CSS | pass `tokens.theme_modes` through |

**Emit rule (NibWP-wide):** every styleable value references a preset, not a hardcoded literal, wherever a preset exists. A raw hex/px that resolves to no preset passes through as a `raw color`/`fixed width` **warning**, not a silent literal. **NEVER `clamp()` for font-size** — use core **fluid-typography** presets (`fluid:{min,max}` on the fontSize entry) or fixed token steps per breakpoint. (See MEMORY: no clamp() for font sizes.)

Write/merge `theme.json` presets before emitting blocks so every `var:preset|…` reference resolves. On a child theme, prefer a theme style-variation JSON or the block-level `settings` so the parent theme is untouched.

## Type ramp → headings & font-size presets

The type ramp becomes named `fontSizes` presets in `theme.json`, plus the heading `level`. Blocks reference the **slug**, not inline px.

| Ramp slot | Heading level | fontSize slug | Fluid preset (min → max) |
|---|---|---|---|
| Display / H1 | `1` | `xx-large` | `2rem → 3rem` |
| H2 | `2` | `x-large` | `1.75rem → 2.25rem` |
| H3 | `3` | `large` | `1.375rem → 1.5rem` |
| Body | — (`core/paragraph`) | `medium` | `1rem` (fixed) |
| Caption / small | — | `small` | `0.875rem` (fixed) |

Use core's built-in slugs (`small`…`xx-large`) when they line up; add custom slugs only for extra ramp steps. Fluid entries let WordPress interpolate between breakpoints without `clamp()` in your markup.

## Component reuse — patterns, synced patterns, query loop

A repeated Figma **Component / Component Set** must **not** become N duplicated block subtrees. Pick by intent:

| Repeat kind | Core mechanism | When |
|---|---|---|
| Reused layout, edited independently per place | **block pattern** (`core/pattern` / registered pattern) | marketing sections, CTA blocks — inserts a copy |
| Reused layout, edit-once-update-everywhere | **synced pattern** (`core/block {"ref":ID}`, formerly reusable block) | shared header/footer/CTA identical across pages |
| Data-driven list of the same card | **query loop** (`core/query` + `core/post-template`) bound to a CPT | team members, posts, products, testimonials |

- Component **variants** (Figma properties) → pattern variations / a modifier class on the block, not a second full pattern.
- For data-driven repeats, emit one `core/query` bound to the CPT and design the single item template inside `core/post-template`; do not unroll the rows.
- See `core/components.md` for component-set detection and dynamic-data binding.

## Responsive

Core blocks are **largely responsive by default** — `core/columns` stacks on narrow viewports (`isStackedOnMobile:true`), constrained groups use the theme `contentSize`, and fluid font presets scale automatically. Note where more is needed:

- **Column stacking:** keep `isStackedOnMobile:true` (default) unless the Figma mobile frame keeps them side-by-side.
- **Flex groups** do **not** auto-stack; a horizontal `core/group` stays a row. To reflow, either use `core/columns` instead, or add block custom CSS / a theme media query — flag it.
- **Per-breakpoint spacing / font size:** core has no native per-breakpoint padding UI in markup; use fluid presets for type, and for spacing add a targeted `@media` in the theme or a block-supplied class. Never inline `clamp()` for font-size.
- Hiding decorative elements on mobile needs a utility class + theme CSS (no core "hide on mobile" attribute); flag as a manual step.

See `core/responsive.md` for the breakpoint model and which adaptations are automatic vs. manual.

## Worked example — Features section

Figma **"Features"** section: horizontal Auto Layout, 3 equal feature cards, each an inner Auto Layout frame with icon + heading + body + button. Tokens: `Color/Primary`, `Space/M`, `Text/H3`, `Text/Body`.

Maps to a `core/columns` (3 `core/column`), each a `core/group` card, all values via presets; columns stack on mobile automatically.

```html
<!-- wp:group {"tagName":"section","layout":{"type":"constrained"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}}} -->
<section class="wp-block-group">
<!-- wp:columns {"style":{"spacing":{"blockGap":{"left":"var:preset|spacing|50"}}}} -->
<div class="wp-block-columns">

  <!-- wp:column -->
  <div class="wp-block-column">
    <!-- wp:group {"layout":{"type":"flex","orientation":"vertical"},"style":{"spacing":{"blockGap":"var:preset|spacing|40","padding":"var:preset|spacing|50"},"border":{"radius":"12px"}}} -->
    <div class="wp-block-group">
      <!-- wp:image {"id":128,"sizeSlug":"thumbnail"} --><figure class="wp-block-image size-thumbnail"><img src="…/icon.svg" alt="" class="wp-image-128"/></figure><!-- /wp:image -->
      <!-- wp:heading {"level":3,"fontSize":"large"} --><h3 class="wp-block-heading has-large-font-size">Fast setup</h3><!-- /wp:heading -->
      <!-- wp:paragraph {"fontSize":"medium"} --><p class="has-medium-font-size">Launch in minutes with sensible defaults.</p><!-- /wp:paragraph -->
      <!-- wp:buttons --><div class="wp-block-buttons">
        <!-- wp:button {"backgroundColor":"primary"} --><div class="wp-block-button"><a class="wp-block-button__link has-primary-background-color has-background wp-element-button" href="#">Learn more</a></div><!-- /wp:button -->
      </div><!-- /wp:buttons -->
    </div>
    <!-- /wp:group -->
  </div>
  <!-- /wp:column -->

  <!-- wp:column --> … card 2 … <!-- /wp:column -->
  <!-- wp:column --> … card 3 … <!-- /wp:column -->

</div>
<!-- /wp:columns -->
</section>
<!-- /wp:group -->
```

`theme.json` supplies `color.palette[primary]`, `spacing.spacingSizes[40/50/70]`, and `typography.fontSizes[large,medium]`, so no literal color/px lives in the markup. If the 3 cards are data-driven (a "Feature" CPT), swap the `core/columns` for a `core/query` + `core/post-template` around one card.

## Rules that always apply

- **Draft + backup, always.** Persist as `post_status:"draft"`; snapshot existing content before `wp_update_post`. **Never overwrite published/live content.**
- **Preset-first.** Reference `var:preset|color|…`, `var:preset|spacing|…`, `fontSize` slugs — literals only when no preset matches, and then as a warning, never silently.
- **Never `clamp()` for font-size** — fluid-typography presets or fixed token steps per breakpoint (MEMORY rule).
- **Valid block markup.** Saved HTML must match the block's `save()` output (correct `wp-block-*` classes, `is-layout-*`, wrapper tags) or the editor forces Block Recovery. Re-emit if the validator flags a mismatch.
- **Don't duplicate** — repeated components become patterns, synced patterns, or a `core/query` loop (see `core/components.md`).
- Pass `warnings[]` straight through to the final report — **fixed widths**, **absolute layers**, **missing fonts**, **raw colors**, and **manual-responsive** notes must surface to the user.
- If validation fails (invalid attribute JSON, block mismatch, unresolved preset), read the error, fix the offending mapping in figma-pro, and **re-emit** — never persist markup that fails validation.
