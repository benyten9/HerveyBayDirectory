# Bricks Query Loops — `posts` element

The `posts` element renders ONE child subtree per matched post. `settings.query` accepts WP_Query-shaped args.

## Minimal config

```json
{
  "name": "posts",
  "settings": {
    "_id": "abc123",
    "query": {
      "post_type": ["project"],
      "posts_per_page": 6,
      "orderby": "date",
      "order": "DESC"
    }
  },
  "children": [<child element index>]
}
```

The single child element subtree becomes the per-post template.

## Common settings.query fields

```json
{
  "post_type":        ["project"],                          // string or array
  "posts_per_page":   6,                                    // -1 for unlimited (avoid for big sets)
  "orderby":          "date",                               // date | title | modified | menu_order | rand | meta_value | meta_value_num
  "order":            "DESC",                               // ASC | DESC
  "tax_query":        [{ "taxonomy": "project_type", "field": "slug", "terms": ["case-study"] }],
  "meta_query":       [{ "key": "featured", "value": "1", "compare": "=" }],
  "meta_key":         "completion_date",                    // when orderby = meta_value_num
  "ignore_sticky_posts": true,
  "no_found_rows":    true,                                 // perf: skip total count when pagination not needed
  "post_status":      "publish",
  "author":           null,                                 // user id
  "s":                null                                  // search keyword
}
```

## Child element data binding

Inside the child subtree, use dynamic data tags. The active post in the loop becomes the scope:

- Heading: `heading.settings.text = "{post_title}"`
- Body: `text.settings.text = "{post_excerpt:25}"`
- Image: `image.settings.image.useFeaturedImage = true`
- Link: `button.settings.link.type = "internal"` + `settings.link.postId = "{post_id}"`
- ACF: `text.settings.text = "{acf:client_name}"`
- Taxonomy: `text.settings.text = "{post_taxonomy:project_type}"`

## Pagination

If the query needs pagination, add a `pagination` element **OUTSIDE** the posts loop (sibling, not child). It auto-binds to the most recent `posts` element in the same template.

## Performance

- Always set `no_found_rows: true` when not paginating (skips total count, faster).
- `posts_per_page` capped at 50 by Bricks' built-in safety net; for archive use Bricks' "Inherit current query" mode (different element variant).
- `orderby: "rand"` is expensive on large tables — cache via Bricks' query caching or a transient.

## Common queries (paste + edit)

**Recent 6 posts:**
```json
{"post_type":["post"],"posts_per_page":6,"orderby":"date","order":"DESC","no_found_rows":true}
```

**Custom post type — featured projects:**
```json
{"post_type":["project"],"posts_per_page":-1,"meta_query":[{"key":"featured","value":"1"}],"orderby":"menu_order","order":"ASC"}
```

**Posts in a specific category:**
```json
{"post_type":["post"],"posts_per_page":9,"tax_query":[{"taxonomy":"category","field":"slug","terms":["case-studies"]}],"orderby":"date","order":"DESC"}
```

**Related posts (same author, exclude current):**
```json
{"post_type":["post"],"posts_per_page":3,"author":"{post_author}","post__not_in":["{post_id}"],"orderby":"rand"}
```

## Anti-patterns

- **N static cards instead of a loop** — when the source has ≥3 repeating cards, run the orchestrator-recommender suggestion: register CPT + ACF + use a `posts` element.
- **Inline HTML instead of dynamic tags** — `heading.text = "Project Alpha"` works for one card; for a loop, use `{post_title}` so every post renders correctly.
- **Hardcoded post IDs in `post__in`** — fragile. Prefer a custom field flag (`featured: 1`) + meta_query.
- **`tax_query` with `field: "name"`** — fragile because names get edited. Use `field: "slug"` or `field: "term_id"`.
