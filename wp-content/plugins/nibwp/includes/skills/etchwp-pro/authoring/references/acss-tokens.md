# ACSS Tokens — Taxonomy & Fallback Rules

> **Use this site's names.** Token names change between Automatic.css versions, and a wrong name never errors: the browser renders its fallback and the page quietly drifts from the design system. When ACSS is active, `nibwp/load-skill-playbook` returns `site_tokens` — every token this site defines, grouped by family — and the validator flags any `var()` name the site does not define. The tables below are Automatic.css 3.3.7; when they disagree with `site_tokens`, `site_tokens` wins.

## The fallback rule (read first)

Every CSS value in a `styles[*].css` string MUST be one of:

1. `var(--token-name, fallback-value)` — a token with a fallback. **This is the default.**
2. A justified **brand accent hex** inside a brand-scoped file (e.g. `#c9a96e` in `luxe-*` classes, `#57e9db` in `alpha-*` classes). Brands may define accent hexes directly when there's no matching token in the core taxonomy.
3. A **structural value** with no semantic token (`1fr`, `100%`, `50ch`, `100vh`, `1px`, `auto`, named fonts like `'Playfair Display'`, `system-ui`).
4. A **`--bo-*` BookingOptimiser token used without fallback** — this is the convention for that brand, since the values are defined in the `.bo-root` scope in `tools/build-booking-optimiser.js`.

The `fallback-value` should be a sensible default that a brand stylesheet might override. Match the fallback values listed below unless you have a specific reason to diverge.

## Which tokens exist

`site_tokens` in the playbook response is the authoritative list for the site you are building on. To see which tokens the site's existing Etch styles already use:

```bash
wp option get etch_styles --format=json | grep -ohE 'var\(--[a-z0-9-]+(,\s*[^)]*)?\)' | sort -u
```

If the token you want is not in `site_tokens`, **do not invent it** — use the closest real token, use a structural value, or raise the question instead of silently adding a new token name.

## Surfaces decide the token (read before picking a colour)

Three Automatic.css 3 behaviours produce pages that validate and still look wrong:

- **`--border-color-light` is white at 20%.** It is for borders on dark sections; on a white or light card it is invisible. On light surfaces use `--border-color-dark` (black at 20%). The validator warns when it sees `--border-color-light` on a border.
- **`--base-*` and `--bg-*` follow the site's brand colour.** `--base-ultra-light` is a pale brand tint, not the grey its old fallbacks suggested. For neutral greys use the `--neutral-*` ramp (`--neutral-ultra-light` is `#f2f2f2`).
- **Muted text depends on the surface.** `--text-dark-muted` on light surfaces, `--text-light-muted` on dark ones.

## Core taxonomy (Automatic.css 3.3.7)

### Space

| Token | Canonical fallback | Use |
|---|---|---|
| `--space-xs` | `0.5rem` | |
| `--space-s` | `0.75rem` | |
| `--space-m` | `1rem` | |
| `--space-l` | `1.5rem` | |
| `--space-xl` | `3rem` | |
| `--space-xxl` | `4.5rem` | |
| `--section-space-m` | `5rem` | Section padding-block for standard content sections |
| `--section-space-l` | `6rem` | Section padding-block for hero / CTA sections |
| `--gutter` | `1rem` | Inline padding at the edge of a section or container |
| `--content-gap` | `2rem` | Gap between blocks of content inside a container |
| `--grid-gap` | `1.5rem` | Gap between grid items and cards |
| `--container-gap` | `2rem` | Gap between containers inside a section |

Layout values (`gap`, `padding`, `margin`, `max-inline-size`, `inset`) MAY use `clamp()` inside the `var()` fallback slot: `padding-block: var(--section-space-m, clamp(3rem, 6vw, 5rem));`.

### Layout

| Token | Canonical fallback |
|---|---|
| `--content-width` | `1366px` |
| `--content-width-safe` | `1366px` |

### Text size

| Token | Canonical fallback |
|---|---|
| `--text-xs` | `0.75rem` |
| `--text-s` | `0.875rem` |
| `--text-m` | `1rem` |
| `--text-l` | `1.125rem` |
| `--text-xl` | `1.5rem` |
| `--text-xxl` | `1.75rem` |
| `--h1` | `2.5rem` |
| `--h2` | `2.25rem` |
| `--h3` | `1.5rem` |
| `--h4` | `1.25rem` |

For responsive type, switch to a smaller `--text-*` or `--h*` token at the breakpoint inside the same selector's inline `@container` rule. **NEVER `clamp()` `font-size`** — the validator rejects it.

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

### Line height

| Token | Canonical fallback |
|---|---|
| `--heading-line-height` | `1.15` |
| `--text-line-height` | `1.5` |

### Radius

| Token | Canonical fallback | Use |
|---|---|---|
| `--radius-xs` | `0.25rem` | |
| `--radius-s` | `0.375rem` | |
| `--radius` | `0.5rem` | |
| `--radius-m` | `0.75rem` | |
| `--radius-l` | `1rem` | |
| `--radius-xl` | `1.5rem` | |
| `--radius-circle` | `50vw` | Pills and circles |

### Color — surfaces

| Token | Canonical fallback | Use |
|---|---|---|
| `--white` | `#fff` | Light section or card |
| `--neutral-ultra-light` | `#f2f2f2` | Neutral light alternate background |
| `--bg-light` / `--bg-ultra-light` | site-specific | The site's configured light backgrounds — tinted by the brand colour |
| `--bg-dark` / `--bg-ultra-dark` | `#0f1117` | Dark sections |

### Color — text

| Token | Canonical fallback | Use |
|---|---|---|
| `--text-dark` | `#111` | Body and headings on light surfaces |
| `--text-dark-muted` | `#4b5563` | De-emphasised text on light surfaces |
| `--text-light` | `#fff` | Body and headings on dark surfaces |
| `--text-light-muted` | `rgba(255, 255, 255, 0.8)` | De-emphasised text on dark surfaces |

### Color — border

| Token | Canonical fallback | Use |
|---|---|---|
| `--border-color-dark` | `rgba(0, 0, 0, 0.2)` | Borders on light surfaces |
| `--border-color-light` | `rgba(255, 255, 255, 0.2)` | Borders on dark surfaces only |
| `--border-size` | `1px` | |

### Color — brand and neutral ramps

| Token | Canonical fallback | Use |
|---|---|---|
| `--primary` | `#2563eb` | Primary action |
| `--primary-hover` | `#1e40af` | Primary hover |
| `--secondary`, `--accent` | site-specific | When the colour is enabled in ACSS |
| `--neutral-ultra-light` … `--neutral-ultra-dark` | `#f2f2f2` … `#111` | True greys |

Every enabled colour gets the same generated ramp: `-ultra-light`, `-light`, `-semi-light`, `-semi-dark`, `-dark`, `-ultra-dark`, plus `-hover`. `--base-*` is that ramp for the brand's base colour, so it is tinted.

### Focus and shadow

| Token | Canonical fallback |
|---|---|
| `--focus-color` | `#2563eb` |
| `--focus-width` | `2px` |
| `--focus-offset` | `2px` |
| `--box-shadow-m` | `0 4px 12px rgba(0, 0, 0, 0.08)` |

## Forbidden invented tokens

The conversion validator rejects any token name in this list (and any token matching the regex blocklist). Do NOT invent — pick the closest real name from the tables above or from `site_tokens`.

### Tailwind-style numeric ramps

`--base-50`, `--base-100`, `--base-200`, `--base-300`, `--base-400`, `--base-500`, `--base-600`, `--base-700`, `--base-800`, `--base-900`

→ real names: the `--neutral-*` ramp for greys, or the brand-tinted `--base-ultra-light` … `--base-ultra-dark`.

### Display heading aliases

`--text-display-m`, `--text-display-l`, `--text-display-xl`

→ real names: `--h1`, `--h2`, `--text-xxl`. For larger headings switch tokens at breakpoints (see anti-pattern §13) — do NOT invent a display tier.

### Regex blocklist

Anything matching `^--text-\d+$`, `^--space-\d+$`, `^--base-\d{2,3}$` is rejected on sight. These are unambiguous signs of a Tailwind/Material muscle-memory leak into ACSS output.

### Adding a token that genuinely doesn't exist

1. Use the closest real token from `site_tokens` (this is right ~90% of the time).
2. For a brand value the design system has no token for (a rating-star gold, a status red), wrap the raw value in a `var()` of your own naming with the raw value as fallback, so it can be defined later. The validator warns that the site does not define that name; for this case that is expected — mention it in the build summary.

## Brand-scoped tokens

### `--bo-*` — BookingOptimiser

A project-specific token set, consumed within the `.bo-root` scope. Used **without fallback** by convention (raw-reference tokens):

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

## Fallback examples

```css
/* Space + layout */
gap: var(--space-l, 1.5rem);
padding-inline: var(--gutter, 1rem);
max-inline-size: var(--content-width, 1366px);

/* Fluid space (clamp inside the fallback slot) */
padding-block: var(--section-space-m, clamp(3rem, 6vw, 5rem));

/* Typography */
font-size: var(--text-l, 1.125rem);
line-height: var(--text-line-height, 1.5);

/* Color on a light card */
color: var(--text-dark, #111);
background: var(--neutral-ultra-light, #f2f2f2);
border-bottom: var(--border-size, 1px) solid var(--border-color-dark, rgba(0, 0, 0, 0.2));
```

## When you need a token that doesn't exist

1. Is there a close real token in `site_tokens`? (e.g. if you want `--danger`, check whether the site enables it; if not, use raw accent hex inside a brand file.)
2. Is there a raw-value pattern already established elsewhere in the library for this case? (Brand hexes — yes. Generic `--danger` — fall back to raw hex with a `var()` wrapper: `background: var(--danger, #e52424);`. This makes the value overridable later without committing to a token you cannot source.)
3. Do not silently add new `--*` token names that will not resolve. The `var()` + fallback pattern is forgiving, but only if the fallback is reasonable.
