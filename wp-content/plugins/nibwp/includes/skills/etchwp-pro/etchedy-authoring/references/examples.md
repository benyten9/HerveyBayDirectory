# Canonical Examples — annotated gold standards

Four files from the live library that demonstrate the core patterns. Read the one closest to your task, then pattern-match. The annotations below each snippet call out which rule the example demonstrates.

---

## 1. Minimal section scaffold — CTA Banner

**Path**: `data/library/Brands/Alpha/CTA/cta-banner.json`

**Why canonical**: smallest valid full-section artifact. Section → container → heading + sub + CTA button. Three content children, no nesting beyond the scaffold. Use this as the starting shape for any simple banner, callout, or announcement section.

**Key patterns shown**:
- Scaffold pair (`etch-section-style` + `etch-container-style`) used verbatim.
- Section-level `padding-block` via `--section-space-l` token.
- Container uses `gap` + `padding-inline` tokens.
- `clamp()` as the raw value for fluid heading size — structural, no token needed.
- Hover transition on the CTA using inline `&:hover { ... }`.
- Alpha brand accent hex (`#57e9db`) used raw on the CTA background (justified brand accent inside a brand-scoped file).

```json
{
    "__libraryMeta": {
        "brand": "Alpha",
        "type": "layout",
        "category": "CTA",
        "tags": ["layout", "cta", "banner", "alpha"],
        "name": "Alpha CTA Banner",
        "description": "Full-width CTA banner with gradient background and centered text. Alpha brand."
    },
    "type": "block",
    "gutenbergBlock": {
        "blockName": "etch/element",
        "attrs": {
            "metadata": { "name": "Alpha CTA Banner" },
            "tag": "section",
            "attributes": { "data-etch-element": "section", "class": "alpha-cta-banner" },
            "styles": ["etch-section-style", "alpha-cta-banner-style"]
        },
        "innerBlocks": [ /* container → heading + sub + btn */ ],
        "innerHTML": "\n\n", "innerContent": ["\n", null, "\n"]
    },
    "version": 2.1,
    "timestamp": "2026-03-01T03:50:00.000Z",
    "styles": {
        "etch-section-style": { "type": "element", "selector": ":where([data-etch-element=\"section\"])", "collection": "default", "css": "inline-size: 100%; display: flex; flex-direction: column; align-items: center;", "readonly": true },
        "alpha-cta-banner-style": { "type": "class", "selector": ".alpha-cta-banner", "collection": "default", "css": "padding-block: var(--section-space-l, 6rem); background: linear-gradient(135deg, #0f1117 0%, #1a2332 100%);", "readonly": false },
        "etch-container-style": { "type": "element", "selector": ":where([data-etch-element=\"container\"])", "collection": "default", "css": "inline-size: 100%; display: flex; flex-direction: column; max-inline-size: var(--content-width, 1366px); align-self: center; margin-inline: auto;", "readonly": true },
        "alpha-cta-banner__container-style": { "type": "class", "selector": ".alpha-cta-banner__container", "collection": "default", "css": "display: flex; flex-direction: column; align-items: center; text-align: center; gap: var(--space-l, 1.5rem); padding-inline: var(--content-padding, 1rem);", "readonly": false },
        "alpha-cta-banner__heading-style": { "type": "class", "selector": ".alpha-cta-banner__heading", "collection": "default", "css": "font-size: clamp(1.75rem, 4vw, 3rem); font-weight: 800; margin: 0; color: #fff;", "readonly": false },
        "alpha-cta-banner__sub-style": { "type": "class", "selector": ".alpha-cta-banner__sub", "collection": "default", "css": "font-size: var(--text-l, 1.125rem); color: rgba(255,255,255,0.65); margin: 0; max-inline-size: 45ch;", "readonly": false },
        "alpha-cta-banner__btn-style": { "type": "class", "selector": ".alpha-cta-banner__btn", "collection": "default", "css": "display: inline-flex; padding: 1rem 2.5rem; border-radius: 0.5rem; background: #57e9db; color: #0f1117; font-weight: 600; font-size: var(--text-m, 1rem); text-decoration: none; transition: background 0.2s, transform 0.15s; &:hover { background: #3dd4c6; transform: translateY(-1px); }", "readonly": false }
    }
}
```

**Note on the button style**: `padding: 1rem 2.5rem` and `border-radius: 0.5rem` are raw values. In a stricter refactor these would become `var(--space-m, 1rem) var(--space-xl, 2.5rem)` and `var(--radius, 0.5rem)`. Acceptable as-is but a good lint target.

---

## 2. Multi-level content + responsive + hover — Feature Grid

**Path**: `data/library/Brands/Alpha/Features/feature-grid.json`

**Why canonical**: four levels of nesting (section → container → grid → card → icon/title/desc), a responsive collapse, and a hover effect — all in one file. Use this as the starting shape for any multi-card/grid component (features, services, team members, pricing tiers).

**Key patterns shown**:
- Inline `@media (max-width: 768px) { grid-template-columns: 1fr; }` inside the grid style's css string.
- Inline `&:hover { box-shadow: ... }` on the card.
- Every card uses the same BEM classes (`alpha-features__card`, etc.) — one style object, three block instances.
- Compound element names (`alpha-features__card-title`, `alpha-features__card-desc`) for sub-elements of a sub-component.
- Container uses `--space-xl` for big vertical gap between title and grid.
- `--surface-light` with fallback for card background.

```json
{
    "__libraryMeta": {
        "brand": "Alpha",
        "type": "component",
        "category": "Features",
        "tags": ["component", "features", "grid", "alpha", "icons"],
        "name": "Alpha Feature Grid",
        "description": "Three-column icon feature grid with Alpha brand colors."
    },
    "type": "block",
    "gutenbergBlock": { "blockName": "etch/element", "attrs": { "tag": "section", "attributes": { "data-etch-element": "section", "class": "alpha-features" }, "styles": ["etch-section-style", "alpha-features-style"] }, "innerBlocks": [ /* container → title + grid(3 cards) */ ] },
    "styles": {
        "alpha-features-style": { "type": "class", "selector": ".alpha-features", "collection": "default", "css": "padding-block: var(--section-space-m, 5rem); background: var(--white, #fff);", "readonly": false },
        "alpha-features__container-style": { "type": "class", "selector": ".alpha-features__container", "collection": "default", "css": "display: flex; flex-direction: column; gap: var(--space-xl, 3rem); align-items: center; padding-inline: var(--content-padding, 1rem);", "readonly": false },
        "alpha-features__title-style": { "type": "class", "selector": ".alpha-features__title", "collection": "default", "css": "font-size: clamp(1.5rem, 4vw, 2.5rem); font-weight: 700; text-align: center; margin: 0; color: var(--heading-color, #111);", "readonly": false },
        "alpha-features__grid-style": { "type": "class", "selector": ".alpha-features__grid", "collection": "default", "css": "display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--space-l, 2rem); inline-size: 100%; @media (max-width: 768px) { grid-template-columns: 1fr; }", "readonly": false },
        "alpha-features__card-style": { "type": "class", "selector": ".alpha-features__card", "collection": "default", "css": "display: flex; flex-direction: column; gap: var(--space-s, 0.75rem); padding: var(--space-l, 2rem); border-radius: var(--radius-m, 0.75rem); background: var(--surface-light, #f8f9fa); text-align: center; align-items: center; transition: box-shadow 0.2s; &:hover { box-shadow: 0 8px 30px rgba(0,0,0,0.08); }", "readonly": false },
        "alpha-features__icon-style": { "type": "class", "selector": ".alpha-features__icon", "collection": "default", "css": "inline-size: 48px; block-size: 48px; border-radius: 50%; background: #57e9db; opacity: 0.9;", "readonly": false },
        "alpha-features__card-title-style": { "type": "class", "selector": ".alpha-features__card-title", "collection": "default", "css": "font-size: var(--text-l, 1.125rem); font-weight: 600; margin: 0; color: var(--heading-color, #111);", "readonly": false },
        "alpha-features__card-desc-style": { "type": "class", "selector": ".alpha-features__card-desc", "collection": "default", "css": "font-size: var(--text-m, 1rem); line-height: 1.6; color: var(--text-muted, #6b7280); margin: 0;", "readonly": false }
    }
}
```

**Annotation — the grid responsive rule**:

```css
display: grid;
grid-template-columns: repeat(3, 1fr);
gap: var(--space-l, 2rem);
inline-size: 100%;
@media (max-width: 768px) { grid-template-columns: 1fr; }
```

This is the canonical pattern — inline `@media` inside the same CSS string. Do not split into a separate `alpha-features__grid--mobile-style` object.

**Annotation — the card hover rule**:

```css
transition: box-shadow 0.2s;
&:hover { box-shadow: 0 8px 30px rgba(0,0,0,0.08); }
```

Inline `&:hover { ... }` nested selector. Same pattern for `:focus`, `:active`, etc.

---

## 3. Fullscreen hero — content-width + brand hex boundary

**Path**: `data/library/Brands/Luxe Horizon/Hero/hero-fullscreen.json`

**Why canonical**: demonstrates `min-block-size: 100vh` fullscreen hero, the `content-width` + `content-padding` tokens for the inner container, and the "brand accent hex used raw inside a brand file" boundary (`#c9a96e` gold on Luxe).

**Key patterns shown**:
- Fullscreen layout: `min-block-size: 100vh; display: flex; align-items: center; justify-content: center;`
- Container with `max-inline-size: var(--content-width, 1366px)`.
- Eyebrow + heading + divider + desc + button vertical stack.
- Serif font family for headings (`'Playfair Display', Georgia, serif`) — structural value, no token.
- Letter-spacing + uppercase + small text for the eyebrow — raw values because they're visual design details with no semantic token.
- Raw hex for Luxe brand accents (`#0a0a0a`, `#c9a96e`, `#faf9f6`) — justified inside the brand file.

```json
{
    "__libraryMeta": {
        "brand": "Luxe Horizon",
        "type": "layout",
        "category": "Hero",
        "tags": ["layout", "hero", "fullscreen", "luxe-horizon", "luxury"],
        "name": "Luxe Horizon Fullscreen Hero",
        "description": "Full-viewport hero with elegant serif typography and golden accents."
    },
    "styles": {
        "luxe-hero-style": { "type": "class", "selector": ".luxe-hero", "collection": "default", "css": "min-block-size: 100vh; display: flex; align-items: center; justify-content: center; background: #0a0a0a; color: #faf9f6;", "readonly": false },
        "luxe-hero__container-style": { "type": "class", "selector": ".luxe-hero__container", "collection": "default", "css": "display: flex; flex-direction: column; align-items: center; text-align: center; gap: var(--space-l, 1.5rem); padding-inline: var(--content-padding, 1rem);", "readonly": false },
        "luxe-hero__eyebrow-style": { "type": "class", "selector": ".luxe-hero__eyebrow", "collection": "default", "css": "font-size: 0.75rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.3em; color: #c9a96e;", "readonly": false },
        "luxe-hero__heading-style": { "type": "class", "selector": ".luxe-hero__heading", "collection": "default", "css": "font-family: 'Playfair Display', Georgia, serif; font-size: clamp(2.5rem, 6vw, 5rem); font-weight: 400; line-height: 1.15; margin: 0; white-space: pre-line;", "readonly": false },
        "luxe-hero__divider-style": { "type": "class", "selector": ".luxe-hero__divider", "collection": "default", "css": "inline-size: 60px; border: none; border-top: 1px solid #c9a96e; margin: 0;", "readonly": false },
        "luxe-hero__desc-style": { "type": "class", "selector": ".luxe-hero__desc", "collection": "default", "css": "font-size: var(--text-l, 1.125rem); line-height: 1.7; color: rgba(250,249,246,0.6); margin: 0; max-inline-size: 50ch;", "readonly": false },
        "luxe-hero__btn-style": { "type": "class", "selector": ".luxe-hero__btn", "collection": "default", "css": "display: inline-flex; padding: 0.875rem 2.5rem; border: 1px solid #c9a96e; color: #c9a96e; font-size: 0.8rem; font-weight: 500; letter-spacing: 0.15em; text-transform: uppercase; text-decoration: none; transition: all 0.3s; &:hover { background: #c9a96e; color: #0a0a0a; }", "readonly": false }
    }
}
```

**Annotation — the brand-hex boundary**:

The eyebrow, divider, and button all use `#c9a96e` directly. This is OK because:
- The file lives under `data/library/Brands/Luxe Horizon/`.
- Luxe's design system is built around that gold as a signature accent.
- There is no `--luxe-gold` token defined (by design; see [acss-tokens.md](acss-tokens.md)).

The same value outside a brand-scoped file would need `var(--accent, #c9a96e)` instead.

**Annotation — structural values the eyebrow uses without tokens**:

```css
font-size: 0.75rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.3em;
```

The `0.3em` letter-spacing and the exact `0.75rem` font-size are visual design choices specific to this hero's eyebrow. There is no general-purpose token for either. Acceptable raw.

---

## 4. Two-column → one-column + clamp + opaque style IDs — Alpha Hero Split

**Path**: `data/library/Elements/Hero/marks-component.json`

**Why canonical**: the best example of the "two columns on desktop, collapse to one on mobile" pattern, with `clamp()` for fluid heading size, and demonstrates that opaque style-object IDs (`7g69qvg`, `nl79no2`) are accepted as long as the selector inside is proper BEM.

**Key patterns shown**:
- Opaque short-hash style IDs (builder-generated). Selectors are still `.alpha-hero-split__heading` etc.
- `grid-template-columns: 1fr 1fr` with inline `@media (max-width: 768px) { grid-template-columns: 1fr; }`.
- Fluid heading: `font-size: clamp(2rem, 5vw, 3.5rem)`.
- CSS string with real newlines inside (the `\n  ` indentation inside `etch-section-style`'s css). This is purely cosmetic — the CSS parser doesn't care.
- Image element with `loading="lazy"` for performance.

```json
{
    "__libraryMeta": {
        "brand": "Alpha",
        "type": "element",
        "category": "Hero",
        "tags": [],
        "name": "Mark's component",
        "description": ""
    },
    "styles": {
        "7g69qvg": { "type": "class", "selector": ".alpha-hero-split", "collection": "default", "css": "padding-block: var(--section-space-l, 6rem); background: var(--surface-dark, #0f1117); color: var(--white, #fff);", "readonly": false },
        "nl79no2": { "type": "class", "selector": ".alpha-hero-split__container", "collection": "default", "css": "display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-xl, 3rem); align-items: center; padding-inline: var(--content-padding, 1rem); @media (max-width: 768px) { grid-template-columns: 1fr; }", "readonly": false },
        "5alo1rv": { "type": "class", "selector": ".alpha-hero-split__content", "collection": "default", "css": "display: flex; flex-direction: column; gap: var(--space-m, 1.5rem);", "readonly": false },
        "8cvtcez": { "type": "class", "selector": ".alpha-hero-split__tagline", "collection": "default", "css": "font-size: var(--text-s, 0.875rem); font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; color: #57e9db;", "readonly": false },
        "eipkvha": { "type": "class", "selector": ".alpha-hero-split__heading", "collection": "default", "css": "font-size: clamp(2rem, 5vw, 3.5rem); font-weight: 800; line-height: 1.1; margin: 0; color: var(--white, #fff);", "readonly": false },
        "xovgrqc": { "type": "class", "selector": ".alpha-hero-split__desc", "collection": "default", "css": "font-size: var(--text-m, 1rem); line-height: 1.6; color: rgba(255,255,255,0.7); margin: 0; max-inline-size: 40ch;", "readonly": false },
        "ldrcxxo": { "type": "class", "selector": ".alpha-hero-split__btn", "collection": "default", "css": "display: inline-flex; align-items: center; align-self: flex-start; padding: 0.875rem 2rem; border-radius: 0.5rem; background: #57e9db; color: #0f1117; font-weight: 600; font-size: var(--text-m, 1rem); text-decoration: none; transition: background 0.2s; &:hover { background: #3dd4c6; }", "readonly": false },
        "ke4byzw": { "type": "class", "selector": ".alpha-hero-split__image", "collection": "default", "css": "inline-size: 100%; block-size: auto; border-radius: var(--radius-l, 1rem); object-fit: cover;", "readonly": false }
    }
}
```

**Annotation — what this file gets wrong** (intentionally kept as-is in the library, called out here for the skill):

- `tags: []` and `description: ""` — both empty. The checklist requires ≥3 tags and a non-empty description. When cloning this pattern, always fill those in.
- `name: "Mark's component"` — non-descriptive. Should be `"Alpha Hero Split"`. The `metadata.name` inside the root block is correct (`"Alpha Hero Split"`); the top-level `__libraryMeta.name` should match.

If you are pattern-matching this file, copy the structure but fix these metadata issues.

---

## Which example to start from

| Your task | Start from |
|---|---|
| Any CTA / callout section | #1 cta-banner.json |
| Any multi-card grid (features, services, pricing tiers, team) | #2 feature-grid.json |
| Any fullscreen hero | #3 hero-fullscreen.json |
| Any two-column hero (text + image) | #4 marks-component.json |
| Button, icon, divider (pure element) | See `json-schema.md` skeleton |

When your task doesn't cleanly match one of these, pick the closest, then adapt. Do not write Etchedy JSON from scratch — always start from a library file and edit down.
