# Global classes — BEM grammar + persistence

Bricks 1.10+ has a built-in **Global Classes** registry stored in `wp_options['bricks_global_classes']`. A global class is defined once, referenced from many elements via `settings._cssGlobalClasses`.

## BEM grammar

```
{brand}-{component}__{element}[--modifier]
```

| Pattern | Example |
|---|---|
| Block (top-level component) | `etched-card`, `etched-hero`, `etched-button` |
| Element (part of a block) | `etched-card__title`, `etched-hero__cta` |
| Modifier (variant) | `etched-button--primary`, `etched-card--featured` |

Rules:

- Brand prefix is mandatory and matches the preflight cached answer (`bricks_brand` in `nibwp_user_defaults`).
- Lower-kebab-case only. No camelCase. No underscores in component/element atoms (use hyphens).
- Modifier uses `--double-dash`. Element uses `__double-underscore`.
- Utility hooks (`is-active`, `is-open`, `has-error`, `sr-only`, `screen-reader-text`) are exempt from the prefix rule.

The validator rejects mismatches via `bricks_missing_brand_prefix`.

## Payload shape

```json
{
  "global_classes": [
    {
      "id": "a1b2c3",
      "name": "etched-card",
      "settings": {
        "_padding":         { "_base": { "top": "1.5rem", "right": "1.5rem", "bottom": "1.5rem", "left": "1.5rem" } },
        "_background":      { "_base": { "color": "var(--white, #fff)" } },
        "_border":          { "_base": { "radius": { "top-left": "12px", "top-right": "12px", "bottom-right": "12px", "bottom-left": "12px" }, "width": { "top": "1px", "right": "1px", "bottom": "1px", "left": "1px" }, "color": "var(--border-color-light, rgba(0,0,0,.08))", "style": "solid" } },
        "_cssCustom":       ".brxe-{id}:hover { transform: translateY(-2px); transition: transform 200ms ease; }"
      }
    },
    {
      "id": "a1b2c4",
      "name": "etched-card__title",
      "settings": {
        "_typography": { "_base": { "font-size": "var(--text-l, 1.125rem)", "font-weight": "600", "color": "var(--heading-color, var(--text-dark, #1a1a1a))" } }
      }
    }
  ]
}
```

The persister merges into `wp_options['bricks_global_classes']` — names are unique, so re-using a name updates the existing class.

## How elements reference them

```json
{
  "name": "block",
  "settings": {
    "_id": "card01",
    "tag": "article",
    "_cssGlobalClasses": ["etched-card"],
    "_cssCustom": ".brxe-{id} { /* one-off override only */ }"
  }
}
```

`{id}` inside `_cssCustom` is auto-replaced by the persister with the element's actual ID, so the selector is unique to that element.

## Per-breakpoint values

Every settings field that takes a value can take a per-breakpoint object:

```json
{
  "_padding": {
    "_base":            { "top": "6rem", "bottom": "6rem", "left": "2rem", "right": "2rem" },
    "_mobile_landscape":{ "top": "4rem", "bottom": "4rem", "left": "1.5rem", "right": "1.5rem" },
    "_mobile_portrait": { "top": "3rem", "bottom": "3rem", "left": "1rem",   "right": "1rem"   }
  }
}
```

Bricks renders separate `@media` blocks automatically — DO NOT write `@media` yourself.

## Reuse vs override

| Goal | Tool |
|---|---|
| Same style across many elements | Define once in `global_classes`. Reference via `_cssGlobalClasses`. |
| One-off variation on a single element | `_cssCustom` on the element, with `.brxe-{id}` selector for scoping. |
| Variant of an existing component | Add a modifier global class (`etched-card--featured`) + reference both base + modifier on the element. |
| Theme-wide token swap | Use Bricks Design System variables (Settings → Design System) — those are root-level CSS vars that every global class can `var(--token, fallback)` against. |

## Persistence semantics

- The `nibwp/bricks-pro-html-to-component` ability merges `payload.global_classes` into `wp_options['bricks_global_classes']` BEFORE persisting the template — so when Bricks renders the template, the classes exist.
- Existing global classes with the same `name` are updated (settings overwritten). New names are appended.
- No global class is ever deleted by this ability — manual cleanup via the Bricks admin UI.

## Anti-patterns

- **Per-element `_cssCustom` duplicating across siblings** — define once on a global class, reference everywhere.
- **Class names without brand prefix** — rejected by validator.
- **`@media` inside `_cssCustom`** — use per-breakpoint setting shape.
- **Names mixing styles + modifiers in one** — split: `etched-card` for layout, `etched-card--featured` for the variant.
