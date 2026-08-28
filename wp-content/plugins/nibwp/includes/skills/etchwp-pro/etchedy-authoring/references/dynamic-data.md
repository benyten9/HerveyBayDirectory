# Dynamic data, loops, conditions & modifiers (Etch 1.5)

Etch extends HTML with a Svelte-inspired templating syntax. This is the **authoritative** binding syntax (mirrors Etch's own engine + AI). **Never invent syntax** — if you can't confirm a key or modifier here, don't emit it.

These bindings go inside string values: `etch/text`/`etch/raw-html` `content`, `attributes.*`, `tag` (dynamic-element), `style`, `data-*`, and component prop slots.

## Dynamic data sources

- **Current object:** `{this.title}`, `{this.permalink}`, `{this.id}`, `{this.featured_image}`, `{this.excerpt}`
- **Custom fields:** `{this.acf.field_name}`, `{this.meta.key}`, `{this.etch.key}` (Etch native fields)
- **In loops:** the loop variable, e.g. `{item.title}`, `{item.permalink.relative}`, `{item.acf.price}`
- **Props (inside a component):** `{props.heading}`, `{props.ctaUrl}`
- **Site / environment:** `{site.home_url}`, `{site.name}`, `{environment.current}` (`"etch"` in the editor, `"frontend"` live)
- **Bracket notation** for keys with dashes/special chars: `{item["my-field"]}`, `{props['my-loop']}`

## Loops

Inline template form (inside `etch/raw-html` content, or a builder text node):

```
{#loop posts as item}            ... {item.title} ...            {/loop}
{#loop posts as item, index}     ... #{index} ...                {/loop}
{#loop posts($cat: category.id) as post}  ...  {/loop}           // arguments
{#loop posts($count: props.count ?? 3) as item} ... {/loop}      // default with ?? 
{#loop props.myLoop as item}     ...                             {/loop}  // loop prop (camelCase)
{#loop props['my-loop'] as item} ...                             {/loop}  // dash-cased prop
```
Nested loops pass parent data via arguments: `{#loop terms($post: item.id) as term}`.

Block form (what this skill emits in `gutenbergBlock`):

```json
{
  "blockName": "etch/loop",
  "attrs": {
    "metadata": { "name": "Posts Loop" },
    "query": { "post_type": "post", "posts_per_page": 3, "orderby": "date", "order": "DESC", "post_status": "publish" }
  },
  "innerBlocks": [ /* per-iteration template; bind fields with {item.xxx} */ ]
}
```
Inside the loop's `innerBlocks`, bind the **loop variable** (`{item.title}`, `{item.permalink}`, `{item.featured_image}`), NOT `{post.x}`. The query uses standard WP_Query args.

### WooCommerce product loop
A Woo product loop exposes the usual fields plus **`{item.gallery_images}`** — the product gallery with the **featured image first** (Etch 1.4.20+). Price/stock: `{item.acf.*}` or Woo fields per the docs.

## Conditions

Inline: `{#if expr}...{:else if expr}...{:else}...{/if}`. Editor-only content: `{#if environment.current === "etch"}...{/if}`.

Block form (this skill's `etch/condition`):
```json
{ "blockName": "etch/condition",
  "attrs": { "condition": { "leftHand": "props.showLede", "operator": "isTruthy", "rightHand": "" },
             "conditionString": "{#if props.showLede}" },
  "innerBlocks": [ /* shown when true */ ] }
```
Operators: `isTruthy`, `isFalsy`, `==`, `!=`, `&&`, `||`. Empty-slot fallback: `"leftHand": "slots.items.empty"`.

## Modifiers

Chain modifiers onto any binding with dot syntax: `{source.path.modifier(arg).modifier2(arg)}`. Args are literals (`'x'`, `5`) or other bindings. Examples:
- `{this.title.toUpperCase()}`
- `{this.date.format('Y-m-d')}`
- `{item.tags.pluck('name').join(', ')}`
- `{this.acf.price.multiply(1.2).numberFormat(2)}`
- `{site.home_url.concat('/shop')}`

Full catalog (exact names — do not invent others):

| Group | Modifiers |
|---|---|
| **Arithmetic** *(1.4.20)* | `add`, `subtract`, `multiply`, `divide`, `mod` |
| **Numeric** | `numberFormat`, `toInt`, `ceil`, `round`, `floor` |
| **String** | `toUpperCase`, `toLowerCase`, `toString`, `toBool`, `trim`, `ltrim`, `rtrim`, `split`, `toSlug`, `truncateChars`, `truncateWords`, `urlEncode`, `urlDecode`, `stripTags`, `replace`, `replaceAll`, `startsWith`, `endsWith` |
| **Collection** | `concat` *(works with arrays, 1.4.19)*, `join`, `slice`, `at`, `includes`, `pluck`, `length`, `reverse`, `values`, `keys`, `indexOf`, `intersects`, `unserializePHP` |
| **Comparison** | `equal`, `less`, `lessOrEqual`, `greater`, `greaterOrEqual` |
| **Date** | `format`, `dateFormat` |

A loop's `target` attribute may also carry modifiers, e.g. `slice(1)` to skip the first item.

## Rules

- Bind the **loop variable** inside loops (`{item.*}`), `this.*` outside, `props.*` inside components.
- Use **bracket notation** for dash-cased keys.
- Chain only the modifiers above; never Tailwind, Handlebars, Blade, Twig, Jinja, or PHP tags.
- Wrap editor-only scaffolding in `{#if environment.current === "etch"}` so it never ships to the frontend.
