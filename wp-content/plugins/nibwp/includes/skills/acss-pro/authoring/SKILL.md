---
name: acss-authoring
description: Use when generating an ACSS (Automatic.css) configuration from a design source (screenshot, HTML+CSS, target URL, Figma). Enforces WCAG AA contrast on heading + body pairs, modular scale ratios in [1.067, 1.618], consistent space-ramp ratio, neutral-ramp luminance gap ≥ 0.80, palette delta-E ≥ 30, and primary_dark luminance shift ≥ 20%. Triggers on "generate ACSS config", "extract palette", "design tokens from screenshot", "ACSS from URL".
---

# ACSS Authoring

This skill generates an ACSS configuration that becomes the source of truth for `var(--text-*)`, `var(--space-*)`, `var(--primary)`, etc. — the same tokens EtchWP Pro components reference.

The conversion from source to config happens AGENT-SIDE. The server-side abilities validate the agent-built config and persist it.

## Flow

1. **Preflight** — call `nibwp/skill-preflight { skill_id: "acss-pro" }`. Server asks: target_settings_group, palette_decision (preserve/overwrite/merge), optional brand_seed (#hex anchor). Mints `_preflight_token`.
2. **Detect** — call `nibwp/acss-pro-detect`. Read current settings + ACSS version. Agent uses this to know what to merge with.
3. **Extract** — agent reads the source:
   - **Screenshot**: extract dominant colors via vision (k-means or top-3 saturated hues), measure body text size in pixels, count visible font weights, sample radius from buttons.
   - **HTML+CSS**: parse stylesheet text for `--*` custom properties, `color`/`background` literals, `font-size`/`line-height` declarations, `border-radius`, `box-shadow`.
   - **URL**: fetch via `nibwp/acss-pro-audit-site` (or agent-side fetch), then parse as above.
4. **Build** the config tree:
   ```json
   {
     "colors": {
       "primary": "#hex", "primary_dark": "#hex", "secondary": "#hex",
       "background": "#fff", "heading": "#hex", "body": "#hex",
       "neutral_light": "#hex", "neutral_dark": "#hex"
     },
     "type": { "family_heading": "Inter", "family_body": "Inter",
               "scale_ratio": 1.250, "size_min": 16, "size_max": 18 },
     "space": { "scale_ratio": 1.500,
                "scale": [0.25, 0.5, 0.75, 1, 1.5, 2.25, 3.375] },
     "radius": { "base": 6, "l": 12, "full": 9999 },
     "shadows": { "s": "0 1px 2px rgba(0,0,0,.05)", "m": "0 4px 12px rgba(0,0,0,.08)" },
     "breakpoints": { "sm": 640, "md": 960, "lg": 1280, "xl": 1536 }
   }
   ```
5. **Validate** — call `nibwp/acss-pro-from-design { config, _preflight_token }`. Validator runs 7 hard rules + 2 warnings. On any failure, EVERY entry carries a `fix_hint` — patch and resubmit.
6. **Approve** — surface the validated config to the user (palette swatches, type ramp preview, contrast ratios). Ask for thumb-up.
7. **Persist** — call `nibwp/acss-pro-update-variables { config, _preflight_token }`. The server re-validates (defense in depth), then persists through **ACSS's own settings model, which saves AND recompiles the dynamic CSS in the same call** — the config is live instantly (no manual "Save" in the ACSS UI). See the exact API below.

## ACSS write API — exact functions (NEVER guess, NEVER hand-write the option)

Automatic.css owns its settings + compiled CSS. To inject/save/validate a config you MUST go through the plugin's own model so the change is written in ACSS's schema **and** the CSS variables file is regenerated on the spot. These are the exact, verified names (ACSS namespace `Automatic_CSS`) — the `update-variables` ability + `lib/persister.php` already call them; do not invent alternatives or `update_option()` the raw array:

| Purpose | Exact call |
|---|---|
| **Save config + regenerate CSS (instant inject)** | `Automatic_CSS\Model\Database_Settings::get_instance()->save_settings($values, true)` — 2nd arg `$trigger_css_generation = true` recompiles the dynamic CSS immediately. |
| **Read current settings (to merge, not clobber)** | `Automatic_CSS\Model\Database_Settings::get_instance()->get_vars()` (all) / `->get_var($id)` (one) |
| **Force a full CSS rebuild explicitly** | `Automatic_CSS\CSS_Engine\CSS_Engine::get_instance()->generate_all_css_files($db)` or `->update_framework_css_files()` |
| **Settings option (read-only reference)** | `wp_options['acss_global_setting']` — ACSS's own array. NEVER hand-write it; always go through `save_settings()`. |
| **Compiled CSS location** | `Automatic_CSS\Plugin::get_dynamic_css_dir()` / `::get_dynamic_css_url()` |
| **ACSS version** | `Automatic_CSS\Plugin::get_plugin_version()` |

`$values` passed to `save_settings()` is the **full** settings array keyed by ACSS's `var_id`s — read `get_vars()`, merge your changes in, save the whole thing. The persister returns `unknown_keys[]` when a flat key isn't in ACSS's live var schema; if that's non-empty, fix the flatten map — a stray key the framework never reads means silent no-op.

### Verified INPUT var_ids — ACSS 4.x (do NOT invent key names)

ACSS 4.x is **OKLCH-based** (~2389 total vars, mostly derived). You only set the small **input** set below; ACSS computes the rest (`-hover`, `-dark`, `-light`, `-ultra-*`, `-oklch` derivatives). Confirmed live via `get_vars()`:

| Config value | ACSS input var_id(s) | Notes |
|---|---|---|
| a color (primary/accent/base/action/shade) | `{name}-l-oklch`, `{name}-c-oklch`, `{name}-h-oklch` | **OKLCH triple, not hex.** e.g. primary = `primary-l-oklch` 0–1 (lightness), `primary-c-oklch` (chroma), `primary-h-oklch` (hue °). Convert hex → OKLCH before writing. |
| body font size (desktop / mobile) | `base-text-desk`, `base-text-mob` | px number |
| heading base size | `base-heading-desk` (`-mob`, `-lh`) | px number |
| space base | `base-space` | px number |
| radius base | `base-radius` | e.g. `"5px"` |
| root font size | `root-font-size` | % |

**Semantic color map** (our config → ACSS 4 names): `primary→primary`, `secondary→accent`, `background/base→base`, plus `action`/`shade` where present. ACSS derives heading/body/neutral text colors from `base` + the palette — do **not** try to set `heading`/`body`/`neutral_*` as separate colors in 4.x.

**How the persister applies this (already implemented):** `lib/persister.php` reads ACSS's live schema via `get_vars()`, and when it sees `primary-l-oklch` it switches to the 4.x path (`nibwp_acss_flatten_config_oklch()` + `nibwp_acss_hex_to_oklch()`): each config hex → OKLCH → `{name}-l/c/h-oklch`, sizes → `base-text-desk`/`-mob`, `base-space`, `base-radius`. It only writes keys ACSS actually exposes; the rest are dropped, not sent as strays. To *overwrite* existing colors (not just add new ones) the preflight `mode` must be `overwrite_with_extracted` — the default `merge_only_new_keys` leaves already-set vars untouched.

> Not yet mapped on 4.x: font-family var_ids (skipped, not guessed — confirm via `get_vars()` before adding). ACSS 3.x sites fall through to the legacy `color-*` path.

## Validator rules (hard rejects)

| Rule id | Description |
|---|---|
| `contrast_fail` | Heading on background < 3.0 OR body on background < 4.5 (WCAG AA) |
| `palette_too_close` | Primary ↔ secondary delta-E < 30 (visually indistinguishable) |
| `brand_dark_undertinted` | `--primary-dark` luminance Δ vs `--primary` < 20% |
| `neutral_ramp_gap` | `neutral_light` ↔ `neutral_dark` luminance gap < 0.80 |
| `scale_ratio_insane` | Type scale ratio outside [1.067, 1.618] |
| `viewport_minmax_inverted` | `size_min >= size_max` |
| `space_scale_drift` | Space ramp ratios drift > 25% from mean (not a clean modular scale) |

## Canonical modular scales (pick one for type)

| Name | Ratio |
|---|---|
| Minor second | 1.067 |
| Major second | 1.125 |
| Minor third  | 1.200 |
| Major third  | 1.250 |
| Perfect fourth | 1.333 |
| Augmented fourth | 1.414 |
| Perfect fifth | 1.500 |
| Golden ratio | 1.618 |

## Palette anchor (brand_seed)

When the preflight `brand_seed` is set, anchor the primary color to that hex. Derive everything else (primary_dark = same hue, luminance −30%; secondary = complementary hue ±30°; neutral_light = desaturated tint; neutral_dark = desaturated shade).

## Out of scope (v1)

- Live Figma API extraction (use screenshot of Figma frame instead).
- Custom shadow recipes (we accept the agent's strings as-is; validator doesn't lint shadows).
- Animation duration / easing tokens.
- Container query breakpoint generation — the agent inherits defaults unless the user overrides.
