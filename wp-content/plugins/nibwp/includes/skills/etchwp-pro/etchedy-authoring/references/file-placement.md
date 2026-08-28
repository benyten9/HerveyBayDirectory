# File Placement & Manifest Registration

## Folder → Type mapping

Every artifact JSON lives under `data/library/` in one of these top-level folders:

| Folder | `__libraryMeta.type` | Manifest `id` prefix |
|---|---|---|
| `data/library/Elements/` | `"element"` | `elements-` |
| `data/library/Components/` | `"component"` | `components-` |
| `data/library/Layouts/` | `"layout"` | `layouts-` |
| `data/library/Templates/` | `"template"` | `templates-` |
| `data/library/Brands/{Brand}/` | (any) | (none — not added to root manifest) |

The `Category` sub-folder is PascalCase and matches `__libraryMeta.category` exactly.

## Path formula

```
data/library/{Type}/{Category}/{slug}.json
```

Examples:

- `data/library/Elements/Buttons/primary.json`
- `data/library/Components/Testimonials/testimonial-split-slider.json`
- `data/library/Layouts/Hero/fullscreen-hero.json`
- `data/library/Brands/Alpha/CTA/cta-banner.json` (brand-scoped — Brand folder replaces the Type folder, but the artifact's own `__libraryMeta.type` still says `"layout"` / `"component"` / `"element"`)

## Manifest structure

`data/manifest.json` is a single nested tree:

```json
{
    "tree": [
        {
            "name": "Layouts",
            "slug": "layouts",
            "children": [
                {
                    "name": "Hero",
                    "slug": "hero",
                    "children": [
                        { "name": "Simple Hero", "id": "layouts-hero-simple-hero" },
                        { "name": "Hero with Image", "id": "layouts-hero-hero-with-image" }
                    ]
                }
            ]
        },
        { "name": "Elements", "slug": "elements", "children": [ ... ] },
        { "name": "Components", "slug": "components", "children": [ ... ] },
        { "name": "Templates", "slug": "templates", "children": [ ... ] }
    ]
}
```

Top-level `tree` has four children: `Layouts`, `Elements`, `Components`, `Templates`. Each has its own `children` array of categories. Each category has a `children` array of leaf entries `{ name, id }`.

## Adding a manifest entry

1. Read `data/manifest.json`.
2. Find the matching top-level `tree[*]` whose `slug` equals your plural type (`layouts`, `elements`, `components`, `templates`).
3. Find the matching category inside its `children` (case-insensitive slug match — e.g. folder `Buttons` → slug `buttons`). If the category does not exist, add it: `{ "name": "Buttons", "slug": "buttons", "children": [] }`.
4. Push your leaf entry to that category's `children` array:
   ```json
   { "name": "Primary Button", "id": "elements-buttons-primary" }
   ```
5. `name` is the human title (matches `__libraryMeta.name`). `id` is `{plural-type}-{category-slug}-{file-slug}` all lowercase.
6. Save.

### Example — adding a new element

Create: `data/library/Elements/Buttons/book-now.json`

Manifest edit: under `tree[*] where slug === "elements"` → `children[*] where slug === "buttons"` → push to `children`:

```json
{ "name": "Book Now", "id": "elements-buttons-book-now" }
```

### Example — adding a new category

If you create `data/library/Components/Pricing/simple-pricing.json` and the `Pricing` category does not yet exist in the manifest under Components, add it first:

```json
{
    "name": "Pricing",
    "slug": "pricing",
    "children": [
        { "name": "Simple Pricing", "id": "components-pricing-simple-pricing" }
    ]
}
```

## ID format reminder

```
{plural-type}-{category-slug}-{file-slug}
```

- `plural-type` — `elements` | `components` | `layouts` | `templates` (matches the top-level folder name, lowercased).
- `category-slug` — lowercase kebab-case of the category folder name (`Slider - Carousel` → `slider-carousel`, but check existing entries — category slugs use plain kebab-case without splitting on spaces around dashes).
- `file-slug` — the file name without `.json`.

All three segments are lowercase and joined by `-`. The full ID must be unique across the whole manifest.

## Brand-scoped files — no manifest edit

Files under `data/library/Brands/{Brand}/{Category}/{slug}.json` are discovered by the builder via folder scan and do NOT appear in `data/manifest.json`. Do not invent manifest entries for them.

## REST API — cache refresh (optional)

After saving, the builder may serve cached library data. To force a refresh:

```
POST /wp-json/etchedy/v1/sync-library
```

Sends the current library state to the builder. Requires the `edit_posts` capability and a valid nonce (see `includes/class-etchedy-rest-api.php`). Not usually needed during local authoring — the builder re-reads on load.

Relevant endpoints (all namespaced `etchedy/v1`, all require `edit_posts`):

| Route | Method | Use |
|---|---|---|
| `/library` | GET | Full tree (returns `manifest.json`) |
| `/component/{id}` | GET | Single artifact by id |
| `/sync-library` | POST | Refresh builder cache |
| `/save-component` | POST | Create new artifact |
| `/update-component` | POST | Modify existing artifact |
| `/delete-component` | POST | Remove artifact |

When the user is editing the plugin source directly (not via the builder UI), you only need to write the file + update the manifest. The REST endpoints are for builder-driven flows.

## Checks after placement

- `ls data/library/{Type}/{Category}/` shows your new file.
- `grep -n '"id": "{plural-type}-{category-slug}-{file-slug}"' data/manifest.json` returns exactly one line.
- `grep -rn '"id":' data/manifest.json | sort | uniq -d` returns nothing (no duplicate IDs).

## Common mistakes

1. **Manifest not updated** — file exists on disk but the builder cannot see it. The #1 source of "why doesn't my component show up?".
2. **Wrong id case** — `components-Testimonials-my-card` (capital T) will not resolve. Always lowercase.
3. **Mismatched category** — `__libraryMeta.category: "Buttons"` but file under `data/library/Elements/Btns/`. The category folder name must match the metadata exactly.
4. **Slug drift** — file `my-button.json`, id `elements-buttons-mybutton`. Must match exactly.
5. **Adding brand-scoped files to manifest** — don't. They live under `Brands/` and are auto-discovered.
