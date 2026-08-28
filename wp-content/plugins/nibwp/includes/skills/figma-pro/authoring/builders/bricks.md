# Figma → Bricks — hand-off contract

Maps a normalized Figma node tree + token map onto **Bricks Builder** elements, classes, and design-system variables.

> **figma-pro NEVER writes `_bricks_page_content_2` (or the header/footer/`_bricks_template_type` variants) directly.** It hands the normalized tree + token map to NibWP's **Bricks builder skill** (`bricks-pro`, a shipping NibWP product) and lets that skill's validated persister — `nibwp/bricks-pro-html-to-component` — do the write. That persister owns serialization, slashing, `bricks_global_classes` merge, draft/backup, and CSS. Bypassing it corrupts the serialized PHP array or overwrites live content.

## Table of contents

1. [Conversion hierarchy](#conversion-hierarchy)
2. [The hand-off payload](#the-hand-off-payload)
3. [Node → element mapping](#node--element-mapping)
4. [Auto Layout → container flex](#auto-layout--container-flex)
5. [Design tokens](#design-tokens)
6. [Type ramp → heading classes](#type-ramp--heading-classes)
7. [Component intelligence](#component-intelligence)
8. [Responsive / breakpoints](#responsive--breakpoints)
9. [Worked example — Primary Button](#worked-example--primary-button)
10. [Rules that always apply](#rules-that-always-apply)

---

## Conversion hierarchy

```
Figma Frame → Sections → Containers → Components → Variables → Styles → Assets → WordPress (Bricks) Elements
```

figma-pro resolves top-down: the outer Frame becomes one or more Bricks `section`s; nested Auto Layout frames become `container`/`block`; leaf nodes become `heading`/`text-basic`/`button`/`image`/`icon`. Variables and Styles resolve to the token map **before** any element is emitted, so every element references tokens, never raw values.

## The hand-off payload

Bricks receives the generic builder payload unchanged:

```
{ target:{type:"page"|"block",title,post_id?},
  tokens:{colors,space,radius,typeRamp,source:"variables"|"styles",theme_modes},
  tree:<normalized node tree — layout/fills/typography already mapped>,
  assets:[{node_id,kind:"image"|"svg",local_path,attachment_id?}],
  options:{breakpoints,draft:true,backup:true} }
```

The Bricks persister returns: `{ ok, post_id, validation:{passed,warnings[]}, score, preview_url }`. figma-pro then runs the pixel-diff against `preview_url`.

### Bricks element record shape (what the persister builds, not figma-pro)

Each element in the `_bricks_page_content_2` array is:

```
{ id:"a1b2c3",         // 6-char
  name:"container",    // element type slug
  parent:"a1b2c0",     // parent id ("0" at root)
  children:["a1b2c4"], // ordered child ids
  settings:{...},      // that element's controls
  label:"Hero" }       // optional, from Figma layer name
```

figma-pro emits a **normalized tree** (nesting + `name` + `settings` + intended `label`); the persister assigns real 6-char ids, wires `parent`/`children`, and serializes.

## Node → element mapping

| Figma node / property | Bricks element (`name`) | Key `settings` |
|---|---|---|
| Top-level **Frame** (page-width) | `section` | `tag`, `_padding`, `_background` |
| Auto Layout **Frame / Container** | `container` | `_display:flex`, `_flex.*` (see next table) |
| Non-layout wrapper / grouping | `block` or `div` | `tag`, `_cssGlobalClasses` |
| **TEXT** — heading type-ramp slot (H1–H6) | `heading` | `tag:h1…h6`, `text`, `_typography` |
| **TEXT** — body / caption slot | `text-basic` | `text`, `_typography` |
| **TEXT** — rich/multi-paragraph | `text` | `text` (HTML) |
| **Button** component / instance | `button` | `text`, `_cssGlobalClasses:["btn-*"]`, `tag`, `link` |
| **Image** fill / raster node | `image` | `image:{id:<attachment_id>,url,size}`, `_objectFit` |
| **Vector / icon** node | `icon` (Bricks icon lib) **or** inline `svg` when custom | `icon` set, or `svg` `source:code` |
| Repeated **Component set** | ONE global class + reused instances | see [Component intelligence](#component-intelligence) |

Rules:
- Choose `heading` vs `text-basic` by the **type-ramp slot** the Figma text style resolves to, not by font size guessed from geometry.
- A vector that matches a Bricks/FontAwesome icon → `icon`; a bespoke logo/illustration → inline `svg` (`source:code`) or an SVG asset.
- Never emit absolute-positioned children unless the Figma layer is genuinely absolute; flag it in `warnings[]` instead.

## Auto Layout → container flex

Auto Layout maps onto the container's `_flex` object (per-breakpoint capable). Bricks renders the `@media` automatically — never write `@media` yourself.

| Figma Auto Layout property | Bricks `settings` | Value mapping |
|---|---|---|
| Direction HORIZONTAL / VERTICAL | `_flex.direction` | `row` / `column` |
| Item spacing (gap) | `_flex.gap` | `var(--space-*, Npx)` from space scale |
| Padding (T/R/B/L) | `_padding` | `{top,right,bottom,left}` as tokens |
| Primary-axis align (packed/space-between) | `_flex.justifyContent` | `flex-start` / `center` / `flex-end` / `space-between` |
| Counter-axis align | `_flex.alignItems` | `flex-start` / `center` / `flex-end` / `stretch` |
| Wrap (Figma "Wrap") | `_flex.wrap` | `wrap` / `nowrap` |
| "Fill container" child | child `_width` | `100%` / flex-grow |
| "Hug contents" | (default) | no explicit width |

```json
{ "name":"container",
  "settings":{ "_display":"flex",
    "_flex":{ "_base":{ "direction":"row", "gap":"var(--space-l, 2rem)",
                        "justifyContent":"space-between", "alignItems":"center", "wrap":"wrap" } },
    "_padding":{ "_base":{ "top":"var(--space-xl, 4rem)", "bottom":"var(--space-xl, 4rem)",
                           "left":"var(--space-m, 1.5rem)", "right":"var(--space-m, 1.5rem)" } } } }
```

## Design tokens

Resolve Figma **Variables** first (`tokens.source:"variables"`), fall back to **Styles** only when no Variables exist.

| Figma token | Bricks target | Notes |
|---|---|---|
| Color Variable | Bricks **global color** + CSS custom property | Register once; elements reference `var(--token, #hex)` |
| Number/spacing Variable | Space scale CSS var (`--space-s/m/l/xl`) | Snap raw px to nearest scale step |
| Radius Variable | `--radius-*` var → `_border.radius` | |
| Variable **modes** (light/dark) | Bricks Design System variable per mode / theme | pass `tokens.theme_modes` through |
| Text style | reusable heading/body global class (see below) | |

**Emit rule (NibWP-wide):** every value is `var(--token, fallback)` — token first, literal fallback second. **NEVER `clamp()` for font-size** — switch the token at the breakpoint instead (see Responsive). Raw hex/px that resolve to no token pass through as a `raw color`/`fixed width` **warning**, not a silent literal.

## Type ramp → heading classes

The type ramp becomes **reusable global classes**, one per ramp slot, referenced via `_cssGlobalClasses` — not per-element inline typography.

| Ramp slot | Global class | `_typography.font-size` |
|---|---|---|
| Display / H1 | `{brand}-h1` | `var(--text-xxl, 3rem)` |
| H2 | `{brand}-h2` | `var(--text-xl, 2.25rem)` |
| H3 | `{brand}-h3` | `var(--text-l, 1.5rem)` |
| Body | `{brand}-body` | `var(--text-m, 1rem)` |
| Caption / small | `{brand}-caption` | `var(--text-s, 0.875rem)` |

Class names follow the Bricks BEM grammar `{brand}-{component}__{element}[--mod]`, lower-kebab, brand prefix mandatory (validator rejects `bricks_missing_brand_prefix`).

## Component intelligence

A repeated Figma **Component / Component Set** must become **ONE** Bricks global class (a reusable "global element" pattern) with many referencing instances — never N duplicated element subtrees.

- First instance defines the class in `payload.global_classes` (`{id,name,settings}`).
- Every instance emits a lightweight element referencing it via `_cssGlobalClasses`; only per-instance content (`text`, `link`, `image`) differs.
- Component **variants** (Figma properties) → **modifier** classes (`--primary`, `--featured`); the instance references base + modifier.
- The persister merges by `name` into `bricks_global_classes` (same name = update, new = append; never deletes).

## Responsive / breakpoints

Map Figma frame widths / layout constraints onto Bricks' default breakpoint keys. Only override the breakpoints that differ from `_base`; Bricks cascades from the next-larger.

| Bricks key | Default range | Fed from Figma |
|---|---|---|
| `_base` | all sizes (desktop-first) | widest frame |
| `_tablet_portrait` | ≤ 1024px | tablet frame, if present |
| `_mobile_landscape` | ≤ 768px | — |
| `_mobile_portrait` | ≤ 480px | mobile frame / narrowest |

Typical adaptations, expressed as per-breakpoint setting objects (never `@media` in `_cssCustom`):
- Row → column: `_flex.direction:{_base:"row", _mobile_portrait:"column"}`.
- Shrink heading: switch the **token**, e.g. `font-size:{_base:"var(--text-xxl,3rem)", _mobile_portrait:"var(--text-l,1.5rem)"}` — never `clamp()`.
- Reduce section padding at smaller keys.
- Hide decorative element: `_display:{_base:"block", _mobile_portrait:"none"}`.

## Worked example — Primary Button

Figma **"Primary Button"** component (Auto Layout, fill `Color/Brand/Primary`, radius 8, text style `Label/M`):

1. Token registered from the color Variable → global color + CSS var `--button-primary`.
2. Component → ONE global class `.btn-primary` (defined once, referenced by every instance).
3. Each instance → a `button` element carrying only its own `text` + `link`.

```json
{ "global_classes":[
    { "id":"btnprm", "name":"btn-primary",
      "settings":{
        "_padding":{ "_base":{ "top":"var(--space-s, .75rem)", "bottom":"var(--space-s, .75rem)",
                               "left":"var(--space-m, 1.5rem)", "right":"var(--space-m, 1.5rem)" } },
        "_background":{ "_base":{ "color":"var(--button-primary, #2b59ff)" } },
        "_border":{ "_base":{ "radius":{ "top-left":"8px","top-right":"8px","bottom-right":"8px","bottom-left":"8px" } } },
        "_typography":{ "_base":{ "font-size":"var(--text-m, 1rem)", "font-weight":"600",
                                  "color":"var(--white, #fff)" } } } } ],
  "tree":[
    { "name":"button", "label":"CTA",
      "settings":{ "tag":"a", "text":"Get started", "link":{"type":"internal"},
                   "_cssGlobalClasses":["btn-primary"] } } ] }
```

A `Primary Button — hover` variant would add a `.btn-primary--hover`/state class rather than a second full definition.

## Rules that always apply

- **Never** write `_bricks_page_content_2` / `bricks_global_classes` from figma-pro. Delegate to the `bricks-pro` persister — slashing, serialization, class merge, and backups live there.
- **Draft + backup, always.** Never overwrite published/live content; write to a draft revision.
- Pass `warnings[]` straight through to the final report — **fixed widths**, **absolute layers**, **missing fonts**, **raw colors** (no token match) must surface to the user.
- Emit `var(--token, fallback)` everywhere; **never `clamp()` for font-size** — switch the token at the breakpoint.
- If the Bricks validator rejects the tree (`bricks_missing_brand_prefix`, `bricks_inline_media_query`, malformed settings), read the rule id, fix the offending token/class/structure in figma-pro's mapping, and **re-delegate** — never force content past validation.
