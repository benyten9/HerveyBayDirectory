# BEM + Naming Rules

## Class grammar

Every class selector in `styles[*].selector` follows:

```
{brand}-{component}__{element}[--{modifier}]
```

- **brand** — lowercase, no spaces. Matches the brand prefix for the artifact's brand context.
- **component** — lowercase kebab-case of the artifact's `slug` or a shortened form.
- **element** — lowercase kebab-case role within the component (`heading`, `sub`, `btn`, `container`, `card`, `card-title`, `card-desc`, `icon`, `image`, `divider`, `eyebrow`).
- **modifier** — optional, follows `--`. For variants like `--primary`, `--large`, `--reversed`.

## Brand prefixes in use

| Brand | Class prefix | Example |
|---|---|---|
| Etchedy core | `etched-` | `.etched-delete-split__label` |
| Alpha | `alpha-` | `.alpha-cta-banner__heading` |
| Luxe Horizon | `luxe-` | `.luxe-hero__container` |
| Modern Blog | `mb-` | `.mb-hero__desc` |
| BookingOptimiser | `bo-` (inside `.bo-root`) | `.bo-root .bo-hero__title` |

When creating a new brand, pick a short lowercase prefix (2–6 chars) and use it consistently. Never mix prefixes within one artifact.

## Component segment — shortening rules

The `component` segment is usually the artifact's slug, but may be shortened when the slug contains its own category prefix:

- slug `alpha-cta-banner` → component `alpha-cta-banner` (class `alpha-cta-banner__heading`)
- slug `alpha-feature-grid` → component `alpha-features` (short form — see `feature-grid.json`)
- slug `luxe-hero-fullscreen` → component `luxe-hero`

Pick one form and use it for every style in the file. Do not mix `alpha-features__card` and `alpha-feature-grid__card` in the same artifact.

## Examples harvested from the library

```css
.alpha-cta-banner                    /* root */
.alpha-cta-banner__container         /* element */
.alpha-cta-banner__heading           /* element */
.alpha-cta-banner__sub               /* element */
.alpha-cta-banner__btn               /* element */

.alpha-features                      /* root */
.alpha-features__container
.alpha-features__title
.alpha-features__grid
.alpha-features__card
.alpha-features__card-title          /* compound element name */
.alpha-features__card-desc
.alpha-features__icon

.luxe-hero
.luxe-hero__container
.luxe-hero__eyebrow
.luxe-hero__heading
.luxe-hero__divider
.luxe-hero__desc
.luxe-hero__btn

.etched-delete-split                 /* root (element type, not a section) */
.etched-delete-split__label
.etched-delete-split__sep
.etched-delete-split__icon
```

## Forbidden patterns

- Generic class names: `.heading`, `.title`, `.btn`, `.card`, `.container`, `.wrapper`, `.row`, `.col`. These collide across components and break builder scoping. Always prefix with `{brand}-{component}__`.
- Single-underscore "element" separators (`alpha-cta-banner_heading`). Must be double underscore.
- Single-dash modifier separators (`alpha-btn-primary`). Must be double dash: `alpha-btn--primary`.
- CamelCase or PascalCase in class names. All lowercase, kebab-case.
- Leading/trailing separators: `__heading`, `alpha__heading--`, `alpha-hero--`.

## Slug and ID rules

### Slug (file name)

- Lowercase kebab-case of the `name`.
- `name: "Alpha CTA Banner"` → `slug: "alpha-cta-banner"` → file: `alpha-cta-banner.json`.
- Matches the `component` segment of the BEM class prefix (usually). If the class-prefix short form differs (`alpha-features` vs `alpha-feature-grid`), the slug/file follows the long form.

### Manifest `id`

Format: `{type}-{category}-{slug}` — all lowercase, all hyphen-separated, **category is lowercased** (not PascalCase).

- `type = "elements"`, category folder `Buttons`, slug `primary` → id `elements-buttons-primary`.
- `type = "components"`, category folder `Testimonials`, slug `testimonial-split-slider` → id `components-testimonials-testimonial-split-slider`.
- `type = "layouts"`, category folder `Hero`, slug `fullscreen-hero` → id `layouts-hero-fullscreen-hero`.

Note: `type` in the id is **plural** (`elements`, `components`, `layouts`, `templates`) because it matches the folder name, whereas `__libraryMeta.type` is singular. This asymmetry is intentional — do not "fix" it.

Brand-scoped variants under `data/library/Brands/{Brand}/` are not registered in the root manifest and therefore have no `id` to worry about — the builder discovers them by folder scan.

## Data attributes

### `data-etch-element`

Semantic scaffold marker. Exactly two values are in use:

- `data-etch-element="section"` on the root `<section>` of a full-section artifact. Targeted by the readonly `etch-section-style`.
- `data-etch-element="container"` on the content wrapper `<div>` inside the section. Targeted by the readonly `etch-container-style`.

Do not invent other values (no `data-etch-element="card"`). Nested layout wrappers use BEM classes, not this attribute.

### `data-etch-sid` — stable IDs (optional)

Used when a block needs a stable, referenceable ID across saves/builder operations. Format:

```
pat-{slug}-{role}
```

- `pat-alpha-cta-banner-root` for the root section.
- `pat-alpha-cta-banner-heading` for the heading inside.
- `pat-alpha-cta-banner-btn` for the CTA button.

Rules:

- Unique within the file.
- Lowercase kebab-case.
- Omit entirely on blocks that do not need a stable reference — most content blocks don't.
- If you include it, include it on the root block at minimum.

The existing examples (`cta-banner.json`, `feature-grid.json`, `hero-fullscreen.json`, `marks-component.json`) do not use `data-etch-sid` — it is optional and mainly for artifacts that will be programmatically targeted later. Do not add it "just in case."

## Style-object ID naming

Two valid forms (covered in [json-schema.md](json-schema.md)):

1. **BEM-with-`-style` suffix** (preferred for hand-authored): `alpha-cta-banner__heading-style`.
2. **Opaque short hash** (builder-generated): `7g69qvg`. Accepted but not preferred when writing new files.

The `selector` inside the style object always follows BEM regardless.

## Metadata `name` vs HTML `name`

- `gutenbergBlock.attrs.metadata.name` — human builder label (e.g. `"CTA Button"`). Short, descriptive.
- Do not confuse with the HTML `name` attribute on form fields — that goes inside `attrs.attributes.name` when needed.

## Brand naming consistency checklist

When introducing a brand to the library:

1. Pick the class prefix (`alpha-`, `luxe-`, etc.) and use it for every class in every file for that brand.
2. Match `__libraryMeta.brand` to the human brand name (`"Alpha"`, `"Luxe Horizon"`).
3. Add `{brand-lowercase}` to `__libraryMeta.tags` on every brand artifact.
4. Place files under `data/library/Brands/{Brand}/{Category}/{slug}.json`.
5. If the brand needs tokens, either define them globally (core taxonomy) or follow the `--bo-*` raw-reference pattern.
