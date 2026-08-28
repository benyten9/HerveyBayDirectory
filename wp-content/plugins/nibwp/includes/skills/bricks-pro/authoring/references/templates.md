# Bricks template types

`template_type` is set in the manifest preflight and OVERRIDES anything the agent puts in the payload.

## When to use each

| Type      | When                                                                                              |
|-----------|---------------------------------------------------------------------------------------------------|
| `content` | Single-post / single-page template (the body of `single.php` equivalent). Replaces post_content. |
| `archive` | Archive listing wrapper (replaces `archive.php`). Combine with a `posts` element + `pagination`. |
| `section` | Reusable cross-template block — embed via `template` element. No conditions panel. |
| `header`  | Global site header. Renders on every page above `content`. |
| `footer`  | Global site footer. Renders on every page below `content`. |
| `error`   | 404 / search-empty / login-error pages. |
| `popup`   | Modal triggered by an Interaction (click, hover, page-load, exit-intent). |

## Conditions (header/footer/content/archive/error)

Bricks templates carry display conditions: where on the site should this template render?

```json
{
  "conditions": [
    { "main": "entirePost", "type": "include" },          // every single post
    { "main": "postType", "type": "include", "value": ["project"] },  // every single post of CPT "project"
    { "main": "templateArchive", "type": "include", "value": ["archive_project"] }
  ]
}
```

Pass the `conditions` array to `nibwp/bricks-create-template`. The persister sets `_bricks_template_settings.conditions` accordingly.

Common conditions:

- `main: "entirePost"` — every single post page
- `main: "postType", value: [...]` — single posts of these CPTs
- `main: "templateArchive", value: [...]` — archive of these CPTs
- `main: "frontPage"` — homepage
- `main: "page", value: [<page_id>, ...]` — specific page
- `main: "term", value: [<taxonomy>:<term_id>]` — specific term archive

`section` templates have NO conditions (they're embedded explicitly via `template` element).

## Where the data comes from

| Template type | Loop scope                                              |
|---------------|---------------------------------------------------------|
| `content`     | The single post being viewed                            |
| `archive`     | Each post in the archive (`posts` element + pagination)  |
| `section`     | Inherited from the parent template's scope              |
| `header` / `footer` / `error` | No automatic scope; use static data or explicit queries |
| `popup`       | Whatever post is in scope when the popup opens          |

## Push mode

Set via preflight `push_mode`:

- `new_template` — creates a new `bricks_template` post. Requires `new_template_title`.
- `replace_template` — overwrites elements + global classes of an existing template. Requires `target_template_id`.
- `append_to_existing` — appends new elements after existing ones in `target_template_id`.

## Anti-patterns

- **`content` template with static text** — defeats the purpose. Use `{post_title}`, `{post_content}`, etc. The recommender flags this via `dynamic_data_hint`.
- **Copy-paste section across templates** — use `section` template + reference via `template` element instead.
- **Header template without sticky / responsive nav** — almost every project needs it. Use `nav-nested` element with mobile toggle.
- **Footer with hand-rolled nav** — use `nav-menu` element pointing at a WP menu, or `nav-nested` if styling demands.
