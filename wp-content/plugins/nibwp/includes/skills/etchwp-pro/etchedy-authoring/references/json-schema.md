# JSON Schema — Etchedy artifact file

Every artifact in `data/library/` has the same top-level shape:

```json
{
    "__libraryMeta": { ... },
    "type": "block",
    "gutenbergBlock": { ... },
    "version": 2.1,
    "timestamp": "2026-04-20T00:00:00.000Z",
    "styles": { ... }
}
```

## `__libraryMeta`

All six fields are required.

| Field | Type | Rules |
|---|---|---|
| `brand` | string | `"etched"` for the core library, or the brand name (`"Alpha"`, `"Luxe Horizon"`, `"Modern Blog"`, `"BookingOptimiser"`). PascalCase with spaces for multi-word brands. |
| `type` | string | Lowercase: `"element"` \| `"component"` \| `"layout"` \| `"template"`. Matches the folder under `data/library/`. |
| `category` | string | PascalCase — matches the sub-folder name exactly (`"Buttons"`, `"Hero"`, `"Features"`, `"CTA"`, `"Pricing"`, `"FAQ"`, `"Footer"`). |
| `tags` | string[] | At least three meaningful tags. Include the type, category, brand, and 1+ descriptive tags. E.g. `["layout", "cta", "banner", "alpha"]`. |
| `name` | string | Human-readable, title-cased. E.g. `"Alpha CTA Banner"`. |
| `description` | string | One sentence. Describe what it is and its visual personality. Non-empty. |

## `type` field (top level, separate from `__libraryMeta.type`)

Always the literal string `"block"`. This is the artifact's outer format marker, consumed by the builder. Do not change it.

## `version` and `timestamp`

- `version`: number. Current is `2.1`. Use `2.1` for new work unless instructed otherwise.
- `timestamp`: ISO 8601 string. Use the current moment when authoring.

## `gutenbergBlock` — the block tree

A single root block, usually `etch/element` with `tag: "section"`:

```json
{
    "blockName": "etch/element",
    "attrs": {
        "metadata": { "name": "Human Name" },
        "tag": "section",
        "attributes": { "data-etch-element": "section", "class": "alpha-cta-banner" },
        "styles": ["etch-section-style", "alpha-cta-banner-style"]
    },
    "innerBlocks": [ /* nested blocks */ ],
    "innerHTML": "\n\n",
    "innerContent": ["\n", null, "\n"]
}
```

### Block types

- `etch/element` — any HTML element. `attrs.tag` sets the tag (`section`, `div`, `h1`..`h6`, `p`, `span`, `a`, `button`, `img`, `ul`, `li`, `hr`, …).
- `etch/text` — leaf text node. `attrs.content` is the string. `innerBlocks` is always `[]`, `innerHTML` is always `""`, `innerContent` is always `[]`.
- `etch/dynamic-element` — like `etch/element` but `tag` accepts `{props.xxx}` template syntax. Use when the rendered tag should vary by prop (e.g. `tag: "{props.headingLevel}"` renders `<h1>` or `<h2>`).
- `etch/component` — references a reusable component by `ref` ID. Props are passed via `attrs.attributes`. See the `components` map below.
- `etch/slot-placeholder` — defines a named insertion slot inside a component definition. `attrs.name` is the slot name. Consumers fill it via `etch/slot-content`.
- `etch/slot-content` — fills a named slot when consuming a component. `attrs.name` matches the slot's name. Children are the slot content.
- `etch/condition` — conditional rendering. `attrs.condition` is an object with `leftHand`, `operator` (`isTruthy`, `==`, `!=`, `&&`, `||`), and `rightHand`. `attrs.conditionString` is a human-readable version. Children render only when the condition is met.
- `etch/raw-html` — renders `attrs.content` as raw HTML. Supports `{props.xxx}` template syntax for dynamic text.
- `etch/dynamic-image` — image with WP media library integration. `attrs.attributes.mediaId` references a WP attachment ID. Falls back to `src` if provided.
- `etch/svg` — inline SVG. `attrs.innerHTML` contains the raw SVG markup. `attrs.attributes` carries `viewBox`, `width`, `height`, `fill`, `stroke`, etc.

### `attrs` on `etch/element`

- `metadata.name` — human label shown in the builder tree. Use a short descriptive name (`"Heading"`, `"Container"`, `"Card 1"`, `"CTA Button"`).
- `tag` — semantic HTML tag. Prefer semantic over `div` wherever sensible.
- `attributes` — HTML attributes applied to the rendered element. Always include `class`. Add `data-etch-element="section"` on the root section block and `data-etch-element="container"` on the inner container. Add `href`, `src`, `alt`, `loading`, `type`, `aria-*` as the element needs.
- `styles` — array of style-object IDs (keys in the top-level `styles` dictionary). Scaffold blocks usually have two: the readonly generic one (`etch-section-style` / `etch-container-style`) plus their BEM-named one. Content blocks usually have one.

### `innerBlocks` / `innerHTML` / `innerContent`

These come from the Gutenberg block format. For authoring purposes:

- `innerBlocks`: array of child blocks.
- `innerHTML`: looks like `"\n\n"` for blocks with one child, `"\n\n\n\n\n\n"` for three children, etc. Just count the child blocks and use 2 newlines per child plus a trailing pair. When in doubt, copy from an existing example — the builder regenerates these on save anyway.
- `innerContent`: alternating `"\n"` and `null` markers. Same "copy from an example" guidance.

For `etch/text`, always use: `"innerBlocks": [], "innerHTML": "", "innerContent": []`.

## `styles` — the style dictionary

Top-level sibling of `gutenbergBlock`. Keys are style IDs referenced from blocks' `attrs.styles`. Values are style objects:

```json
{
    "alpha-cta-banner__heading-style": {
        "type": "class",
        "selector": ".alpha-cta-banner__heading",
        "collection": "default",
        "css": "font-size: clamp(1.75rem, 4vw, 3rem); font-weight: 800; margin: 0;",
        "readonly": false
    }
}
```

### Style-object fields

| Field | Type | Rules |
|---|---|---|
| `type` | `"class"` \| `"element"` | `"class"` = selector is a class (`.x`). `"element"` = selector is an attribute/element selector (usually `:where([data-etch-element="..."])`). |
| `selector` | string | The CSS selector. For `type: "class"`, always starts with `.` and follows BEM. For `type: "element"`, always the `:where(...)` form for the scaffold styles. |
| `collection` | string | Almost always `"default"`. |
| `css` | string | The declarations, separated by `;`. Inline `@media` and `&:hover` nested rules as needed. See style rules below. |
| `readonly` | boolean | `true` for the two scaffold styles (`etch-section-style`, `etch-container-style`). `false` for all BEM content styles. |

### Style-object ID naming

Two conventions are valid:

1. **BEM-with-`-style` suffix** (preferred, matches `cta-banner.json` and `feature-grid.json`): `alpha-cta-banner__heading-style`, `luxe-hero__container-style`. Easy to read.
2. **Opaque short hash** (used in `marks-component.json`): `7g69qvg`, `nl79no2`. Generated by the builder when styles are added there. Accepted but not preferred for hand-authored files.

The `selector` inside MUST always be a proper BEM class regardless of which ID convention is used.

### Canonical scaffold styles — copy verbatim

Every full-section artifact includes these two readonly styles unchanged:

```json
"etch-section-style": {
    "type": "element",
    "selector": ":where([data-etch-element=\"section\"])",
    "collection": "default",
    "css": "inline-size: 100%; display: flex; flex-direction: column; align-items: center;",
    "readonly": true
},
"etch-container-style": {
    "type": "element",
    "selector": ":where([data-etch-element=\"container\"])",
    "collection": "default",
    "css": "inline-size: 100%; display: flex; flex-direction: column; max-inline-size: var(--content-width, 1366px); align-self: center; margin-inline: auto;",
    "readonly": true
}
```

## Minimal valid skeleton (copy-paste starting point)

```json
{
    "__libraryMeta": {
        "brand": "etched",
        "type": "element",
        "category": "Buttons",
        "tags": ["element", "button", "primary", "etched"],
        "name": "Primary Button",
        "description": "Solid primary call-to-action button."
    },
    "type": "block",
    "gutenbergBlock": {
        "blockName": "etch/element",
        "attrs": {
            "metadata": { "name": "Primary Button" },
            "tag": "a",
            "attributes": { "href": "#", "class": "etched-primary-button" },
            "styles": ["etched-primary-button-style"]
        },
        "innerBlocks": [
            {
                "blockName": "etch/text",
                "attrs": { "content": "Get started" },
                "innerBlocks": [],
                "innerHTML": "",
                "innerContent": []
            }
        ],
        "innerHTML": "\n\n",
        "innerContent": ["\n", null, "\n"]
    },
    "version": 2.1,
    "timestamp": "2026-04-20T00:00:00.000Z",
    "styles": {
        "etched-primary-button-style": {
            "type": "class",
            "selector": ".etched-primary-button",
            "collection": "default",
            "css": "display: inline-flex; align-items: center; padding: var(--space-s, 0.75rem) var(--space-l, 1.5rem); border-radius: var(--radius-m, 0.5rem); background: var(--primary, #2563eb); color: var(--white, #fff); font-size: var(--text-m, 1rem); font-weight: 600; text-decoration: none; transition: background 0.2s; &:hover { background: var(--primary-dark, #1e40af); }",
            "readonly": false
        }
    }
}
```

Full-section artifacts (layouts, components) extend this by nesting a container and using both scaffold styles. See [examples.md](examples.md) for the full patterns.

## `components` — the reusable component map (optional)

Top-level sibling of `gutenbergBlock` and `styles`. Keys are numeric component IDs (matching `ref` values in `etch/component` blocks). Values are component definitions:

```json
{
    "components": {
        "89": {
            "id": 89,
            "legacyId": "",
            "name": "Section Intro",
            "key": "SectionIntro",
            "properties": [
                {
                    "name": "Style",
                    "key": "style",
                    "keyTouched": false,
                    "type": { "primitive": "string", "specialized": "select" },
                    "default": "Left",
                    "selectOptionsString": "Left\nCenter\nTwo Column"
                },
                {
                    "name": "Show Lede",
                    "key": "showLede",
                    "keyTouched": false,
                    "type": { "primitive": "boolean" },
                    "default": true
                }
            ],
            "description": "",
            "blocks": [ /* the component's internal block tree */ ]
        }
    }
}
```

### Component definition fields

| Field | Type | Rules |
|---|---|---|
| `id` | number | Unique ID within the artifact. Referenced by `etch/component` blocks via `ref`. |
| `name` | string | Human-readable name shown in the builder. |
| `key` | string | PascalCase identifier (e.g. `SectionIntro`, `IconListAlpha`). |
| `properties` | array | Typed props the component accepts. Each has `name`, `key` (camelCase), `type` ({primitive, specialized?}), `default`, optionally `selectOptionsString` (newline-separated options for `select`), and optionally `description` (a one-line note documenting the prop for reuse — Etch 1.4.14+). |
| `blocks` | array | The component's internal block tree. Uses `{props.xxx}` template syntax to bind prop values to attributes, text, and tags. |
| `description` | string | Optional description. |

### Property types

| primitive | specialized | Description |
|---|---|---|
| `"string"` | (none) | Free text input |
| `"string"` | `"select"` | Dropdown. Options in `selectOptionsString` (newline-separated). |
| `"string"` | `"image"` | Image URL picker |
| `"boolean"` | (none) | Toggle switch |

> **Document props for reuse (Etch 1.4.14+).** Add an optional one-line `description` to any property so the builder shows what it's for:
> `{ "name": "Style", "key": "style", "type": { "primitive": "string", "specialized": "select" }, "default": "Left", "selectOptionsString": "Left\nCenter", "description": "Layout variant for the section." }`
>
> For dynamic data + modifiers inside `blocks` (e.g. `{item.title.toUpperCase()}`, `{#loop}`, `{#if}`), see [dynamic-data.md](dynamic-data.md).

### Template syntax inside component blocks

Inside a component's `blocks`, use `{props.xxx}` to bind property values:

- In `tag`: `"tag": "{props.headingLevel}"` — dynamic HTML tag
- In `attributes`: `"href": "{props.ctaUrl}"`, `"class": "intro__heading {props.class}"`
- In `style` attribute: `"style": "--icon: url({props.icon}); --gap: {props.gap};"` — pass props as CSS custom properties
- In `etch/text` content: `"content": "{props.sectionHeading}"`
- In `etch/raw-html` content: `"content": "{props.label}"`
- In `data-*` attributes: `"data-heading-level": "{props.headingLevel}"`, `"data-intro-style": "{props.style}"`
- In conditions: `"leftHand": "props.showLede"` with `"operator": "isTruthy"`

### Slot system

Components define named slots via `etch/slot-placeholder` inside their `blocks` array. When consuming a component, fill slots via `etch/slot-content`:

```json
// Inside component definition (blocks):
{ "blockName": "etch/slot-placeholder", "attrs": { "name": "items" }, ... }

// When using the component:
{
    "blockName": "etch/component",
    "attrs": { "ref": 3312, "attributes": {} },
    "innerBlocks": [
        {
            "blockName": "etch/slot-content",
            "attrs": { "name": "items" },
            "innerBlocks": [ /* slot children */ ]
        }
    ]
}
```

Empty-slot fallback: use `etch/condition` with `"leftHand": "slots.items.empty"` to show placeholder content when the slot has no children.

### Third scaffold style — `etch-flex-div-style`

In addition to `etch-section-style` and `etch-container-style`, Kevin Geary's patterns use a third readonly scaffold for flex wrappers:

```json
"etch-flex-div-style": {
    "type": "element",
    "selector": ":where([data-etch-element=\"flex-div\"])",
    "collection": "default",
    "css": "inline-size: 100%; display: flex; flex-direction: column;",
    "readonly": true
}
```

Apply it via `"data-etch-element": "flex-div"` on the element. Use for content wrappers, media wrappers, or any block that needs the base flex-column scaffold.
