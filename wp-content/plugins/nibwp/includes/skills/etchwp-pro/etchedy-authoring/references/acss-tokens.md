# ACSS Tokens — Taxonomy & Fallback Rules

## The fallback rule (read first)

Every CSS value in a `styles[*].css` string MUST be one of:

1. `var(--token-name, fallback-value)` — a token with a fallback. **This is the default.**
2. A justified **brand accent hex** inside a brand-scoped file (e.g. `#c9a96e` in `luxe-*` classes, `#57e9db` in `alpha-*` classes). Brands may define accent hexes directly when there's no matching token in the core taxonomy.
3. A **structural value** with no semantic token (`1fr`, `100%`, `50ch`, `100vh`, `1px`, `auto`, named fonts like `'Playfair Display'`, `system-ui`).
4. A **`--bo-*` BookingOptimiser token used without fallback** — this is the convention for that brand, since the values are defined in the `.bo-root` scope in `tools/build-booking-optimiser.js`.

The `fallback-value` should be a sensible default that a brand stylesheet might override. Match the fallback values listed below unless you have a specific reason to diverge.

## Scan command (always run once per task)

Before writing a new file, refresh your awareness of what tokens are actually in use:

```bash
grep -rohE 'var\(--[a-z0-9-]+(,\s*[^)]*)?\)' data/library/ | sort -u
```

If the token you want does not appear in the output, **do not invent it** — either use a close existing token, use a structural value, or raise the question instead of silently adding a new token name.

## Core taxonomy

Harvested from the live library as of 2026-04-20. When multiple fallback values appear in the wild, the **canonical** one (most common and semantically correct) is listed first.

### Space

| Token | Canonical fallback | Notes |
|---|---|---|
| `--space-2xs` | `0.25rem` | |
| `--space-xs` | `0.5rem` | |
| `--space-s` | `0.75rem` | |
| `--space-m` | `1rem` | Also seen as `1.25rem`, `1.5rem`, or `clamp(1rem, 2.5vw, 1.5rem)` for fluid layouts. |
| `--space-l` | `1.5rem` | Also `2rem` or `clamp(1.5rem, 4cqi, 2.5rem)` for fluid gaps. |
| `--space-xl` | `3rem` | Or `clamp(2rem, 4vw, 3rem)`. |
| `--section-space-m` | `5rem` | Section padding-block for standard content sections. |
| `--section-space-l` | `6rem` | Section padding-block for hero / CTA sections. |
| `--content-gap` | (none seen) | Rare. Prefer `--space-*`. |
| `--card-gap` | `1lh` | |

### Layout

| Token | Canonical fallback |
|---|---|
| `--content-width` | `1366px` |
| `--content-width-narrow` | `42rem` |
| `--content-padding` | `1rem` |

### Text size

| Token | Canonical fallback |
|---|---|
| `--text-xs` | `0.75rem` |
| `--text-s` | `0.875rem` |
| `--text-m` | `1rem` |
| `--text-l` | `1.125rem` |
| `--text-xl` | `1.5rem` |
| `--text-xxl` | `1.75rem` |
| `--h2` / `--h2-size` | `2.25rem` |
| `--h3` | `1.5rem` |

For responsive type, switch to a smaller `--text-*` token at the breakpoint inside the same selector's inline `@container` rule. **NEVER `clamp()` `font-size`** — the validator rejects it. Layout values (`gap`, `padding`, `margin`, `max-inline-size`, `inset`) MAY use `clamp()` inside the `var()` fallback slot.

Example (correct):

```css
.alpha-hero__title {
  font-size: var(--text-xxl, 1.75rem);
  @container (inline-size < to-rem(600px)) {
    font-size: var(--text-xl, 1.5rem);
  }
}
```

Example (validator will reject):

```css
.alpha-hero__title { font-size: clamp(1.5rem, 4vw, 2.5rem); }   /* ❌ clamp on font-size */
.alpha-hero__title { font-size: var(--text-xxl, clamp(1.5rem, 4vw, 2.5rem)); }   /* ❌ clamp in fallback */
```

## Forbidden invented tokens

The conversion validator rejects any token name in this list (and any token matching the regex blocklist). Do NOT invent — pick the closest canonical name from the tables above.

### Tailwind-style numeric ramps

`--base-50`, `--base-100`, `--base-200`, `--base-300`, `--base-400`, `--base-500`, `--base-600`, `--base-700`, `--base-800`, `--base-900`

→ canonical names: `--base-ultra-light`, `--base-light`, `--base-medium`, `--base-dark`, `--base-ultra-dark`.

### Display heading aliases

`--text-display-m`, `--text-display-l`, `--text-display-xl`

→ canonical names: `--text-xxl`, `--h2`, `--h3`. For headings larger than `--h2`, switch tokens at breakpoints (see anti-pattern §13) — do NOT invent a display tier.

### Regex blocklist

Anything matching `^--text-\d+$`, `^--space-\d+$`, `^--base-\d{2,3}$` is rejected on sight. These are unambiguous signs of a Tailwind/Material muscle-memory leak into ACSS output.

### Adding a token that genuinely doesn't exist

Two options that pass validation:

1. Use the closest existing canonical token (this is right ~90% of the time).
2. Wrap a raw value inside `var(--your-new-name, raw-value)` AND open a follow-up to add the token to this taxonomy. The validator allows unknown token names as long as the raw fallback is a sensible value (hex color, rem/px/em number, or known structural keyword). It rejects only the names explicitly blocklisted above.

### Line height

| Token | Canonical fallback |
|---|---|
| `--leading-snug` | `1.3` |
| `--leading-normal` | `1.5` |
| `--leading-relaxed` | `1.6` |
| `--heading-line-height` | `1.15` |

### Radius

| Token | Canonical fallback |
|---|---|
| `--radius` | `0.5rem` |
| `--radius-m` | `0.75rem` |
| `--radius-l` | `1rem` |
| `--radius-full` | `50%` |

### Color — surface

| Token | Canonical fallback |
|---|---|
| `--white` | `#fff` |
| `--surface-dark` | `#0f1117` |
| `--surface-light` | `#f8f9fa` |

### Color — text

| Token | Canonical fallback |
|---|---|
| `--heading-color` | `#111` |
| `--text-dark` | `#374151` |
| `--text-muted` | `#6b7280` |
| `--footer-text` | `rgba(255,255,255,0.65)` |

### Color — border

| Token | Canonical fallback |
|---|---|
| `--border-color-light` | `#e8e8e8` |
| `--border-size` | `1px` |

### Color — brand (neutral brand palette, not brand-specific)

| Token | Canonical fallback |
|---|---|
| `--primary` | `#2563eb` |
| `--primary-dark` | `#1e40af` |
| `--secondary` | `#0d1b2a` |

### Color — base (grayscale ramp)

| Token | Canonical fallback |
|---|---|
| `--base-ultra-light` | `#f5f5f5` |
| `--base-light` | `#c8c8c8` |
| `--base-medium` | `#9a9a9a` |
| `--base-dark` | `#6a6a6a` |
| `--base-ultra-dark` | `#000` |

## Brand-scoped tokens

### `--bo-*` — BookingOptimiser

Defined and consumed in `tools/build-booking-optimiser.js` and consumed inside `data/library/Components/Homepage/homepage-booking-optimiser.json` within the `.bo-root` scope. Used **without fallback** by convention (raw-reference tokens):

| Group | Tokens |
|---|---|
| Backgrounds | `--bo-bg`, `--bo-bg-2`, `--bo-bg-cream`, `--bo-bg-soft` |
| Borders | `--bo-border`, `--bo-border-strong` |
| Ink / text | `--bo-ink`, `--bo-ink-2`, `--bo-muted`, `--bo-muted-2` |
| Fonts | `--bo-font-disp`, `--bo-font-mono`, `--bo-font-sans`, `--bo-font-serif` |
| Gold accent | `--bo-gold-500`, `--bo-gold-600`, `--bo-gold-700` |
| Green accent | `--bo-green-400`, `--bo-green-600` |
| Violet accent | `--bo-violet-500`, `--bo-violet-600` |
| Orange accent | `--bo-orange-500` |
| Gradients | `--bo-grad`, `--bo-grad-soft` |
| Radius | `--bo-r-md`, `--bo-r-lg`, `--bo-r-xl` |
| Shadows | `--bo-sh-xs`, `--bo-sh-sm`, `--bo-sh-md`, `--bo-sh-lg`, `--bo-sh-xl`, `--bo-sh-gold` |
| Layout | `--bo-maxw`, `--bo-pad`, `--bo-padx` |

Only use these inside BookingOptimiser-scoped files.

### `--luxe-*` — does NOT exist

Luxe Horizon consumes raw hex directly (`#0a0a0a`, `#c9a96e`, `#faf9f6`). Do not invent `--luxe-*` tokens; follow the raw-hex pattern for that brand.

### `--alpha-*`, `--mb-*`, `--etched-*` — do NOT exist as defined tokens

These brand prefixes appear as **class prefixes only**. Their color values are either hardcoded (`#57e9db` for Alpha teal) or drawn from the core taxonomy above. Treat the brand hexes as the "brand accent" justified-raw case.

## Fallback examples from the live library

```css
/* Space + layout */
gap: var(--space-l, 1.5rem);
padding-inline: var(--content-padding, 1rem);
max-inline-size: var(--content-width, 1366px);

/* Fluid space (clamp inside the fallback slot) */
padding-block: var(--section-space-m, clamp(3rem, 6vw, 5rem));

/* Typography */
font-size: var(--text-l, 1.125rem);
line-height: var(--leading-relaxed, 1.6);

/* Color */
color: var(--heading-color, #111);
background: var(--surface-light, #f8f9fa);
border-bottom: var(--border-size, 1px) solid var(--border-color-light, #e8e8e8);
```

## When you need a token that doesn't exist

1. Is there a close structural equivalent? (e.g. if you want `--danger`, is there a `--primary`? — no, use raw accent hex inside a brand file.)
2. Is there a raw-value pattern already established elsewhere in the library for this case? (Brand hexes — yes. Generic `--danger` — no precedent; fall back to raw hex with a `var()` wrapper: `background: var(--danger, #e52424);`. This makes the value overridable later without committing to a token you cannot source.)
3. Do not silently add new `--*` token names that will not resolve. The `var()` + fallback pattern is forgiving, but only if the fallback is reasonable.
