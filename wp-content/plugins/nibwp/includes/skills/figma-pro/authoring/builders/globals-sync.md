# builders/globals-sync.md — tokens → builder-native globals

The token step (pipeline step 3, `core/tokens.md`) must land the NDO token set as
**real builder-native globals** — entries the target builder shows in its *own*
Global Colors / Typography / Classes UI — not merely inline `var(--token)` on each
element. This file is the per-builder sync reference: which native store each token
class writes into, the exact API, and the gotchas that corrupt a sync.

This is NibWP's token-sync layer. It covers Elementor (classic Kit + v4 Atomic),
Bricks, Gutenberg/block-theme `theme.json`, and Etch/ACSS — the full builder set
(see §8).

## Table of contents
1. Why builder-native globals (not inline var only)
2. Delegation contract (adapter writes, never figma-pro)
3. NDO token recap
4. Elementor — classic Kit globals
5. Elementor v4 — Atomic globals & prefixed classes
6. Bricks — global classes + palette
7. Gutenberg / block themes — theme.json
8. ACSS / EtchWP — compose acss-pro
9. Coverage + landed-vs-inline report
10. Cross-references

---

## 1. Why builder-native globals (not inline var only)

Inline `color: var(--primary, #2563EB)` renders correctly but is **invisible to the
builder**. The user opens Global Colors and sees nothing; changing the palette in
the builder UI doesn't touch the page; a second element can't pick "Primary" from a
swatch. A native global is:

- **Editable** in the builder's own UI (color picker, typography preset, class panel).
- **Propagating** — one edit re-flows every element bound to it.
- **Discoverable** — it appears in the builder's global lists, so future manual edits reuse it.

Rule: land each token as a native global **and** reference elements via the builder's
own global var (`var(--e-global-color-{id})`, `var:preset|color|primary`, a class),
not a raw hex. Only tokens with no native slot stay inline — and those get reported (§9).

## 2. Delegation contract — the adapter writes, never figma-pro

figma-pro's token step **hands the NDO `tokens` object to the builder ADAPTER** and
the adapter's persister writes the native store. figma-pro **never** writes builder
meta/options directly (same rule as `builders/elementor.md` §1 for `_elementor_data`).
When **acss-pro** is active, the token step composes it FIRST (`core/composition.md`
§2) — ACSS owns the token system natively and the builder globals are layered on top.

CRUD-diff, don't clobber: every sync is an **ADD / SET / DEL diff** matched by
`_id`/`slug`/`title`, so a re-sync updates tokens in place and leaves unrelated
user-authored globals (and helper/boxed-width classes) untouched.

## 3. NDO token recap (`core/schema.md`, `core/tokens.md`)

```
tokens: {
  source: "variables" | "styles",
  colors:   { --primary:#2563EB, --surface:#fff, --text:#0B0B0B, --accent:… },
  space:    { --space-3xs … --space-2xl },           // ascending scale
  radius:   { --radius-s, --radius-m, --radius-l },
  typeRamp: { display|h1|h2|h3|body|small : {size,weight,line,family} },
  theme_modes: [ "light", "dark" ]                    // dark = value overrides
}
```

## 4. Elementor — classic Kit globals

Elementor stores globals on the **active Kit** (a CPT). Resolve it live, never guess IDs:

```php
$kits = \Elementor\Plugin::$instance->kits_manager;
$kit  = $kits->get_active_kit();          // Kit document
$s    = $kit->get_settings();             // current global arrays
```

Kit settings keys and NDO mapping:

| Kit setting key | Shape | NDO source |
|---|---|---|
| `system_colors` | `[{_id:'primary', title, color}]` | `colors.--primary/--secondary/--text/--accent` |
| `custom_colors` | `[{_id, title, color}]` | remaining `colors.*` |
| `system_typography` | `[{_id:'primary', title, typography_font_family, typography_font_size:{size,unit}, typography_font_weight, typography_line_height}]` | `typeRamp` slots |
| `custom_typography` | `[{_id, title, typography_*}]` | extra ramp slots |
| `container_width` | `{size,unit}` | boxed content width |

Apply the ADD/SET/DEL diff, then persist via the kit document (regenerates kit CSS):

```php
$s['system_colors'] = merge_by_id($s['system_colors'], $ndo_system_colors); // SET matches, ADD new
$kit->update_settings($s);   // NOT raw update_post_meta — this regenerates global CSS
```

Widgets reference these as `var(--e-global-color-{_id})` and
`var(--e-global-typography-{_id}-font-size)` etc. Emit **helper classes** and inject
them into both the **editor preview** and the **frontend head**:

```css
.text-h1   { font-size: var(--e-global-typography-h1-font-size);
             font-weight: var(--e-global-typography-h1-font-weight);
             line-height: var(--e-global-typography-h1-line-height); }
.container-boxed { max-width: var(--e-global-...container-width, 1140px); margin-inline:auto; }
```

## 5. Elementor v4 — Atomic globals & prefixed classes (modern path)

v4 ("Atomic") replaces ad-hoc styling with **global classes** + **global color
variables**. Prefer this when the install runs the v4 editor.

**Stores:**
- Global classes → `\Elementor\Modules\GlobalClasses\Global_Classes_Repository` (get/put the class map).
- Global color variables → kit meta `_elementor_global_variables`, each typed
  `global-color-variable` (`{id, label, value:'#RRGGBB', type:'global-color-variable'}`).

**Prefixed reusable classes** — one prefix per style axis, each token → one class,
each class carries per-breakpoint `variants`:

| Prefix | Axis | NDO source |
|---|---|---|
| `g-ut` | typography | `typeRamp` slot |
| `g-up` | padding | `space` scale |
| `g-ub` | border | — |
| `g-ur` | border-radius | `radius` scale |
| `g-us` | shadow | effect tokens |
| `g-ug` | gap | `space` scale |
| `g-uw` | width | boxed width |

**Value format is `$$type`-tagged** (this is the part hand-rolled JSON gets wrong):

```php
// a size value
[ '$$type' => 'size', 'value' => [ 'size' => 48, 'unit' => 'px' ] ]

// a g-ut typography variant's props
'props' => [
  'font-size'   => [ '$$type'=>'size', 'value'=>['size'=>48,'unit'=>'px'] ],
  'font-weight' => [ '$$type'=>'string', 'value'=>'700' ],
  'line-height' => [ '$$type'=>'size', 'value'=>['size'=>1.1,'unit'=>'em'] ], // em, not px
]
```

**Breakpoint order for `variants`** (widest → narrowest — emit in this order):
`widescreen, desktop, laptop, tablet_extra, tablet, mobile_extra, mobile`.

**v4 gotchas — document and honor every one:**
- **Mirror class ORDER into the editor PREVIEW context.** If the preview's class list
  order differs from the saved repo, v4 **doesn't list** the classes in the panel.
- **Convert `rgba()`→hex** before writing a color variable — v4 color vars want `#RRGGBB`.
- **`line-height` default unit is `em`, not `px`** — writing px produces giant leading.
- **Don't wipe the boxed-width (`g-uw`) class on a token-only sync** — the CRUD diff
  must SET color/typography and leave layout classes intact.

## 6. Bricks — global classes + palette

Bricks keeps globals in WP options, not per-post:

| Store | Option | Shape |
|---|---|---|
| Global classes | `bricks_global_classes` | `[{id, name, settings:{…}}]` |
| Global colors | Bricks color palette option | palette entries `{id, name, raw/hex}` |

Mapping:
- `colors.*` → **Bricks global colors** (one palette entry per token).
- `typeRamp` slots → **reusable heading/text classes** in `bricks_global_classes`
  (`settings` carries `typography.font-size`, `font-weight`, `line-height`, `font-family`).
- `space`/`radius` → class `settings` (padding/gap/border-radius).

Elements bind a class by its **id** via `_cssGlobalClasses` (array of class ids on the
element), so a class edit propagates. Diff `bricks_global_classes` by `id`/`name` —
ADD new, SET matching, never rewrite the whole array.

```php
$classes = get_option('bricks_global_classes', []);
$classes = merge_by_id($classes, $ndo_bricks_classes);   // CRUD diff
update_option('bricks_global_classes', $classes);
// element:  '_cssGlobalClasses' => [ 'h1-class-id' ]
```

## 7. Gutenberg / block themes — theme.json

NibWP syncs Gutenberg tokens natively. Write the NDO tokens into
either the theme's `theme.json` **or** the user global-styles CPT (`wp_global_styles`,
the merged runtime layer edited in the Site Editor — prefer this for a non-destructive,
user-editable sync):

| theme.json path | Shape | NDO source |
|---|---|---|
| `settings.color.palette` | `[{slug:'primary', name, color:'#…'}]` | `colors.*` |
| `settings.spacing.spacingSizes` | `[{slug:'40', name, size:'1rem'}]` | `space` scale |
| `settings.typography.fontSizes` | `[{slug:'body', name, size}]` | `typeRamp` sizes |
| `settings.typography.fontFamilies` | `[{slug, name, fontFamily}]` | ramp families |

Blocks then reference **presets**, not raw values:
`var:preset|color|primary`, `var:preset|spacing|40`, and named font sizes
(`fontSize:"body"`). These surface natively in the editor's color/typography panels.

```php
// user global styles CPT (Site Editor layer)
$gs   = \WP_Theme_JSON_Resolver::get_user_data();   // read
// merge palette/spacingSizes/fontSizes via CRUD diff (match by slug), then persist to wp_global_styles
```

**No `clamp()` font-size** (`core/tokens.md` §6): use WP **fluid typography**
(`settings.typography.fluid` with `fluid:{min,max}` per size) or per-step ramp tokens.

## 8. ACSS / EtchWP — compose acss-pro

If **acss-pro** is active/entitled (`skill:acss`, #62), the token step maps NDO tokens
to **ACSS's own** variables + utility classes + palette/spacing scale — the native
token system for Etch — instead of ad-hoc `var()`:

| NDO | ACSS native |
|---|---|
| `colors.*` | ACSS palette slots (`--primary`, `--base`, `--accent`, shade steps) |
| `space.*` | ACSS spacing scale (`--space-*`) |
| `radius.*` | ACSS radius scale |
| `typeRamp` | ACSS text scale (`--text-*`) + utility text classes |

ACSS runs **BEFORE** the builder (`core/composition.md` §2), so its variables already
exist when the Etch/builder globals are layered on. Map to real ACSS scale slots only
— the Etch/ACSS validator rejects invented tokens (`--text-7`, `--space-999`). Detail
in `builders/etchwp.md`.

## 9. Coverage — and report what landed

**Coverage:**

| Builder | figma-pro |
|---|---|
| Elementor classic Kit | ✅ |
| Elementor v4 Atomic | ✅ (typed values, preview-order, unit fixes) |
| Bricks | ✅ |
| Gutenberg / block theme `theme.json` | ✅ |
| Etch / ACSS scale | ✅ (composes acss-pro) |
| Re-sync CRUD diff (no clobber) | ✅ everywhere |

**Always report which tokens landed as native globals vs stayed inline.** After the
sync, emit a per-class summary so the user knows exactly what the builder UI now owns:

```
Tokens → globals (Elementor v4):
  colors:   4/4 landed as global-color-variable
  typeRamp: 5/6 landed as g-ut classes  (1 inline — 'caption' has no ramp slot, flagged)
  space:    7/7 landed as g-up/g-ug
  radius:   3/3 landed as g-ur
  inline-only: 1  (see flags)
```

## 10. Cross-references

- `core/tokens.md` — token extraction/mapping, no-clamp rule, styles fallback.
- `core/composition.md` — acss-pro folds into the token step, runs before the builder.
- `core/schema.md` — the NDO `tokens` object shape.
- `builders/elementor.md` — Elementor hand-off + Kit globals context.
- `builders/bricks.md`, `builders/gutenberg.md`, `builders/etchwp.md` — the sibling
  builder adapters this doc's sync layer plugs into.
