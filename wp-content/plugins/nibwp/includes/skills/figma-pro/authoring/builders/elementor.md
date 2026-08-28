# builders/elementor.md — Figma → Elementor

The Elementor adapter turns the NDO (`core/schema.md`) into Elementor's native
data. figma-pro **never writes Elementor meta directly** — it hands the NDO to the
elementor-pro builder skill, whose persister handles the format-specific hazards
(especially slashing) and runs `validate → score → dry_run → persist`.

## Table of contents
1. The critical `_elementor_data` hazard
2. Hand-off contract
3. NDO → Elementor element mapping
4. Live widget registry (don't hardcode)
5. Auto-layout → containers
6. Tokens → Site Settings globals
7. Components, templates & loops
8. Persist rules
9. Worked example (Hero)

---

## 1. The critical `_elementor_data` hazard

Elementor stores the page as JSON in the `_elementor_data` post meta. That JSON
**must be `wp_slash()`'d before `update_post_meta`** — WordPress strips `\/` and
`\uXXXX` on unslashed meta, corrupting the JSON into a **blank page**. This is the
single most common Elementor-import failure. The elementor-pro persister already
does this correctly; **do not** bypass it and write meta yourself.

Alongside the data, the persister sets:
- `_elementor_edit_mode = builder`
- `_elementor_version`, `_elementor_template_type`
- regenerates CSS via `\Elementor\Core\Files\CSS\Post::create($id)->update()`
  (without this the page has data but no styles).

## 2. Hand-off contract

Standard NDO payload (see the SKILL pipeline / `core/schema.md`):
```
{ target:{type:"page"|"block", title, post_id?},
  tokens:{colors,space,radius,typeRamp,source,theme_modes},
  tree:<NDO node tree>, assets:[…], options:{breakpoints,draft:true,backup:true} }
```
The elementor-pro adapter converts `tree` → the Elementor element array, `tokens` →
Site Settings globals, and persists (slashed).

## 3. NDO → Elementor element mapping

| NDO node | Elementor |
|---|---|
| `section` (top band) | Container (flex, full-width) — or legacy Section |
| `container` (auto-layout) | Container with flex settings |
| `text` (heading slot) | Heading widget |
| `text` (body) | Text Editor widget |
| button / CTA | Button widget |
| `image` | Image widget (sideloaded attachment) |
| `svg`/icon | Icon widget / inline SVG |
| `component_instance` | a saved template / global widget, reused |
| repeating data | Loop Grid (Elementor Pro) bound to a source |

## 4. Live widget registry — don't hardcode control ids

Elementor control ids differ across versions and Free/Pro. The adapter resolves
them at runtime, never from a hardcoded list:
- widget types: `\Elementor\Plugin::instance()->widgets_manager->get_widget_types()`
- a widget's controls: `->get_controls()` on the widget instance.

Map NDO style props to whatever control ids the live registry reports (e.g. a
heading's size control, a container's flex-direction control). This is why the
elementor-pro skill introspects the real install (proven against Elementor 4.1.4 +
Pro) instead of guessing.

## 5. Auto-layout → containers

NDO `layout` (flex) → Elementor **Container** flex controls:

| NDO layout | Elementor container |
|---|---|
| `direction: row/column` | flex direction |
| `justify` | justify-content |
| `align` | align-items |
| `gap: --space-*` | gap (linked to a global where possible) |
| `padding` | padding (per-side, token-backed) |
| `wrap: true` | flex-wrap |
| `sizing: fill/hug/fixed` | width: full / inline / custom |

Prefer flex Containers over the legacy Section/Column model — cleaner and
responsive. Grid (equal columns) → Container with grid or N child Containers.

## 6. Tokens → Site Settings globals

Map the NDO token system to Elementor **Site Settings**:
- `colors` → **Global Colors** (Primary/Secondary/Text/Accent…), referenced by
  widgets so a palette change propagates.
- `typeRamp` → **Global Fonts** (typography presets per ramp slot).
- `space`/`radius` → reused via container/widget spacing controls (token-backed).

**No `clamp()` font-size** — use the ramp's token steps + Elementor's per-breakpoint
typography controls for responsive type (see `core/responsive.md`).

## 7. Components, templates & loops

- Repeated NDO components → an Elementor **saved template** / global widget, reused
  — not duplicated element trees.
- Component variants (Button primary/secondary/outline) → template variations or
  conditional classes.
- Repeating **data** (cards bound to a CPT/Woo) → **Loop Grid** bound to the source
  the Decision Engine proposed (`core/parser.md` §6), not static duplication.

## 8. Persist rules

- Through the elementor-pro persister only: `wp_slash` the data, set edit-mode/
  version/template-type meta, regenerate CSS, round-trip check, backup, `draft:true`.
- Never overwrite live; never write `_elementor_data` from figma-pro directly.
- On validation failure, fix the NDO mapping and re-delegate.

## 9. Worked example — Hero

NDO hero: flex column, gap `--space-l`, padding `--space-2xl`, bg `--surface`;
child H1 (`display` ramp) + Button (primary).

Adapter → Elementor:
```
Container (flex, column, align center, gap {global space-l}, padding {space-2xl},
           background = Global Color "surface")
  ├ Heading widget   → text "Ship faster", typography = Global Font "Display",
  │                     color = Global Color "Text"
  └ Button widget    → style backed by Global Color "Primary", radius {radius-s}
```
Persisted (slashed) as a **draft**; CSS regenerated; then figma-pro runs the
pixel-diff verify against the Figma export.
